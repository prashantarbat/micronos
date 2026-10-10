<?php

declare(strict_types=1);

use App\Connectors\ConnectorRunner;
use App\Connectors\Manifest;
use App\Connectors\ManifestRegistry;
use App\Connectors\Targets\ServiceTarget;
use App\Libraries\ConnectorEgressGuard;
use CodeIgniter\Config\Services;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The connector engine skeleton — and above all the F2 property that the
 * `service` target adapter, and therefore every manifest-driven connector,
 * reaches the data plane through `connector.service` and never through
 * `system.service`.
 *
 * @internal
 */
final class ConnectorEngineTest extends BaseTestCase
{
    private function serviceManifest(array $source): Manifest
    {
        return Manifest::fromArray([
            'schema'  => 1,
            'id'      => 'test-connector',
            'name'    => 'Test Connector',
            'version' => '1.0.0',
            'sources' => [$source],
        ]);
    }

    // ------------------------------------------------------------------
    // Manifest validation
    // ------------------------------------------------------------------

    public function testValidManifestLoadsAndNormalisesSources(): void
    {
        $manifest = Manifest::fromArray([
            'schema'  => 1,
            'id'      => 'q-feeds',
            'name'    => 'Q-Feeds',
            'version' => '1.0.0',
            'sources' => [
                ['id' => 'domains', 'target' => 'dnsbl', 'url' => 'https://tip.qfeeds.com/api/v1/feeds/x', 'format' => 'plain-domain-list'],
            ],
        ]);

        $this->assertSame('q-feeds', $manifest->id());
        $this->assertCount(1, $manifest->sources());
        $this->assertSame('dnsbl', $manifest->sources()[0]['target']);
    }

    public function testUnknownTargetAndFormatAreRefused(): void
    {
        foreach ([
            ['id' => 'x', 'target' => 'nftables', 'url' => 'https://e.example/x', 'format' => 'plain-ip-list'],
            ['id' => 'x', 'target' => 'dnsbl', 'url' => 'https://e.example/x', 'format' => 'exec-shell'],
        ] as $source) {
            try {
                $this->serviceManifest($source);
                $this->fail('manifest should have been refused: ' . json_encode($source));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testServiceSourceCannotSmuggleAnRpcName(): void
    {
        // A manifest may name the service id and action, and nothing else. A
        // `method`/`rpc`/`unit` key is refused at load time, so manifest data
        // can never choose the RPC.
        $this->expectException(InvalidArgumentException::class);

        $this->serviceManifest([
            'id'      => 'daemon',
            'target'  => 'service',
            'service' => 'mdns-repeater',
            'action'  => 'restart',
            'method'  => 'system.service',
        ]);
    }

    public function testServiceSourceRejectsUnsafeServiceIdAndBadAction(): void
    {
        foreach ([
            ['id' => 'd', 'target' => 'service', 'service' => 'nftables; rm -rf /', 'action' => 'restart'],
            ['id' => 'd', 'target' => 'service', 'service' => 'unbound', 'action' => 'explode'],
        ] as $source) {
            try {
                $this->serviceManifest($source);
                $this->fail('manifest should have been refused: ' . json_encode($source));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testDuplicateSourceIdsAndBadSchemaAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Manifest::fromArray([
            'schema'  => 2,
            'id'      => 'x',
            'name'    => 'X',
            'version' => '1.0.0',
            'sources' => [['id' => 'd', 'target' => 'service', 'service' => 'squid', 'action' => 'restart']],
        ]);
    }

    // ------------------------------------------------------------------
    // ServiceTarget: the connector.service path
    // ------------------------------------------------------------------

    public function testServiceTargetCallsConnectorServiceNotSystemService(): void
    {
        $ipc = new FakeIpcClient([
            'connector.service' => ['result' => ['success' => true, 'status' => 'applied', 'state' => 'active', 'steps' => [], 'warnings' => []], 'error' => null],
        ]);

        $target = new ServiceTarget($ipc);
        $result = $target->apply($this->serviceManifest([
            'id' => 'daemon', 'target' => 'service', 'service' => 'mdns-repeater', 'action' => 'restart',
        ]), ['id' => 'daemon', 'target' => 'service', 'service' => 'mdns-repeater', 'action' => 'restart']);

        $this->assertSame('connector.service', ServiceTarget::RPC);
        $this->assertSame(ServiceTarget::RPC, $ipc->calls[0]['method']);
        $this->assertSame(['service' => 'mdns-repeater', 'action' => 'restart'], $ipc->calls[0]['params']);
        $this->assertTrue($result['success']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame('active', $result['state']);
    }

    public function testServiceTargetSurfacesMicrondAllowlistRefusal(): void
    {
        // micrond refuses a unit outside CONNECTOR_SAFE_UNITS with a well-formed
        // success=false result (not a transport error) and runs no command.
        $ipc = new FakeIpcClient([
            'connector.service' => ['result' => [
                'success' => false, 'status' => 'error',
                'message' => 'refusing service not permitted for connectors: nftables',
            ], 'error' => null],
        ]);

        $target = new ServiceTarget($ipc);
        $result = $target->apply($this->serviceManifest([
            'id' => 'd', 'target' => 'service', 'service' => 'nftables', 'action' => 'stop',
        ]), ['id' => 'd', 'target' => 'service', 'service' => 'nftables', 'action' => 'stop']);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not permitted for connectors', $result['message']);
        $this->assertSame('connector.service', $ipc->calls[0]['method']);
    }

    public function testServiceTargetReportsTransportError(): void
    {
        $ipc = new FakeIpcClient(); // no response queued → -32601 Method not found

        $target = new ServiceTarget($ipc);
        $result = $target->apply($this->serviceManifest([
            'id' => 'd', 'target' => 'service', 'service' => 'squid', 'action' => 'start',
        ]), ['id' => 'd', 'target' => 'service', 'service' => 'squid', 'action' => 'start']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Method not found', $result['message']);
    }

    // ------------------------------------------------------------------
    // ConnectorRunner: the live path
    // ------------------------------------------------------------------

    public function testRunnerDrivesServiceTargetThroughInjectedIpc(): void
    {
        $ipc = new FakeIpcClient([
            'connector.service' => ['result' => ['success' => true, 'status' => 'applied', 'state' => 'active'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $runner   = new ConnectorRunner();
        $summary  = $runner->runManifest($this->serviceManifest([
            'id' => 'daemon', 'target' => 'service', 'service' => 'mdns-repeater', 'action' => 'restart',
        ]));

        $this->assertTrue($summary['success']);
        $this->assertSame('applied', $summary['sources'][0]['status']);

        // The engine must never reach the admin RPC from manifest data.
        $methods = array_column($ipc->calls, 'method');
        $this->assertSame(['connector.service'], $methods);
        $this->assertNotContains('system.service', $methods);
    }

    public function testRunnerReportsUnregisteredTargetsAsSkipped(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $manifest = Manifest::fromArray([
            'schema'  => 1,
            'id'      => 'q-feeds',
            'name'    => 'Q-Feeds',
            'version' => '1.0.0',
            'sources' => [
                ['id' => 'domains', 'target' => 'dnsbl', 'url' => 'https://e.example/x', 'format' => 'plain-domain-list'],
            ],
        ]);

        // A runner registered with only the service adapter has no fetch
        // adapter, so the fetch source is reported skipped and never attempted.
        $runner  = new ConnectorRunner(new ManifestRegistry(), ['service' => new ServiceTarget($ipc)]);
        $summary = $runner->runManifest($manifest);

        $this->assertFalse($summary['success']);
        $this->assertSame('skipped', $summary['sources'][0]['status']);
        $this->assertSame([], $ipc->calls);
    }

    public function testRunnerStagesFetchSourcesThroughTheEgressGuard(): void
    {
        $tmpDir = sys_get_temp_dir() . '/micron_runner_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);

        $requested = [];
        $transport = static function (array $request, callable $sink) use (&$requested): array {
            $requested[] = $request;
            $sink("blocked.example\n");

            return ['status' => 200, 'headers' => [], 'bytes_in' => 16, 'error' => null];
        };

        $guard  = new ConnectorEgressGuard(static fn (string $host): array => ['93.184.216.34'], $transport);
        $runner = new ConnectorRunner(null, null, $guard, $tmpDir);

        $manifest = Manifest::fromArray([
            'schema'  => 1,
            'id'      => 'q-feeds',
            'name'    => 'Q-Feeds',
            'version' => '1.0.0',
            'sources' => [
                ['id' => 'domains', 'target' => 'dnsbl', 'url' => 'https://feeds.example/list', 'format' => 'plain-domain-list'],
            ],
        ]);

        $summary = $runner->runManifest($manifest);

        try {
            $this->assertTrue($summary['success']);
            $this->assertSame('staged', $summary['sources'][0]['status']);
            $this->assertSame('93.184.216.34', $requested[0]['ip'], 'the fetch must be pinned to the validated IP');

            $staged = $tmpDir . '/' . $summary['sources'][0]['ref'];
            $this->assertFileExists($staged);
            $this->assertStringContainsString('blocked.example', (string) file_get_contents($staged));
            $this->assertContains('dnsbl', $runner->supportedTargets());
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    public function testRunnerRefusesUnknownConnectorId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ConnectorRunner())->run('no-such-connector');
    }

    public function testRunnerRunsTheShippedManifestEndToEnd(): void
    {
        $ipc = new FakeIpcClient([
            'connector.service' => ['result' => ['success' => true, 'status' => 'applied', 'state' => 'active'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $summary = (new ConnectorRunner())->run('mdns-reflection');

        $this->assertSame('mdns-reflection', $summary['connector']);
        $this->assertTrue($summary['success']);
        $this->assertSame('connector.service', $ipc->calls[0]['method']);
        $this->assertSame('mdns-repeater', $ipc->calls[0]['params']['service']);
    }

    // ------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------

    public function testRegistryLoadsShippedManifestsAndMissesUnknownIds(): void
    {
        $registry = new ManifestRegistry();

        $this->assertArrayHasKey('mdns-reflection', $registry->all());
        $this->assertNull($registry->get('does-not-exist'));
        $this->assertSame('mdns-reflection', $registry->get('mdns-reflection')?->id());
    }

    public function testRegistryLoadsFromACustomDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/micron_connectors_' . bin2hex(random_bytes(4));
        mkdir($dir . '/demo', 0777, true);
        file_put_contents($dir . '/demo/manifest.json', json_encode([
            'schema'  => 1,
            'id'      => 'demo',
            'name'    => 'Demo',
            'version' => '1.0.0',
            'sources' => [['id' => 'd', 'target' => 'service', 'service' => 'squid', 'action' => 'start']],
        ]));

        try {
            $registry = new ManifestRegistry($dir);
            $this->assertSame(['demo'], $registry->ids());
        } finally {
            @unlink($dir . '/demo/manifest.json');
            @rmdir($dir . '/demo');
            @rmdir($dir);
        }
    }
}
