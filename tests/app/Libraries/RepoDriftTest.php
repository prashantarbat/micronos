<?php

declare(strict_types=1);

use App\Libraries\RepoDrift;
use App\Libraries\RepoRegistry;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;
use Tests\Support\Libraries\RepoFixture;

/**
 * Repository drift detection (Plugin Spec §5.2, §9.5).
 *
 * The interesting property is that a repository can be *perfectly configured*
 * and still be quietly dead, so most of these tests are about what a single
 * observation cannot see: a metadata `Date:` that never advances, a signing key
 * whose own validity is running out, and a key that has been rotated out from
 * under the declared fingerprint.
 *
 * `evaluate()` is pure — row + test report + clock in, verdict out — so the
 * thresholds are pinned here without a network, a daemon or a database. The
 * `observe()`/`sweep()` tests then prove the observation is carried between
 * sweeps, because that history is what makes any of it possible.
 *
 * @internal
 */
final class RepoDriftTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const FINGERPRINT = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
    private const OTHER_KEY   = '1234567890ABCDEF1234567890ABCDEF12345678';

    /** A fixed clock, so every threshold assertion is about the rule and not the date. */
    private const NOW = '2026-06-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRepos();
    }

    protected function tearDown(): void
    {
        $this->clearRepos();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The rules (pure)
    // ------------------------------------------------------------------

    public function testAFreshRepositoryIsHealthy(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified(), self::NOW);

        $this->assertSame([], $verdict['kinds']);
        $this->assertSame('', $verdict['highest']);
        $this->assertSame('Healthy', $verdict['summary']);
        // The observation is still returned: "checked and healthy" is a fact the
        // page has to be able to show, and an absent value cannot express it.
        $this->assertSame('2026-06-13 00:00:00', $verdict['metadata_date']);
        $this->assertSame('2027-01-01 00:00:00', $verdict['key_expires_at']);
    }

    public function testTheSameMetadataDateStandingForTooLongIsNotUpdating(): void
    {
        // Seen 20 days ago and never advanced: the vendor has stopped publishing.
        // This is the one rule that cannot be judged from a single sweep.
        $repo = $this->repo([
            'metadata_date'    => '2026-05-01 00:00:00',
            'metadata_seen_at' => '2026-05-26 00:00:00',
        ]);

        $test = $this->verified(['release' => ['date' => 'Fri, 01 May 2026 00:00:00 UTC']]);

        $verdict = RepoDrift::evaluate($repo, $test, self::NOW);

        $this->assertSame(['not_updating'], $this->kinds($verdict));
        $this->assertSame(RepoDrift::CRITICAL, $verdict['highest']);
        $this->assertStringContainsString('same metadata', $verdict['kinds'][0]['detail']);
        // The first-seen stamp survives: "flagged for 20 days" must not reset
        // every time the sweep notices it again.
        $this->assertSame('2026-05-26 00:00:00', $verdict['metadata_seen_at']);
    }

    public function testMetadataNothingHasChangedButOnlyJustIsQuiet(): void
    {
        // The same date, but noticed today — the window has not elapsed, so this
        // is not yet evidence of a vendor going quiet. This is what stops the
        // first sweep after installation from flagging everything.
        $repo = $this->repo([
            'metadata_date'    => '2026-06-10 00:00:00',
            'metadata_seen_at' => '2026-06-10 00:00:00',
        ]);

        $verdict = RepoDrift::evaluate($repo, $this->verified(['release' => ['date' => 'Wed, 10 Jun 2026 00:00:00 UTC']]), self::NOW);

        $this->assertSame([], $verdict['kinds']);
    }

    public function testAdvancingMetadataRestartsTheObservationWindow(): void
    {
        // The row carried an old date; the vendor has published a new one, so the
        // window must restart at *now*, not keep the stale first-seen stamp.
        $repo = $this->repo([
            'metadata_date'    => '2026-05-01 00:00:00',
            'metadata_seen_at' => '2026-05-02 00:00:00',
        ]);

        $verdict = RepoDrift::evaluate($repo, $this->verified(['release' => ['date' => 'Sun, 14 Jun 2026 00:00:00 UTC']]), self::NOW);

        $this->assertSame([], $verdict['kinds']);
        $this->assertSame(self::NOW, $verdict['metadata_seen_at']);
    }

    public function testOldMetadataIsStale(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified([
            'release' => ['date' => 'Fri, 01 May 2026 00:00:00 UTC'],
        ]), self::NOW);

        $this->assertSame([RepoDrift::METADATA_STALE], $this->kinds($verdict));
        $this->assertSame(RepoDrift::WARNING, $verdict['highest']);
        $this->assertStringContainsString('45 days old', $verdict['kinds'][0]['detail']);
    }

    public function testExpiredValidUntilIsCritical(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified([
            'release' => ['valid_until' => 'Fri, 12 Jun 2026 00:00:00 GMT'],
        ]), self::NOW);

        $this->assertSame([RepoDrift::METADATA_EXPIRED], $this->kinds($verdict));
        $this->assertSame(RepoDrift::CRITICAL, $verdict['highest']);
    }

    public function testValidUntilInsideTheWindowIsAWarning(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified([
            'release' => ['valid_until' => 'Thu, 18 Jun 2026 00:00:00 GMT'], // 3 days
        ]), self::NOW);

        $this->assertSame([RepoDrift::METADATA_EXPIRING], $this->kinds($verdict));
        $this->assertSame(RepoDrift::WARNING, $verdict['highest']);
    }

    public function testAKeyCloseToExpiryIsFlaggedBeforeItLapses(): void
    {
        // Metadata is perfectly fresh — the *signing key* is the thing aging out,
        // and only gpg knows that. This is the whole reason the daemon reports
        // the key's own dates rather than reusing the metadata's Valid-Until.
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified([
            'key' => ['expires_at' => '2026-06-26'], // 10 days
        ]), self::NOW);

        $this->assertSame([RepoDrift::KEY_EXPIRING], $this->kinds($verdict));
        $this->assertSame(RepoDrift::WARNING, $verdict['highest']);
        $this->assertStringContainsString('10 days', $verdict['kinds'][0]['detail']);
    }

    public function testAnExpiredKeyIsCritical(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified([
            'key' => ['expires_at' => '2026-06-01'],
        ]), self::NOW);

        $this->assertSame([RepoDrift::KEY_EXPIRED], $this->kinds($verdict));
        $this->assertSame(RepoDrift::CRITICAL, $verdict['highest']);
    }

    public function testAKeyThatNeverExpiresIsNotFlagged(): void
    {
        // gpg reports no expiry as an empty string; an absent date must not be
        // read as "long ago".
        $verdict = RepoDrift::evaluate($this->repo(), $this->verified([
            'key' => ['expires_at' => '', 'created_at' => '2021-09-25'],
        ]), self::NOW);

        $this->assertSame([], $verdict['kinds']);
        $this->assertNull($verdict['key_expires_at']);
        $this->assertSame('2021-09-25 00:00:00', $verdict['key_created_at']);
    }

    public function testASignerThatIsNotTheDeclaredKeyIsARotation(): void
    {
        // The daemon's machine-readable reason is what makes this distinguishable
        // from a vendor whose CDN blipped: a rotation needs a person.
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'        => false,
            'reason'    => 'signer_mismatch',
            'message'   => 'the release is signed by ' . self::OTHER_KEY . ', not the declared ' . self::FINGERPRINT,
            'signature' => ['signer' => self::OTHER_KEY, 'verified' => true],
            'key'       => ['declared' => self::FINGERPRINT, 'matched' => true],
        ], self::NOW);

        $this->assertSame([RepoDrift::KEY_ROTATED], $this->kinds($verdict));
        $this->assertSame(RepoDrift::CRITICAL, $verdict['highest']);
        // Both fingerprints are named, because the operator has to compare them
        // out of band before trusting the new one.
        $this->assertStringContainsString(self::OTHER_KEY, $verdict['kinds'][0]['detail']);
        $this->assertStringContainsString(self::FINGERPRINT, $verdict['kinds'][0]['detail']);
    }

    public function testAnInvalidSignatureIsAlsoTreatedAsARotation(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'     => false,
            'reason' => 'signature_invalid',
            'message' => 'the release is not validly signed by the imported key',
        ], self::NOW);

        $this->assertSame([RepoDrift::KEY_ROTATED], $this->kinds($verdict));
    }

    public function testAnUnreachableRepositoryIsAWarningNotACritical(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'      => false,
            'reason'  => 'release_unreachable',
            'message' => 'the repository did not serve signed release metadata (connection refused)',
        ], self::NOW);

        $this->assertSame([RepoDrift::UNREACHABLE], $this->kinds($verdict));
        $this->assertSame(RepoDrift::WARNING, $verdict['highest']);
    }

    public function testAKeyThatCannotBeFetchedIsUnreachable(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'      => false,
            'reason'  => 'key_fetch_failed',
            'message' => 'the key URL did not return a PGP key',
        ], self::NOW);

        $this->assertSame([RepoDrift::UNREACHABLE], $this->kinds($verdict));
    }

    public function testAFailureOnARepositoryThatNeverVerifiedIsNotARegression(): void
    {
        // It has never worked, so "it did not work" is news, not an incident.
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'      => false,
            'reason'  => 'release_unreachable',
            'message' => 'timeout',
        ], self::NOW);

        $this->assertSame([RepoDrift::UNREACHABLE], $this->kinds($verdict));
        $this->assertNotContains(RepoDrift::VERIFICATION_LOST, $this->kinds($verdict));
        $this->assertNull($verdict['last_verified_at']);
    }

    public function testARepositoryThatPreviouslyVerifiedAndNowFailsIsFlagged(): void
    {
        // The regression is the event worth waking someone for: this source was
        // trustworthy and now is not.
        $repo = $this->repo(['last_verified_at' => '2026-06-10 04:45:00']);

        $verdict = RepoDrift::evaluate($repo, [
            'ok'      => false,
            'reason'  => 'release_unreachable',
            'message' => 'connection refused',
        ], self::NOW);

        $kinds = $this->kinds($verdict);
        $this->assertContains(RepoDrift::UNREACHABLE, $kinds);
        $this->assertContains(RepoDrift::VERIFICATION_LOST, $kinds);
        $this->assertSame(RepoDrift::WARNING, RepoDrift::severityOf(RepoDrift::VERIFICATION_LOST));

        $lost = $this->entry($verdict, RepoDrift::VERIFICATION_LOST);
        $this->assertStringContainsString('2026-06-10', $lost['detail']);
        // The reason is carried, so the page can say *why* it stopped.
        $this->assertStringContainsString('release_unreachable', $lost['detail']);

        // The stamp is deliberately not cleared: the next failure is still a
        // regression rather than a repository that never worked.
        $this->assertSame('2026-06-10 04:45:00', $verdict['last_verified_at']);
    }

    public function testARotationOnAPreviouslyVerifiedRepositoryStaysCritical(): void
    {
        $repo = $this->repo(['last_verified_at' => '2026-06-10 04:45:00']);

        $verdict = RepoDrift::evaluate($repo, [
            'ok'        => false,
            'reason'    => 'signer_mismatch',
            'message'   => 'signed by ' . self::OTHER_KEY,
            'signature' => ['signer' => self::OTHER_KEY],
            'key'       => ['declared' => self::FINGERPRINT],
        ], self::NOW);

        // The reason keeps its own severity; the loss is reported beside it.
        $this->assertContains(RepoDrift::KEY_ROTATED, $this->kinds($verdict));
        $this->assertContains(RepoDrift::VERIFICATION_LOST, $this->kinds($verdict));
        $this->assertSame(RepoDrift::CRITICAL, $verdict['highest']);
    }

    public function testASuccessfulCheckStampsTheVerification(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(['last_verified_at' => null]), $this->verified(), self::NOW);

        $this->assertSame(self::NOW, $verdict['last_verified_at']);
    }

    public function testASuccessfulCheckAdvancesAnOlderVerificationStamp(): void
    {
        $verdict = RepoDrift::evaluate(
            $this->repo(['last_verified_at' => '2026-06-01 04:45:00']),
            $this->verified(),
            self::NOW,
        );

        $this->assertSame(self::NOW, $verdict['last_verified_at']);
    }

    public function testOurOwnBrokenDefinitionIsCriticalRatherThanTransient(): void
    {
        // This is a defect in the stored row, not the vendor's behaviour. Calling
        // it a warning would let it sit there looking like a network blip.
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'      => false,
            'reason'  => 'invalid_repository',
            'message' => 'suite is not a valid suite',
        ], self::NOW);

        $this->assertSame([RepoDrift::DEFINITION_INVALID], $this->kinds($verdict));
        $this->assertSame(RepoDrift::CRITICAL, $verdict['highest']);
        $this->assertStringContainsString('stored definition', $verdict['kinds'][0]['detail']);
    }

    public function testAFailedCheckDoesNotInventFreshnessFindings(): void
    {
        // A failed verification says nothing about the metadata's date, so no
        // freshness kind may be reported from one.
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok'      => false,
            'reason'  => 'release_unreachable',
            'message' => 'timeout',
        ], self::NOW);

        $this->assertSame([RepoDrift::UNREACHABLE], $this->kinds($verdict));
        // And the known-good observations are preserved rather than wiped.
        $this->assertSame('2026-06-01 00:00:00', $verdict['metadata_date']);
    }

    public function testEveryKindExplainsItself(): void
    {
        // The page and the CLI both render from the kind table, so a kind without
        // a label, a severity or a remedy is a badge that cannot be acted on.
        foreach (RepoDrift::kinds() as $kind => $definition) {
            $this->assertNotSame('', RepoDrift::labelOf($kind), $kind);
            $this->assertNotSame($kind, RepoDrift::labelOf($kind), $kind);
            $this->assertContains(RepoDrift::severityOf($kind), [RepoDrift::WARNING, RepoDrift::CRITICAL], $kind);
            $this->assertNotSame('', RepoDrift::remedyOf($kind), $kind);
        }
    }

    public function testSummaryAndHighestRollUpTheKinds(): void
    {
        $this->assertSame('', RepoDrift::highest([]));
        $this->assertSame('Healthy', RepoDrift::summary([]));

        $this->assertSame(RepoDrift::WARNING, RepoDrift::highest([RepoDrift::KEY_EXPIRING]));
        // One critical among warnings makes the whole verdict critical.
        $this->assertSame(RepoDrift::CRITICAL, RepoDrift::highest([RepoDrift::KEY_EXPIRING, RepoDrift::KEY_ROTATED]));

        $this->assertSame('Key expiring + Metadata stale', RepoDrift::summary([RepoDrift::KEY_EXPIRING, RepoDrift::METADATA_STALE]));
        $this->assertTrue(RepoDrift::isCritical(RepoDrift::KEY_EXPIRED));
        $this->assertFalse(RepoDrift::isCritical(RepoDrift::UNREACHABLE));
    }

    public function testUngparsableDatesAreSkippedRatherThanGuessedAt(): void
    {
        $verdict = RepoDrift::evaluate($this->repo(), [
            'ok' => true,
            'release' => ['date' => 'not a date', 'valid_until' => ''],
            'key' => ['expires_at' => ''],
        ], self::NOW);

        $this->assertSame([], $verdict['kinds']);
        // Nothing was learned, so nothing is written over what was known.
        $this->assertSame('2026-06-01 00:00:00', $verdict['metadata_date']);
    }

    // ------------------------------------------------------------------
    // Observing and persisting
    // ------------------------------------------------------------------

    public function testObserveRecordsTheObservationAndReportsItStarting(): void
    {
        $this->insertRow();
        $drift = $this->drift();

        $row = $drift->observe($this->stored(), $this->verified(['release' => ['date' => 'Fri, 01 May 2026 00:00:00 UTC']]), self::NOW);

        $this->assertTrue($row['drifting']);
        $this->assertTrue($row['started']);
        $this->assertFalse($row['cleared']);
        $this->assertSame([RepoDrift::METADATA_STALE], $row['kinds']);
        $this->assertSame(self::NOW, $row['since']);

        $stored = $this->stored();
        $this->assertSame(['metadata_stale'], $stored['drift_kinds']);
        $this->assertSame(self::NOW, $stored['drift_since']);
        $this->assertSame(self::NOW, $stored['last_check_at']);
        $this->assertStringContainsString('WARNING', $stored['drift_detail']);
        $this->assertStringContainsString('Metadata stale', $stored['drift_detail']);
    }

    public function testAStandingProblemIsNotReAnnouncedAndKeepsItsStartDate(): void
    {
        $this->insertRow();
        $drift = $this->drift();
        $test  = $this->verified(['release' => ['date' => 'Fri, 01 May 2026 00:00:00 UTC']]);

        $drift->observe($this->stored(), $test, '2026-06-10 12:00:00');
        $second = $drift->observe($this->stored(), $test, self::NOW);

        // Alerting keys on the change, so a problem that is still there is not a
        // new alert — but its age is preserved, which is the number that matters.
        $this->assertFalse($second['started']);
        $this->assertTrue($second['unchanged']);
        $this->assertSame('2026-06-10 12:00:00', $second['since']);
        $this->assertSame('2026-06-10 12:00:00', $this->stored()['drift_since']);
    }

    public function testRecoveryClearsTheVerdictAndReportsIt(): void
    {
        $this->insertRow(['drift_kinds' => 'metadata_stale', 'drift_since' => '2026-06-01 00:00:00']);
        $drift = $this->drift();

        $row = $drift->observe($this->stored(), $this->verified(), self::NOW);

        $this->assertFalse($row['drifting']);
        $this->assertTrue($row['cleared']);
        $this->assertNull($row['since']);

        $stored = $this->stored();
        $this->assertSame([], $stored['drift_kinds']);
        $this->assertNull($stored['drift_since']);
        $this->assertSame('', $stored['drift_detail']);
        // It was still checked, and that is recorded.
        $this->assertSame(self::NOW, $stored['last_check_at']);
    }

    public function testObserveStampsTheVerificationOnSuccess(): void
    {
        $this->insertRow(['last_verified_at' => null]);

        $row = $this->drift()->observe($this->stored(), $this->verified(), self::NOW);

        $this->assertSame(self::NOW, $row['last_verified_at']);
        $this->assertSame(self::NOW, $this->stored()['last_verified_at']);
    }

    public function testObserveKeepsTheVerificationStampThroughAFailure(): void
    {
        $this->insertRow(['last_verified_at' => '2026-06-10 04:45:00']);

        $row = $this->drift()->observe($this->stored(), [
            'ok'      => false,
            'reason'  => 'release_unreachable',
            'message' => 'timeout',
        ], self::NOW);

        $this->assertContains(RepoDrift::VERIFICATION_LOST, $row['kinds']);
        // The last good verification is retained on the row, not lost with the check.
        $this->assertSame('2026-06-10 04:45:00', $this->stored()['last_verified_at']);
    }

    public function testObserveToleranceWhenTheRegistryHasNoTable(): void
    {
        // The observation must not be the thing that breaks an appliance whose
        // schema predates it.
        $drift = new RepoDrift(new RepoRegistry(new FakeIpcClient(), $this->db), $this->db);
        $row   = $drift->observe($this->repo(), $this->verified(), self::NOW);

        $this->assertFalse($row['drifting']);
        $this->assertSame('Healthy', $row['summary']);
    }

    // ------------------------------------------------------------------
    // The sweep
    // ------------------------------------------------------------------

    public function testTheSweepRefreshesMetadataAndChecksOnlyEnabledRepositories(): void
    {
        $this->insertRow(['id' => 'live', 'enabled' => 1]);
        $this->insertRow(['id' => 'parked', 'enabled' => 0]);

        $registry = $this->registry($this->verifiedIpc());
        $report   = (new RepoDrift($registry, $this->db))->sweep();

        $this->assertTrue($report['refresh']['success']);
        $this->assertSame(1, $report['checked']);
        $this->assertSame(['live'], array_column($report['rows'], 'id'));

        // The disabled repository is not swept: nothing should be observed for a
        // repository apt is not using.
        $parked = $this->db->table('plugin_repos')->where('id', 'parked')->get()->getRowArray();
        $this->assertNull($parked['last_check_at']);
    }

    public function testTheSweepFlagsStaleMetadataEndToEnd(): void
    {
        $this->insertRow();

        // The daemon is what parses the served metadata into release fields, so
        // the canned report is what carries the Date the drift rules compare. It
        // is deliberately old (stale) while Valid-Until is still in the future,
        // which is exactly the "quiet but not yet broken" case this feature is
        // for. The dates are relative to the real clock because sweep() uses it.
        $date  = 'Fri, 01 May 2026 00:00:00 UTC';
        $until = gmdate('D, d M Y H:i:s', time() + 30 * 86400) . ' GMT';

        $registry = $this->registry(
            $this->verifiedIpc(['date' => $date, 'valid_until' => $until]),
            RepoFixture::routedGuard(['/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease(['Date' => $date, 'Valid-Until' => $until])]]),
        );

        $report = (new RepoDrift($registry, $this->db))->sweep();

        $this->assertSame(['vendor'], $report['drifting']);
        $this->assertSame([], $report['critical'], 'stale metadata is a warning, not a critical');
        $this->assertSame(['vendor'], $report['started']);

        $stored = $this->stored();
        $this->assertSame(['metadata_stale'], $stored['drift_kinds']);
        // The metadata date the vendor published is what the next sweep compares
        // against — that comparison is the whole mechanism.
        $this->assertSame('2026-05-01 00:00:00', $stored['metadata_date']);
        $this->assertNotSame('', (string) $stored['last_check_at']);
    }

    public function testTheSweepCanBeLimitedToOneRepository(): void
    {
        $this->insertRow(['id' => 'one', 'enabled' => 1]);
        $this->insertRow(['id' => 'two', 'enabled' => 1]);

        $registry = $this->registry($this->verifiedIpc());
        $report   = (new RepoDrift($registry, $this->db))->sweep(['two']);

        $this->assertSame(1, $report['checked']);
        $this->assertSame(['two'], array_column($report['rows'], 'id'));
        $this->assertNull($this->db->table('plugin_repos')->where('id', 'one')->get()->getRowArray()['last_check_at']);
    }

    public function testStateCountsTheFlaggedRepositories(): void
    {
        $repos = [
            ['id' => 'a', 'drift_kinds' => []],
            ['id' => 'b', 'drift_kinds' => [RepoDrift::METADATA_STALE]],
            ['id' => 'c', 'drift_kinds' => [RepoDrift::KEY_EXPIRED]],
        ];

        $state = RepoDrift::state($repos);

        $this->assertSame(2, $state['flagged']);
        $this->assertSame(1, $state['critical']);
        $this->assertSame(RepoDrift::CRITICAL, $state['highest']);

        $state = RepoDrift::state([$repos[0], $repos[1]]);
        $this->assertSame(1, $state['flagged']);
        $this->assertSame(0, $state['critical']);
        $this->assertSame(RepoDrift::WARNING, $state['highest']);

        $this->assertSame(['flagged' => 0, 'critical' => 0, 'highest' => ''], RepoDrift::state([$repos[0]]));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function drift(): RepoDrift
    {
        return new RepoDrift($this->registry(new FakeIpcClient()), $this->db);
    }

    /**
     * A registry whose verification actually runs — through the repository's own
     * fetch path and the egress guard, so `sweep()` is exercised the way the
     * scheduled task runs it.
     */
    private function registry(FakeIpcClient $ipc, ?object $guard = null): RepoRegistry
    {
        return new RepoRegistry($ipc, $this->db, $guard ?? RepoFixture::routedGuard([
            '/debian/dists/stable/InRelease' => [200, RepoFixture::inRelease()],
        ]));
    }

    /**
     * An IPC double that answers the metadata refresh and the verification the way
     * a daemon that verified the release would.
     */
    private function verifiedIpc(array $release = []): FakeIpcClient
    {
        return new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'ok', 'repos' => 1], 'error' => null],
            'plugin.repo_test'    => ['result' => [
                'success'   => true,
                'status'    => 'ok',
                'id'        => 'vendor',
                'key'       => ['declared' => self::FINGERPRINT, 'found' => [self::FINGERPRINT], 'matched' => true],
                'signature' => ['verified' => true, 'signer' => self::FINGERPRINT, 'mode' => 'inrelease'],
                'release'   => $release + [
                    'origin'      => 'Vendor Repository',
                    'suite'       => 'stable',
                    'codename'    => 'vendorbook',
                    'date'        => 'Sat, 13 Jun 2026 00:00:00 UTC',
                    'valid_until' => 'Wed, 15 Jul 2026 00:00:00 GMT',
                ],
                'warnings'  => [],
                'steps'     => [],
            ], 'error' => null],
        ]);
    }

    /**
     * A successful verification report, in the shape the Repositories page and
     * this library both consume.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function verified(array $overrides = []): array
    {
        return $overrides + [
            'ok'        => true,
            'status'    => 'ok',
            'id'        => 'vendor',
            'signature' => ['verified' => true, 'signer' => self::FINGERPRINT, 'mode' => 'inrelease'],
            'key'       => [
                'declared'   => self::FINGERPRINT,
                'found'      => [self::FINGERPRINT],
                'matched'    => true,
                'created_at' => '2021-09-25',
                'expires_at' => '2027-01-01',
            ],
            'release'   => [
                'origin'      => 'Vendor Repository',
                'suite'       => 'stable',
                'codename'    => 'vendorbook',
                'date'        => 'Sat, 13 Jun 2026 00:00:00 UTC',
                'valid_until' => 'Wed, 15 Jul 2026 00:00:00 GMT',
            ],
            'warnings'  => [],
            'steps'     => [],
        ];
    }

    /**
     * A repository row as `RepoRegistry::shape()` returns it, plus whatever was
     * remembered about it from the previous sweep.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function repo(array $overrides = []): array
    {
        return $overrides + [
            'id'               => 'vendor',
            'name'             => 'Vendor APT',
            'fingerprint'      => self::FINGERPRINT,
            'drift_kinds'      => [],
            'drift_since'      => null,
            'metadata_date'    => '2026-06-01 00:00:00',
            'metadata_seen_at' => null,
            'last_verified_at' => null,
        ];
    }

    /**
     * The stored row, reshaped the way the registry would hand it to the evaluator.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return $this->registry(new FakeIpcClient())->get('vendor') ?? [];
    }

    /**
     * @return list<string>
     */
    private function kinds(array $verdict): array
    {
        return array_map(static fn ($entry) => $entry['kind'], $verdict['kinds']);
    }

    /**
     * @return array{kind: string, severity: string, detail: string}
     */
    private function entry(array $verdict, string $kind): array
    {
        foreach ($verdict['kinds'] as $entry) {
            if ($entry['kind'] === $kind) {
                return $entry;
            }
        }

        $this->fail("kind {$kind} was not reported");
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
