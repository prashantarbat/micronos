<?php

namespace Tests\App\Controllers;

use App\Libraries\PackageManager;
use App\Models\InterfaceModel;
use App\Plugins\PluginRegistry;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for enterprise-grade Interface CRUD:
 *  - Store physical port assignments & 802.1Q VLAN virtual interfaces
 *  - Edit & save link addressing, MSS clamping, MAC spoofing, MTU
 *  - Toggle interface administrative state
 *  - Delete interface with dependency protection (firewall/NAT/DHCP)
 *  - Core WAN/LAN deletion protection
 *
 * @internal
 */
final class InterfacesCrudTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'interfaces.view',
        'interfaces.edit',
        'interfaces.apply',
        'interfaces.vlan.manage',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('interfaces')->whereIn('id', ['dmz_test', 'vlan_test', 'opt_test'])->delete();
        $this->db->table('firewall_rules')->where('interface_id', 'dmz_test')->delete();
        $this->deleteLldpdRow();

        // Ensure core interfaces exist in test database
        $now = date('Y-m-d H:i:s');
        if ($this->db->table('interfaces')->where('id', 'wan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id'                 => 'wan',
                'name'               => 'WAN',
                'physical_device'    => 'eth0',
                'interface_type'     => 'physical',
                'enabled'            => 1,
                'ip_assignment_type' => 'dhcp',
                'mtu'                => 1500,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
        if ($this->db->table('interfaces')->where('id', 'lan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id'                 => 'lan',
                'name'               => 'LAN',
                'physical_device'    => 'eth1',
                'interface_type'     => 'physical',
                'enabled'            => 1,
                'ip_assignment_type' => 'static',
                'ipv4_address'       => '192.168.1.1',
                'ipv4_netmask'       => 24,
                'mtu'                => 1500,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('interfaces')->whereIn('id', ['dmz_test', 'vlan_test', 'opt_test'])->delete();
        $this->db->table('firewall_rules')->where('interface_id', 'dmz_test')->delete();
        $this->deleteLldpdRow();
        parent::tearDown();
    }

    private function deleteLldpdRow(): void
    {
        if ($this->db->tableExists('plugins')) {
            $this->db->table('plugins')->where('id', 'lldpd')->delete();
        }
    }

    /**
     * Mark lldpd installed, which is the only thing that makes the interface
     * inventory consult the LLDP neighbour table.
     */
    private function installLldpd(): void
    {
        $this->db->table('plugins')->insert([
            'id'            => 'lldpd',
            'name'          => 'lldpd',
            'version'       => '1.0.0',
            'class'         => 'B',
            'state'         => 'enabled',
            'installed_at'  => date('Y-m-d H:i:s'),
            'enabled'       => 1,
            'data_policy'   => 'retain',
            'manifest_json' => '{}',
        ]);
    }

    /**
     * A daemon that answers `plugin.lldpd.neighbours` with structured rows.
     *
     * @param list<array<string, mixed>> $ports
     */
    private function fakeLldpdDaemon(array $ports): FakeIpcClient
    {
        $ipc = new FakeIpcClient([
            'plugin.lldpd.neighbours' => ['result' => [
                'success' => true,
                'status'  => 'ok',
                'id'      => 'lldpd',
                'data'    => ['ports' => $ports, 'truncated' => false],
            ], 'error' => null],
        ]);
        Services::injectMock('packageManager', new PackageManager($ipc, new PluginRegistry()));

        return $ipc;
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'admin',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    public function testIndexRendersInterfacesDashboardWithSummaryAndModal(): void
    {
        $this->withSession($this->session());
        $result = $this->get('interfaces');

        $result->assertOK();
        $body = $result->getBody();

        $this->assertStringContainsString('Network Interfaces', $body);
        $this->assertStringContainsString('Add Interface / VLAN', $body);
        $this->assertStringContainsString('Physical NICs', $body);
        $this->assertStringContainsString('802.1Q VLANs', $body);
    }

    public function testIndexShowsTheNeighbourEachInterfaceIsPatchedInto(): void
    {
        $this->installLldpd();

        $ipc = $this->fakeLldpdDaemon([
            // LAN is bound to eth1, so this names the switch and port it reaches.
            ['interface' => 'eth1', 'chassis' => 'core-sw1', 'port' => 'Gi0/12', 'mgmt_ip' => '10.0.0.2'],
            // eth2 answers too, but no interface is bound to it. A peer is
            // inventory of the *device*, shown only where the device is used.
            ['interface' => 'eth2', 'chassis' => 'edge-sw2', 'port' => 'Te1/0/1'],
        ]);

        $result = $this->withSession($this->session())->get('interfaces');

        $result->assertOK();
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Patched into', $body);
        $this->assertStringContainsString('core-sw1', $body);
        $this->assertStringContainsString('Gi0/12', $body);
        $this->assertStringNotContainsString('edge-sw2', $body);

        // The inventory asked for the structured table, not the text listing.
        $this->assertSame('plugin.lldpd.neighbours', $ipc->calls[0]['method']);
        $this->assertSame('json', $ipc->calls[0]['params']['format']);
    }

    public function testIndexShowsNoPeerWhenLldpdIsNotInstalled(): void
    {
        // A daemon that *would* answer, to prove the plugin gate — not the
        // daemon — is what keeps an uninstalled plugin out of the inventory.
        $ipc = $this->fakeLldpdDaemon([
            ['interface' => 'eth1', 'chassis' => 'core-sw1', 'port' => 'Gi0/12'],
        ]);

        $result = $this->withSession($this->session())->get('interfaces');

        $result->assertOK();
        $this->assertStringNotContainsString('Patched into', (string) $result->getBody());
        $this->assertSame([], $ipc->calls, 'An uninstalled plugin must not be asked for neighbours.');
    }

    public function testIndexStillRendersWhenTheNeighbourDaemonFails(): void
    {
        $this->installLldpd();

        // No canned response: the daemon is treated as unreachable.
        Services::injectMock('packageManager', new PackageManager(new FakeIpcClient(), new PluginRegistry()));

        $result = $this->withSession($this->session())->get('interfaces');

        $result->assertOK();
        $this->assertStringContainsString('Network Interfaces', (string) $result->getBody());
        $this->assertStringNotContainsString('Patched into', (string) $result->getBody());
    }

    public function testStorePhysicalInterface(): void
    {
        $result = $this->post('interfaces/store', $this->csrf([
            'id'                 => 'dmz_test',
            'name'               => 'DMZ Network',
            'interface_type'     => 'physical',
            'physical_device'    => 'eth2',
            'ip_assignment_type' => 'static',
            'ipv4_address'       => '172.16.1.1',
            'ipv4_netmask'       => '24',
            'ipv4_gateway'       => '172.16.1.254',
            'mtu'                => '1500',
            'mss_clamp'          => '1452',
            'mac_override'       => '00:11:22:33:44:55',
            'speed_duplex'       => '1000baseT-FD',
            'description'        => 'Isolated DMZ Tier',
            'enabled'            => '1',
        ]));

        $result->assertRedirectTo(base_url('interfaces'));

        $row = $this->db->table('interfaces')->where('id', 'dmz_test')->get()->getRowArray();
        $this->assertNotNull($row, 'Physical interface was not persisted in database.');
        $this->assertSame('DMZ Network', $row['name']);
        $this->assertSame('physical', $row['interface_type']);
        $this->assertSame('eth2', $row['physical_device']);
        $this->assertSame('static', $row['ip_assignment_type']);
        $this->assertSame('172.16.1.1', $row['ipv4_address']);
        $this->assertSame(24, (int) $row['ipv4_netmask']);
        $this->assertSame(1452, (int) $row['mss_clamp']);
        $this->assertSame('00:11:22:33:44:55', $row['mac_override']);
    }

    public function testStoreVlanVirtualInterface(): void
    {
        $result = $this->post('interfaces/store', $this->csrf([
            'id'                 => 'vlan_test',
            'name'               => 'Guest Wi-Fi VLAN',
            'interface_type'     => 'vlan',
            'vlan_parent'        => 'eth1',
            'vlan_tag'           => '100',
            'vlan_priority'      => '3',
            'ip_assignment_type' => 'static',
            'ipv4_address'       => '10.10.100.1',
            'ipv4_netmask'       => '24',
            'mtu'                => '1500',
            'description'        => '802.1Q tagged subnet for guests',
            'enabled'            => '1',
        ]));

        $result->assertRedirectTo(base_url('interfaces'));

        $row = $this->db->table('interfaces')->where('id', 'vlan_test')->get()->getRowArray();
        $this->assertNotNull($row, 'VLAN interface was not persisted in database.');
        $this->assertSame('Guest Wi-Fi VLAN', $row['name']);
        $this->assertSame('vlan', $row['interface_type']);
        $this->assertSame('eth1', $row['vlan_parent']);
        $this->assertSame(100, (int) $row['vlan_tag']);
        $this->assertSame(3, (int) $row['vlan_priority']);
        $this->assertSame('eth1.100', $row['physical_device'], 'Kernel device binding should be parent.tag');
    }

    public function testStoreRejectsInvalidVlanTag(): void
    {
        $result = $this->post('interfaces/store', $this->csrf([
            'name'           => 'Bad VLAN',
            'interface_type' => 'vlan',
            'vlan_parent'    => 'eth1',
            'vlan_tag'       => '5000', // out of 1-4094 range
        ]));

        $result->assertRedirect();

        $count = $this->db->table('interfaces')->where('name', 'Bad VLAN')->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testStoreRejectsDuplicateName(): void
    {
        // 'LAN' already exists in seed
        $result = $this->post('interfaces/store', $this->csrf([
            'name'            => 'LAN',
            'interface_type'  => 'physical',
            'physical_device' => 'eth3',
        ]));

        $result->assertRedirect();

        // Count should remain 1
        $count = $this->db->table('interfaces')->where('name', 'LAN')->countAllResults();
        $this->assertSame(1, $count);
    }

    public function testToggleInterface(): void
    {
        // Seed test interface enabled = 1
        $now = date('Y-m-d H:i:s');
        $this->db->table('interfaces')->insert([
            'id'                 => 'opt_test',
            'name'               => 'OPT Test',
            'physical_device'    => 'eth3',
            'interface_type'     => 'physical',
            'enabled'            => 1,
            'ip_assignment_type' => 'dhcp',
            'mtu'                => 1500,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        // Toggle to disabled
        $this->post('interfaces/opt_test/toggle', $this->csrf([]))
            ->assertRedirectTo(base_url('interfaces'));

        $row = $this->db->table('interfaces')->where('id', 'opt_test')->get()->getRowArray();
        $this->assertSame(0, (int) $row['enabled']);

        // Toggle back to enabled
        $this->post('interfaces/opt_test/toggle', $this->csrf([]))
            ->assertRedirectTo(base_url('interfaces'));

        $row = $this->db->table('interfaces')->where('id', 'opt_test')->get()->getRowArray();
        $this->assertSame(1, (int) $row['enabled']);
    }

    public function testDeleteInterfaceBlocksWhenReferencedByFirewallRules(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('interfaces')->insert([
            'id'                 => 'dmz_test',
            'name'               => 'DMZ Protect',
            'physical_device'    => 'eth2',
            'interface_type'     => 'physical',
            'enabled'            => 1,
            'ip_assignment_type' => 'static',
            'ipv4_address'       => '172.16.0.1',
            'ipv4_netmask'       => 24,
            'mtu'                => 1500,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        // Add firewall rule referencing dmz_test
        $this->db->table('firewall_rules')->insert([
            'id'           => 'rule_test_dmz_dep',
            'sequence'     => 99,
            'interface_id' => 'dmz_test',
            'action'       => 'pass',
            'direction'    => 'in',
            'protocol'     => 'tcp',
            'source_type'  => 'any',
            'source_value' => 'any',
            'dest_type'    => 'any',
            'dest_value'   => 'any',
            'dest_port'    => '80',
            'enabled'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // Attempt deletion
        $this->post('interfaces/dmz_test/delete', $this->csrf([]))
            ->assertRedirectTo(base_url('interfaces'));

        // Interface must still exist due to dependency protection
        $exists = $this->db->table('interfaces')->where('id', 'dmz_test')->countAllResults();
        $this->assertSame(1, $exists, 'Interface should not be deleted when referenced by firewall rules.');
    }

    public function testDeleteInterfaceSucceedsWhenUnreferenced(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('interfaces')->insert([
            'id'                 => 'opt_test',
            'name'               => 'Unused OPT',
            'physical_device'    => 'eth3',
            'interface_type'     => 'physical',
            'enabled'            => 1,
            'ip_assignment_type' => 'dhcp',
            'mtu'                => 1500,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $this->post('interfaces/opt_test/delete', $this->csrf([]))
            ->assertRedirectTo(base_url('interfaces'));

        $exists = $this->db->table('interfaces')->where('id', 'opt_test')->countAllResults();
        $this->assertSame(0, $exists, 'Unreferenced interface should be deleted successfully.');
    }

    public function testDeleteProtectsCoreWanAndLan(): void
    {
        // Attempt to delete WAN
        $this->post('interfaces/wan/delete', $this->csrf([]))
            ->assertRedirectTo(base_url('interfaces'));

        $this->assertSame(1, $this->db->table('interfaces')->where('id', 'wan')->countAllResults());

        // Attempt to delete LAN
        $this->post('interfaces/lan/delete', $this->csrf([]))
            ->assertRedirectTo(base_url('interfaces'));

        $this->assertSame(1, $this->db->table('interfaces')->where('id', 'lan')->countAllResults());
    }

    public function testStoreWithPredictableNicName(): void
    {
        $this->db->table('interfaces')->where('id', 'predictable_test')->delete();

        $params = [
            'id'                 => 'predictable_test',
            'name'               => 'SFP Port 1',
            'interface_type'     => 'physical',
            'physical_device'    => 'enp7s0',
            'ip_assignment_type' => 'dhcp',
            'enabled'            => 1,
            'mtu'                => 1500,
        ];

        $result = $this->post('interfaces/store', $this->csrf($params));
        $result->assertRedirectTo(base_url('interfaces'));

        $row = $this->db->table('interfaces')->where('id', 'predictable_test')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('enp7s0', $row['physical_device']);

        $this->db->table('interfaces')->where('id', 'predictable_test')->delete();
    }

    public function testStoreWithCustomDeviceName(): void
    {
        $this->db->table('interfaces')->where('id', 'custom_nic_test')->delete();

        $params = [
            'id'                     => 'custom_nic_test',
            'name'                   => 'USB WAN Dongle',
            'interface_type'         => 'physical',
            'physical_device'        => '__custom__',
            'physical_device_custom' => 'enx001122334455',
            'ip_assignment_type'     => 'dhcp',
            'enabled'                => 1,
            'mtu'                    => 1500,
        ];

        $result = $this->post('interfaces/store', $this->csrf($params));
        $result->assertRedirectTo(base_url('interfaces'));

        $row = $this->db->table('interfaces')->where('id', 'custom_nic_test')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('enx001122334455', $row['physical_device']);

        $this->db->table('interfaces')->where('id', 'custom_nic_test')->delete();
    }
}

