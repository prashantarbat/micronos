<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;
use Tests\Support\Libraries\RepoFixture;

/**
 * The scheduled half of the Repositories screen (`micron:repo-drift`).
 *
 * The command exists because a vendor that quietly stops publishing is not
 * something an administrator remembers to look for, so the things that matter
 * here are that it can be *scheduled* safely and that its exit status can be
 * watched by something that is not a person:
 *
 *  - a `--dry-run` reads the stored verdicts and touches nothing;
 *  - `--json` gives an external monitor a stable shape rather than a table;
 *  - a critical finding exits non-zero, so cron mail or a monitor sees it.
 *
 * The daemon is faked, but the whole fetch path (key, release metadata, egress
 * guard) still runs, so this exercises the command the way the schedule does.
 *
 * @internal
 */
final class RepoDriftCommandTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const FINGERPRINT = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
    private const OTHER_KEY   = '1234567890ABCDEF1234567890ABCDEF12345678';

    /** @var array<string, mixed>|null the drift job row before this test */
    private ?array $cronRow = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRepos();

        // The test connections share one in-memory database for the whole
        // process, so a run recorded here would otherwise leak into tests that
        // assert the task's schedule later in the suite.
        $this->cronRow = $this->db->table('cron_jobs')->where('id', 'cron_plugin_repo_drift')->get()->getRowArray();
    }

    protected function tearDown(): void
    {
        $this->clearRepos();

        if (is_array($this->cronRow)) {
            $this->db->table('cron_jobs')->where('id', 'cron_plugin_repo_drift')->update($this->cronRow);
        }

        parent::tearDown();
    }

    public function testTheCommandIsRegistered(): void
    {
        $this->assertArrayHasKey('micron:repo-drift', service('commands')->getCommands());
    }

    public function testDryRunReportsTheStoredVerdictAndWritesNothing(): void
    {
        $this->insertRow(['drift_kinds' => 'metadata_stale', 'drift_since' => '2026-10-01 00:00:00', 'last_check_at' => '2026-10-05 04:45:00']);

        // A dry run must not reach the daemon at all: injecting an empty double
        // proves the refresh and verification paths were never entered.
        Services::injectMock('ipc', new FakeIpcClient());

        $exit   = $this->runCommand(['dry-run' => null, 'json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertTrue($report['refresh_ok']);
        $this->assertTrue($report['repositories'][0]['drifting']);
        $this->assertSame(['metadata_stale'], $report['repositories'][0]['kinds']);
        // An inspection is not a check: the stored observation is unchanged.
        $this->assertSame('2026-10-05 04:45:00', $this->row()['last_check_at']);
    }

    public function testJsonReportsStaleMetadataAsAWarningWithoutFailing(): void
    {
        $this->insertRow();
        Services::injectMock('ipc', $this->driftIpc());

        $exit   = $this->runCommand(['json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit, 'stale metadata is a warning, not a critical');
        $this->assertSame(['vendor'], $report['drifting']);
        $this->assertSame([], $report['critical']);
        $this->assertSame(['vendor'], $report['started']);
        $this->assertSame('Metadata stale', $report['repositories'][0]['summary']);
        $this->assertNotSame('', (string) $this->row()['last_check_at']);
    }

    public function testACriticalFindingExitsNonZeroSoAMonitorSeesIt(): void
    {
        $this->insertRow();
        Services::injectMock('ipc', $this->rotationIpc());

        $exit   = $this->runCommand(['json' => null]);
        $report = $this->json();

        // A task that reports success while a key has been rotated is worse than
        // no task at all, so the exit status is part of the contract.
        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertSame(['vendor'], $report['critical']);
        $this->assertSame(['key_rotated'], $report['repositories'][0]['kinds']);
        $this->assertSame('critical', $report['repositories'][0]['highest']);
    }

    public function testTheConsoleTableNamesTheDriftingRepository(): void
    {
        $this->insertRow();
        Services::injectMock('ipc', $this->driftIpc());

        $exit = $this->runCommand([]);
        $out  = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('curated repository drift sweep', $out);
        $this->assertStringContainsString('vendor', $out);
        $this->assertStringContainsString('Metadata stale', $out);
    }

    public function testTheRunIsRecordedAgainstTheScheduledTask(): void
    {
        $this->insertRow();
        Services::injectMock('ipc', $this->driftIpc());

        $this->runCommand([]);

        $row = $this->db->table('cron_jobs')->where('id', 'cron_plugin_repo_drift')->get()->getRowArray();

        $this->assertNotNull($row, 'the drift task must be seeded so its history has somewhere to land');
        $this->assertNotSame('', (string) $row['last_run_at']);
        $this->assertStringContainsString('vendor', (string) $row['last_output']);
    }

    public function testNoEnabledRepositoriesIsNotAFailure(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok'], 'error' => null],
        ]));

        $exit   = $this->runCommand(['json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertSame(0, $report['checked']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Run the command through the framework, capturing its output.
     *
     * @param array<int|string,string|null> $params
     */
    private function runCommand(array $params): int
    {
        $this->resetStreamFilterBuffer();

        return (int) service('commands')->run('micron:repo-drift', $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode(trim($this->getStreamFilterBuffer()), true);

        $this->assertIsArray($decoded, 'the command must emit well-formed JSON');

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray() ?? [];
    }

    /**
     * A daemon whose verification succeeds but whose release metadata is old:
     * stale, not broken.
     */
    private function driftIpc(): FakeIpcClient
    {
        return new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => 1], 'error' => null],
            'plugin.repo_test'    => ['result' => $this->report([
                'release' => [
                    'date'        => 'Fri, 01 May 2026 00:00:00 UTC',
                    'valid_until' => gmdate('D, d M Y H:i:s', time() + 30 * 86400) . ' GMT',
                ],
            ]), 'error' => null],
        ]);
    }

    /**
     * A daemon that found the metadata signed by a key other than the declared —
     * a rotation, and therefore critical.
     */
    private function rotationIpc(): FakeIpcClient
    {
        return new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => 1], 'error' => null],
            'plugin.repo_test'    => ['result' => $this->report([
                'success'   => false,
                'reason'    => 'signer_mismatch',
                'message'   => 'the release is signed by ' . self::OTHER_KEY . ', not the declared ' . self::FINGERPRINT,
                'signature' => ['verified' => true, 'signer' => self::OTHER_KEY, 'mode' => 'inrelease'],
            ]), 'error' => null],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        return $overrides + [
            'success'   => true,
            'status'    => 'ok',
            'id'        => 'vendor',
            'key'       => ['declared' => self::FINGERPRINT, 'found' => [self::FINGERPRINT], 'matched' => true],
            'signature' => ['verified' => true, 'signer' => self::FINGERPRINT, 'mode' => 'inrelease'],
            'release'   => ['origin' => 'Vendor Repository', 'suite' => 'stable', 'codename' => 'vendorbook'],
            'warnings'  => [],
            'steps'     => [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRow(array $overrides = []): void
    {
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $this->db->table('plugin_repos')->insert($overrides + [
            'id'              => 'vendor',
            'name'            => 'Vendor APT',
            'url'             => 'https://download.example.com/debian',
            'suite'           => 'stable',
            'components'      => 'main',
            'architectures'   => 'amd64',
            'key_fingerprint' => self::FINGERPRINT,
            'key_url'         => '',
            'key_armor'       => RepoFixture::armor(),
            'enabled'         => 1,
        ]);
    }

    private function clearRepos(): void
    {
        if ($this->db->tableExists('plugin_repos')) {
            $this->db->table('plugin_repos')->emptyTable();
        }
    }
}
