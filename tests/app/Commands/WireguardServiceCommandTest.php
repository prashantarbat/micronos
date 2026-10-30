<?php

namespace Tests\App\Commands;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\Libraries\FakeIpcClient;

final class WireguardServiceCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private FakeIpcClient $ipc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('wireguard_tunnels')->whereIn('id', ['wgsvc_a', 'wgsvc_b'])->delete();
        $this->ipc = new FakeIpcClient([
            'vpn.wireguard.service' => [
                'result' => ['success' => true, 'message' => 'WireGuard tunnels started.'],
                'error'  => null,
            ],
        ]);
        Services::injectMock('ipc', $this->ipc);
    }

    protected function tearDown(): void
    {
        $this->db->table('wireguard_tunnels')->whereIn('id', ['wgsvc_a', 'wgsvc_b'])->delete();
        parent::tearDown();
    }

    public function testCommandSendsAllConfiguredInterfaceNamesAndEnabledFlags(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('wireguard_tunnels')->insertBatch([
            [
                'id' => 'wgsvc_a', 'name' => 'North branch', 'interface_name' => 'wg23', 'listen_port' => 51823,
                'private_key' => 'aGVsbG8gd29ybGQgaGVsbG8gd29ybGQgaGVsbG8hIQ==', 'public_key' => 'cHViIGtleSBub3J0aA==',
                'addresses' => '10.23.0.1/24', 'ipv4_address' => '10.23.0.1', 'ipv4_subnet' => 24,
                'mtu' => 1420, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => 'wgsvc_b', 'name' => 'Branch link', 'interface_name' => 'wg-branch', 'listen_port' => 51824,
                'private_key' => 'aGVsbG8gd29ybGQgaGVsbG8gd29ybGQgaGVsbG8hIQ==', 'public_key' => 'cHViIGtleSBicmFuY2g=',
                'addresses' => '10.24.0.1/24', 'ipv4_address' => '10.24.0.1', 'ipv4_subnet' => 24,
                'mtu' => 1420, 'enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $this->resetStreamFilterBuffer();
        command('wireguard:service start');
        $output   = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('WireGuard tunnels started.', $output);
        $calls = array_values(array_filter($this->ipc->calls, static fn (array $call): bool => $call['method'] === 'vpn.wireguard.service'));
        $this->assertCount(1, $calls);
        $tunnels = array_column($calls[0]['params']['tunnels'], null, 'interface');
        $this->assertArrayHasKey('wg23', $tunnels);
        $this->assertArrayHasKey('wg-branch', $tunnels);
        $this->assertTrue($tunnels['wg23']['enabled']);
        $this->assertFalse($tunnels['wg-branch']['enabled']);
        $this->assertStringContainsString('ListenPort = 51823', $tunnels['wg23']['config']);
    }
}