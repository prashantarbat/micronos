<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The Cron page is where an operator answers "is this appliance backing itself
 * up?", so what it says about the nightly snapshot has to be true.
 *
 * It said the opposite twice over. The seeded row named
 * `/usr/local/bin/micron-backup` — a binary shipped nowhere — while reporting
 * `last_status: success` over a `/var/backups/*.embk` archive no code produced;
 * and the run-history modal's fallback dataset invented a `micron-backup` run
 * whose output read "Encryption verified". Torn together they said: a successful
 * encrypted offsite backup exists.
 *
 * `CronSeedCommandTest` guards the seeds and presets; these cover what the
 * operator actually sees rendered.
 *
 * @internal
 */
final class CronPageTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        // No daemon: this is what makes the run-history fallback reachable.
        Services::injectMock('ipc', new FakeIpcClient());

        // The row the corrective migration leaves behind, written
        // unconditionally because other tests in this suite empty the table.
        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->where('id', 'cron_backup_nightly')->delete();
        $this->db->table('cron_jobs')->insert([
            'id'           => 'cron_backup_nightly',
            'minute'       => '0',
            'hour'         => '2',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'root',
            'command'      => 'php spark backup:create',
            'description'  => 'Nightly Configuration Snapshot (disabled until scheduled snapshots, retention and offsite export are implemented)',
            'enabled'      => 0,
            'last_status'  => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function testTheNightlyBackupRowShowsTheCommandItWillActuallyRun(): void
    {
        $result = $this->withSession($this->session())->get('cron');

        $result->assertStatus(200);
        $result->assertSee('php spark backup:create');
        $result->assertSee('Nightly Configuration Snapshot');

        // The retired helper and its invented archive must not reach the page.
        $result->assertDontSee('micron-backup');
        $result->assertDontSee('.embk');
    }

    public function testTheRunHistoryDoesNotInventABackupRun(): void
    {
        // The illustrative history exists only for the development client.
        Services::injectMock('ipc', (new FakeIpcClient())->asDevelopmentMock());
        $result = $this->withSession($this->session())->get('cron/logs');

        $result->assertStatus(200);

        $body = (string) $result->getJSON();
        $this->assertStringNotContainsString('micron-backup', $body);
        $this->assertStringNotContainsString('Encryption verified', $body);
        $this->assertStringNotContainsString('backup archive', strtolower($body));

        // The fallback still answers with entries, so the assertions above are
        // not passing on an empty response.
        $entries = json_decode($body, true)['entries'] ?? [];
        $this->assertNotEmpty($entries, 'the run-history fallback returned nothing to inspect');
    }

    /** On a live appliance with no daemon answer, nothing is invented and the reason is given. */
    public function testALiveApplianceWithoutTheDaemonShowsNoInventedRuns(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $data = json_decode((string) $this->withSession($this->session())->get('cron/logs')->getJSON(), true);

        $this->assertSame([], $data['entries']);
        $this->assertNotEmpty($data['unavailable']);
        $this->assertFalse($data['mock']);
    }

    /** Real journal lines from the daemon are parsed into run-history rows. */
    public function testDaemonJournalLinesBecomeRunHistory(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'system.cron_logs' => ['result' => ['success' => true, 'logs' => [
                '2026-10-14T03:15:01+0000 micron-os CRON[4120]: (www-data) CMD (cd /usr/local/micron && /usr/bin/php spark micron:sync-services --geo)',
            ]], 'error' => null],
        ]));

        $data  = json_decode((string) $this->withSession($this->session())->get('cron/logs')->getJSON(), true);
        $entry = $data['entries'][0] ?? [];

        $this->assertSame('www-data', $entry['user'] ?? null);
        $this->assertSame(4120, $entry['pid'] ?? null);
        $this->assertStringContainsString('micron:sync-services', $entry['command'] ?? '');
        $this->assertNull($data['unavailable']);
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => ['dashboard.view'],
            'user_permissions' => ['dashboard.view'],
        ];
    }
}
