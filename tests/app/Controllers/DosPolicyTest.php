<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for DoS / DDoS Mitigation Policies:
 *   GET  firewall/dos
 *   POST firewall/dos/store
 *   POST firewall/dos/(:segment)/update
 *   POST firewall/dos/(:segment)/toggle
 *   POST firewall/dos/(:segment)/delete
 *   POST firewall/dos/apply
 *   Nftables compiler verification for dos_mitigation chain & sets
 *
 * @internal
 */
final class DosPolicyTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'firewall.rules.view',
        'firewall.rules.edit',
        'firewall.rules.delete',
        'firewall.rules.apply',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('dos_policies')->emptyTable();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    public function testIndexRendersView(): void
    {
        $result = $this->withSession($this->session())->get('firewall/dos');

        $result->assertOK();
        $result->assertSee('DDoS &amp; DoS Protection Policies');
        $result->assertSee('Create DoS Policy');
    }

    public function testStoreCreatesPolicy(): void
    {
        $result = $this->post('firewall/dos/store', $this->csrf([
            'name'               => 'WAN Attack Mitigation',
            'interface_id'       => 'wan',
            'syn_flood_enabled'  => '1',
            'syn_flood_rate'     => '150',
            'syn_flood_burst'    => '300',
            'conn_limit_enabled' => '1',
            'conn_limit_max'     => '40',
            'conn_rate_enabled'  => '1',
            'conn_rate_max'      => '50',
            'udp_flood_enabled'  => '1',
            'udp_flood_rate'     => '400',
            'icmp_flood_enabled' => '1',
            'icmp_flood_rate'    => '15',
            'port_scan_enabled'  => '1',
            'action'             => 'drop',
            'ban_duration'       => '600',
            'exempt_addresses'   => '192.168.1.0/24, 1.1.1.1',
            'log_enabled'        => '1',
            'enabled'            => '1',
            'description'        => 'Edge WAN protection rule',
        ]));

        $result->assertRedirect();

        $row = $this->db->table('dos_policies')->where('name', 'WAN Attack Mitigation')->get()->getRowArray();
        $this->assertNotNull($row, 'DoS policy was not inserted into database.');
        $this->assertSame('wan', $row['interface_id']);
        $this->assertSame(1, (int) $row['syn_flood_enabled']);
        $this->assertSame(150, (int) $row['syn_flood_rate']);
        $this->assertSame(300, (int) $row['syn_flood_burst']);
        $this->assertSame(40, (int) $row['conn_limit_max']);
        $this->assertSame(50, (int) $row['conn_rate_max']);
        $this->assertSame(400, (int) $row['udp_flood_rate']);
        $this->assertSame(15, (int) $row['icmp_flood_rate']);
        $this->assertSame(1, (int) $row['port_scan_enabled']);
        $this->assertSame('drop', $row['action']);
        $this->assertSame(600, (int) $row['ban_duration']);
        $this->assertSame('192.168.1.0/24, 1.1.1.1', $row['exempt_addresses']);
    }

    public function testUpdateModifiesPolicy(): void
    {
        $this->seedPolicy('dos_test_upd', 'Original Policy', [
            'syn_flood_rate' => 100,
            'conn_limit_max' => 50,
        ]);

        $result = $this->post('firewall/dos/dos_test_upd/update', $this->csrf([
            'name'               => 'Updated Policy',
            'interface_id'       => 'all',
            'syn_flood_enabled'  => '1',
            'syn_flood_rate'     => '250',
            'syn_flood_burst'    => '500',
            'conn_limit_enabled' => '1',
            'conn_limit_max'     => '80',
            'conn_rate_enabled'  => '0',
            'conn_rate_max'      => '60',
            'udp_flood_enabled'  => '0',
            'udp_flood_rate'     => '500',
            'icmp_flood_enabled' => '0',
            'icmp_flood_rate'    => '20',
            'port_scan_enabled'  => '0',
            'action'             => 'reject',
            'ban_duration'       => '300',
            'exempt_addresses'   => '10.0.0.0/8',
            'log_enabled'        => '0',
            'enabled'            => '1',
            'description'        => 'Updated description',
        ]));

        $result->assertRedirect();

        $row = $this->db->table('dos_policies')->where('id', 'dos_test_upd')->get()->getRowArray();
        $this->assertSame('Updated Policy', $row['name']);
        $this->assertSame('all', $row['interface_id']);
        $this->assertSame(250, (int) $row['syn_flood_rate']);
        $this->assertSame(500, (int) $row['syn_flood_burst']);
        $this->assertSame(80, (int) $row['conn_limit_max']);
        $this->assertSame(0, (int) $row['conn_rate_enabled']);
        $this->assertSame('reject', $row['action']);
        $this->assertSame(300, (int) $row['ban_duration']);
        $this->assertSame('10.0.0.0/8', $row['exempt_addresses']);
    }

    public function testToggleFlipsEnabledState(): void
    {
        $this->seedPolicy('dos_test_tog', 'Toggle Test', ['enabled' => 1]);

        $this->post('firewall/dos/dos_test_tog/toggle', $this->csrf([]))->assertRedirect();
        $row = $this->db->table('dos_policies')->where('id', 'dos_test_tog')->get()->getRowArray();
        $this->assertSame(0, (int) $row['enabled']);

        $this->post('firewall/dos/dos_test_tog/toggle', $this->csrf([]))->assertRedirect();
        $row = $this->db->table('dos_policies')->where('id', 'dos_test_tog')->get()->getRowArray();
        $this->assertSame(1, (int) $row['enabled']);
    }

    public function testDeleteRemovesPolicy(): void
    {
        $this->seedPolicy('dos_test_del', 'Delete Target');

        $this->post('firewall/dos/dos_test_del/delete', $this->csrf([]))->assertRedirect();

        $count = $this->db->table('dos_policies')->where('id', 'dos_test_del')->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testCompilerCompilesDosMitigation(): void
    {
        $this->seedPolicy('dos_rule_1', 'Compiler Test Rule', [
            'interface_id'       => 'wan',
            'syn_flood_enabled'  => 1,
            'syn_flood_rate'     => 100,
            'syn_flood_burst'    => 200,
            'conn_limit_enabled' => 1,
            'conn_limit_max'     => 50,
            'conn_rate_enabled'  => 1,
            'conn_rate_max'      => 60,
            'udp_flood_enabled'  => 1,
            'udp_flood_rate'     => 500,
            'icmp_flood_enabled' => 1,
            'icmp_flood_rate'    => 20,
            'port_scan_enabled'  => 1,
            'action'             => 'drop',
            'ban_duration'       => 600,
            'exempt_addresses'   => '192.168.1.100, 10.10.0.0/16',
            'log_enabled'        => 1,
            'enabled'            => 1,
        ]);

        $compiler = new \App\Libraries\NftablesCompiler();
        $ruleset  = $compiler->compile();

        // 1. Verify dynamic blacklist set declaration
        $this->assertStringContainsString('set dos_blacklist', $ruleset);
        $this->assertStringContainsString('flags timeout;', $ruleset);

        // 2. Verify dos_mitigation chain declaration
        $this->assertStringContainsString('chain dos_mitigation {', $ruleset);

        // 3. Verify blacklist set check and drop
        $this->assertStringContainsString('ip saddr @dos_blacklist counter drop', $ruleset);

        // 4. Verify exemptions
        $this->assertStringContainsString('ip saddr { 192.168.1.100, 10.10.0.0/16 } return', $ruleset);

        // 5. Verify abnormal port scan packet filters
        $this->assertStringContainsString('tcp flags & (fin|syn) == (fin|syn)', $ruleset);
        $this->assertStringContainsString('tcp flags & (fin|syn|rst|psh|ack|urg) == 0', $ruleset);

        // 6. Verify TCP SYN flood rate limit meter
        $this->assertStringContainsString('tcp flags syn meter dos_syn_dos_rule_1', $ruleset);

        // 7. Verify concurrent connection cap and rate limiter
        $this->assertStringContainsString('ct count over 50', $ruleset);
        $this->assertStringContainsString('meter dos_rate_dos_rule_1', $ruleset);

        // 8. Verify protocol flood meters (UDP & ICMP)
        $this->assertStringContainsString('meter dos_udp_dos_rule_1', $ruleset);
        $this->assertStringContainsString('meter dos_icmp_dos_rule_1', $ruleset);

        // 9. Verify jumps injected into input and forward chains
        $this->assertStringContainsString('jump dos_mitigation;', $ruleset);
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
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

    private function seedPolicy(string $id, string $name, array $overrides = []): void
    {
        $this->db->table('dos_policies')->insert(array_merge([
            'id'                 => $id,
            'name'               => $name,
            'interface_id'       => 'all',
            'syn_flood_enabled'  => 1,
            'syn_flood_rate'     => 100,
            'syn_flood_burst'    => 200,
            'conn_limit_enabled' => 1,
            'conn_limit_max'     => 50,
            'conn_rate_enabled'  => 1,
            'conn_rate_max'      => 60,
            'udp_flood_enabled'  => 1,
            'udp_flood_rate'     => 500,
            'icmp_flood_enabled' => 1,
            'icmp_flood_rate'    => 20,
            'port_scan_enabled'  => 1,
            'action'             => 'drop',
            'ban_duration'       => 600,
            'exempt_addresses'   => '',
            'log_enabled'        => 0,
            'enabled'            => 1,
            'description'        => null,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ], $overrides));
    }
}
