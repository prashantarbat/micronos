<?php

declare(strict_types=1);

use App\Connectors\Manifest;
use App\Connectors\Targets\FetchTarget;
use App\Libraries\ConnectorEgressGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * FetchTarget: fetch through the egress guard, parse into a canonical typed set,
 * enforce the target/type match, and retain only the validated set.
 *
 * @internal
 */
final class FetchTargetTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/micron_fetch_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function guard(array $ips, string $body): ConnectorEgressGuard
    {
        $transport = static function (array $request, callable $sink) use ($body): array {
            $sink($body);

            return ['status' => 200, 'headers' => [], 'bytes_in' => strlen($body), 'error' => null];
        };

        return new ConnectorEgressGuard(static fn (string $host): array => $ips, $transport);
    }

    private function manifest(string $target, string $format): Manifest
    {
        return Manifest::fromArray([
            'schema'  => 1,
            'id'      => 'demo',
            'name'    => 'Demo',
            'version' => '1.0.0',
            'sources' => [
                ['id' => 'feed', 'target' => $target, 'url' => 'https://feeds.example/list', 'format' => $format],
            ],
        ]);
    }

    public function testFetchesParsesAndWritesATypedSet(): void
    {
        $target = new FetchTarget('dnsbl', $this->guard(['93.184.216.34'], "evil.example.com\ntracker.example.net\nnope\n"), $this->dir);
        $result = $target->apply($this->manifest('dnsbl', 'plain-domain-list'), [
            'id' => 'feed', 'target' => 'dnsbl', 'url' => 'https://feeds.example/list', 'format' => 'plain-domain-list',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('staged', $result['status']);
        $this->assertSame('domain', $result['type']);
        $this->assertSame(2, $result['entries']);
        $this->assertSame(1, $result['dropped']);

        $set = $this->dir . '/' . $result['ref'];
        $this->assertFileExists($set);
        $this->assertStringContainsString("evil.example.com\n", (string) file_get_contents($set));
        $this->assertStringContainsString('tracker.example.net', (string) file_get_contents($set));

        // The raw untrusted body is never retained.
        $this->assertFileDoesNotExist($this->dir . '/demo__feed.feed');
    }

    public function testTypeMismatchIsRefused(): void
    {
        // An address group must not be fed domains.
        $target = new FetchTarget('addressgroup', $this->guard(['93.184.216.34'], "evil.example.com\n"), $this->dir);
        $result = $target->apply($this->manifest('addressgroup', 'plain-domain-list'), [
            'id' => 'feed', 'target' => 'addressgroup', 'url' => 'https://feeds.example/list', 'format' => 'plain-domain-list',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('needs address', $result['message']);
    }

    public function testEmptyParseIsRefusedAndLeavesNoSet(): void
    {
        $target = new FetchTarget('dnsbl', $this->guard(['93.184.216.34'], "# only comments\n\n"), $this->dir);
        $result = $target->apply($this->manifest('dnsbl', 'plain-domain-list'), [
            'id' => 'feed', 'target' => 'dnsbl', 'url' => 'https://feeds.example/list', 'format' => 'plain-domain-list',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no valid entries', $result['message']);
        $this->assertFileDoesNotExist($this->dir . '/demo__feed.set');
    }

    public function testEgressRefusalIsPropagated(): void
    {
        // The resolver returns an internal address: the guard must refuse the
        // fetch before anything is parsed or staged.
        $target = new FetchTarget('dnsbl', $this->guard(['10.0.0.5'], "evil.example.com\n"), $this->dir);
        $result = $target->apply($this->manifest('dnsbl', 'plain-domain-list'), [
            'id' => 'feed', 'target' => 'dnsbl', 'url' => 'https://feeds.example/list', 'format' => 'plain-domain-list',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('egress refused', $result['message']);
        $this->assertFileDoesNotExist($this->dir . '/demo__feed.set');
    }

    public function testAddressGroupProducesCidrs(): void
    {
        $target = new FetchTarget('addressgroup', $this->guard(['93.184.216.34'], "10.0.0.9/24\n1.2.3.4\n"), $this->dir);
        $result = $target->apply($this->manifest('addressgroup', 'plain-ip-list'), [
            'id' => 'feed', 'target' => 'addressgroup', 'url' => 'https://feeds.example/list', 'format' => 'plain-ip-list',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('address', $result['type']);
        $set = (string) file_get_contents($this->dir . '/' . $result['ref']);
        $this->assertStringContainsString('10.0.0.0/24', $set);
        $this->assertStringContainsString('1.2.3.4/32', $set);
    }
}
