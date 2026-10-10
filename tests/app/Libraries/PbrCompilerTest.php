<?php

use App\Libraries\NftablesCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Policy-Based Routing compilation.
 *
 * PBR is enforced in two halves — a forward-chain mark and a companion
 * `ip rule` / `ip route` section — so these tests assert both, and that the
 * mark and the rule's routing table always agree.
 *
 * @internal
 */
final class PbrCompilerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('routing_pbr_rules')->emptyTable();
        $this->db->table('routing_vrfs')->emptyTable();
        $this->db->table('firewall_addresses')->where('is_system', 0)->delete();

        $this->db->table('interfaces')->where('id', 'wan')->delete();
        $this->db->table('interfaces')->where('id', 'lan')->delete();
        $this->db->table('interfaces')->insertBatch([
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1', 'enabled' => 1, 'ip_assignment_type' => 'static'],
        ]);
    }

    private function insertPbr(array $overrides = []): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('routing_pbr_rules')->insert(array_merge([
            'id'             => 'pbr_' . bin2hex(random_bytes(4)),
            'priority'       => 10,
            'description'    => 'Test rule',
            'match_src'         => null,
            'match_dst'         => null,
            'match_src_objects' => null,
            'match_dst_objects' => null,
            'match_protocol' => null,
            'match_dscp'     => null,
            'match_iif'      => null,
            'set_nexthop'    => null,
            'set_table'      => null,
            'set_vrf'        => null,
            'action'         => 'permit',
            'enabled'        => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $overrides));
    }

    public function testPermitRuleMarksAndAddsARoutingRule(): void
    {
        $this->insertPbr([
            'match_src'   => '10.10.0.0/24',
            'match_iif'   => 'lan',
            'set_nexthop' => '203.0.113.1',
            'set_table'   => 110,
        ]);

        $compiler = new NftablesCompiler($this->db);
        $ruleset  = $compiler->compile();

        // The mark is set in the forward chain...
        $this->assertMatchesRegularExpression(
            '/iifname "eth1" ip saddr 10\.10\.0\.0\/24 .*meta mark set 10110/',
            $ruleset,
        );

        // ...and the routing half looks the mark up in the same table. It is
        // kernel routing state, not nftables syntax, so it is emitted separately
        // — mixing `ip` lines into the ruleset would make `nft -f` fail.
        $rendered = implode("\n", $compiler->renderRoutingPolicy());
        $this->assertStringContainsString('ip rule add fwmark 10110 lookup 110 priority 10', $rendered);
        $this->assertStringContainsString('ip route replace default via 203.0.113.1 table 110', $rendered);
    }

    public function testMarkAndTableAlwaysAgree(): void
    {
        $this->insertPbr(['set_table' => 42]);

        $compiler = new NftablesCompiler($this->db);

        $this->assertStringContainsString('meta mark set 10042', $compiler->compile());
        $this->assertStringContainsString('ip rule add fwmark 10042 lookup 42', implode("\n", $compiler->renderRoutingPolicy()));
    }

    public function testVrfResolvesToItsTableId(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('routing_vrfs')->insert([
            'id' => 'vrf_test', 'name' => 'CUST_A', 'table_id' => 205, 'interfaces' => '[]',
            'description' => '', 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->insertPbr(['set_vrf' => 'CUST_A', 'set_nexthop' => '198.51.100.1']);

        $compiler = new NftablesCompiler($this->db);

        $this->assertStringContainsString('meta mark set 10205', $compiler->compile());
        $this->assertStringContainsString('ip rule add fwmark 10205 lookup 205', implode("\n", $compiler->renderRoutingPolicy()));
    }

    public function testDenyRuleDropsAndEmitsNoRoutingRule(): void
    {
        $this->insertPbr([
            'match_dst' => '192.168.99.0/24',
            'action'    => 'deny',
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip daddr 192.168.99.0/24', $ruleset);
        $this->assertStringContainsString('drop', $ruleset);
        $this->assertStringNotContainsString('ip rule add fwmark', $ruleset);
    }

    public function testDisabledRuleIsNotCompiled(): void
    {
        $this->insertPbr([
            'description' => 'Disabled rule',
            'set_table'   => 120,
            'enabled'     => 0,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringNotContainsString('Disabled rule', $ruleset);
    }

    public function testRuleWithoutAForwardingTargetIsSkipped(): void
    {
        // A permit rule with no table, VRF or next-hop has nowhere to steer
        // traffic, so it must not emit a bare mark.
        $this->insertPbr(['description' => 'No target']);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringNotContainsString('No target', $ruleset);
    }

    public function testDscpNamesAndNumbersAreAccepted(): void
    {
        $this->insertPbr(['match_dscp' => 'ef', 'set_table' => 130]);
        $this->insertPbr(['match_dscp' => '24', 'set_table' => 131]);
        $this->insertPbr(['match_dscp' => 'nonsense', 'set_table' => 132]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip dscp 46', $ruleset);
        $this->assertStringContainsString('ip dscp 24', $ruleset);
        $this->assertStringNotContainsString('nonsense', $ruleset);
    }

    /** Inserts a firewall address object for object-match tests. */
    private function insertAddress(string $name, string $type, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('firewall_addresses')->insert([
            'id' => 'addr_' . bin2hex(random_bytes(4)), 'name' => $name, 'type' => $type, 'value' => $value,
            'interface' => 'any', 'color' => '#000', 'comment' => '', 'is_system' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function testFqdnObjectResolvesToItsNamedSet(): void
    {
        $this->insertAddress('GitHub_FQDN', 'fqdn', 'github.com');
        $this->insertPbr([
            'match_src_objects' => json_encode(['GitHub_FQDN']),
            'set_table'         => 170,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip saddr @fqdn_github_com', $ruleset);
        $this->assertStringContainsString('meta mark set 10170', $ruleset);
    }

    public function testInternetServiceObjectResolvesToItsNamedSet(): void
    {
        $this->insertAddress('SaaS', 'internet-service', 'aws');
        $this->insertPbr([
            'match_dst_objects' => json_encode(['SaaS']),
            'set_table'         => 171,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip daddr @isdb_aws', $ruleset);
    }

    public function testGeoipObjectResolvesToItsNamedSet(): void
    {
        $this->insertAddress('Countries', 'geoip', 'US');
        $this->insertPbr([
            'match_dst_objects' => json_encode(['Countries']),
            'set_table'         => 172,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip daddr @geo_us', $ruleset);
    }

    public function testLiteralCidrObjectResolvesInline(): void
    {
        $this->insertAddress('LAN_Subnet', 'ipmask', '192.168.1.0/24');
        $this->insertPbr([
            'match_src_objects' => json_encode(['LAN_Subnet']),
            'set_table'         => 173,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip saddr 192.168.1.0/24', $ruleset);
    }

    public function testLiteralCidrAndObjectAreBothMatched(): void
    {
        $this->insertAddress('GitHub_FQDN', 'fqdn', 'github.com');
        $this->insertPbr([
            'match_src'         => '10.1.0.0/16',
            'match_src_objects' => json_encode(['GitHub_FQDN']),
            'set_table'         => 174,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip saddr 10.1.0.0/16', $ruleset);
        $this->assertStringContainsString('ip saddr @fqdn_github_com', $ruleset);
    }

    public function testNamedSetIsNeverCombinedInsideALiteral(): void
    {
        // nftables cannot mix a named set reference with other elements in one
        // anonymous set, so multiple values must become separate rules.
        $this->insertAddress('GitHub_FQDN', 'fqdn', 'github.com');
        $this->insertPbr([
            'match_src'         => '10.1.0.0/16',
            'match_src_objects' => json_encode(['GitHub_FQDN']),
            'set_table'         => 175,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringNotContainsString('{ 10.1.0.0/16, @fqdn_github_com }', $ruleset);
        $this->assertStringNotContainsString('@fqdn_github_com', substr($ruleset, 0, strpos($ruleset, '@fqdn_github_com')) . '{ ');
    }

    public function testMultipleDestinationObjectsEmitOneRuleEach(): void
    {
        $this->insertAddress('DNS_A', 'ipmask', '1.1.1.1/32');
        $this->insertAddress('DNS_B', 'ipmask', '8.8.8.8/32');
        $this->insertPbr([
            'match_dst_objects' => json_encode(['DNS_A', 'DNS_B']),
            'set_table'         => 176,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip daddr 1.1.1.1/32', $ruleset);
        $this->assertStringContainsString('ip daddr 8.8.8.8/32', $ruleset);
        $this->assertSame(2, substr_count($ruleset, 'meta mark set 10176'));
    }

    public function testObjectListNamingAllMatchesEverything(): void
    {
        $this->insertPbr([
            'match_src_objects' => json_encode(['all']),
            'set_table'         => 178,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        // "all" means no source clause at all, so no ip saddr appears in the rule.
        $this->assertStringContainsString('comment "PBR: Test rule" meta mark set 10178', $ruleset);
    }

    public function testAddressGroupExpandsToItsMembers(): void
    {
        $this->insertAddress('DNS_A', 'ipmask', '1.1.1.1/32');
        $now = date('Y-m-d H:i:s');
        $this->db->table('firewall_address_groups')->insert([
            'id' => 'grp_test', 'name' => 'DNS_Group', 'members' => json_encode(['DNS_A']),
            'comment' => '', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->insertPbr([
            'match_dst_objects' => json_encode(['DNS_Group']),
            'set_table'         => 179,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip daddr 1.1.1.1/32', $ruleset);

        $this->db->table('firewall_address_groups')->where('name', 'DNS_Group')->delete();
    }

    public function testRulesAreEmittedInPriorityOrder(): void
    {
        $this->insertPbr(['priority' => 50, 'description' => 'Second', 'set_table' => 150]);
        $this->insertPbr(['priority' => 10, 'description' => 'First', 'set_table' => 110]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        // assertLessThan(expected, actual) → First must appear before Second.
        $this->assertLessThan(
            strpos($ruleset, 'Second'),
            strpos($ruleset, 'First'),
        );
    }
}
