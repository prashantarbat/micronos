<?php

namespace Tests\App\Libraries;

use App\Libraries\NftablesCompiler;
use App\Libraries\VpnInterfaceProvider;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Validates that /firewall/rules Web UI entities and nftables compiled ruleset
 * are strictly in sync across all VPN types (WireGuard, OpenVPN, IPsec, Road Warrior)
 * and Multi-WAN configurations.
 */
final class FirewallVpnMultiWanSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function tearDown(): void
    {
        $this->db->table('wireguard_tunnels')->emptyTable();
        $this->db->table('openvpn_servers')->emptyTable();
        $this->db->table('ipsec_tunnels')->emptyTable();
        $this->db->table('firewall_rules')->emptyTable();
        $this->db->table('wan_gateways')->emptyTable();
        $this->db->table('wan_gateway_groups')->emptyTable();
        $this->db->table('interfaces')->where('id', 'wan2')->delete();
        parent::tearDown();
    }

    public function testUnifiedInterfacesIncludeAllVpnTunnels(): void
    {
        // 1. Seed WireGuard tunnel
        $this->db->table('wireguard_tunnels')->insert([
            'id'             => 'wg_branch',
            'name'           => 'WG Branch Office',
            'interface_name' => 'wg0',
            'listen_port'    => 51820,
            'private_key'    => 'testkey',
            'public_key'     => 'testpubkey',
            'ipv4_address'   => '10.200.0.1',
            'addresses'      => '10.200.0.1/24',
            'enabled'        => 1,
        ]);

        // 2. Seed OpenVPN server
        $this->db->table('openvpn_servers')->insert([
            'id'             => 1,
            'name'           => 'Road Warrior OpenVPN',
            'interface_name' => 'tun0',
            'mode'           => 'server',
            'listen_port'    => 1194,
            'protocol'       => 'udp',
            'tunnel_subnet'  => '10.8.0.0/24',
            'enabled'        => 1,
        ]);

        // 3. Seed IPsec tunnel
        $this->db->table('ipsec_tunnels')->insert([
            'id'             => 1,
            'name'           => 'Site-to-Site AWS',
            'mode'           => 'site2site',
            'vti_enabled'    => 1,
            'vti_interface'  => 'vti0',
            'vti_local_ip'   => '10.254.0.1',
            'local_subnet'   => '192.168.1.0/24',
            'remote_subnet'  => '172.16.0.0/16',
            'enabled'        => 1,
        ]);

        $unified = VpnInterfaceProvider::getUnifiedInterfaces($this->db);
        $devs = array_column($unified, 'physical_device');

        $this->assertContains('wg0', $devs, 'WireGuard tunnel interface must be discovered in unified interfaces');
        $this->assertContains('tun0', $devs, 'OpenVPN tunnel interface must be discovered in unified interfaces');
        $this->assertContains('vti0', $devs, 'IPsec tunnel interface must be discovered in unified interfaces');
    }

    public function testFirewallRuleTargetingVpnCompilesToValidNftables(): void
    {
        // The test database is shared across the suite; another test may
        // already have created WAN and LAN.
        foreach ([
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1', 'enabled' => 1, 'ip_assignment_type' => 'static'],
        ] as $row) {
            if ($this->db->table('interfaces')->where('id', $row['id'])->countAllResults() === 0) {
                $this->db->table('interfaces')->insert($row);
            }
        }

        // Add a WireGuard -> LAN firewall rule
        $this->db->table('firewall_rules')->insert([
            'id'            => 'pol_wg_lan',
            'name'          => 'Allow WG to LAN',
            'interface_id'  => 'lan',
            'src_interface' => 'wg0',
            'dst_interface' => 'eth1',
            'direction'     => 'forward',
            'protocol'      => 'any',
            'sources'       => json_encode(['10.200.0.0/24']),
            'destinations'  => json_encode(['192.168.1.0/24']),
            'services'      => json_encode(['ALL']),
            'action'        => 'accept',
            'sequence'      => 10,
            'enabled'       => 1,
        ]);

        // Add an OpenVPN Road Warrior -> WAN rule
        $this->db->table('firewall_rules')->insert([
            'id'            => 'pol_rw_wan',
            'name'          => 'Road Warrior to Internet',
            'interface_id'  => 'wan',
            'src_interface' => 'tun0',
            'dst_interface' => 'eth0',
            'direction'     => 'forward',
            'protocol'      => 'any',
            'sources'       => json_encode(['10.8.0.0/24']),
            'destinations'  => json_encode(['0.0.0.0/0']),
            'services'      => json_encode(['ALL']),
            'action'        => 'accept',
            'nat_enabled'   => 1,
            'sequence'      => 20,
            'enabled'       => 1,
        ]);

        $compiler = new NftablesCompiler($this->db);
        $ruleset  = $compiler->compile();

        // Verify forward rules compile the exact interfaces
        $this->assertStringContainsString('iifname "wg0"', $ruleset);
        $this->assertStringContainsString('oifname "eth1"', $ruleset);
        $this->assertStringContainsString('iifname "tun0"', $ruleset);
        $this->assertStringContainsString('oifname "eth0"', $ruleset);

        // Verify fallback tunnel rules exist for all VPN tunnels and road warriors
        $this->assertStringContainsString('iifname "wg+" accept', $ruleset);
        $this->assertStringContainsString('oifname "wg+" accept', $ruleset);
        $this->assertStringContainsString('iifname "tun+" accept', $ruleset);
        $this->assertStringContainsString('oifname "tun+" accept', $ruleset);
        $this->assertStringContainsString('iifname "tap+" accept', $ruleset);
        $this->assertStringContainsString('iifname "vti+" accept', $ruleset);
        $this->assertStringContainsString('iifname "ipsec+" accept', $ruleset);
        $this->assertStringContainsString('iifname "xfrm+" accept', $ruleset);
        $this->assertStringContainsString('iifname "ppp+" accept', $ruleset);
        $this->assertStringContainsString('meta secpath exists accept', $ruleset);
    }

    public function testMultiWanBalancesAcrossUplinks(): void
    {
        $this->db->table('interfaces')->whereIn('id', ['wan', 'wan2', 'lan'])->delete();
        $this->db->table('interfaces')->insertBatch([
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'wan2', 'name' => 'WAN2', 'physical_device' => 'eth2', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1', 'enabled' => 1, 'ip_assignment_type' => 'static'],
        ]);

        $this->db->table('wan_gateways')->insert([
            'id'           => 'gw_isp1',
            'name'         => 'ISP 1 Primary',
            'interface'    => 'eth0',
            'gateway_ip'   => '198.51.100.1',
            'weight'       => 2,
            'priority'     => 1,
            'enabled'      => 1,
        ]);

        $this->db->table('wan_gateways')->insert([
            'id'           => 'gw_isp2',
            'name'         => 'ISP 2 Backup',
            'interface'    => 'eth2',
            'gateway_ip'   => '203.0.113.1',
            'weight'       => 1,
            'priority'     => 2,
            'enabled'      => 1,
        ]);

        $this->db->table('wan_gateway_groups')->insert([
            'id'             => 'mwan_grp_lb',
            'name'           => 'Dual WAN Load Balance',
            'strategy'       => 'load-balance',
            'sticky_enabled' => 1,
            'sticky_timeout' => 300,
            'enabled'        => 1,
            'members'        => json_encode([
                ['id' => 'gw_isp1', 'tier' => 1, 'weight' => 2],
                ['id' => 'gw_isp2', 'tier' => 1, 'weight' => 1],
            ]),
        ]);

        $compiler = new NftablesCompiler($this->db);
        $ruleset  = $compiler->compile();

        // Sticky map declaration
        $this->assertStringContainsString('map micron_sticky_mwan_grp_lb', $ruleset);
        // Multi-WAN mark generation
        $this->assertStringContainsString('Multi-WAN flow affinity', $ruleset);
        $this->assertStringContainsString('Multi-WAN load balance: Dual WAN Load Balance', $ruleset);
        // Outbound NAT on WAN interfaces
        $this->assertStringContainsString('oifname "eth0" masquerade', $ruleset);
        $this->assertStringContainsString('oifname "eth2" masquerade', $ruleset);
    }
}
