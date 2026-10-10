<?php

declare(strict_types=1);

use App\Libraries\PackageManager;
use App\Plugins\PluginRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * PackageManager: resolve a plugin id to a plan, refuse the protected core, and
 * dispatch every operation over IPC without ever shelling out (Plugin Spec §5).
 *
 * @internal
 */
final class PackageManagerTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/micron_plugins_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/plugin.json') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writePlugin(string $id, array $manifest): void
    {
        mkdir($this->dir . '/' . $id, 0700, true);
        file_put_contents($this->dir . '/' . $id . '/plugin.json', json_encode($manifest));
    }

    private function manager(?FakeIpcClient $ipc = null, ?string $dir = null): PackageManager
    {
        return new PackageManager($ipc ?? new FakeIpcClient(), new PluginRegistry($dir));
    }

    public function testCatalogueListsTheShippedPlugins(): void
    {
        $ids = array_column($this->manager()->catalogue(), 'id');

        $this->assertSame(
            ['darkstat', 'iperf3', 'ipv6-tunnel', 'lightsquid', 'lldpd', 'nat64', 'netbird', 'zerotier'],
            $ids
        );
    }

    public function testCatalogueCarriesTheClassCPluginsRepositoryRequirement(): void
    {
        $entry = array_values(array_filter(
            $this->manager()->catalogue(),
            static fn (array $row): bool => $row['id'] === 'netbird',
        ))[0];

        $this->assertSame('C', $entry['class']);
        $this->assertSame(['netbird'], $entry['repos']);
        $this->assertSame(['netbird'], $entry['packages']);
    }

    public function testPlanForAShippedPluginIsInstallable(): void
    {
        $plan = $this->manager()->plan('lldpd');

        $this->assertTrue($plan['ok']);
        $this->assertSame(['lldpd'], $plan['packages']);
        $this->assertSame(['lldpd'], $plan['units']);
        $this->assertSame([], $plan['blocked']);
    }

    public function testPlanForAnUnknownPluginReportsAnError(): void
    {
        $plan = $this->manager()->plan('does-not-exist');

        $this->assertFalse($plan['ok']);
        $this->assertSame('unknown plugin: does-not-exist', $plan['error']);
    }

    public function testPlanBlocksAPluginRequiringAProtectedPackage(): void
    {
        $this->writePlugin('evil', [
            'schema'       => 1,
            'id'           => 'evil',
            'name'         => 'Evil',
            'version'      => '1.0.0',
            'class'        => 'B',
            'requires'     => ['packages' => ['nftables']],
            'capabilities' => [],
            'lifecycle'    => [['step' => 'install', 'action' => 'apt.install', 'packages' => ['nftables']]],
        ]);

        $plan = $this->manager(null, $this->dir)->plan('evil');

        $this->assertFalse($plan['ok']);
        $this->assertNotEmpty($plan['blocked']);
    }

    public function testInstallDispatchesToTheDaemon(): void
    {
        $ipc = (new FakeIpcClient())->respond('plugin.install', [
            'result' => ['success' => true, 'status' => 'applied', 'steps' => ['apt-get -y install lldpd rc=0']],
            'error'  => null,
        ]);

        $result = $this->manager($ipc)->install('lldpd');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $ipc->calls);
        $this->assertSame('plugin.install', $ipc->calls[0]['method']);
        $this->assertSame('lldpd', $ipc->calls[0]['params']['id']);
        $this->assertSame([
            'system.service:lldpd',
            'net.raw',
            'root.rpc:plugin.status',
            'root.rpc:plugin.lldpd.apply',
            'root.rpc:plugin.lldpd.status',
            'root.rpc:plugin.lldpd.neighbours',
            'fs.write:/etc/systemd/system/lldpd.service.d/micron.conf',
            'fs.write:/etc/lldpd.conf',
            'fs.write:/var/lib/micron/plugins/lldpd',
        ], $ipc->calls[0]['params']['grants']);
    }

    public function testInstallOfABlockedPluginNeverReachesTheDaemon(): void
    {
        $this->writePlugin('evil', [
            'schema'       => 1,
            'id'           => 'evil',
            'name'         => 'Evil',
            'version'      => '1.0.0',
            'class'        => 'B',
            'requires'     => ['packages' => ['nftables']],
            'capabilities' => [],
            'lifecycle'    => [['step' => 'install', 'action' => 'apt.install', 'packages' => ['nftables']]],
        ]);

        $ipc    = new FakeIpcClient();
        $result = $this->manager($ipc, $this->dir)->install('evil');

        $this->assertFalse($result['success']);
        $this->assertSame([], $ipc->calls);
    }

    public function testServiceDispatchesWithTheIdAndAction(): void
    {
        $ipc = (new FakeIpcClient())->respond('plugin.service', [
            'result' => ['success' => true, 'status' => 'applied'],
            'error'  => null,
        ]);

        $this->manager($ipc)->service('darkstat', 'restart');

        $this->assertSame('plugin.service', $ipc->calls[0]['method']);
        $this->assertSame(['id' => 'darkstat', 'action' => 'restart'], $ipc->calls[0]['params']);
    }
}
