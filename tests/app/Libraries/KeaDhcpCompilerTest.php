<?php

declare(strict_types=1);

use App\Libraries\KeaDhcpCompiler;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

/**
 * KeaDhcpCompiler: build the Kea Dhcp4 JSON the daemon validates and installs
 * — subnet pools, gateway/DNS options and static MAC reservations — and skip an
 * interface whose DHCP server is disabled. This is the exact artifact `dhcp.apply`
 * validates, so its shape is asserted here rather than assumed.
 *
 * @internal
 */
final class KeaDhcpCompilerTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        foreach (['dhcp_reservations', 'dhcp_server_settings', 'interfaces'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->emptyTable();
            }
        }
    }

    private function iface(string $id, string $dev, string $ip, int $cidr, int $enabled = 1): void
    {
        $this->db->table('interfaces')->insert([
            'id' => $id, 'name' => strtoupper($id), 'physical_device' => $dev,
            'enabled' => $enabled, 'ip_assignment_type' => 'static',
            'ipv4_address' => $ip, 'ipv4_netmask' => $cidr,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function settings(string $iface, int $enabled = 1): void
    {
        $this->db->table('dhcp_server_settings')->insert([
            'interface_id' => $iface, 'enabled' => $enabled,
            'range_from' => '192.168.1.10', 'range_to' => '192.168.1.245',
            'gateway' => '192.168.1.1', 'dns_1' => '192.168.1.1', 'domain_name' => 'micron.lan',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testCompilesAValidKeaDhcp4Config(): void
    {
        $this->iface('lan', 'eth1', '192.168.1.1', 24);
        $this->settings('lan');
        $this->db->table('dhcp_reservations')->insert([
            'id' => 'res_1', 'interface_id' => 'lan', 'ip_address' => '192.168.1.50',
            'mac_address' => '00:11:22:33:44:55', 'hostname' => 'printer',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $json = (new KeaDhcpCompiler())->compile();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('Dhcp4', $decoded);
        $this->assertSame(['eth1'], $decoded['Dhcp4']['interfaces-config']['interfaces']);
        $this->assertSame('memfile', $decoded['Dhcp4']['lease-database']['type']);
        $this->assertCount(1, $decoded['Dhcp4']['subnet4']);

        $subnet = $decoded['Dhcp4']['subnet4'][0];
        $this->assertSame(1, $subnet['id']);
        $this->assertSame('192.168.1.0/24', $subnet['subnet']);
        $this->assertSame('eth1', $subnet['interface']);
        $this->assertSame('192.168.1.10 - 192.168.1.245', $subnet['pools'][0]['pool']);

        $optionNames = array_column($subnet['option-data'], 'name');
        $this->assertContains('routers', $optionNames);
        $this->assertContains('domain-name-servers', $optionNames);

        $this->assertCount(1, $subnet['reservations']);
        $this->assertSame('00:11:22:33:44:55', $subnet['reservations'][0]['hw-address']);
        $this->assertSame('192.168.1.50', $subnet['reservations'][0]['ip-address']);
    }

    public function testASubnetWithNoReservationsStillEmitsAnEmptyList(): void
    {
        $this->iface('lan', 'eth1', '192.168.1.1', 24);

        $decoded = json_decode((new KeaDhcpCompiler())->compile(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $decoded['Dhcp4']['subnet4'][0]['reservations']);
    }

    public function testAnExplicitlyDisabledDhcpSettingProducesNoSubnet(): void
    {
        $this->iface('lan', 'eth1', '192.168.1.1', 24);
        $this->settings('lan', 0);

        $decoded = json_decode((new KeaDhcpCompiler())->compile(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $decoded['Dhcp4']['subnet4']);
        $this->assertSame([], $decoded['Dhcp4']['interfaces-config']['interfaces']);
    }
}
