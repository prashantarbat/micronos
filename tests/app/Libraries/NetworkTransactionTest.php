<?php

namespace Tests\App\Libraries;

use App\Libraries\Network\AddressChangeSync;
use App\Libraries\Network\NetworkPlan;
use App\Libraries\Network\NetworkTransaction;
use App\Libraries\NftablesCompiler;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * WAN/LAN address changes propagate to every dependent setting, through one
 * transaction shared by the Web UI, the CLI and System > Networking (IPv6):
 * database + dependents → plan + nftables + Kea → micrond → commit, or
 * everything is left exactly as it was.
 *
 * @internal
 */
final class NetworkTransactionTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const TABLES = ['interfaces', 'firewall_rules', 'dhcp_server_settings', 'dhcp_reservations', 'nat_dnat',
        'network_transactions', 'system_settings', 'firewall_addresses', 'dns_hosts'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLES as $t) {
            if ($this->db->tableExists($t)) {
                $this->saved[$t] = $this->db->table($t)->get()->getResultArray();
                $this->db->table($t)->emptyTable();
            }
        }
        $now = date('Y-m-d H:i:s');
        foreach ([
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'interface_type' => 'physical', 'enabled' => 1,
                'ip_assignment_type' => 'static', 'ipv4_address' => '203.0.113.10', 'ipv4_netmask' => 24, 'ipv4_gateway' => '203.0.113.1',
                'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1', 'interface_type' => 'physical', 'enabled' => 1,
                'ip_assignment_type' => 'static', 'ipv4_address' => '192.168.1.1', 'ipv4_netmask' => 24, 'ipv4_gateway' => null,
                'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
        ] as $row) {
            $this->db->table('interfaces')->insert($row);
        }
        foreach ([
            ['id' => 'r_literal', 'sequence' => 10, 'interface_id' => 'lan', 'action' => 'pass', 'direction' => 'in', 'protocol' => 'tcp',
                'source_type' => 'network', 'source_value' => '192.168.1.0/24', 'dest_type' => 'ip', 'dest_value' => '192.168.1.1',
                'dest_port' => '443', 'description' => 'LAN to GUI', 'enabled' => 1, 'name' => 'LAN to GUI'],
            ['id' => 'r_host', 'sequence' => 20, 'interface_id' => 'lan', 'action' => 'pass', 'direction' => 'in', 'protocol' => 'tcp',
                'source_type' => 'ip', 'source_value' => '192.168.1.50', 'dest_type' => 'any', 'dest_value' => 'any',
                'description' => 'Printer', 'enabled' => 1, 'name' => 'Printer'],
            ['id' => 'r_external', 'sequence' => 30, 'interface_id' => 'wan', 'action' => 'drop', 'direction' => 'in', 'protocol' => 'any',
                'source_type' => 'network', 'source_value' => '198.51.100.0/24', 'dest_type' => 'any', 'dest_value' => 'any',
                'description' => 'Blocklist', 'enabled' => 1, 'name' => 'Blocklist'],
        ] as $row) {
            $this->db->table('firewall_rules')->insert($row);
        }
        $this->db->table('dhcp_server_settings')->insert([
            'interface_id' => 'lan', 'enabled' => 1, 'range_from' => '192.168.1.100', 'range_to' => '192.168.1.200',
            'gateway' => '192.168.1.1', 'dns_1' => '192.168.1.1',
        ]);
        $this->db->table('nat_dnat')->insert(['id' => 'pf1', 'name' => 'Web server', 'interface_id' => 'wan',
            'external_ip' => '203.0.113.10', 'mapped_ip' => '192.168.1.80', 'protocol' => 'tcp', 'enabled' => 1]);
        $this->db->table('system_settings')->insert(['id' => 1, 'hostname' => 'micron', 'ipv6_enabled' => 0]);

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $t => $rows) {
            $this->db->table($t)->emptyTable();
            foreach ($rows as $r) {
                $this->db->table($t)->insert($r);
            }
        }
        parent::tearDown();
    }

    private function ipc(array $apply = ['success' => true, 'status' => 'applied', 'steps' => ['ok']]): FakeIpcClient
    {
        $ipc = new FakeIpcClient(['network.apply' => ['error' => null, 'result' => $apply]]);
        Services::injectMock('ipc', $ipc);

        return $ipc;
    }

    private function moveLan(string $ip, int $mask, array $opts = []): array
    {
        return (new NetworkTransaction(Services::ipc(), $this->db))->apply(static function ($db) use ($ip, $mask) {
            $before = $db->table('interfaces')->where('id', 'lan')->get()->getRowArray();
            $db->table('interfaces')->where('id', 'lan')->update(['ipv4_address' => $ip, 'ipv4_netmask' => $mask]);

            return ['before' => $before, 'after' => $db->table('interfaces')->where('id', 'lan')->get()->getRowArray()];
        }, $opts);
    }

    private function row(string $table, string $col, string $val): array
    {
        return $this->db->table($table)->where($col, $val)->get()->getRowArray() ?? [];
    }

    public function testALanChangeUpdatesEveryDependentAndTheGeneratedConfig(): void
    {
        $ipc = $this->ipc();
        $r   = $this->moveLan('10.20.0.1', 16);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('10.20.0.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);

        // DHCP: router/DNS options and the pool follow the subnet.
        $dhcp = $this->row('dhcp_server_settings', 'interface_id', 'lan');
        $this->assertSame('10.20.0.1', $dhcp['gateway']);
        $this->assertSame('10.20.0.1', $dhcp['dns_1']);
        $this->assertTrue(NetworkPlan::inSubnet($dhcp['range_from'], '10.20.0.0', 16));
        $this->assertTrue(NetworkPlan::inSubnet($dhcp['range_to'], '10.20.0.0', 16));

        // Policies that spelled out the old LAN now follow it.
        $rule = $this->row('firewall_rules', 'id', 'r_literal');
        $this->assertSame('{lan:network}', $rule['source_value']);
        $this->assertSame('{lan:address}', $rule['dest_value']);
        // An unrelated external address is untouched; an internal host is reported, not guessed.
        $this->assertSame('198.51.100.0/24', $this->row('firewall_rules', 'id', 'r_external')['source_value']);
        $this->assertSame('192.168.1.50', $this->row('firewall_rules', 'id', 'r_host')['source_value']);
        $reviewed = implode("\n", array_map(static fn ($x) => $x['what'] . ' ' . $x['value'], $r['review']));
        $this->assertStringContainsString('Printer', $reviewed);
        $this->assertStringContainsString('192.168.1.80', $reviewed, 'the port-forward target in the old subnet is listed');

        // One RPC with the plan, the ruleset and DHCP — all already showing the new subnet.
        $call = $ipc->calls[0];
        $this->assertSame('network.apply', $call['method']);
        $lan = array_values(array_filter($call['params']['plan']['interfaces'], static fn ($i) => $i['id'] === 'lan'))[0];
        $this->assertSame('10.20.0.1/16', $lan['ipv4']);
        $this->assertStringContainsString('10.20.0.0/16', $call['params']['ruleset']);
        $this->assertStringNotContainsString('192.168.1.0/24', $call['params']['ruleset']);
        $this->assertStringContainsString('10.20.0.0/16', json_encode($call['params']['dhcp4'], JSON_UNESCAPED_SLASHES));
        $this->assertFalse($call['params']['plan']['ipv6_enabled'], 'IPv6 stays off unless switched on');
    }

    public function testConsoleAssignmentAppliesTheNewDevicesAndLanDefaultsTogether(): void
    {
        $ipc = $this->ipc();
        $r = (new NetworkTransaction(Services::ipc(), $this->db))->apply(function ($db) {
            $lanBefore = $db->table('interfaces')->where('id', 'lan')->get()->getRowArray();
            $db->table('interfaces')->where('id', 'wan')->update([
                'physical_device' => 'eth3',
                'ip_assignment_type' => 'dhcp',
                'ipv4_address' => null,
                'ipv4_gateway' => null,
            ]);
            $db->table('interfaces')->where('id', 'lan')->update([
                'physical_device' => 'eth2',
                'ip_assignment_type' => 'static',
                'ipv4_address' => '10.10.10.10',
                'ipv4_netmask' => 24,
                'ipv4_gateway' => null,
            ]);

            return ['before' => $lanBefore, 'after' => $db->table('interfaces')->where('id', 'lan')->get()->getRowArray()];
        }, ['actor' => 'test', 'reason' => 'console assignment']);

        $this->assertTrue($r['ok'], $r['message']);
        $call = $ipc->calls[0];
        $wan = array_values(array_filter($call['params']['plan']['interfaces'], static fn ($i) => $i['id'] === 'wan'))[0];
        $lan = array_values(array_filter($call['params']['plan']['interfaces'], static fn ($i) => $i['id'] === 'lan'))[0];
        $this->assertSame('eth3', $wan['device']);
        $this->assertSame('dhcp', $wan['mode']);
        $this->assertSame('eth2', $lan['device']);
        $this->assertSame('10.10.10.10/24', $lan['ipv4']);
        $this->assertSame('eth2', $this->row('interfaces', 'id', 'lan')['physical_device']);
    }

    public function testADaemonRefusalLeavesTheDatabaseExactlyAsItWas(): void
    {
        $this->ipc(['success' => false, 'status' => 'rolled_back', 'errors' => ['eth1: could not assign'], 'message' => 'restored']);
        $r = $this->moveLan('10.20.0.1', 16);

        $this->assertFalse($r['ok']);
        $this->assertSame('rolled_back', $r['status']);
        $this->assertSame('192.168.1.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);
        $this->assertSame('192.168.1.1', $this->row('dhcp_server_settings', 'interface_id', 'lan')['gateway']);
        $this->assertSame('192.168.1.0/24', $this->row('firewall_rules', 'id', 'r_literal')['source_value']);
    }

    public function testAnUnreachableDaemonChangesNothing(): void
    {
        Services::injectMock('ipc', new FakeIpcClient(['network.apply' => ['error' => ['message' => 'connection refused'], 'result' => null]]));
        $r = $this->moveLan('10.20.0.1', 16);

        $this->assertFalse($r['ok']);
        $this->assertSame('192.168.1.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);
    }

    public function testInvalidAndOverlappingAddressesNeverReachTheDaemon(): void
    {
        $ipc = $this->ipc();
        $r1  = $this->moveLan('203.0.113.77', 24);   // overlaps the WAN
        $r2  = $this->moveLan('10.0.0.0', 24);       // network address
        $r3  = $this->moveLan('10.0.0.1', 4);        // absurd prefix

        foreach ([$r1, $r2, $r3] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertSame('invalid', $r['status']);
        }
        $this->assertStringContainsString('overlaps', $r1['message']);
        $this->assertSame([], $ipc->calls);
        $this->assertSame('192.168.1.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);
    }

    public function testKeepReferencesListsInsteadOfRewriting(): void
    {
        $this->ipc();
        $r = $this->moveLan('10.20.0.1', 16, ['update_references' => false]);

        $this->assertTrue($r['ok']);
        $this->assertSame('192.168.1.0/24', $this->row('firewall_rules', 'id', 'r_literal')['source_value']);
        $this->assertNotEmpty(array_filter($r['review'], static fn ($x) => str_contains($x['reason'], '{lan:network}')));
    }

    public function testAWanAddressChangeFollowsIntoPortForwards(): void
    {
        $ipc = $this->ipc();
        $r   = (new NetworkTransaction(Services::ipc(), $this->db))->apply(static function ($db) {
            $before = $db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
            $db->table('interfaces')->where('id', 'wan')->update(['ipv4_address' => '198.18.0.10', 'ipv4_gateway' => '198.18.0.1']);

            return ['before' => $before, 'after' => $db->table('interfaces')->where('id', 'wan')->get()->getRowArray()];
        });

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('{wan:address}', $this->row('nat_dnat', 'id', 'pf1')['external_ip']);
        $this->assertStringContainsString('198.18.0.10', $ipc->calls[0]['params']['ruleset']);
        $this->assertStringNotContainsString('203.0.113.10', $ipc->calls[0]['params']['ruleset']);
        $wan = array_values(array_filter($ipc->calls[0]['params']['plan']['interfaces'], static fn ($i) => $i['id'] === 'wan'))[0];
        $this->assertSame('198.18.0.1', $wan['gateway']);
    }

    public function testTokensResolveToTheCurrentAddressAtCompileTime(): void
    {
        $this->db->table('firewall_rules')->where('id', 'r_literal')->update(['source_value' => '{lan:network}', 'dest_value' => '{lan:address}']);
        $ruleset = (new NftablesCompiler($this->db))->compile();
        $this->assertStringContainsString('192.168.1.0/24', $ruleset);
        $this->assertStringContainsString('192.168.1.1', $ruleset);
        $this->assertStringNotContainsString('{lan:', $ruleset, 'no unresolved token may reach nft');
    }

    public function testAConfirmWindowChangeIsRecordedAndRestoredWhenRolledBack(): void
    {
        $ipc = $this->ipc(['success' => true, 'status' => 'pending_confirm', 'tx' => 'abc123', 'confirm_timeout' => 120]);
        $r   = $this->moveLan('10.20.0.1', 16, ['confirm' => true]);

        $this->assertTrue($r['ok']);
        $this->assertSame('pending_confirm', $r['status']);
        $this->assertSame(NetworkTransaction::CONFIRM_SECONDS, $ipc->calls[0]['params']['confirm_timeout']);
        $this->assertSame('pending', $this->row('network_transactions', 'tx', 'abc123')['status']);

        // While pending, no second change is accepted.
        $busy = $this->moveLan('10.30.0.1', 16);
        $this->assertSame('busy', $busy['status']);

        // The daemon rolled it back (timeout): the database follows.
        $ipc->respond('network.status', ['error' => null, 'result' => ['success' => true, 'pending' => null,
            'last' => ['status' => 'rolled_back', 'tx' => 'abc123']]]);
        $msg = (new NetworkTransaction($ipc, $this->db))->reconcile();
        $this->assertNotNull($msg);
        $this->assertSame('192.168.1.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);
        $this->assertSame('192.168.1.1', $this->row('dhcp_server_settings', 'interface_id', 'lan')['gateway']);
        $this->assertSame('192.168.1.0/24', $this->row('firewall_rules', 'id', 'r_literal')['source_value']);
        $this->assertSame('rolled_back', $this->row('network_transactions', 'tx', 'abc123')['status']);
    }

    public function testAConfirmedChangeIsKept(): void
    {
        $ipc = $this->ipc(['success' => true, 'status' => 'pending_confirm', 'tx' => 'def456']);
        $this->moveLan('10.20.0.1', 16, ['confirm' => true]);
        $ipc->respond('network.confirm', ['error' => null, 'result' => ['success' => true, 'status' => 'applied']]);

        $c = (new NetworkTransaction($ipc, $this->db))->confirm('def456');
        $this->assertTrue($c['ok']);
        $this->assertSame('committed', $this->row('network_transactions', 'tx', 'def456')['status']);
        $this->assertSame('10.20.0.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);
    }

    public function testTheInterfacesPageSaveGoesThroughTheTransaction(): void
    {
        $ipc  = $this->ipc();
        $perm = ['interfaces.view', 'interfaces.edit', 'interfaces.apply'];
        $this->withSession(['logged_in' => true, 'user_id' => 'u1', 'username' => 'admin', 'is_superadmin' => 1,
            'permissions' => $perm, 'user_permissions' => $perm]);
        $res = $this->post('interfaces/save', [
            config(SecurityConfig::class)->tokenName => service('security')->getHash(),
            'id' => 'lan', 'name' => 'LAN', 'enabled' => '1', 'ip_assignment_type' => 'static',
            'ipv4_address' => '172.20.0.1', 'ipv4_netmask' => '24', 'mtu' => '1500',
        ]);
        $res->assertRedirect();
        $this->assertSame('network.apply', $ipc->calls[0]['method'] ?? null, 'saving applies — no separate "Apply Changes" step');
        $this->assertSame('172.20.0.1', $this->row('interfaces', 'id', 'lan')['ipv4_address']);
        $this->assertSame('172.20.0.1', $this->row('dhcp_server_settings', 'interface_id', 'lan')['gateway']);
        $this->assertIsArray(session('net_result'));
    }

    public function testTheIpv6SwitchIsAppliedThroughTheSameTransaction(): void
    {
        $ipc  = $this->ipc();
        $perm = ['system.info.view', 'system.settings.edit', 'dashboard.view'];
        $this->withSession(['logged_in' => true, 'user_id' => 'u1', 'username' => 'admin', 'is_superadmin' => 1,
            'permissions' => $perm, 'user_permissions' => $perm]);
        $this->post('system/save', [
            config(SecurityConfig::class)->tokenName => service('security')->getHash(),
            'hostname' => 'micron', 'ipv6_enabled' => '1',
        ])->assertRedirect();

        $apply = array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'network.apply'));
        $this->assertCount(1, $apply);
        $this->assertTrue($apply[0]['params']['plan']['ipv6_enabled']);
        $this->assertStringNotContainsString('IPv6 disabled system-wide', $apply[0]['params']['ruleset']);
        $this->assertSame(1, (int) $this->row('system_settings', 'id', '1')['ipv6_enabled']);
    }

    public function testARefusedIpv6ChangeKeepsTheOldValue(): void
    {
        $this->ipc(['success' => false, 'status' => 'rolled_back', 'message' => 'kernel IPv6 is off, plan says on']);
        $perm = ['system.info.view', 'system.settings.edit', 'dashboard.view'];
        $this->withSession(['logged_in' => true, 'user_id' => 'u1', 'username' => 'admin', 'is_superadmin' => 1,
            'permissions' => $perm, 'user_permissions' => $perm]);
        $this->post('system/save', [
            config(SecurityConfig::class)->tokenName => service('security')->getHash(),
            'hostname' => 'micron', 'ipv6_enabled' => '1',
        ])->assertRedirect();

        $this->assertSame(0, (int) $this->row('system_settings', 'id', '1')['ipv6_enabled']);
        $this->assertStringContainsString('IPv6 was left disabled', (string) session('error'));
    }

    public function testIpv6OffCompilesADropForAllIpv6Traffic(): void
    {
        $ruleset = (new NftablesCompiler($this->db))->compile();
        $this->assertStringContainsString('meta nfproto ipv6 drop', $ruleset);
    }

    public function testFactoryDefaultsAreReconciledIdempotently(): void
    {
        $this->db->table('firewall_rules')->insert(['id' => 'rule_lan_webgui_access', 'sequence' => 1, 'interface_id' => 'lan',
            'action' => 'pass', 'direction' => 'in', 'protocol' => 'tcp', 'source_type' => 'network', 'source_value' => '10.10.10.0/24',
            'dest_type' => 'ip', 'dest_value' => '10.10.10.10', 'dest_port' => '443', 'enabled' => 1]);
        $sync = new AddressChangeSync($this->db);
        $first  = $sync->reconcileFactoryDefaults();
        $second = $sync->reconcileFactoryDefaults();

        $this->assertNotEmpty($first);
        $rule = $this->row('firewall_rules', 'id', 'rule_lan_webgui_access');
        $this->assertSame('{lan:network}', $rule['source_value']);
        $this->assertSame('{lan:address}', $rule['dest_value']);
        $this->assertSame([], array_filter($second, static fn ($l) => str_starts_with($l, 'firewall rule')), 'second run changes nothing');
    }
}
