<?php

namespace Tests\App\Libraries;

use App\Libraries\FrrCompiler;
use App\Libraries\ServiceConfigCompiler;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

/**
 * The compilers behind ConfigApplier: FRR, Net-SNMP, rsyslog SIEM forwarding,
 * Keepalived and the tc/CAKE shaper. Each must emit valid syntax for good rows
 * and drop (with a comment) values that would break or inject into the file.
 */
final class ServiceCompilersTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    // No refresh: each test truncates the tables it writes, and a full
    // rollback would run every migration's down() on the shared schema.
    protected $refresh     = false;

    private function upsert(string $table, array $row): void
    {
        $this->db->table($table)->truncate();
        $this->db->table($table)->insert($row);
    }

    public function testFrrEmitsBgpNeighboursAndSkipsInvalidOnes(): void
    {
        $this->upsert('frr_bgp_settings', ['id' => 1, 'enabled' => 1, 'local_as' => 65001, 'router_id' => '10.0.0.1',
            'networks' => '192.168.10.0/24', 'redistribute_connected' => 1]);
        $this->db->table('frr_bgp_neighbors')->truncate();
        foreach ([
            ['id' => 'n1', 'neighbor_ip' => '10.0.0.2', 'remote_as' => 65002, 'description' => 'upstream', 'enabled' => 1, 'next_hop_self' => 1],
            ['id' => 'n2', 'neighbor_ip' => '10.0.0.3; rm -rf /', 'remote_as' => 65003, 'enabled' => 1],
            ['id' => 'n3', 'neighbor_ip' => '10.0.0.4', 'remote_as' => 65004, 'enabled' => 0],
        ] as $row) {
            $this->db->table('frr_bgp_neighbors')->insert($row);
        }

        $conf = (new FrrCompiler())->compile();

        $this->assertStringContainsString('router bgp 65001', $conf);
        $this->assertStringContainsString(' bgp router-id 10.0.0.1', $conf);
        $this->assertStringContainsString(' neighbor 10.0.0.2 remote-as 65002', $conf);
        $this->assertStringContainsString('  neighbor 10.0.0.2 next-hop-self', $conf);
        $this->assertStringContainsString('  network 192.168.10.0/24', $conf);
        $this->assertStringNotContainsString('rm -rf', preg_replace('/^\s*! skipped.*$/m', '', $conf),
            'an invalid neighbour may only appear inside a "! skipped" comment');
        $this->assertStringNotContainsString('10.0.0.4', $conf, 'a disabled neighbour is not compiled');
    }

    public function testFrrOspfNetworksAndPassiveDefault(): void
    {
        $this->upsert('frr_ospf_settings', ['id' => 1, 'enabled' => 1, 'router_id' => '1.1.1.1', 'passive_default' => 1]);
        $this->db->table('frr_ospf_networks')->truncate();
        $this->db->table('frr_ospf_networks')->insert(['id' => 'o1', 'network' => '10.1.0.0/16', 'area' => '0', 'enabled' => 1]);

        $conf = (new FrrCompiler())->compile();

        $this->assertStringContainsString("router ospf\n ospf router-id 1.1.1.1", $conf);
        $this->assertStringContainsString(' passive-interface default', $conf);
        $this->assertStringContainsString(' network 10.1.0.0/16 area 0', $conf);
    }

    public function testFrrOspfInterfaceAreaDescriptionAndOmittedDefaults(): void
    {
        $this->upsert('frr_ospf_settings', [
            'id' => 1,
            'enabled' => 1,
            'router_id' => '192.168.240.147',
            'log_adj_changes' => 1,
            'reference_bandwidth' => 100,
            'max_paths' => 4,
            'default_area' => '0.0.0.0',
        ]);
        $this->db->table('frr_ospf_ifaces')->truncate();
        $this->db->table('frr_ospf_ifaces')->insert([
            'id' => 'oif1',
            'interface' => 'wg538',
            'description' => 'WG538_ILL',
            'network_type' => 'point-to-point',
            'area' => '0.0.0.0',
            'metric' => 20,
            'ignore_mtu' => 1,
            'bfd_enabled' => 1,
        ]);
        $this->db->table('frr_ospf_ifaces')->insert([
            'id' => 'oif2',
            'interface' => 'enp3s0',
            'description' => null,
            'network_type' => 'broadcast',
            'area' => null,
            'ignore_mtu' => 1,
            'bfd_enabled' => 1,
            'passive' => 1,
        ]);
        $this->db->table('frr_ospf_networks')->truncate();
        $this->db->table('frr_ospf_networks')->insert(['id' => 'n1', 'network' => '172.17.0.152/30', 'area' => '0.0.0.0', 'enabled' => 1]);

        $conf = (new FrrCompiler())->compile();

        // Interface wg538 block
        $this->assertStringContainsString("interface wg538\n description \"ospfd: WG538_ILL\"", $conf);
        $this->assertStringContainsString(' ip ospf network point-to-point', $conf);
        $this->assertStringContainsString(' ip ospf cost 20', $conf);
        $this->assertStringContainsString(' ip ospf bfd', $conf);
        $this->assertStringContainsString(' ip ospf mtu-ignore', $conf);
        $this->assertStringContainsString(' ip ospf area 0.0.0.0', $conf);

        // Interface enp3s0 block
        $this->assertStringContainsString("interface enp3s0\n ip ospf network broadcast", $conf);
        $this->assertStringContainsString(' ip ospf mtu-ignore', $conf);
        $this->assertStringContainsString(' ip ospf area 0.0.0.0', $conf);

        // Defaults 100 and 4 should NOT be emitted
        $this->assertStringNotContainsString('auto-cost reference-bandwidth', $conf);
        $this->assertStringNotContainsString('maximum-paths', $conf);

        // Global and protocol settings matching pfSense behavior
        $this->assertStringContainsString('ip nht resolve-via-default', $conf);
        $this->assertStringContainsString('ip router-id 192.168.240.147', $conf);
        $this->assertStringContainsString('log-adjacency-changes detail', $conf);
        $this->assertStringContainsString("\nbfd\n!\nline vty", $conf);

        // Non-default values SHOULD be emitted
        $this->upsert('frr_ospf_settings', [
            'id' => 1,
            'reference_bandwidth' => 1000,
            'max_paths' => 8,
        ]);
        $conf2 = (new FrrCompiler())->compile();
        $this->assertStringContainsString(' auto-cost reference-bandwidth 1000', $conf2);
        $this->assertStringContainsString(' maximum-paths 8', $conf2);
    }

    public function testSnmpdRestrictsCommunitiesToAllowedSubnets(): void
    {
        $this->upsert('snmp_settings', ['id' => 1, 'enabled' => 1, 'listen_port' => 161, 'v1_v2c_enabled' => 1,
            'read_community' => 'n0c-read', 'allowed_subnets' => '10.0.0.0/8, bogus', 'system_location' => "DC1\nrocommunity evil"]);

        $conf = (new ServiceConfigCompiler())->snmpd();

        $this->assertStringContainsString('rocommunity n0c-read 10.0.0.0/8', $conf);
        $this->assertStringNotContainsString('bogus', $conf);
        $this->assertStringNotContainsString("\nrocommunity evil", $conf, 'a newline in a text field must not start a new directive');
    }

    public function testSnmpdDisabledListensOnLoopbackOnly(): void
    {
        $this->upsert('snmp_settings', ['id' => 1, 'enabled' => 0, 'read_community' => 'public']);
        $conf = (new ServiceConfigCompiler())->snmpd();

        $this->assertStringContainsString('agentAddress udp:127.0.0.1:161', $conf);
        $this->assertStringNotContainsString('rocommunity', $conf);
    }

    public function testSnmpdOmitsIpv6WhenSystemIpv6IsDisabled(): void
    {
        $this->upsert('system_settings', ['id' => 1, 'ipv6_enabled' => 0]);
        $this->upsert('snmp_settings', [
            'id' => 1, 'enabled' => 1, 'listen_port' => 161, 'v1_v2c_enabled' => 1,
            'read_community' => 'public', 'allowed_subnets' => '192.168.1.0/24, 2001:db8::/32',
        ]);
        $conf = (new ServiceConfigCompiler())->snmpd();

        $this->assertStringContainsString('agentAddress udp:161', $conf);
        $this->assertStringNotContainsString('udp6:[::]:161', $conf);
        $this->assertStringContainsString('rocommunity public 192.168.1.0/24', $conf);
        $this->assertStringNotContainsString('rocommunity6', $conf);
    }

    public function testSnmpdIncludesIpv6WhenSystemIpv6IsEnabled(): void
    {
        $this->upsert('system_settings', ['id' => 1, 'ipv6_enabled' => 1]);
        $this->upsert('snmp_settings', [
            'id' => 1, 'enabled' => 1, 'listen_port' => 161, 'v1_v2c_enabled' => 1,
            'read_community' => 'public', 'allowed_subnets' => '192.168.1.0/24, 2001:db8::/32',
        ]);
        $conf = (new ServiceConfigCompiler())->snmpd();

        $this->assertStringContainsString('agentAddress udp:161,udp6:[::]:161', $conf);
        $this->assertStringContainsString('rocommunity public 192.168.1.0/24', $conf);
        $this->assertStringContainsString('rocommunity6 public 2001:db8::/32', $conf);
    }

    public function testKeepalivedConvertsDottedMasksAndMasksThePasswordInPreview(): void
    {
        $this->upsert('ha_settings', ['id' => 1, 'enabled' => 1, 'node_name' => 'fw-a', 'mode' => 'master', 'vrrp_router_id' => 51,
            'vrrp_interface' => 'eth1', 'vrrp_priority' => 110, 'vrrp_auth_pass' => 'S3cret99', 'preempt' => 1,
            'conntrackd_enabled' => 0, 'conntrackd_interface' => 'eth2', 'peer_sync_ip' => '10.9.9.2', 'peer_sync_port' => 3780,
            'config_sync_enabled' => 0, 'config_sync_peer_ip' => '', 'config_sync_api_key' => '']);
        $this->db->table('ha_virtual_ips')->truncate();
        $this->db->table('ha_virtual_ips')->insert(['interface' => 'eth1', 'vip_type' => 'vrrp', 'ip_address' => '192.168.1.254',
            'subnet_mask' => '255.255.255.0', 'vrrp_router_id' => 51, 'vrrp_priority' => 110]);

        $c    = new ServiceConfigCompiler();
        $conf = $c->keepalived();

        $this->assertStringContainsString('192.168.1.254/24 dev eth1', $conf);
        $this->assertStringContainsString('auth_pass S3cret99', $conf);
        $this->assertStringNotContainsString('S3cret99', $c->keepalivedPreview());
    }

    public function testQosEmitsOnlyTcBatchLinesForValidInterfaces(): void
    {
        $this->db->table('qos_shapers')->truncate();
        foreach ([
            ['interface' => 'eth0', 'enabled' => 1, 'bandwidth_down_mbps' => 500, 'bandwidth_up_mbps' => 100,
                'scheduler' => 'cake', 'diffserv' => 'diffserv4', 'flow_isolation' => 'triple-isolate', 'ack_filter' => 1, 'rtt_ms' => 100],
            ['interface' => 'eth0;reboot', 'enabled' => 1, 'bandwidth_down_mbps' => 1, 'bandwidth_up_mbps' => 1,
                'scheduler' => 'cake', 'diffserv' => 'diffserv4', 'flow_isolation' => 'flows'],
        ] as $row) {
            $this->db->table('qos_shapers')->insert($row);
        }

        $script = (new ServiceConfigCompiler())->qos();

        $this->assertStringContainsString('qdisc replace dev eth0 root cake bandwidth 100mbit diffserv4 triple-isolate ack-filter rtt 100ms', $script);
        $this->assertStringNotContainsString('reboot', $script);
    }

    public function testSiemForwardsOnlyToAValidCollector(): void
    {
        $this->upsert('siem_settings', ['id' => 1, 'enabled' => 1, 'server_host' => 'siem.corp.example', 'server_port' => 6514,
            'protocol' => 'TCP', 'log_format' => 'JSON', 'severity_threshold' => 'warning', 'forward_system' => 1]);
        $conf = (new ServiceConfigCompiler())->rsyslogSiem();
        $this->assertStringContainsString('target="siem.corp.example" port="6514" protocol="tcp"', $conf);

        $this->upsert('siem_settings', ['id' => 1, 'enabled' => 1, 'server_host' => 'x" then action(type="omprog"', 'server_port' => 514]);
        $conf = (new ServiceConfigCompiler())->rsyslogSiem();
        $this->assertStringNotContainsString('omprog', $conf);
    }
}
