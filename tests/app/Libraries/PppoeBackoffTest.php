<?php

namespace Tests\App\Libraries;

use App\Libraries\PppoeBackoff;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Tests the escalating backoff logic, retry intervals, alert thresholds,
 * and state persistence for PPPoE redials.
 *
 * @internal
 */
final class PppoeBackoffTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private string $tempJsonPath;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->db->tableExists(PppoeBackoff::TABLE)) {
            $this->db->table(PppoeBackoff::TABLE)->emptyTable();
        }
        if ($this->db->tableExists('audit_logs')) {
            $this->db->table('audit_logs')->where('action', 'pppoe.link.alert')->delete();
        }

        $this->tempJsonPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pppoe_backoff_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempJsonPath)) {
            @unlink($this->tempJsonPath);
        }
        parent::tearDown();
    }

    public function testEscalatingScheduleDurations(): void
    {
        $backoff = new PppoeBackoff($this->db);

        $this->assertSame(0, $backoff->calculateBackoffSeconds(0));
        $this->assertSame(300, $backoff->calculateBackoffSeconds(1), '1st failure: 5 minutes (300s)');
        $this->assertSame(900, $backoff->calculateBackoffSeconds(2), '2nd failure: 15 minutes (900s)');
        $this->assertSame(1800, $backoff->calculateBackoffSeconds(3), '3rd failure: 30 minutes (1800s)');
        $this->assertSame(3600, $backoff->calculateBackoffSeconds(4), '4th failure: 60 minutes (3600s)');
        $this->assertSame(7200, $backoff->calculateBackoffSeconds(5), '5th failure: 120 minutes (7200s)');
        $this->assertSame(7200, $backoff->calculateBackoffSeconds(10), 'Capped at max 2 hours (7200s)');
    }

    public function testRecordFailureIncrementsCountAndEnforcesBackoff(): void
    {
        $backoff = new PppoeBackoff($this->db);
        $baseTime = 1700000000;

        // 1st failure
        $res1 = $backoff->recordFailure('eth0', 'LCP negotiation timeout', $baseTime);
        $this->assertSame(1, $res1['failure_count']);
        $this->assertSame(300, $res1['remaining_seconds']);
        $this->assertTrue($res1['is_backed_off']);
        $this->assertFalse($res1['alert_raised'], 'Alert must not be raised on first failure');
        $this->assertSame('5m', $res1['remaining_human']);
        $this->assertTrue($backoff->isBackedOff('eth0', $baseTime + 100));
        $this->assertFalse($backoff->isBackedOff('eth0', $baseTime + 301), 'Backoff should expire after 300s');

        // 2nd failure (advances to 900s and raises alert)
        $res2 = $backoff->recordFailure('eth0', 'PAP auth failed', $baseTime + 301);
        $this->assertSame(2, $res2['failure_count']);
        $this->assertSame(900, $res2['remaining_seconds']);
        $this->assertTrue($res2['is_backed_off']);
        $this->assertTrue($res2['alert_raised'], 'Alert must be raised on 2nd consecutive failure');
        $this->assertSame(1, $res2['alert_count']);

        // Check audit log entry
        $audit = $this->db->table('audit_logs')
            ->where('action', 'pppoe.link.alert')
            ->where('target', 'eth0')
            ->get()
            ->getRowArray();
        $this->assertNotNull($audit);
        $this->assertSame('alert', $audit['action_type']);
        $this->assertStringContainsString('consecutive_failures: 2', $audit['new_state']);
    }

    public function testRecordSuccessClearsBackoffState(): void
    {
        $backoff = new PppoeBackoff($this->db);
        $baseTime = 1700000000;

        $backoff->recordFailure('eth0', 'Carrier lost', $baseTime);
        $this->assertTrue($backoff->isBackedOff('eth0', $baseTime + 10));

        $backoff->recordSuccess('eth0');
        $this->assertFalse($backoff->isBackedOff('eth0', $baseTime + 10));

        $state = $backoff->getLinkState('eth0', $baseTime + 10);
        $this->assertSame(0, $state['failure_count']);
        $this->assertFalse($state['is_backed_off']);
    }

    public function testResetSingleAndAllInterfaces(): void
    {
        $backoff = new PppoeBackoff($this->db);
        $baseTime = 1700000000;

        $backoff->recordFailure('eth0', 'Err 1', $baseTime);
        $backoff->recordFailure('eth1', 'Err 2', $baseTime);

        $this->assertTrue($backoff->isBackedOff('eth0', $baseTime));
        $this->assertTrue($backoff->isBackedOff('eth1', $baseTime));

        // Reset eth0 only
        $backoff->reset('eth0');
        $this->assertFalse($backoff->isBackedOff('eth0', $baseTime));
        $this->assertTrue($backoff->isBackedOff('eth1', $baseTime));

        // Reset all
        $backoff->reset(null);
        $this->assertFalse($backoff->isBackedOff('eth1', $baseTime));
    }

    public function testJsonFallbackWhenDatabaseUnavailable(): void
    {
        // Null database connection forces JSON file backend
        $backoff = new PppoeBackoff(null, $this->tempJsonPath);
        $baseTime = 1700000000;

        $res = $backoff->recordFailure('wan0', 'No PADO received', $baseTime);
        $this->assertSame(1, $res['failure_count']);
        $this->assertFileExists($this->tempJsonPath);

        $state = $backoff->getLinkState('wan0', $baseTime);
        $this->assertSame(1, $state['failure_count']);
        $this->assertSame('wan0', $state['interface']);
        $this->assertTrue($state['is_backed_off']);

        $backoff->recordSuccess('wan0');
        $stateAfter = $backoff->getLinkState('wan0', $baseTime);
        $this->assertSame(0, $stateAfter['failure_count']);
        $this->assertFalse($stateAfter['is_backed_off']);
    }
}
