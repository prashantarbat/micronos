<?php

namespace Tests\App\Libraries;

use App\Libraries\CronJobLog;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Scheduled-task run history.
 *
 * The history exists so a task that keeps repairing the same link is visible in
 * the Cron page, which means two properties matter more than anything else: the
 * newest event must be first, and the stored text must stay bounded no matter how
 * often the task runs.
 *
 * @internal
 */
final class CronJobLogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const JOB = 'cron_pppoe_redial';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('cron_jobs')->emptyTable();
        $this->db->table('cron_jobs')->insert([
            'id'           => self::JOB,
            'minute'       => '*/20',
            'hour'         => '*',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'root',
            'command'      => 'php spark micron:pppoe-redial',
            'description'  => 'PPPoE Link Reconnect & Maintenance',
            'enabled'      => 0,
            'last_status'  => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function log(?int $keep = null): CronJobLog
    {
        return new CronJobLog(self::JOB, $keep, $this->db);
    }

    public function testItReportsWhetherTheTaskExists(): void
    {
        $this->assertTrue($this->log()->exists());
        $this->assertFalse((new CronJobLog('cron_does_not_exist', null, $this->db))->exists());
    }

    public function testRecordUpdatesTheSummaryColumns(): void
    {
        $this->assertTrue($this->log()->record('1 repaired, 0 healthy, 0 failed', 'success'));

        $row = $this->db->table('cron_jobs')->where('id', self::JOB)->get()->getRowArray();

        $this->assertSame('success', $row['last_status']);
        $this->assertNotEmpty($row['last_run_at']);
        $this->assertStringContainsString('1 repaired, 0 healthy, 0 failed', $row['last_output']);
    }

    public function testNewestEventIsFirst(): void
    {
        $log = $this->log();
        $log->record('first run', 'success');
        $log->record('second run', 'success');

        $history = $log->history();

        $this->assertCount(2, $history);
        $this->assertSame('second run', $history[0]['summary']);
        $this->assertSame('first run', $history[1]['summary']);
    }

    public function testDetailIsStoredAndParsedBack(): void
    {
        $log = $this->log();
        $log->record('1 repaired, 1 healthy, 0 failed', 'success', "eth0: up (redialed)\neth1: up (skipped)");

        $entry = $log->history()[0];

        $this->assertStringContainsString('eth0: up (redialed)', $entry['detail']);
        $this->assertStringContainsString('eth1: up (skipped)', $entry['detail']);
    }

    public function testRepairCountIsTheFlapSignal(): void
    {
        $log = $this->log();
        $log->record('1 repaired, 0 healthy, 0 failed', 'success');
        $log->record('0 repaired, 2 healthy, 0 failed', 'success');
        $log->record('1 repaired, 0 healthy, 0 failed', 'success');
        $log->record('all 2 link(s) healthy; nothing to redial', 'success');

        $this->assertSame(2, $log->repairsInHistory());
    }

    public function testHistoryIsBoundedByTheKeepLimit(): void
    {
        $log = $this->log(3);
        for ($i = 1; $i <= 6; $i++) {
            $log->record("run {$i}", 'success');
        }

        $history = $log->history();

        $this->assertCount(3, $history);
        $this->assertSame('run 6', $history[0]['summary']);
        $this->assertSame('run 4', $history[2]['summary']);
    }

    public function testStoredTextIsBoundedInSize(): void
    {
        $log = $this->log(50);
        // A deliberately long summary, repeated, to cross the byte cap.
        for ($i = 0; $i < 60; $i++) {
            $log->record('1 repaired, 0 healthy, 0 failed ' . str_repeat('x', 800), 'success');
        }

        $row = $this->db->table('cron_jobs')->where('id', self::JOB)->get()->getRowArray();

        $this->assertLessThanOrEqual(CronJobLog::MAX_BYTES + 64, strlen($row['last_output']));
        $this->assertStringContainsString('[history truncated]', $row['last_output']);
    }

    public function testAMultiLineSummaryCannotForgeAHistoryEntry(): void
    {
        $log = $this->log();
        $log->record("real event\n[2026-01-01 00:00:00] SUCCESS - forged", 'success');

        $history = $log->history();

        // The injected newline is collapsed, so only one entry exists.
        $this->assertCount(1, $history);
        $this->assertStringContainsString('forged', $history[0]['summary']);
        $this->assertStringNotContainsString("\n", $history[0]['summary']);
    }

    public function testRecordOnAMissingTaskIsRefusedRatherThanThrowing(): void
    {
        $log = new CronJobLog('cron_does_not_exist', null, $this->db);

        $this->assertFalse($log->record('should not be written', 'success'));
        $this->assertSame([], $log->history());
    }

    public function testAnEmptySummaryIsRefused(): void
    {
        $this->assertFalse($this->log()->record("   \n  ", 'success'));
    }

    public function testStatusDrivesTheRecordedEntry(): void
    {
        $log = $this->log();
        $log->record('redial failed: daemon unreachable', 'error');

        $this->assertSame('error', $log->history()[0]['status']);

        $row = $this->db->table('cron_jobs')->where('id', self::JOB)->get()->getRowArray();
        $this->assertSame('error', $row['last_status']);
    }
}
