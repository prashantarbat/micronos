<?php

use App\Libraries\ApplianceResolver;
use App\Libraries\DynamicDnsRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Covers the interface path's resolver reconcile: what the data plane reports
 * is recorded while the DNS Server Override is enabled, everything is dropped
 * when it is not, and a failed report never silently discards what is already
 * known. The apply parameters handed back are checked too, since they are what
 * the controller pushes through `dns.resolver.apply`.
 *
 * @internal
 */
final class ApplianceResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** @var array<string, mixed>|null */
    private ?array $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->db->table('dns_settings')->where('id', 'global')->delete();
        $this->db->table('dynamic_dns_servers')->emptyTable();

        $this->db->table('dns_settings')->insert([
            'id'                      => 'global',
            'enable_dnssec'           => 1,
            'enable_dnsbl'            => 1,
            'upstream_mode'           => 'dot',
            'upstream_servers'        => json_encode([['ip' => '1.1.1.1'], ['ip' => '9.9.9.9']]),
            'cache_min_ttl'           => 60,
            'cache_max_ttl'           => 86400,
            'blocked_query_action'    => '0.0.0.0',
            'dns_server_override'     => 1,
            'dns_resolution_behavior' => 'local-fallback',
            'updated_at'              => '2026-10-06 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->delete();
        if (is_array($this->original)) {
            $this->db->table('dns_settings')->insert($this->original);
        }

        $this->db->table('dynamic_dns_servers')->emptyTable();

        parent::tearDown();
    }

    private function registry(): DynamicDnsRegistry
    {
        return new DynamicDnsRegistry($this->db);
    }

    /** An override-on report is recorded and merged into the apply parameters. */
    public function testReconcileRecordsTheDataPlaneReport(): void
    {
        $ipc = new FakeIpcClient([
            'interface.dynamic_dns' => ['error' => null, 'result' => [
                'success' => true,
                'status'  => 'ok',
                'sources' => [
                    ['source' => 'dhcp', 'interface' => 'WAN', 'servers' => ['10.0.0.138']],
                ],
            ]],
        ]);

        $plan = (new ApplianceResolver($this->db, $ipc))->reconcile();

        $this->assertTrue($plan['override']);
        $this->assertSame(1, $plan['recorded']);
        $this->assertSame(['10.0.0.138'], $plan['servers']);
        $this->assertSame(['10.0.0.138'], $this->registry()->servers());

        $this->assertSame('local-fallback', $plan['apply']['behavior']);
        $this->assertTrue($plan['apply']['override']);
        $this->assertStringContainsString('nameserver 10.0.0.138', $plan['apply']['config']);

        // Only the read-only report is issued; the caller owns the apply call.
        $this->assertSame(['interface.dynamic_dns'], array_column($ipc->calls, 'method'));
    }

    /** With the override off, the report is not even requested and the store is cleared. */
    public function testReconcileDropsEverythingWhenOverrideIsOff(): void
    {
        $this->registry()->record([['source' => 'dhcp', 'interface' => 'WAN', 'servers' => ['10.0.0.138']]]);
        $this->db->table('dns_settings')->where('id', 'global')->update(['dns_server_override' => 0]);

        $ipc = new FakeIpcClient();

        $plan = (new ApplianceResolver($this->db, $ipc))->reconcile();

        $this->assertFalse($plan['override']);
        $this->assertSame(1, $plan['dropped']);
        $this->assertSame([], $this->registry()->servers());
        $this->assertSame([], $ipc->calls, 'no report is needed to drop them');
        $this->assertStringNotContainsString('nameserver 10.0.0.138', $plan['apply']['config']);
    }

    /** A failed report leaves the existing record untouched (no silent drop). */
    public function testReconcileKeepsTheRecordWhenTheReportFails(): void
    {
        $this->registry()->record([['source' => 'dhcp', 'interface' => 'WAN', 'servers' => ['10.0.0.138']]]);

        $ipc = new FakeIpcClient([
            'interface.dynamic_dns' => ['error' => ['code' => -32601, 'message' => 'Method not found'], 'result' => null],
        ]);

        $plan = (new ApplianceResolver($this->db, $ipc))->reconcile();

        $this->assertSame(1, $plan['recorded'], 'the previous record must survive a transient failure');
        $this->assertSame(['10.0.0.138'], $this->registry()->servers());
    }

    /** With the override on but no dynamic links, the store is cleared to empty. */
    public function testReconcileWithEmptyReportClearsTheStore(): void
    {
        $this->registry()->record([['source' => 'ppp', 'interface' => 'P', 'servers' => ['100.64.0.53']]]);

        $ipc = new FakeIpcClient([
            'interface.dynamic_dns' => ['error' => null, 'result' => ['success' => true, 'sources' => []]],
        ]);

        $plan = (new ApplianceResolver($this->db, $ipc))->reconcile();

        $this->assertSame(0, $plan['recorded']);
        $this->assertSame([], $this->registry()->servers());
        $this->assertStringNotContainsString('nameserver 100.64.0.53', $plan['apply']['config']);
    }

    /** applyParams reflects the persisted state without touching the data plane. */
    public function testApplyParamsCompileTheCurrentState(): void
    {
        $params = (new ApplianceResolver($this->db, new FakeIpcClient()))->applyParams();

        $this->assertSame('local-fallback', $params['behavior']);
        $this->assertTrue($params['override']);
        $this->assertStringContainsString('nameserver 127.0.0.1', $params['config']);
        $this->assertStringContainsString('nameserver 1.1.1.1', $params['config']);
    }
}
