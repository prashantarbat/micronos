<?php

declare(strict_types=1);

use App\Controllers\Backup;
use App\Libraries\RepoRegistry;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Restoring the curated plugin repositories onto the daemon (Plugin Spec §5.2 +
 * §13).
 *
 * Restoring the `plugin_repos` rows puts the *registry* back, but the appliance's
 * apt state is files the daemon generated — the sources stanza, the keyring and
 * the pin. Without a reconcile a Class C plugin comes back `blocked` for a reason
 * invisible on the Repositories page: the repository reads as registered and
 * enabled while the sources file its `requires.repos` names does not exist.
 *
 * `Backup::restore()` reads the archive through `UploadedFile::isValid()`, which
 * requires `is_uploaded_file()` and therefore cannot be satisfied by a feature
 * test at all — so these tests drive the reconcile step directly through a test
 * double, instead of weakening the upload check to make it reachable.
 *
 * @internal
 */
final class BackupRestoreTestDouble extends Backup
{
    /**
     * Resolve the collaborators `restore()` would have, so the reconcile can be
     * driven without an upload. `Services::ipc()` must already be mocked.
     */
    public function bootForTest(): void
    {
        $this->initController(service('request'), service('response'), service('logger'));
    }

    /** @return array<string, list<string>> */
    public static function sectionsForTest(): array
    {
        return self::RESTORE_SECTIONS;
    }

    /**
     * @param mixed $sections
     *
     * @return list<string>
     */
    public function selectedTablesForTest($sections): array
    {
        return $this->selectedTablesFor($sections);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $nicMap
     * @param list<string>          $selectedTables
     *
     * @return array{0: array<string, mixed>, 1: array{ok: bool, message: string}|null}
     */
    public function applyRestoreForTest(array $data, array $nicMap = [], array $selectedTables = [], bool $replaceKey = false): array
    {
        return $this->applyRestore($data, $nicMap, $selectedTables, $replaceKey);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array{ok: bool, message: string}|null
     */
    public function reapplyForTest(array $result): ?array
    {
        return $this->reapplyRepositories($result);
    }

    /**
     * @param array<string, mixed>                 $result
     * @param array{ok: bool, message: string}|null $repoNote
     *
     * @return array{0: string, 1: string}
     */
    public function flashForTest(array $result, ?array $repoNote = null): array
    {
        return $this->restoreFlash($result, $repoNote);
    }
}

/**
 * @internal
 */
final class BackupRepositoryRestoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const FINGERPRINT = 'EFE37DF047DF7CCDF1FC54FA83F79AD029778355';
    private const ARMOR       = "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nmQGNBGFPbxEBDADYH1aQc4\n-----END PGP PUBLIC KEY BLOCK-----\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRepos();

        // A real table of our own, so "the archive restored something, just not
        // repositories" is a genuine restore rather than an empty one.
        $probe = $this->db->prefixTable('backup_repo_restore_probe');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$probe} (
            id VARCHAR(32) PRIMARY KEY,
            note VARCHAR(64)
        )");
        $this->db->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('backup_repo_restore_probe'));
        $this->db->resetDataCache();

        $this->clearRepos();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertRepo(string $id = 'netbird', string $armor = self::ARMOR, string $url = 'https://pkgs.netbird.io/debian', string $suite = 'stable'): void
    {
        $this->db->table('plugin_repos')->insert([
            'id'              => $id,
            'url'             => $url,
            'key_fingerprint' => self::FINGERPRINT,
            'enabled'         => 1,
            'name'            => 'NetBird (pkgs.netbird.io)',
            'suite'           => $suite,
            'components'      => 'main',
            'architectures'   => 'amd64,arm64',
            'key_url'         => '',
            'key_armor'       => $armor,
            'last_outcome'    => '',
            'last_detail'     => '',
            'added_by'        => 'preset',
            'created_at'      => '2026-10-06 00:00:00',
        ]);
    }

    /**
     * A registry bound to a recording IPC client, installed as the shared
     * service so the controller resolves it.
     */
    private function injectRegistry(FakeIpcClient $ipc): RepoRegistry
    {
        Services::injectMock('ipc', $ipc);

        $registry = new RepoRegistry($ipc, $this->db);
        Services::injectMock('repoRegistry', $registry);

        return $registry;
    }

    /** @param list<string> $restored */
    private function restoreReport(array $restored): array
    {
        return ['success' => true, 'restored_tables' => $restored, 'warnings' => [], 'key_action' => 'none', 'needs_credentials' => false, 'verification' => ['ok' => true, 'checked' => 0, 'failed' => [], 'reason' => null]];
    }

    private function clearRepos(): void
    {
        if ($this->db->tableExists('plugin_repos')) {
            $this->db->table('plugin_repos')->emptyTable();
        }
    }

    // ------------------------------------------------------------------
    // The whole sequence: archive in, repositories reconciled out
    // ------------------------------------------------------------------

    /**
     * The end the feature exists for: an archive written by this appliance,
     * restored onto a rebuilt one, leaves the daemon holding the curated
     * repositories — with the archived signing key, and committed.
     *
     * A plaintext archive is used deliberately: an encrypted one would carry the
     * connector master key into `restore()`, which writes it through the
     * *default* key store path on this machine. The encrypted envelope itself is
     * covered by BackupRepositoryPolicyTest, and the reconcile is indifferent to
     * which form the archive took.
     */
    public function testAnArchivesRepositoriesAreReappliedAndCommittedByARestore(): void
    {
        $this->insertRepo('netbird');

        $engine = new \App\Libraries\BackupEngine($this->db, new \App\Libraries\ConnectorKeyStore());
        $archive = $engine->createBackup(null);
        $decoded = json_decode($archive['content'], true);

        // A rebuilt appliance: the registry is gone.
        $this->clearRepos();

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => [
                'success' => true,
                'status'  => 'applied',
                'repos'   => [['id' => 'netbird']],
            ], 'error' => null],
        ]);
        $this->injectRegistry($ipc);

        $double = new BackupRestoreTestDouble();
        $double->bootForTest();

        [$result, $note] = $double->applyRestoreForTest($decoded);

        $this->assertTrue($result['success']);
        $this->assertContains('plugin_repos', $result['restored_tables']);

        // ...the registry is back...
        $this->assertSame(1, $this->db->table('plugin_repos')->where('id', 'netbird')->countAllResults());

        // ...the daemon was told to apply it, with the archived key...
        $this->assertNotNull($note);
        $this->assertTrue($note['ok'], $note['message']);

        $applies = array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.repo.apply'));
        $this->assertCount(1, $applies);
        $this->assertSame('netbird', $applies[0]['params']['repos'][0]['id']);
        $this->assertSame(rtrim(self::ARMOR), $applies[0]['params']['repos'][0]['key_armor']);
        $this->assertSame(self::FINGERPRINT, $applies[0]['params']['repos'][0]['fingerprint']);

        // ...and the reconcile happened before the commit, so the commit
        // captures the sources the restore introduced.
        $methods = array_column($ipc->calls, 'method');
        $this->assertSame(['plugin.repo.apply', 'system.commit'], $methods);
    }

    public function testARestoreFromAnArchiveWithoutRepositoriesStillCommitsButDoesNotApply(): void
    {
        $this->insertRepo('keep-me');

        $ipc = new FakeIpcClient();
        $this->injectRegistry($ipc);

        $double = new BackupRestoreTestDouble();
        $double->bootForTest();

        [$result, $note] = $double->applyRestoreForTest([
            'format' => 2,
            'tables' => ['backup_repo_restore_probe' => [['id' => 'p1', 'note' => 'restored']]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertContains('backup_repo_restore_probe', $result['restored_tables']);
        $this->assertNull($note);
        $this->assertSame(['system.commit'], array_column($ipc->calls, 'method'));
        $this->assertSame(1, $this->db->table('plugin_repos')->where('id', 'keep-me')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Selective restore: the section the operator picks
    // ------------------------------------------------------------------

    /**
     * The invariant that keeps a section honest: every table a section names has
     * to be one the archive actually carries. Otherwise the operator ticks
     * "Firewall & NAT Rules", gets no error, and silently receives less than
     * they asked for. (This check is what surfaced `nat_nptv6` / `nat_settings`
     * being named by a section but never archived.)
     */
    public function testEverySelectableSectionNamesATableTheArchiveCarries(): void
    {
        $archive = json_decode(
            (new \App\Libraries\BackupEngine($this->db, new \App\Libraries\ConnectorKeyStore()))->createBackup(null)['content'],
            true,
        );
        $archived = array_keys($archive['tables']);

        foreach (BackupRestoreTestDouble::sectionsForTest() as $section => $tables) {
            $this->assertNotSame([], $tables, "section '{$section}' restores nothing");

            foreach ($tables as $table) {
                $this->assertContains(
                    $table,
                    $archived,
                    "section '{$section}' promises {$table}, but the backup never archives it",
                );
            }
        }
    }

    public function testTheRepositoriesSectionRestoresTheCuratedRepositoryRegistry(): void
    {
        $tables = (new BackupRestoreTestDouble())->selectedTablesForTest(['repositories']);

        $this->assertSame(['plugin_repos'], $tables);
    }

    /**
     * The half of the feature an operator actually reaches: ticking only
     * "Package Repositories & Signing Keys" restores those rows and nothing else
     * — and still reconciles the daemon. A rebuild that has to preserve the
     * appliance's firewall but re-adopt its curated repositories is exactly the
     * case this covers.
     */
    public function testSelectingOnlyTheRepositorySectionRestoresAndReappliesIt(): void
    {
        $this->insertRepo('netbird');

        $archive = (new \App\Libraries\BackupEngine($this->db, new \App\Libraries\ConnectorKeyStore()))
            ->createBackup(null);
        $decoded = json_decode($archive['content'], true);

        $this->clearRepos();

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => ['success' => true, 'status' => 'applied', 'repos' => [['id' => 'netbird']]], 'error' => null],
        ]);
        $this->injectRegistry($ipc);

        $double = new BackupRestoreTestDouble();
        $double->bootForTest();

        $selected = $double->selectedTablesForTest(['repositories']);
        [$result, $note] = $double->applyRestoreForTest($decoded, [], $selected);

        $this->assertTrue($result['success']);
        $this->assertSame(['plugin_repos'], $result['restored_tables'], 'a single-section restore must not touch anything else');
        $this->assertSame(1, $this->db->table('plugin_repos')->where('id', 'netbird')->countAllResults());
        $this->assertNotNull($note);
        $this->assertTrue($note['ok'], $note['message']);
        $this->assertContains('plugin.repo.apply', array_column($ipc->calls, 'method'));
    }

    public function testAFullRestoreLimitsToNothing(): void
    {
        $double = new BackupRestoreTestDouble();

        $this->assertSame([], $double->selectedTablesForTest([]));
        $this->assertSame([], $double->selectedTablesForTest(['all']));
        $this->assertSame([], $double->selectedTablesForTest(null));
        $this->assertSame([], $double->selectedTablesForTest('nonsense'));
    }

    public function testAnUnknownSectionContributesNoTables(): void
    {
        $double = new BackupRestoreTestDouble();

        $this->assertSame([], $double->selectedTablesForTest(['does-not-exist']));
        $this->assertSame(
            ['interfaces', 'plugin_repos'],
            $double->selectedTablesForTest(['network', 'does-not-exist', 'repositories']),
            'a known section must still be honoured alongside an unknown one',
        );
    }

    // ------------------------------------------------------------------
    // Reconcile after a restore
    // ------------------------------------------------------------------

    public function testARestoreThatCarriedRepositoriesReappliesThemToTheDaemon(): void
    {
        $this->insertRepo();

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => [
                'success' => true,
                'status'  => 'applied',
                'repos'   => [['id' => 'netbird', 'sources_path' => '/etc/apt/sources.list.d/micron-netbird.sources']],
            ], 'error' => null],
        ]);
        $this->injectRegistry($ipc);

        $note = (new BackupRestoreTestDouble())->reapplyForTest($this->restoreReport(['plugin_repos']));

        $this->assertNotNull($note);
        $this->assertTrue($note['ok']);
        $this->assertStringContainsString('1 curated plugin repository was re-applied', $note['message']);

        $applies = array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.repo.apply'));
        $this->assertCount(1, $applies, 'the restored repositories must be reconciled exactly once');

        $payload = $applies[0]['params']['repos'];
        $this->assertCount(1, $payload);
        $this->assertSame('netbird', $payload[0]['id']);
        $this->assertSame(self::FINGERPRINT, $payload[0]['fingerprint']);
        // The archived signing key is what the daemon verifies against — the
        // restore must not depend on the vendor still serving it. (`shape()`
        // trims the stored armor, so compare against the trimmed form; the
        // verbatim round trip is asserted in BackupRepositoryPolicyTest.)
        $this->assertSame(rtrim(self::ARMOR), $payload[0]['key_armor']);
        $this->assertTrue($payload[0]['enabled']);
    }

    public function testARestoreThatCarriedManyRepositoriesReportsThemAll(): void
    {
        // Distinct sources: two repositories declaring the same url/suite is a
        // deliberate conflict in the plan (§5.2), not something to restore over.
        $this->insertRepo('vendor-a', self::ARMOR, 'https://pkgs.vendor-a.example/debian');
        $this->insertRepo('vendor-b', self::ARMOR, 'https://pkgs.vendor-b.example/debian');

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => ['success' => true, 'status' => 'applied', 'repos' => [
                ['id' => 'vendor-a'], ['id' => 'vendor-b'],
            ]], 'error' => null],
        ]);
        $this->injectRegistry($ipc);

        $note = (new BackupRestoreTestDouble())->reapplyForTest($this->restoreReport(['plugin_repos']));

        $this->assertTrue($note['ok']);
        $this->assertStringContainsString('2 curated plugin repositories were re-applied', $note['message']);
        $this->assertCount(2, array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.repo.apply'))[0]['params']['repos']);
    }

    /**
     * An archive from before repositories existed restores real tables, just
     * none of ours — so the daemon must be left completely alone.
     */
    public function testARestoreWithoutTheRepositoryRegistryNeverTouchesTheDaemon(): void
    {
        $this->insertRepo('keep-me');

        $ipc = new FakeIpcClient();
        $this->injectRegistry($ipc);

        $note = (new BackupRestoreTestDouble())->reapplyForTest($this->restoreReport(['interfaces', 'firewall_rules']));

        $this->assertNull($note, 'nothing to reconcile means no note, not an empty success');
        $this->assertSame([], $ipc->calls, 'an archive with no repository registry must not reach the daemon');
    }

    public function testARestoredRegistryThatIsEntirelyDisabledReconcilesToNone(): void
    {
        // The archive registered a repository but never enabled it: the reconcile
        // still has to run, because anything the daemon held from before must be
        // retired.
        $this->insertRepo('disabled-vendor');
        $this->db->table('plugin_repos')->where('id', 'disabled-vendor')->update(['enabled' => 0]);

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => ['success' => true, 'status' => 'applied', 'repos' => []], 'error' => null],
        ]);
        $this->injectRegistry($ipc);

        $note = (new BackupRestoreTestDouble())->reapplyForTest($this->restoreReport(['plugin_repos']));

        $this->assertTrue($note['ok']);
        $this->assertStringContainsString('reconciled to none', $note['message']);

        $applies = array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.repo.apply'));
        $this->assertCount(1, $applies);
        $this->assertSame([], $applies[0]['params']['repos']);
    }

    // ------------------------------------------------------------------
    // Failure is surfaced, never swallowed
    // ------------------------------------------------------------------

    public function testADaemonThatRefusesThePlanIsReportedAsNotApplied(): void
    {
        $this->insertRepo();

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => [
                'success' => false,
                'status'  => 'blocked',
                'message' => 'the keyring could not be written',
            ], 'error' => null],
        ]);
        $this->injectRegistry($ipc);

        $note = (new BackupRestoreTestDouble())->reapplyForTest($this->restoreReport(['plugin_repos']));

        $this->assertFalse($note['ok']);
        $this->assertStringContainsString('NOT applied', $note['message']);
        $this->assertStringContainsString('the keyring could not be written', $note['message']);
        $this->assertStringContainsString('Repositories page', $note['message']);
    }

    public function testAFailedReapplyMakesTheRestoreFlashAnAttentionState(): void
    {
        $double = new BackupRestoreTestDouble();

        [$type, $message] = $double->flashForTest(
            $this->restoreReport(['plugin_repos']),
            ['ok' => false, 'message' => 'The curated plugin repositories were restored but NOT applied to the appliance.'],
        );

        $this->assertSame('error', $type, 'a degraded restore must not read as a clean success (§7.6)');
        $this->assertStringContainsString('NOT applied', $message);
    }

    public function testASuccessfulReapplyKeepsTheRestoreFlashSuccessful(): void
    {
        $double = new BackupRestoreTestDouble();

        [$type, $message] = $double->flashForTest(
            $this->restoreReport(['plugin_repos']),
            ['ok' => true, 'message' => '1 curated plugin repository was re-applied to the appliance with the archived signing key.'],
        );

        $this->assertSame('success', $type);
        $this->assertStringContainsString('re-applied', $message);
    }

    public function testARestoreFlashWithoutARepositoryNoteIsUnchanged(): void
    {
        $double = new BackupRestoreTestDouble();

        [$type, $message] = $double->flashForTest($this->restoreReport([]));

        $this->assertSame('success', $type);
        $this->assertStringNotContainsString('repositor', $message);
    }
}
