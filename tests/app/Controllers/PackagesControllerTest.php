<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\CatalogueFixture;
use Tests\Support\Libraries\FakeIpcClient;
use Tests\Support\Libraries\RepoFixture;

/**
 * Plugin Repositories screen (Plugin Spec §5.2, §9): the registry renders with
 * its generated stanzas, CRUD persists or refuses, apply reconciles through the
 * daemon, and a conflicting plan never reaches the appliance.
 *
 * The page talks to the daemon only through `micrond`, so everything here runs
 * against a fake IPC bridge — the assertions are about the *plan* the page
 * presents and the requests it is willing to send.
 *
 * @internal
 */
final class PackagesControllerTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['plugins.view', 'plugins.repo.refresh', 'plugins.repo.manage'];

    private const FINGERPRINT = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRepos();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        // The key URL path is only ever fetched through the egress guard; a
        // stubbed guard keeps the page deterministic without a network.
        Services::injectMock('egressGuard', CatalogueFixture::guard($this->armor()));
    }

    protected function tearDown(): void
    {
        $this->clearRepos();

        parent::tearDown();
    }

    public function testIndexRendersTheRegistryAndTheGeneratedPlan(): void
    {
        $this->insertRow();

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [], 'count' => 0, 'core_pinned' => false], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins/repositories');

        $result->assertOK();
        $result->assertSee('Plugin Repositories');
        $result->assertSee('Vendor APT');
        $result->assertSee('https://download.example.com/debian');
        // The page shows what will be written, not just what was entered.
        $result->assertSee('micron-vendor.sources');
        $result->assertSee('Pin-Priority: 100');
        $result->assertSee('Compiled plan');
        $result->assertSee('NOT APPLIED');
    }

    public function testIndexReportsRepositoriesAlreadyAppliedOnTheDaemon(): void
    {
        $this->insertRow();

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => [
                'success' => true, 'status' => 'ok', 'count' => 1, 'core_pinned' => true,
                'core_pin' => '/etc/apt/preferences.d/micron-plugin-core.pref',
                'repos' => [['id' => 'vendor', 'url' => 'https://download.example.com/debian', 'suite' => 'stable', 'pinned' => true, 'keyed' => true]],
            ], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins/repositories');

        $result->assertOK();
        $result->assertSee('APPLIED');
        $result->assertSee('CORE PINNED');
    }

    public function testStorePersistsARepository(): void
    {
        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->releaseGuard());

        $result = $this->post('plugins/repositories/store', $this->csrf($this->form()));

        $result->assertRedirect();
        $result->assertSessionHas('success');

        $row = $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('https://download.example.com/debian', $row['url']);
        $this->assertSame(self::FINGERPRINT, $row['key_fingerprint']);
        $this->assertSame(1, (int) $row['enabled']);
    }

    public function testRegisteringVerifiesTheSourceBeforeSaving(): void
    {
        $ipc = $this->verifyingIpc();
        Services::injectMock('ipc', $ipc);
        Services::injectMock('egressGuard', $this->releaseGuard());

        $this->post('plugins/repositories/store', $this->csrf($this->form()))->assertRedirect();

        // Registering is the moment trust is asked for, so the check runs against
        // the candidate before the row exists.
        $this->assertSame('plugin.repo_test', $ipc->calls[0]['method']);
        $this->assertNotNull($this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray());
    }

    public function testRegisteringAnUnreachableRepositoryIsRefused(): void
    {
        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $result = $this->post('plugins/repositories/store', $this->csrf($this->form()));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
        // The failure is kept so the page can show exactly why it was refused.
        $result->assertSessionHas('repo_test_report');
    }

    public function testRegisteringARepositoryWhoseKeyDoesNotMatchIsRefused(): void
    {
        Services::injectMock('ipc', $this->mismatchedIpc());
        Services::injectMock('egressGuard', $this->releaseGuard());

        $result = $this->post('plugins/repositories/store', $this->csrf($this->form()));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    public function testRegisteringCanBeOverriddenForADeliberatelyOfflineCheck(): void
    {
        // Nothing is reachable, but the operator has said so explicitly. The
        // override is a choice, never a silent fallback.
        Services::injectMock('ipc', new FakeIpcClient());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $result = $this->post('plugins/repositories/store', $this->csrf($this->form(['skip_verify' => '1'])));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertNotNull($this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray());
    }

    public function testStoreRefusesAnInvalidRepositoryWithoutPersistingIt(): void
    {
        $result = $this->post('plugins/repositories/store', $this->csrf($this->form(['url' => 'http://download.example.com/debian'])));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    public function testToggleDisablesAnEnabledRepository(): void
    {
        $this->insertRow();

        $result = $this->post('plugins/repositories/vendor/toggle', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame(0, (int) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['enabled']);
    }

    public function testToggleOfAnUnknownRepositoryFlashesAnError(): void
    {
        $result = $this->post('plugins/repositories/ghost/toggle', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
    }

    public function testEnablingARepositoryVerifiesItFirst(): void
    {
        $this->insertRow(['enabled' => 0]);

        $ipc = $this->verifyingIpc();
        Services::injectMock('ipc', $ipc);
        Services::injectMock('egressGuard', $this->releaseGuard());

        $result = $this->post('plugins/repositories/vendor/toggle', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame('plugin.repo_test', $ipc->calls[0]['method']);
        $this->assertSame(1, (int) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['enabled']);
    }

    public function testEnablingAnUnreachableRepositoryIsRefused(): void
    {
        $this->insertRow(['enabled' => 0]);
        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $result = $this->post('plugins/repositories/vendor/toggle', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        // Bringing a source into service is what verification gates. It stays off.
        $this->assertSame(0, (int) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['enabled']);
    }

    public function testDisablingARepositoryIsNeverGatedByVerification(): void
    {
        // Retiring a source must always be possible, even when it is unreachable:
        // otherwise a broken vendor could trap a repository in service.
        $this->insertRow(['enabled' => 1]);
        Services::injectMock('ipc', new FakeIpcClient());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $result = $this->post('plugins/repositories/vendor/toggle', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame(0, (int) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['enabled']);
    }

    public function testEnablingCanBeOverriddenForADeliberatelyOfflineCheck(): void
    {
        $this->insertRow(['enabled' => 0]);
        Services::injectMock('ipc', new FakeIpcClient());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $result = $this->post('plugins/repositories/vendor/toggle', $this->csrf(['skip_verify' => '1']));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame(1, (int) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['enabled']);
    }

    public function testRegisteringStampsTheVerificationSoALaterFailureIsARegression(): void
    {
        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->releaseGuard());

        $this->post('plugins/repositories/store', $this->csrf($this->form()))->assertRedirect();

        // A source that has verified at least once can be told apart from one that
        // never has, which is what the scheduled sweep's regression warning uses.
        $this->assertNotSame('', (string) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['last_verified_at']);
    }

    public function testEnablingStampsTheVerification(): void
    {
        $this->insertRow(['enabled' => 0]);
        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->releaseGuard());

        $this->post('plugins/repositories/vendor/toggle', $this->csrf([]))->assertRedirect();

        $this->assertNotSame('', (string) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['last_verified_at']);
    }

    public function testTheOfflineOverrideDoesNotClaimAVerification(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $this->post('plugins/repositories/store', $this->csrf($this->form(['skip_verify' => '1'])))->assertRedirect();

        // Saved unverified means exactly that: no claim of a verification.
        $this->assertNull($this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['last_verified_at']);
    }

    public function testIndexSurfacesALostVerificationWithItsReason(): void
    {
        $this->insertRow([
            'drift_kinds'      => 'unreachable,verification_lost',
            'drift_since'      => '2026-10-05 04:45:00',
            'drift_detail'     => "WARNING — Unreachable: The repository could not be reached, or served no signed metadata: connection refused.\nWARNING — Verification lost: This repository last verified on 2026-10-01 and no longer does (release_unreachable).",
            'last_check_at'    => '2026-10-06 04:45:00',
            'last_verified_at' => '2026-10-01 04:45:00',
        ]);

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [], 'count' => 0, 'core_pinned' => false], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/repositories');

        $page->assertOK();
        $page->assertSee('Verification lost');
        $page->assertSee('last verified on 2026-10-01');
        $page->assertSee('release_unreachable');
        // And the row carries the last good verification, so the loss has a date.
        $page->assertSee('verified 2026-10-01 04:45:00');
    }

    public function testDeleteRemovesTheRepository(): void
    {
        $this->insertRow();

        $result = $this->post('plugins/repositories/vendor/delete', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    public function testApplyReconcilesThroughTheDaemon(): void
    {
        $this->insertRow();

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => [
                'success' => true, 'status' => 'applied',
                'repos'   => [['id' => 'vendor']], 'steps' => [], 'warnings' => [],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/repositories/apply', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame('plugin.repo.apply', $ipc->calls[0]['method']);

        $payload = $ipc->calls[0]['params']['repos'];
        $this->assertCount(1, $payload);
        $this->assertSame('vendor', $payload[0]['id']);
    }

    public function testApplyIsRefusedWhenThePlanConflictsAndNothingIsSent(): void
    {
        $this->insertRow(['id' => 'one']);
        $this->insertRow(['id' => 'two']);

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/repositories/apply', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertSame([], $ipc->calls);
    }

    public function testRepositoryRowOffersTheTestAction(): void
    {
        $this->insertRow();

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [], 'count' => 0, 'core_pinned' => false], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins/repositories');

        $result->assertOK();
        $result->assertSee('plugins/repositories/vendor/test');
        $result->assertSee('nothing is changed');
    }

    public function testTestActionVerifiesAndRendersTheReport(): void
    {
        $this->insertRow();

        $ipc = new FakeIpcClient(['plugin.repo_test' => ['result' => $this->verifiedReport(), 'error' => null]]);
        Services::injectMock('ipc', $ipc);
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $result = $this->post('plugins/repositories/vendor/test', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame('plugin.repo_test', $ipc->calls[0]['method']);
        $this->assertStringContainsString('Suite: stable', (string) $ipc->calls[0]['params']['release']);

        // The report is kept in the session (far too rich for a flash message),
        // so the next page load can show what was checked and what it found.
        $page = $this->withSession($this->session() + ['repo_test_report' => $this->verifiedReport() + [
            'ok' => true, 'release_url' => 'https://download.example.com/debian/dists/stable/InRelease', 'release_mode' => 'inrelease',
        ]])->get('plugins/repositories');

        $page->assertOK();
        $page->assertSee('Repository verification');
        $page->assertSee('NOTHING WAS WRITTEN');
        $page->assertSee('Vendor Repository');
        $page->assertSee('vendorbook');
        $page->assertSee('TESTED');
        $page->assertSee('inrelease signature verified');
    }

    public function testTestReportShowsWhenItWasCheckedAndCanBeDismissed(): void
    {
        $this->insertRow();

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [], 'count' => 0, 'core_pinned' => false], 'error' => null],
        ]));

        $report = $this->verifiedReport() + [
            'ok' => true, 'release_url' => 'https://download.example.com/debian/dists/stable/InRelease',
            'release_mode' => 'inrelease', 'checked_at' => '2026-10-06 09:41:00',
        ];

        $page = $this->withSession($this->session() + ['repo_test_report' => $report])->get('plugins/repositories');
        $page->assertSee('checked 2026-10-06 09:41:00');

        // `csrf()` primes the base session, so the report has to be put back
        // afterwards — otherwise the dismissal would be asserted against a
        // session that never held it.
        $params = $this->csrf([]);
        $this->withSession($this->session() + ['repo_test_report' => $report]);

        $dismiss = $this->post('plugins/repositories/test/clear', $params);

        $dismiss->assertRedirect();
        // The report is session state, so dismissing it leaves nothing behind.
        $dismiss->assertSessionMissing('repo_test_report');
    }

    public function testEditingARepositoryDropsTheStaleVerificationReport(): void
    {
        $this->insertRow();

        $report = $this->verifiedReport() + [
            'ok' => true, 'release_url' => 'https://download.example.com/debian/dists/stable/InRelease',
            'release_mode' => 'inrelease', 'checked_at' => '2026-10-06 09:41:00',
        ];

        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->releaseGuard());

        $params = $this->csrf($this->form(['name' => 'Renamed Vendor']));
        $this->withSession($this->session() + ['repo_test_report' => $report]);

        $saved = $this->post('plugins/repositories/store', $params);

        $saved->assertRedirect();
        // The definition just changed, so the old answer no longer describes the
        // row and the panel must not keep claiming it does.
        $saved->assertSessionMissing('repo_test_report');
    }

    public function testARejectedSubmitKeepsTheVerificationReport(): void
    {
        $this->insertRow();

        $report = $this->verifiedReport() + [
            'ok' => true, 'release_url' => 'https://download.example.com/debian/dists/stable/InRelease',
            'release_mode' => 'inrelease', 'checked_at' => '2026-10-06 09:41:00',
        ];

        $params = $this->csrf($this->form(['url' => 'http://download.example.com/debian']));
        $this->withSession($this->session() + ['repo_test_report' => $report]);

        // A plain-HTTP URL is refused by the field grammars, so nothing was
        // changed and the verification still describes the stored row. This is
        // also the control for the stale-report tests: it proves the report is
        // really in the session when the request runs.
        $refused = $this->post('plugins/repositories/store', $params);

        $refused->assertSessionHas('error');
        $refused->assertSessionHas('repo_test_report');
    }

    public function testTestActionFlashesAnErrorForAnUnreachableRepository(): void
    {
        $this->insertRow();

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);
        Services::injectMock('egressGuard', RepoFixture::routedGuard([]));

        $result = $this->post('plugins/repositories/vendor/test', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        // Nothing was fetched, so the daemon was never asked to verify anything.
        $this->assertSame([], array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.repo_test'));
    }

    public function testTestActionFlashesAnErrorForAnUnknownRepository(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->post('plugins/repositories/ghost/test', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
    }

    public function testFailedVerificationIsShownAsFailed(): void
    {
        $this->insertRow();

        $failed = [
            'ok' => false, 'success' => false, 'status' => 'error', 'id' => 'vendor',
            'message' => 'the release is signed by 0123, not the declared ABCD',
            'key' => ['declared' => 'ABCD', 'found' => ['0123'], 'matched' => false],
            'signature' => ['verified' => false, 'signer' => '', 'mode' => ''],
            'release' => [], 'warnings' => [], 'steps' => ['imported the signing key into a private keyring (1 key(s))'],
        ];

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [], 'count' => 0, 'core_pinned' => false], 'error' => null],
        ]));

        $page = $this->withSession($this->session() + ['repo_test_report' => $failed])->get('plugins/repositories');

        $page->assertOK();
        $page->assertSee('FAILED');
        $page->assertSee('the release is signed by 0123');
        $page->assertSee('TEST FAILED');
    }

    public function testRefreshOnlyAsksForMetadata(): void
    {
        $this->insertRow();

        $ipc = new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/repositories/refresh', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertSame('plugin.repo.refresh', $ipc->calls[0]['method']);
    }

    public function testUnappliedRepositoriesAreOfferedAsAnOrphanOnTheDaemon(): void
    {
        // Applied on the daemon but no longer registered here.
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => [
                'success' => true, 'status' => 'ok', 'count' => 1, 'core_pinned' => true,
                'repos'   => [['id' => 'stale', 'url' => 'https://old.example.com/debian', 'suite' => 'stable']],
            ], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins/repositories');

        $result->assertOK();
        $result->assertSee('stale');
        $result->assertSee('no longer enabled here');
    }

    public function testNavigationOffersTheRepositoriesPage(): void
    {
        $result = $this->withSession($this->session())->get('plugins');

        $result->assertOK();
        $result->assertSee('plugins/repositories');
        $result->assertSee('Repositories');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The report a daemon that verified a repository would return.
     *
     * @return array<string, mixed>
     */
    private function verifiedReport(): array
    {
        return [
            'success'   => true,
            'status'    => 'ok',
            'id'        => 'vendor',
            'url'       => 'https://download.example.com/debian',
            'suite'     => 'stable',
            'key'       => ['declared' => self::FINGERPRINT, 'found' => [self::FINGERPRINT], 'matched' => true],
            'signature' => ['verified' => true, 'signer' => self::FINGERPRINT, 'mode' => 'inrelease'],
            'release'   => [
                'origin'        => 'Vendor Repository',
                'label'         => 'Vendor',
                'suite'         => 'stable',
                'codename'      => 'vendorbook',
                'architectures' => ['amd64', 'arm64'],
                'components'    => ['main'],
            ],
            'warnings'  => [],
            'steps'     => ['imported the signing key into a private keyring (1 key(s))', 'inrelease signature verified against ' . self::FINGERPRINT],
        ];
    }

    /**
     * The report a daemon would return for the **netbird preset** — the shipped
     * fingerprint is the signer — carrying the same preset tags the controller
     * adds, so the page's preset panel has something real to render.
     *
     * @return array<string, mixed>
     */
    private function presetReport(): array
    {
        $fingerprint = 'EFE37DF047DF7CCDF1FC54FA83F79AD029778355';

        return [
            'success'   => true,
            'status'    => 'ok',
            'ok'        => true,
            'id'        => 'netbird',
            'url'       => 'https://pkgs.netbird.io/debian',
            'suite'     => 'stable',
            'key'       => ['declared' => $fingerprint, 'found' => [$fingerprint], 'matched' => true],
            'signature' => ['verified' => true, 'signer' => $fingerprint, 'mode' => 'inrelease'],
            'release'   => [
                'origin'        => 'NetBird',
                'label'         => 'NetBird',
                'suite'         => 'stable',
                'codename'      => 'stable',
                'architectures' => ['amd64', 'arm64'],
                'components'    => ['main'],
            ],
            'warnings'  => [],
            'steps'     => ['imported the signing key into a private keyring (1 key(s))'],
            // The tags {@see \App\Controllers\Packages::testPreset()} adds.
            'preset'             => 'netbird',
            'preset_name'        => 'NetBird (pkgs.netbird.io)',
            'preset_url'         => 'https://pkgs.netbird.io/debian',
            'preset_key_source'  => 'https://pkgs.netbird.io/debian/public.key',
            'preset_verified_at' => '2026-10-06',
            'checked_at'         => '2026-10-09 12:00:00',
            'release_url'        => 'https://pkgs.netbird.io/debian/dists/stable/InRelease',
            'release_mode'       => 'inrelease',
        ];
    }

    // ------------------------------------------------------------------
    // Preset repositories (the Class C vendor repositories)
    // ------------------------------------------------------------------

    public function testThePresetsPanelOffersTheShippedPresetWithItsVerifiedFingerprint(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $page = $this->withSession($this->session())->get('plugins/repositories');

        $page->assertOK();
        $page->assertSee('Preset repositories');
        $page->assertSee('NetBird (pkgs.netbird.io)');
        $page->assertSee('https://pkgs.netbird.io/debian');
        $page->assertSee('EFE37DF047DF7CCDF1FC54FA83F79AD029778355');
        // The provenance is on the page, so an operator can re-derive the
        // fingerprint rather than take it on faith.
        $page->assertSee('pkgs.netbird.io/debian/public.key');
        $page->assertSee('needed by netbird');
        // The second vendor repository §11 needs, with the same provenance.
        $page->assertSee('ZeroTier One (download.zerotier.com)');
        $page->assertSee('https://download.zerotier.com/debian/trixie');
        $page->assertSee('74A5E9C458E1A431F1DA57A71657198823E52A61');
        $page->assertSee('download.zerotier.com/contact%40zerotier.com.gpg');
        $page->assertSee('needed by zerotier');
        $page->assertSee('Register preset');
        // Registering is not trusting: a preset is not a live source yet.
        $page->assertSee('nothing is fetched from the vendor until you enable it and apply');
    }

    public function testRegisteringTheZerotierPresetLandsItDisabled(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->post('plugins/repositories/preset/zerotier', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');

        $row = $this->db->table('plugin_repos')->where('id', 'zerotier')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('https://download.zerotier.com/debian/trixie', $row['url']);
        $this->assertSame('trixie', $row['suite']);
        $this->assertSame('74A5E9C458E1A431F1DA57A71657198823E52A61', $row['key_fingerprint']);
        $this->assertSame(0, (int) $row['enabled'], 'a preset must not be enabled by registering it');
    }

    public function testRegisteringAPresetLandsItDisabled(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->post('plugins/repositories/preset/netbird', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');

        $row = $this->db->table('plugin_repos')->where('id', 'netbird')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('https://pkgs.netbird.io/debian', $row['url']);
        $this->assertSame('stable', $row['suite']);
        $this->assertSame('EFE37DF047DF7CCDF1FC54FA83F79AD029778355', $row['key_fingerprint']);
        $this->assertSame(0, (int) $row['enabled'], 'a preset must not be enabled by registering it');
        $this->assertSame('Ministrator', $row['added_by']);

        $page = $this->withSession($this->session())->get('plugins/repositories');
        $page->assertSee('REGISTERED');
        $page->assertSee('DISABLED');
        $page->assertSee('DISABLED');
    }

    public function testRegisteringAPresetNeverRewritesAStoredFingerprint(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $this->post('plugins/repositories/preset/netbird', $this->csrf([]))->assertRedirect();

        // An operator who pinned something else — after a key rotation, say —
        // must not have it silently replaced by the preset's value.
        $this->db->table('plugin_repos')->where('id', 'netbird')
            ->update(['key_fingerprint' => '0F1E2D3C4B5A69788796A5B4C3D2E1F00A1B2C3D']);

        $again = $this->post('plugins/repositories/preset/netbird', $this->csrf([]));

        $again->assertRedirect();
        $again->assertSessionHas('success');
        $this->assertSame(
            '0F1E2D3C4B5A69788796A5B4C3D2E1F00A1B2C3D',
            $this->db->table('plugin_repos')->where('id', 'netbird')->get()->getRowArray()['key_fingerprint'],
        );
    }

    /**
     * `zenarmor` is the sentinel deliberately: §11 names it as a Class C
     * integration whose repository is a credentialed vendor service, so no
     * preset can exist for it. See {@see \App\Libraries\RepoPresets}.
     */
    public function testAnUnknownPresetIsRefused(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->post('plugins/repositories/preset/zenarmor', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Proving a preset against its vendor (the read-only live check)
    // ------------------------------------------------------------------

    /**
     * A preset's value is the claim that the fingerprint it declares really signs
     * the vendor's metadata, so the check must be runnable *before* the repository
     * is registered — and must not register it as a side effect.
     */
    public function testVerifyingAPresetProvesTheShippedTrustDataWithoutRegisteringIt(): void
    {
        $ipc = new FakeIpcClient(['plugin.repo_test' => ['result' => $this->presetReport(), 'error' => null]]);
        Services::injectMock('ipc', $ipc);
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $result = $this->post('plugins/repositories/preset/netbird/test', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');

        // The daemon was asked about the preset's own URL, suite and fingerprint...
        $this->assertSame('plugin.repo_test', $ipc->calls[0]['method']);
        $this->assertSame('https://pkgs.netbird.io/debian', $ipc->calls[0]['params']['repo']['url']);
        $this->assertSame('stable', $ipc->calls[0]['params']['repo']['suite']);
        $this->assertSame('EFE37DF047DF7CCDF1FC54FA83F79AD029778355', $ipc->calls[0]['params']['repo']['fingerprint']);
        // ...and the key travelled inline (the shipped armor), so no key fetch.
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY', (string) $ipc->calls[0]['params']['repo']['key_armor']);
        // Proving shipped trust data is not adopting it: nothing was registered.
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    public function testVerifyingAPresetAgainstAnUnreachableVendorFailsWithoutRegisteringIt(): void
    {
        Services::injectMock('ipc', $this->verifyingIpc());
        Services::injectMock('egressGuard', $this->unreachableGuard());

        $result = $this->post('plugins/repositories/preset/netbird/test', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        // The report is kept so the panel can show *why* it failed, exactly as
        // the per-row Test does.
        $result->assertSessionHas('repo_test_report');
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    /**
     * A preset check is about the image's shipped trust data, not about the row an
     * operator registered — so it must not stamp a verification onto a repository
     * it never examined. A later drift sweep then still calls a genuine failure a
     * *lost* verification rather than a fresh one.
     */
    public function testVerifyingAPresetLeavesARegisteredRowsVerificationStampUntouched(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());
        $this->post('plugins/repositories/preset/netbird', $this->csrf([]))->assertRedirect();
        $this->db->table('plugin_repos')->where('id', 'netbird')->update(['last_verified_at' => '2020-01-01 00:00:00']);

        Services::injectMock('ipc', new FakeIpcClient(['plugin.repo_test' => ['result' => $this->presetReport(), 'error' => null]]));
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $this->post('plugins/repositories/preset/netbird/test', $this->csrf([]))->assertRedirect();

        $this->assertSame(
            '2020-01-01 00:00:00',
            $this->db->table('plugin_repos')->where('id', 'netbird')->get()->getRowArray()['last_verified_at'],
        );
    }

    /**
     * `zenarmor` is again the sentinel: no preset can exist for it, so the check
     * must refuse rather than report success for something it never looked at.
     */
    public function testVerifyingAnUnknownPresetIsRefused(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->post('plugins/repositories/preset/zenarmor/test', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
        // Nothing was checked, so there is no report to show.
        $result->assertSessionMissing('repo_test_report');
    }

    public function testThePresetCheckIsOfferedAndItsReportRendersAsAPresetReport(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $page = $this->withSession($this->session() + ['repo_test_report' => $this->presetReport()])
            ->get('plugins/repositories');

        $page->assertOK();
        // Offered on every preset, registered or not.
        $page->assertSee('Verify against vendor');
        $page->assertSee('plugins/repositories/preset/netbird/test');
        $page->assertSee('plugins/repositories/preset/zerotier/test');
        // The report says *what* it is about: the image's shipped trust data,
        // not a repository the operator registered.
        $page->assertSee('Preset verification — NetBird (pkgs.netbird.io)');
        $page->assertSee('was not registered, enabled or applied');
        $page->assertSee('the image records it as verified 2026-10-06');
        $page->assertSee('VERIFIED');
    }

    // ------------------------------------------------------------------
    // Repository drift (the scheduled half, and the on-demand sweep)
    // ------------------------------------------------------------------

    public function testDriftActionRefreshesAndReportsNoDrift(): void
    {
        $this->insertRow();

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok'], 'error' => null],
            'plugin.repo_test'    => ['result' => $this->verifiedReport(), 'error' => null],
        ]));
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $result = $this->post('plugins/repositories/drift', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
        $this->assertNotSame('', (string) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['last_check_at']);
    }

    public function testDriftActionFlagsDriftingRepositoriesAsAWarning(): void
    {
        $this->insertRow();

        // Metadata that is old but not yet invalid: the repository is not
        // broken, it is quiet — a warning, not a critical.
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok'], 'error' => null],
            'plugin.repo_test'    => ['result' => [
                'release' => [
                    'origin'      => 'Vendor Repository',
                    'suite'       => 'stable',
                    'codename'    => 'vendorbook',
                    'date'        => 'Fri, 01 May 2026 00:00:00 UTC',
                    'valid_until' => gmdate('D, d M Y H:i:s', time() + 30 * 86400) . ' GMT',
                ],
            ] + $this->verifiedReport(), 'error' => null],
        ]));
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $result = $this->post('plugins/repositories/drift', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('warning');
        $this->assertSame('metadata_stale', $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['drift_kinds']);
    }

    public function testDriftActionReportsAFailedMetadataRefresh(): void
    {
        $this->insertRow();

        // A verdict drawn from metadata that could not be fetched is a statement
        // about the fetch, not about the vendor, and must not be presented as one.
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo.refresh' => ['error' => ['code' => -1, 'message' => 'apt-get update failed'], 'result' => null],
            'plugin.repo_test'    => ['result' => $this->verifiedReport(), 'error' => null],
        ]));
        Services::injectMock('egressGuard', RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $result = $this->post('plugins/repositories/drift', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
    }

    public function testDriftActionSaysSoWhenThereIsNothingToCheck(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok'], 'error' => null],
        ]));

        $result = $this->post('plugins/repositories/drift', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testIndexRendersTheDriftPanelAndTheDriftColumn(): void
    {
        $this->insertRow([
            'drift_kinds'    => 'metadata_stale',
            'drift_since'    => '2026-10-01 00:00:00',
            'drift_detail'   => 'WARNING — Metadata stale: The release metadata is 45 days old (last published 2026-05-01).',
            'last_check_at'  => '2026-10-06 04:45:00',
            'key_expires_at' => '2027-01-01 00:00:00',
        ]);

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [], 'count' => 0, 'core_pinned' => false], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/repositories');

        $page->assertOK();
        // The header badge, the panel, and the column all read from the same
        // stored verdict, so the page cannot disagree with itself.
        $page->assertSee('1 DRIFTING');
        $page->assertSee('Repository drift');
        $page->assertSee('Check Drift Now');
        $page->assertSee('Metadata stale');
        $page->assertSee('flagged since 2026-10-01 00:00:00');
        $page->assertSee('45 days old');
        $page->assertSee('Drift');
        $page->assertSee('since 2026-10-01 00:00:00');
        // The schedule is surfaced, because a badge nobody refreshes looks like
        // an assurance. Asserted through the link rather than the schedule text
        // so this does not depend on whether the drift task has run yet.
        $page->assertSee('Scheduled tasks');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'id'              => 'vendor',
            'name'            => 'Vendor APT',
            'url'             => 'https://download.example.com/debian',
            'suite'           => 'stable',
            'components'      => 'main',
            'architectures'   => 'amd64',
            'key_fingerprint' => self::FINGERPRINT,
            'key_armor'       => $this->armor(),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRow(array $overrides = []): void
    {
        $this->db->table('plugin_repos')->insert($overrides + [
            'id'              => 'vendor',
            'name'            => 'Vendor APT',
            'url'             => 'https://download.example.com/debian',
            'suite'           => 'stable',
            'components'      => 'main',
            'architectures'   => 'amd64',
            'key_fingerprint' => self::FINGERPRINT,
            'key_url'         => '',
            'key_armor'       => $this->armor(),
            'enabled'         => 1,
        ]);
    }

    private function armor(): string
    {
        return "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n"
            . base64_encode('micron test key') . "\n"
            . "-----END PGP PUBLIC KEY BLOCK-----\n";
    }

    /** A daemon that verified the release against the declared key. */
    private function verifyingIpc(): FakeIpcClient
    {
        return new FakeIpcClient(['plugin.repo_test' => ['result' => $this->verifiedReport(), 'error' => null]]);
    }

    /** A daemon that found the signing key does not match the declared fingerprint. */
    private function mismatchedIpc(): FakeIpcClient
    {
        return new FakeIpcClient(['plugin.repo_test' => ['result' => [
            'success'   => false,
            'status'    => 'error',
            'reason'    => 'fingerprint_mismatch',
            'message'   => 'the imported key does not match the declared fingerprint',
            'key'       => ['declared' => self::FINGERPRINT, 'found' => ['DEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEF'], 'matched' => false],
            'signature' => ['verified' => false, 'signer' => '', 'mode' => ''],
            'release'   => [],
            'warnings'  => [],
            'steps'     => [],
        ], 'error' => null]]);
    }

    /** An egress guard that serves signed release metadata. */
    private function releaseGuard(): object
    {
        return RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]);
    }

    /** An egress guard that reaches nothing. */
    private function unreachableGuard(): object
    {
        return RepoFixture::routedGuard([]);
    }

    private function clearRepos(): void
    {
        if ($this->db->tableExists('plugin_repos')) {
            $this->db->table('plugin_repos')->emptyTable();
        }
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
}
