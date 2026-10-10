<?php

declare(strict_types=1);

use App\Libraries\PackageManager;
use App\Libraries\RepoRegistry;
use App\Plugins\PluginRegistry;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\CatalogueFixture;
use Tests\Support\Libraries\FakeIpcClient;
use Tests\Support\Libraries\RepoFixture;

/**
 * Curated plugin repositories (Plugin Spec §5.2): the field grammars, the
 * generated `deb822` stanza and apt pin, the plan's conflicts, the declarative
 * apply and the resolution of a manifest's `requires.repos`.
 *
 * An apt repository is arbitrary root code, so the validation tests are the
 * point of this file: everything the operator types must be unable to become
 * something other than a single, well-formed, signed and pinned source.
 *
 * @internal
 */
final class RepoRegistryTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const FINGERPRINT = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';

    private string $definitions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRepos();

        $this->definitions = sys_get_temp_dir() . '/micron_repo_defs_' . bin2hex(random_bytes(4));
        mkdir($this->definitions, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->clearRepos();
        $this->removeDirectory($this->definitions);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function testNormalizeAcceptsAFullySpecifiedRepository(): void
    {
        $result = RepoRegistry::normalize([
            'id'              => 'ZeroTier-One',
            'name'            => 'ZeroTier One',
            'url'             => 'https://download.example.com/debian/stable',
            'suite'           => 'stable',
            'components'      => 'main, contrib',
            'architectures'   => 'amd64, arm64',
            // gpg prints a fingerprint in groups of four; both spellings are accepted.
            'key_fingerprint' => implode(' ', str_split(self::FINGERPRINT, 4)),
            'key_armor'       => $this->armor(),
        ]);

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));

        $repo = $result['repo'];
        $this->assertSame('zerotier-one', $repo['id']);
        $this->assertSame('download.example.com', $repo['origin']);
        $this->assertSame(['main', 'contrib'], $repo['components']);
        $this->assertSame(['amd64', 'arm64'], $repo['architectures']);
        // gpg prints fingerprints in groups of four; the stored form is bare hex.
        $this->assertSame('ABCDEF0123456789ABCDEF0123456789ABCDEF01', $repo['fingerprint']);
        $this->assertTrue($repo['has_key']);
        $this->assertFalse($repo['flat']);
    }

    /**
     * Everything an operator might paste that must not become a source stanza.
     *
     * @param array<string, mixed> $overrides
     *
     * @dataProvider refusedInputs
     */
    public function testNormalizeRefusesUnsafeInput(array $overrides, string $expect): void
    {
        $base = [
            'id'              => 'vendor',
            'url'             => 'https://download.example.com/debian',
            'suite'           => 'stable',
            'components'      => 'main',
            'architectures'   => 'amd64',
            'key_fingerprint' => self::FINGERPRINT,
            'key_armor'       => $this->armor(),
        ];

        $result = RepoRegistry::normalize($overrides + $base);

        $this->assertFalse($result['ok'], 'Input was accepted: ' . json_encode($overrides));
        $this->assertStringContainsString($expect, implode(' ', $result['errors']));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedInputs(): array
    {
        return [
            'plain http'          => [['url' => 'http://download.example.com/debian'], 'https'],
            'credentials in URL'  => [['url' => 'https://user:pass@download.example.com/debian'], 'https'],
            'apt option injection' => [['url' => 'https://download.example.com/debian [trusted=yes]'], 'https'],
            'query string'        => [['url' => 'https://download.example.com/debian?arch=amd64'], 'https'],
            'traversal'           => [['url' => 'https://download.example.com/../../etc'], 'https'],
            'whitespace'          => [['url' => "https://download.example.com/debian\nx"], 'https'],
            'distro host'         => [['url' => 'https://deb.debian.org/debian'], 'own distribution'],
            'option in suite'     => [['suite' => 'stable; rm -rf /'], 'suite'],
            'bad component'       => [['components' => 'main$'], 'component'],
            'bad architecture'    => [['architectures' => 'sparc64'], 'architecture'],
            'short fingerprint'   => [['key_fingerprint' => 'ABCDEF01'], 'fingerprint'],
            'non-hex fingerprint' => [['key_fingerprint' => str_repeat('Z', 40)], 'fingerprint'],
            'missing key'         => [['key_armor' => '', 'key_url' => ''], 'signing key is required'],
            'two key sources'     => [['key_url' => 'https://download.example.com/key.asc'], 'not both'],
            'bad key url'         => [['key_armor' => '', 'key_url' => 'http://download.example.com/key.asc'], 'key URL'],
            'armor not armored'   => [['key_armor' => 'just some text'], 'armored'],
            'bad id'              => [['id' => 'Vendor/../x'], 'repository id'],
        ];
    }

    public function testFlatRepositoryCarriesNoComponents(): void
    {
        $result = RepoRegistry::normalize([
            'id'              => 'flat',
            'url'             => 'https://download.example.com/apt',
            'suite'           => './',
            'components'      => 'main',
            'key_fingerprint' => self::FINGERPRINT,
            'key_armor'       => $this->armor(),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('flat', implode(' ', $result['errors']));

        $flat = RepoRegistry::normalize([
            'id'              => 'flat',
            'url'             => 'https://download.example.com/apt',
            'suite'           => './',
            'key_fingerprint' => self::FINGERPRINT,
            'key_armor'       => $this->armor(),
        ]);

        $this->assertTrue($flat['ok'], implode(' ', $flat['errors']));
        $this->assertTrue($flat['repo']['flat']);
        $this->assertStringNotContainsString('Components:', RepoRegistry::sourceStanza($flat['repo']));
    }

    // ------------------------------------------------------------------
    // Generated files
    // ------------------------------------------------------------------

    public function testSourceStanzaIsSignedDebOnly(): void
    {
        $repo = $this->normalized();

        $stanza = RepoRegistry::sourceStanza($repo);

        $this->assertStringContainsString("Types: deb\n", $stanza);
        $this->assertStringContainsString('URIs: https://download.example.com/debian', $stanza);
        $this->assertStringContainsString('Suites: stable', $stanza);
        $this->assertStringContainsString('Components: main', $stanza);
        $this->assertStringContainsString('Architectures: amd64', $stanza);
        $this->assertStringContainsString('Signed-By: /etc/apt/keyrings/micron-vendor.gpg', $stanza);
        // A source repository is never generated, and no apt option syntax is
        // writable, so `[trusted=yes]` cannot be expressed at all.
        $this->assertStringNotContainsString('deb-src', $stanza);
        $this->assertStringNotContainsString('[', $stanza);
    }

    public function testPinStanzaHoldsTheRepositoryBelowTheDistribution(): void
    {
        $pin = RepoRegistry::pinStanza($this->normalized());

        $this->assertStringContainsString('Package: *', $pin);
        $this->assertStringContainsString('Pin: origin "download.example.com"', $pin);
        // 100 sits below the distribution's own 500.
        $this->assertStringContainsString('Pin-Priority: 100', $pin);
        $this->assertSame(100, RepoRegistry::PIN_PRIORITY);
    }

    public function testGeneratedPathsAreNamespaced(): void
    {
        $this->assertSame('/etc/apt/sources.list.d/micron-vendor.sources', RepoRegistry::sourcesPath('vendor'));
        $this->assertSame('/etc/apt/preferences.d/micron-vendor.pref', RepoRegistry::preferencesPath('vendor'));
        $this->assertSame('/etc/apt/keyrings/micron-vendor.gpg', RepoRegistry::keyringPath('vendor'));
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    public function testSaveRefusesAnInvalidRepositoryWithoutWriting(): void
    {
        $result = $this->registry()->save(['id' => 'vendor', 'url' => 'ftp://example.com']);

        $this->assertFalse($result['ok']);
        $this->assertNotSame([], $result['errors']);
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());
    }

    public function testSaveUpsertsAndIsIdempotent(): void
    {
        $registry = $this->registry();

        $first = $registry->save($this->input(), 'Ministrator');
        $this->assertTrue($first['ok'], implode(' ', $first['errors']));

        $second = $registry->save($this->input(['name' => 'Renamed Vendor']), 'Ministrator');
        $this->assertTrue($second['ok'], implode(' ', $second['errors']));

        $this->assertSame(1, $this->db->table('plugin_repos')->countAllResults());
        $this->assertSame('Renamed Vendor', (string) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['name']);
        $this->assertSame('Ministrator', (string) $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray()['added_by']);
    }

    public function testToggleAndDelete(): void
    {
        $registry = $this->registry();
        $registry->save($this->input());

        $this->assertTrue($registry->setEnabled('vendor', false));
        $this->assertFalse($registry->get('vendor')['enabled']);
        $this->assertSame([], $registry->enabled());

        $this->assertFalse($registry->setEnabled('ghost', true));
        $this->assertTrue($registry->delete('vendor'));
        $this->assertNull($registry->get('vendor'));
    }

    // ------------------------------------------------------------------
    // The plan
    // ------------------------------------------------------------------

    public function testPlanIsReadyForAValidRepository(): void
    {
        $this->registry()->save($this->input());

        $plan = $this->registry()->plan();

        $this->assertTrue($plan['ready'], implode(' ', $plan['conflicts']));
        $this->assertSame(1, $plan['registered']);
        $this->assertSame(1, $plan['enabled']);
        $this->assertCount(1, $plan['repos']);
        $this->assertStringContainsString('Pin-Priority: 100', $plan['repos'][0]['pin']);
    }

    public function testPlanConflictsOnDuplicateSources(): void
    {
        $this->insertRow(['id' => 'one']);
        $this->insertRow(['id' => 'two']);

        $plan = $this->registry()->plan();

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('same source', implode(' ', $plan['conflicts']));
    }

    public function testPlanConflictsOnARepositoryThatShadowsTheDistribution(): void
    {
        // A row that predates validation (or was inserted another way) must not
        // become a live source just because it is enabled.
        $this->insertRow(['id' => 'shadow', 'url' => 'https://deb.debian.org/debian']);

        $plan = $this->registry()->plan();

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('own distribution', implode(' ', $plan['conflicts']));
    }

    public function testPlanConflictsOnAnEnabledRepositoryWithNoKey(): void
    {
        $this->insertRow(['id' => 'keyless', 'key_armor' => '', 'key_url' => '', 'key_fingerprint' => '']);

        $plan = $this->registry()->plan();

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('no signing key', implode(' ', $plan['conflicts']));
    }

    public function testPlanWarnsWhenTwoRepositoriesShareASigningKey(): void
    {
        $this->insertRow(['id' => 'one']);
        $this->insertRow(['id' => 'two', 'url' => 'https://download.example.com/other']);

        $plan = $this->registry()->plan();

        $this->assertTrue($plan['ready'], implode(' ', $plan['conflicts']));
        $this->assertStringContainsString('share signing key', implode(' ', $plan['warnings']));
    }

    public function testDisabledRepositoriesAreNotCompiled(): void
    {
        $this->registry()->save($this->input());
        $this->registry()->setEnabled('vendor', false);

        $plan = $this->registry()->plan();

        $this->assertSame(1, $plan['registered']);
        $this->assertSame(0, $plan['enabled']);
        $this->assertSame([], $plan['repos']);
        $this->assertTrue($plan['ready']);
    }

    // ------------------------------------------------------------------
    // Apply
    // ------------------------------------------------------------------

    public function testApplyDispatchesEnabledRepositoriesAndRefreshesState(): void
    {
        $this->registry()->save($this->input());

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => [
                'success' => true, 'status' => 'applied',
                'repos' => [['id' => 'vendor']], 'steps' => [], 'warnings' => [],
            ], 'error' => null],
        ]);

        $result = (new RepoRegistry($ipc, $this->db, CatalogueFixture::guard('')))->apply();

        $this->assertTrue($result['success']);
        $this->assertSame('plugin.repo.apply', $ipc->calls[0]['method']);

        $payload = $ipc->calls[0]['params']['repos'];
        $this->assertCount(1, $payload);
        $this->assertSame('vendor', $payload[0]['id']);
        $this->assertSame(self::FINGERPRINT, $payload[0]['fingerprint']);
        // The key travels as material, never as a URL the daemon would fetch.
        $this->assertNotSame('', trim((string) $payload[0]['key_armor']));

        $row = $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray();
        $this->assertSame('ok', $row['last_outcome']);
        $this->assertNotNull($row['last_refresh']);
    }

    public function testFetchedKeyUrlsAreVerifiedAndCachedAfterASuccessfulApply(): void
    {
        $armor = $this->armor('FETCHED');
        $this->registry()->save($this->input(['key_armor' => '', 'key_url' => 'https://download.example.com/debian/key.asc']));

        $ipc = new FakeIpcClient([
            'plugin.repo.apply' => ['result' => ['success' => true, 'status' => 'applied', 'repos' => [], 'steps' => [], 'warnings' => []], 'error' => null],
        ]);

        $result = (new RepoRegistry($ipc, $this->db, CatalogueFixture::guard($armor)))->apply();

        $this->assertTrue($result['success']);
        $fetched = trim((string) $ipc->calls[0]['params']['repos'][0]['key_armor']);
        $this->assertSame(trim($armor), $fetched);

        // Only a successful apply (which means the daemon matched the
        // fingerprint) caches the fetched key inline.
        $row = $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray();
        $this->assertSame(trim($armor), trim((string) $row['key_armor']));
    }

    public function testARepositoryWithAnUnfetchableKeyIsNotAppliedAtAll(): void
    {
        $this->registry()->save($this->input(['key_armor' => '', 'key_url' => 'https://download.example.com/debian/key.asc']));

        $ipc = new FakeIpcClient();
        $result = (new RepoRegistry($ipc, $this->db, CatalogueFixture::guard('not a PGP key')))->apply();

        $this->assertFalse($result['success']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame([], $ipc->calls, 'A repository whose key cannot be verified must never reach the daemon.');

        $row = $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray();
        $this->assertSame('error', $row['last_outcome']);
    }

    public function testApplyIsBlockedWithoutCallingTheDaemonWhenThePlanConflicts(): void
    {
        $this->insertRow(['id' => 'one']);
        $this->insertRow(['id' => 'two']);

        $ipc = new FakeIpcClient();
        $result = (new RepoRegistry($ipc, $this->db, CatalogueFixture::guard('')))->apply();

        $this->assertFalse($result['success']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame([], $ipc->calls);
    }

    public function testDaemonStatusReadsThroughTheBridge(): void
    {
        $ipc = new FakeIpcClient([
            'plugin.repo_status' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => [['id' => 'vendor']], 'count' => 1, 'core_pinned' => true], 'error' => null],
        ]);

        $status = (new RepoRegistry($ipc, $this->db, CatalogueFixture::guard('')))->daemonStatus();

        $this->assertTrue($status['core_pinned']);
        $this->assertSame('plugin.repo_status', $ipc->calls[0]['method']);
    }

    // ------------------------------------------------------------------
    // requires.repos resolution
    // ------------------------------------------------------------------

    public function testSatisfiedReportsUnregisteredAndDisabledRepositories(): void
    {
        $this->assertSame(['vendor'], $this->registry()->satisfied(['vendor'])['missing']);

        $this->registry()->save($this->input());
        $this->assertTrue($this->registry()->satisfied(['vendor'])['ok']);

        $this->registry()->setEnabled('vendor', false);
        $this->assertSame(['vendor'], $this->registry()->satisfied(['vendor'])['missing']);
    }

    public function testAManifestRequiringARepositoryIsBlockedUntilItIsRegistered(): void
    {
        $this->writePlugin('vendored', [
            'schema'       => 1,
            'id'           => 'vendored',
            'name'         => 'Vendored Overlay Agent',
            'version'      => '1.0.0',
            'class'        => 'C',
            'requires'     => ['packages' => ['vendored-agent'], 'repos' => ['vendor']],
            'capabilities' => [],
            'lifecycle'    => [['step' => 'install', 'action' => 'apt.install', 'packages' => ['vendored-agent']]],
        ]);

        $manager = new PackageManager(
            new FakeIpcClient(),
            new PluginRegistry($this->definitions),
            new RepoRegistry(new FakeIpcClient(), $this->db, CatalogueFixture::guard('')),
        );

        $plan = $manager->plan('vendored');
        $this->assertFalse($plan['ok']);
        $this->assertStringContainsString('repository "vendor"', implode(' ', $plan['blocked']));
        $this->assertSame(['vendor'], $plan['repos']);

        // Register and enable the repository: the same plugin becomes installable.
        $saved = $manager->repositories()->save($this->input());
        $this->assertTrue($saved['ok'], implode(' ', $saved['errors']));

        $plan = $manager->plan('vendored');
        $this->assertTrue($plan['ok'], implode(' ', $plan['blocked']));
    }

    // ------------------------------------------------------------------
    // Verification (read-only)
    // ------------------------------------------------------------------

    public function testVerificationChecksTheReleaseMetadataThroughTheDaemon(): void
    {
        $this->insertRow();

        $ipc = $this->verifyingIpc('imported the signing key into a private keyring');
        $registry = new RepoRegistry($ipc, $this->db, RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));

        $report = $registry->test('vendor');

        $this->assertTrue($report['ok'], (string) ($report['message'] ?? ''));
        $this->assertSame('plugin.repo_test', $ipc->calls[0]['method']);

        $params = $ipc->calls[0]['params'];
        // The release metadata and the key material travel to the daemon, which
        // is the only place the signature can actually be checked.
        $this->assertStringContainsString('Suite: stable', (string) $params['release']);
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', (string) $params['repo']['key_armor']);
        $this->assertSame(self::FINGERPRINT, $params['repo']['fingerprint']);
        // A clearsigned document needs no detached signature.
        $this->assertSame('', (string) $params['signature']);

        $this->assertSame('inrelease', $report['release_mode']);
        $this->assertSame('/debian/dists/stable/InRelease', (string) parse_url((string) $report['release_url'], PHP_URL_PATH));
        // A verification is a statement about one definition at one moment, so
        // the report says when it was made.
        $this->assertNotSame('', (string) $report['checked_at']);
        $this->assertNotFalse(strtotime((string) $report['checked_at']));
    }

    public function testVerificationFallsBackToADetachedReleaseAndSignature(): void
    {
        $this->insertRow();

        // InRelease is absent (the default 404); the older detached pair is not.
        $ipc = $this->verifyingIpc();
        $registry = new RepoRegistry($ipc, $this->db, RepoFixture::routedGuard([
            '/debian/dists/stable/Release'     => [200, RepoFixture::inRelease()],
            '/debian/dists/stable/Release.gpg' => [200, RepoFixture::detachedSignature()],
        ]));

        $report = $registry->test('vendor');

        $this->assertTrue($report['ok'], (string) ($report['message'] ?? ''));
        $this->assertSame('release', $report['release_mode']);
        $this->assertStringContainsString('BEGIN PGP SIGNATURE', (string) $ipc->calls[0]['params']['signature']);
    }

    public function testVerificationOfAnUnreachableSourceNeverReachesTheDaemon(): void
    {
        $this->insertRow();

        $ipc = new FakeIpcClient();
        $report = (new RepoRegistry($ipc, $this->db, RepoFixture::routedGuard([])))->test('vendor');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('did not serve signed release metadata', (string) $report['message']);
        $this->assertSame([], $ipc->calls);
        // A failure still answers in the report's own shape, so the view never
        // has to guess which fields exist.
        $this->assertSame(['verified' => false, 'signer' => '', 'mode' => ''], $report['signature']);
        $this->assertNotEquals('', (string) $report['checked_at']);
        $this->assertFalse($report['key']['matched']);
    }

    public function testVerificationRefusesAKeyUrlThatIsNotAKey(): void
    {
        $this->insertRow(['key_url' => 'https://download.example.com/debian/key.asc', 'key_armor' => '']);

        $ipc = new FakeIpcClient();
        $registry = new RepoRegistry($ipc, $this->db, RepoFixture::routedGuard([
            '/debian/key.asc' => [200, 'this is not a PGP key'],
        ]));

        $report = $registry->test('vendor');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('ASCII-armored', (string) $report['message']);
        $this->assertSame([], $ipc->calls);
    }

    public function testVerificationResolvesAKeyUrlThroughTheEgressGuard(): void
    {
        $this->insertRow(['key_url' => 'https://download.example.com/debian/key.asc', 'key_armor' => '']);

        $armor = RepoFixture::armor('fetched-for-verification');
        $ipc   = $this->verifyingIpc();
        $registry = new RepoRegistry($ipc, $this->db, RepoFixture::routedGuard([
            '/debian/key.asc'                  => [200, $armor],
            '/debian/dists/stable/InRelease'  => [200, RepoFixture::inRelease()],
        ]));

        $report = $registry->test('vendor');

        $this->assertTrue($report['ok'], (string) ($report['message'] ?? ''));
        $this->assertSame(trim($armor), trim((string) $ipc->calls[0]['params']['repo']['key_armor']));

        // A *test* must not cache the key or record an outcome: only an apply, in
        // which the daemon matched the fingerprint, may mutate the registry.
        $row = $this->db->table('plugin_repos')->where('id', 'vendor')->get()->getRowArray();
        $this->assertSame('', (string) $row['key_armor']);
        $this->assertSame('', (string) $row['last_outcome']);
        $this->assertNull($row['last_refresh']);
    }

    public function testVerificationRefusesAnOversizedRelease(): void
    {
        $this->insertRow();

        $ipc = new FakeIpcClient();
        $registry = new RepoRegistry($ipc, $this->db, RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, str_repeat('A', RepoRegistry::MAX_RELEASE_BYTES + 1)],
        ]));

        $report = $registry->test('vendor');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('2048 KiB limit', (string) $report['message']);
        $this->assertSame([], $ipc->calls);
    }

    public function testVerificationOfAnUnknownRepository(): void
    {
        $report = $this->registry()->test('ghost');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('unknown repository', (string) $report['message']);
    }

    public function testVerificationRefusesARepositoryWithNoKey(): void
    {
        $this->insertRow(['key_url' => '', 'key_armor' => '', 'key_fingerprint' => '']);

        $report = $this->registry()->test('vendor');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('no signing key', (string) $report['message']);
    }

    public function testReleaseCandidatesUseDistsForANamedSuiteAndTheRootForFlat(): void
    {
        $named = RepoRegistry::releaseCandidates(['url' => 'https://dl.example.com/debian/', 'suite' => 'stable']);
        $this->assertSame('https://dl.example.com/debian/dists/stable/InRelease', $named[0]['url']);
        $this->assertSame('https://dl.example.com/debian/dists/stable/Release', $named[1]['url']);
        $this->assertSame('https://dl.example.com/debian/dists/stable/Release.gpg', $named[1]['signature_url']);

        // A flat repository keeps its release metadata at the root, not under dists.
        $flat = RepoRegistry::releaseCandidates(['url' => 'https://dl.example.com/apt/', 'suite' => './']);
        $this->assertSame('https://dl.example.com/apt/InRelease', $flat[0]['url']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * An IPC double that answers `plugin.repo_test` the way a daemon that
     * verified the release would.
     */
    private function verifyingIpc(string $step = 'verified'): FakeIpcClient
    {
        return new FakeIpcClient([
            'plugin.repo_test' => ['result' => [
                'success'   => true,
                'status'    => 'ok',
                'id'        => 'vendor',
                'url'       => 'https://download.example.com/debian',
                'suite'     => 'stable',
                'key'       => ['declared' => self::FINGERPRINT, 'found' => [self::FINGERPRINT], 'matched' => true],
                'signature' => ['verified' => true, 'signer' => self::FINGERPRINT, 'mode' => 'inrelease'],
                'release'   => [
                    'origin'        => 'Vendor Repository',
                    'suite'         => 'stable',
                    'codename'      => 'vendorbook',
                    'architectures' => ['amd64', 'arm64'],
                    'components'    => ['main'],
                ],
                'warnings'  => [],
                'steps'     => [$step],
            ], 'error' => null],
        ]);
    }

    private function registry(): RepoRegistry
    {
        return new RepoRegistry(new FakeIpcClient(), $this->db, CatalogueFixture::guard(''));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
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
     * @return array<string, mixed>
     */
    private function normalized(): array
    {
        return RepoRegistry::normalize($this->input())['repo'];
    }

    private function armor(string $label = 'TEST'): string
    {
        return "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n"
            . base64_encode($label . ' micron test key') . "\n"
            . "-----END PGP PUBLIC KEY BLOCK-----\n";
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

    /**
     * @param array<string, mixed> $manifest
     */
    private function writePlugin(string $id, array $manifest): void
    {
        mkdir($this->definitions . '/' . $id, 0700, true);
        file_put_contents($this->definitions . '/' . $id . '/plugin.json', json_encode($manifest));
    }

    private function clearRepos(): void
    {
        if ($this->db->tableExists('plugin_repos')) {
            $this->db->table('plugin_repos')->emptyTable();
        }
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path . '/*/plugin.json') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($path . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub);
        }
        @rmdir($path);
    }
}
