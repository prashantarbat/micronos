<?php

use App\Libraries\NftablesCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Multi-WAN load balancing and sticky connections.
 *
 * A load-balanced group spreads new flows across its members and pins each flow
 * to one gateway. With sticky connections enabled it additionally pins a source
 * address to the gateway it was first assigned, for as long as the configured
 * timeout allows.
 *
 * @internal
 */
final class MultiWanStickyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('wan_gateway_groups')->emptyTable();
        $this->db->table('wan_gateways')->emptyTable();

        $this->db->table('interfaces')->where('id', 'wan')->delete();
        $this->db->table('interfaces')->where('id', 'lan')->delete();
        $this->db->table('interfaces')->insertBatch([
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1', 'enabled' => 1, 'ip_assignment_type' => 'static'],
        ]);

        $this->insertGateway(['id' => 'gw_a', 'name' => 'GW_A', 'interface' => 'eth0', 'gateway_ip' => '203.0.113.1', 'priority' => 1]);
        $this->insertGateway(['id' => 'gw_b', 'name' => 'GW_B', 'interface' => 'eth1', 'gateway_ip' => '198.51.100.1', 'priority' => 2]);
    }

    private function insertGateway(array $overrides = []): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('wan_gateways')->insert(array_merge([
            'id'           => 'gw_' . bin2hex(random_bytes(4)),
            'name'         => 'GW',
            'interface'    => 'eth0',
            'gateway_ip'   => '203.0.113.1',
            'monitor_ip'   => null,
            'weight'       => 1,
            'priority'     => 1,
            'latency_warn' => 200,
            'latency_down' => 500,
            'loss_warn'    => 10,
            'loss_down'    => 20,
            'probe_iface'  => null,
            'description'  => null,
            'enabled'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $overrides));
    }

    private function insertGroup(array $overrides = []): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('wan_gateway_groups')->insert(array_merge([
            'id'             => 'gwg_' . bin2hex(random_bytes(4)),
            'name'           => 'BALANCED',
            'strategy'       => 'load-balance',
            'sticky_enabled' => 1,
            'sticky_timeout' => 3600,
            'members'        => json_encode([
                ['id' => 'gw_a', 'tier' => 1, 'weight' => 1],
                ['id' => 'gw_b', 'tier' => 1, 'weight' => 1],
            ]),
            'trigger'     => 'down',
            'description' => null,
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $overrides));
    }

    private function compiler(): NftablesCompiler
    {
        return new NftablesCompiler($this->db);
    }

    private function stickyRules(array $multiWan): array
    {
        return array_values(array_filter(
            $multiWan['rules'],
            static fn (string $line): bool => str_contains($line, '@micron_sticky_'),
        ));
    }

    public function testStickyGroupDeclaresSourceTrackingMapWithTimeout(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky', 'sticky_timeout' => 3600]);

        $maps = implode("\n", $this->compiler()->compileMultiWan()['maps']);

        $this->assertStringContainsString('map micron_sticky_gwg_sticky', $maps);
        $this->assertStringContainsString('type ipv4_addr : mark', $maps);
        $this->assertStringContainsString('flags dynamic, timeout', $maps);
        $this->assertStringContainsString('timeout 3600s', $maps);
    }

    public function testZeroTimeoutMapHasNoLifetime(): void
    {
        $this->insertGroup(['id' => 'gwg_zero', 'sticky_timeout' => 0]);

        $maps = implode("\n", $this->compiler()->compileMultiWan()['maps']);

        $this->assertStringContainsString('map micron_sticky_gwg_zero', $maps);
        $this->assertStringContainsString('flags dynamic', $maps);
        // "removed as soon as the state expires" — no map-level timeout.
        $this->assertStringNotContainsString('timeout ', $maps);
    }

    public function testStickyGroupReusesTheRecordedGatewayForASource(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky']);

        $rules = implode("\n", $this->stickyRules($this->compiler()->compileMultiWan()));

        $this->assertStringContainsString(
            'ip saddr @micron_sticky_gwg_sticky meta mark 0 meta mark set ip saddr map @micron_sticky_gwg_sticky',
            $rules,
        );
    }

    public function testStickyGroupRecordsTheChosenGatewayAgainstTheSource(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky']);

        $rules = implode("\n", $this->compiler()->compileMultiWan()['rules']);

        $this->assertStringContainsString('add @micron_sticky_gwg_sticky { ip saddr : meta mark }', $rules);
    }

    public function testNonStickyGroupDeclaresNoMapAndRecordsNothing(): void
    {
        $this->insertGroup(['id' => 'gwg_plain', 'sticky_enabled' => 0]);

        $multiWan = $this->compiler()->compileMultiWan();

        $this->assertSame([], $multiWan['maps']);
        $this->assertStringNotContainsString('micron_sticky', implode("\n", $multiWan['rules']));
    }

    public function testRoundRobinSpreadsOneConnectionPerMember(): void
    {
        $this->insertGroup(['strategy' => 'round-robin', 'sticky_enabled' => 0]);

        $rules = implode("\n", $this->compiler()->compileMultiWan()['rules']);

        $this->assertStringContainsString('numgen inc mod 2', $rules);
    }

    public function testLoadBalanceWeightsTheDistribution(): void
    {
        $this->insertGroup([
            'strategy' => 'load-balance',
            'members'  => json_encode([
                ['id' => 'gw_a', 'tier' => 1, 'weight' => 2],
                ['id' => 'gw_b', 'tier' => 1, 'weight' => 1],
            ]),
        ]);

        $rules = implode("\n", $this->compiler()->compileMultiWan()['rules']);

        // Three slices: two for gw_a, one for gw_b. The two equal slices
        // collapse into one range.
        $this->assertStringContainsString('numgen random mod 3', $rules);
        $this->assertMatchesRegularExpression('/map \{ 0-1 : \d+, 2 : \d+ \}/', $rules);
    }

    public function testFlowAffinityCopiesTheChoiceOntoTheConnection(): void
    {
        $this->insertGroup(['sticky_enabled' => 0]);

        $rules = implode("\n", $this->compiler()->compileMultiWan()['rules']);

        $this->assertStringContainsString('ct mark set meta mark', $rules);
        $this->assertStringContainsString('meta mark 0 meta mark set ct mark', $rules);
    }

    public function testFailoverGroupIsNotLoadBalanced(): void
    {
        $this->insertGroup(['strategy' => 'failover']);

        $multiWan = $this->compiler()->compileMultiWan();

        $this->assertSame([], $multiWan['maps']);
        $this->assertSame([], $multiWan['rules']);
        $this->assertSame([], $multiWan['balancers']);
    }

    public function testGroupWithFewerThanTwoMembersIsNotBalanced(): void
    {
        $this->insertGroup([
            'members' => json_encode([['id' => 'gw_a', 'tier' => 1, 'weight' => 1]]),
        ]);

        $this->assertSame([], $this->compiler()->compileMultiWan()['balancers']);
    }

    public function testDisabledGatewayIsDroppedFromTheMemberList(): void
    {
        $this->db->table('wan_gateways')->where('id', 'gw_b')->update(['enabled' => 0]);
        $this->insertGroup([]);

        // Only one usable member remains, so the group is not balanced.
        $this->assertSame([], $this->compiler()->compileMultiWan()['balancers']);
    }

    public function testUnknownMemberIsIgnored(): void
    {
        $this->insertGroup([
            'members' => json_encode([
                ['id' => 'gw_a', 'tier' => 1, 'weight' => 1],
                ['id' => 'gw_missing', 'tier' => 1, 'weight' => 1],
            ]),
        ]);

        $this->assertSame([], $this->compiler()->compileMultiWan()['balancers']);
    }

    public function testStickyReuseRulesAreEmittedBeforeAssignmentRules(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky', 'name' => 'STICKY']);

        $rules = $this->compiler()->compileMultiWan()['rules'];

        $reuse = null;
        $assign = null;
        foreach ($rules as $i => $line) {
            if ($reuse === null && str_contains($line, 'meta mark set ip saddr map')) {
                $reuse = $i;
            }
            if ($assign === null && str_contains($line, 'ct state new') && str_contains($line, 'numgen')) {
                $assign = $i;
            }
        }

        $this->assertNotNull($reuse);
        $this->assertNotNull($assign);
        // A known source must never be re-balanced by a later assignment rule.
        $this->assertLessThan($assign, $reuse);
    }

    public function testBalancedGroupProducesPerGatewayRoutingRulesAndRoutes(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky']);

        $policy = $this->compiler()->compileRoutingPolicy();

        $multiWanRules = array_values(array_filter(
            $policy['rules'],
            static fn (array $r): bool => str_starts_with($r['label'], 'Multi-WAN'),
        ));
        $this->assertCount(2, $multiWanRules);

        // Each gateway gets its own mark and its own routing table.
        $marks  = array_column($multiWanRules, 'mark');
        $tables = array_column($multiWanRules, 'table');
        $this->assertCount(2, array_unique($marks));
        $this->assertCount(2, array_unique($tables));

        // Multi-WAN tables live above the Policy-Based Routing band.
        foreach ($tables as $table) {
            $this->assertGreaterThanOrEqual(201, $table);
            $this->assertLessThanOrEqual(252, $table);
        }

        $nexthops = array_column($policy['routes'], 'nexthop');
        $this->assertContains('203.0.113.1', $nexthops);
        $this->assertContains('198.51.100.1', $nexthops);
    }

    public function testMultiWanMarksNeverCollideWithPolicyRoutingMarks(): void
    {
        $this->insertGroup([]);

        $multiWan = $this->compiler()->compileMultiWan();

        foreach ($multiWan['gateways'] as $mark) {
            $this->assertGreaterThanOrEqual(20001, $mark);
            // The PBR band tops out at 10252.
            $this->assertGreaterThan(10252, $mark);
        }
    }

    public function testRenderedRoutingPolicyNamesTheBalancedGroup(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky', 'name' => 'STICKY']);

        $rendered = implode("\n", $this->compiler()->renderRoutingPolicy());

        $this->assertStringContainsString('ip rule add fwmark', $rendered);
        $this->assertStringContainsString('lookup', $rendered);
        $this->assertStringContainsString('Multi-WAN STICKY', $rendered);
        $this->assertStringContainsString('ip route replace default via', $rendered);
    }

    public function testCompiledRulesetIsValidNftables(): void
    {
        $this->insertGroup(['id' => 'gwg_sticky', 'sticky_timeout' => 60]);

        $ruleset = $this->compiler()->compile();

        // A balanced, sticky group must appear in the single nftables ruleset
        // and must not introduce any non-nftables line.
        $this->assertStringContainsString('map micron_sticky_gwg_sticky', $ruleset);
        $this->assertStringContainsString('add @micron_sticky_gwg_sticky', $ruleset);
        $this->assertSame(0, preg_match('/^\s*ip (rule|route) /m', $ruleset));
    }
}
