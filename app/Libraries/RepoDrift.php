<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Curated-repository drift detection (Plugin Spec §5.2, §9.5).
 *
 * A curated repository can be correctly configured and still be quietly dead.
 * The package manager's per-row `Test` action answers "is this trustworthy right
 * now?", which is a different question from "is this still being maintained?".
 * Three things rot over time, and none of them is visible in a single
 * observation:
 *
 *  - **The vendor stops publishing.** Its release metadata keeps declaring the
 *    same `Date:`. Detecting that requires remembering the date seen last time
 *    and when it first appeared.
 *  - **The signing key ages out.** A key has its own validity, independent of the
 *    metadata's `Valid-Until`. When it lapses, the repository stops verifying —
 *    and an operator finds out at the worst possible moment.
 *  - **The vendor rotates the key.** The metadata is then signed by a key other
 *    than the one declared out of band, so verification fails. That failure is
 *    *not* the same as an unreachable vendor: one needs the operator to re-verify
 *    a key, the other needs patience. Hence the daemon's machine-readable
 *    `reason` codes rather than prose matching.
 *
 * Keeping the observations is what makes the comparison possible, so the verdict
 * is written back onto the repository row (`drift_kinds`, `drift_since`,
 * `drift_detail`, `last_check_at`) and the raw inputs beside it
 * (`metadata_date`, `metadata_seen_at`, `key_expires_at`, `key_created_at`,
 * `last_verified_at`).
 *
 * `drift_since` deliberately survives a sweep: "flagged six days ago" has to stay
 * stable while the drift persists, or the age of a problem resets every time it
 * is noticed — which is the one number an operator needs.
 *
 * `evaluate()` is pure — repository row in, verdict out — so every threshold and
 * rule is testable without a network, a daemon or a database.
 */
class RepoDrift
{
    public const WARNING  = 'warning';
    public const CRITICAL = 'critical';

    // -- What is wrong -----------------------------------------------------

    /** The vendor's metadata has declared the same `Date:` for too long. */
    public const NOT_UPDATING = 'not_updating';
    /** The metadata is simply old. */
    public const METADATA_STALE = 'metadata_stale';
    /** `Valid-Until` has passed: apt would now refuse the repository. */
    public const METADATA_EXPIRED = 'metadata_expired';
    /** `Valid-Until` is close, so a lapse is imminent. */
    public const METADATA_EXPIRING = 'metadata_expiring';
    /** The signing key's own validity has ended. */
    public const KEY_EXPIRED = 'key_expired';
    /** The signing key's own validity is close to ending. */
    public const KEY_EXPIRING = 'key_expiring';
    /**
     * The metadata no longer verifies against the declared key.
     *
     * Both a genuine rotation and a corrupt publication land here, because the
     * operator action is the same either way: re-establish the vendor's key out
     * of band before trusting the repository again. The detail text says which.
     */
    public const KEY_ROTATED = 'key_rotated';
    /** The repository could not be reached, or served no signed metadata. */
    public const UNREACHABLE = 'unreachable';
    /** The check could not be completed for a reason that is not reachability. */
    public const CHECK_FAILED = 'check_failed';
    /**
     * The stored definition itself is invalid, so no verification can succeed.
     *
     * Distinct from {@see self::CHECK_FAILED} because it is a defect in *our* row
     * rather than a transient failure, and must not be treated as one. Its
     * severity is therefore part of the kind rather than overridden at the call
     * site — otherwise the criticality would be lost the moment the verdict is
     * stored, and the page would read it back as a mere warning.
     */
    public const DEFINITION_INVALID = 'definition_invalid';
    /**
     * A repository that verified before no longer does.
     *
     * Distinct from the reason-scoped kinds beside it: `unreachable` and
     * `check_failed` describe one failed check, while this records a *regression*
     * from a state the operator was entitled to rely on. A repository that has
     * never verified failing is news; one that verified yesterday failing is an
     * incident, and telling them apart is what the previous successful
     * verification — carried on the row as `last_verified_at` — buys us.
     */
    public const VERIFICATION_LOST = 'verification_lost';

    // -- Thresholds --------------------------------------------------------

    /** A signing key inside this window is worth an operator's attention. */
    public const KEY_EXPIRING_DAYS = 30;

    /** Metadata inside this window of `Valid-Until` is about to lapse. */
    public const METADATA_EXPIRING_DAYS = 7;

    /** Metadata older than this says the vendor has gone quiet. */
    public const STALE_METADATA_DAYS = 21;

    /**
     * How long the same metadata `Date:` may stand before the repository counts
     * as no longer publishing.
     *
     * Shorter than the staleness threshold on purpose: `metadata_stale` reacts to
     * one old timestamp (which a one-off quiet month produces), while
     * `not_updating` reacts to *repetition* across sweeps, which is the stronger
     * and more actionable signal.
     */
    public const NOT_UPDATING_DAYS = 14;

    /**
     * kind => [severity, short label, what the operator should do].
     *
     * The remedy is part of the model because a badge that says only "DRIFT" is
     * not actionable; each of these has exactly one obvious next step.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const KINDS = [
        self::NOT_UPDATING      => [self::CRITICAL, 'Not updating', 'Confirm the vendor still publishes this repository; if it has moved, update the URL, otherwise retire it.'],
        self::METADATA_EXPIRED  => [self::CRITICAL, 'Metadata expired', 'The repository is no longer usable by apt. Check the vendor for a new release file.'],
        self::KEY_EXPIRED       => [self::CRITICAL, 'Key expired', 'Obtain the vendor\'s current signing key and update the declared fingerprint.'],
        self::KEY_ROTATED       => [self::CRITICAL, 'Key rotated', 'Re-check the vendor\'s fingerprint out of band, then update the declared key.'],
        self::METADATA_STALE    => [self::WARNING, 'Metadata stale', 'Watch this repository: its metadata has not been regenerated recently.'],
        self::METADATA_EXPIRING => [self::WARNING, 'Metadata expiring', 'The metadata is close to its Valid-Until; expect a refresh shortly.'],
        self::KEY_EXPIRING      => [self::WARNING, 'Key expiring', 'Plan a key rotation with the vendor before the key lapses.'],
        self::UNREACHABLE       => [self::WARNING, 'Unreachable', 'Usually transient. If it persists, the repository has effectively stopped updating.'],
        self::CHECK_FAILED      => [self::WARNING, 'Check failed', 'Inspect the recorded detail; the check itself could not complete.'],
        self::DEFINITION_INVALID => [self::CRITICAL, 'Definition invalid', 'Fix the stored repository definition: this appliance cannot verify it as written.'],
        self::VERIFICATION_LOST  => [self::WARNING, 'Verification lost', 'A repository that verified before has stopped. Re-run the check; if it persists, treat the source as untrusted until it verifies again.'],
    ];

    /** Reasons that mean the declared key no longer verifies the metadata. */
    private const ROTATION_REASONS = ['signer_mismatch', 'signature_invalid', 'fingerprint_mismatch'];

    /** Reasons that mean the repository was not reachable. */
    private const UNREACHABLE_REASONS = ['release_unreachable', 'key_fetch_failed'];

    /**
     * Reasons that are a defect in *our* stored definition rather than in the
     * vendor's behaviour. These are {@see self::DEFINITION_INVALID}, never a
     * transient warning.
     */
    private const DEFINITION_REASONS = ['invalid_repository', 'unknown_repository'];

    private ?RepoRegistry $registry;

    /** @var BaseConnection */
    private $db;

    public function __construct(?RepoRegistry $registry = null, $db = null)
    {
        $this->registry = $registry;
        $this->db       = $db ?? \Config\Database::connect();
    }

    /** The registry, resolved lazily so construction stays cheap. */
    private function registry(): RepoRegistry
    {
        return $this->registry ??= new RepoRegistry();
    }

    // ---------------------------------------------------------------------
    // The rules
    // ---------------------------------------------------------------------

    /**
     * kind => [severity, label, remedy]. The page and the CLI both render from
     * this, so a new kind cannot be added without saying what to do about it.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    public static function severityOf(string $kind): string
    {
        return self::KINDS[$kind][0] ?? self::WARNING;
    }

    public static function labelOf(string $kind): string
    {
        return self::KINDS[$kind][1] ?? $kind;
    }

    public static function remedyOf(string $kind): string
    {
        return self::KINDS[$kind][2] ?? '';
    }

    /** Whether a kind ranks above a mere warning. */
    public static function isCritical(string $kind): bool
    {
        return self::severityOf($kind) === self::CRITICAL;
    }

    /**
     * Judge one repository from one verification result plus what the previous
     * sweep remembered about it.
     *
     * Pure: no database, no network, no clock beyond the injected `$now`. The
     * returned `metadata_seen_at` is the value the caller should persist, so the
     * "has it advanced?" comparison lives here with the rest of the rules rather
     * than being re-derived by each caller.
     *
     * @param array<string, mixed> $repo the repository row, as `RepoRegistry::shape()` returns it
     * @param array<string, mixed> $test a `plugin.repo_test` report
     * @param string|null          $now  injectable clock, `Y-m-d H:i:s`
     *
     * @return array{kinds: list<array{kind: string, severity: string, detail: string}>,
     *               highest: string, summary: string,
     *               metadata_date: string|null, metadata_seen_at: string|null,
     *               key_created_at: string|null, key_expires_at: string|null,
     *               last_verified_at: string|null}
     */
    public static function evaluate(array $repo, array $test, ?string $now = null): array
    {
        $nowTs = self::timestamp($now ?? date('Y-m-d H:i:s')) ?? time();

        $release    = is_array($test['release'] ?? null) ? $test['release'] : [];
        $key        = is_array($test['key'] ?? null) ? $test['key'] : [];

        $metadataDate  = self::timestamp((string) ($release['date'] ?? ''));
        $validUntil    = self::timestamp((string) ($release['valid_until'] ?? ''));
        $keyExpires    = self::timestamp((string) ($key['expires_at'] ?? ''));
        $keyCreated    = self::timestamp((string) ($key['created_at'] ?? ''));

        // The last time this repository verified. Its presence is exactly what
        // makes a failure a *lost* verification rather than a repository that
        // never worked.
        $lastVerified = self::timestamp((string) ($repo['last_verified_at'] ?? ''));

        // Carry the observation window forward: the same metadata date keeps its
        // original first-seen stamp, a new one restarts it.
        $seenAt = self::timestamp((string) ($repo['metadata_seen_at'] ?? ''));
        $prev   = self::timestamp((string) ($repo['metadata_date'] ?? ''));

        $drifted = [];

        // 1. Did the check itself succeed? A failure is categorised by the
        //    daemon's reason code, because "the vendor rotates keys" and "the
        //    vendor's CDN blipped" need opposite responses.
        if (($test['ok'] ?? false) !== true) {
            $reason = (string) ($test['reason'] ?? 'check_failed');

            if (in_array($reason, self::ROTATION_REASONS, true)) {
                $drifted[] = self::entry(self::KEY_ROTATED, self::rotationDetail($reason, $test, $key));
            } else {
                // A definition this appliance cannot verify is a defect in *our*
                // row, not a transient failure: it gets its own kind so the
                // severity stays derivable from the verdict once it is stored.
                $kind = in_array($reason, self::DEFINITION_REASONS, true)
                    ? self::DEFINITION_INVALID
                    : (in_array($reason, self::UNREACHABLE_REASONS, true) ? self::UNREACHABLE : self::CHECK_FAILED);

                $drifted[] = self::entry($kind, self::failureDetail($reason, $test));
            }

            // A repository that verified before and does not now is a
            // *regression* rather than a diagnosis, so it is reported beside the
            // reason-specific finding: that one says why, this one says the source
            // the operator was relying on has stopped being verifiable.
            if ($lastVerified !== null) {
                $drifted[] = self::entry(self::VERIFICATION_LOST, sprintf(
                    'This repository last verified on %s and no longer does (%s).',
                    date('Y-m-d', $lastVerified),
                    $reason,
                ));
            }
        } else {
            // 2. Has the metadata advanced since the last sweep? This runs before
            //    the freshness rules because "the vendor stopped publishing" is
            //    the stronger verdict: when it holds it is reported *instead of*
            //    the weaker metadata_stale, not beside it.
            $notUpdating = false;
            $standing    = 0;
            if ($metadataDate !== null) {
                $sameAsBefore = $prev !== null && $prev === $metadataDate;

                if (! $sameAsBefore) {
                    $seenAt = $nowTs;
                }

                $standing    = $seenAt === null ? 0 : $nowTs - $seenAt;
                $notUpdating = $sameAsBefore && $standing >= self::NOT_UPDATING_DAYS * 86400;
            }

            // 3. Metadata freshness — one timestamp, one verdict.
            if ($validUntil !== null && $validUntil < $nowTs) {
                $drifted[] = self::entry(self::METADATA_EXPIRED, sprintf(
                    'The release metadata expired on %s; apt will refuse this repository.',
                    date('Y-m-d', $validUntil),
                ));
            } elseif ($validUntil !== null && $validUntil - $nowTs <= self::METADATA_EXPIRING_DAYS * 86400) {
                $drifted[] = self::entry(self::METADATA_EXPIRING, sprintf(
                    'The release metadata expires in %s (%s).',
                    self::humanDays($validUntil - $nowTs),
                    date('Y-m-d', $validUntil),
                ));
            } elseif ($metadataDate !== null && ! $notUpdating && $nowTs - $metadataDate > self::STALE_METADATA_DAYS * 86400) {
                $drifted[] = self::entry(self::METADATA_STALE, sprintf(
                    'The release metadata is %d days old (last published %s).',
                    (int) floor(($nowTs - $metadataDate) / 86400),
                    date('Y-m-d', $metadataDate),
                ));
            }

            if ($notUpdating) {
                $drifted[] = self::entry(self::NOT_UPDATING, sprintf(
                    'The vendor has published the same metadata (%s) for %d days; the repository appears to have stopped updating.',
                    date('Y-m-d', $metadataDate),
                    (int) floor($standing / 86400),
                ));
            }

            // 4. The signing key's own validity, which is not the metadata's.
            if ($keyExpires !== null && $keyExpires < $nowTs) {
                $drifted[] = self::entry(self::KEY_EXPIRED, sprintf(
                    'The signing key expired on %s, so this repository can no longer be verified.',
                    date('Y-m-d', $keyExpires),
                ));
            } elseif ($keyExpires !== null && $keyExpires - $nowTs <= self::KEY_EXPIRING_DAYS * 86400) {
                $drifted[] = self::entry(self::KEY_EXPIRING, sprintf(
                    'The signing key expires in %s (%s); plan a rotation with the vendor.',
                    self::humanDays($keyExpires - $nowTs),
                    date('Y-m-d', $keyExpires),
                ));
            }
        }

        // A repository that never reported a metadata date cannot be judged for
        // advancement; keep whatever was known rather than inventing a value.
        $persistedDate = $metadataDate !== null ? date('Y-m-d H:i:s', $metadataDate) : ($repo['metadata_date'] ?? null);
        $persistedSeen = $metadataDate !== null
            ? ($seenAt !== null ? date('Y-m-d H:i:s', $seenAt) : null)
            : ($repo['metadata_seen_at'] ?? null);

        $kinds = array_map(static fn ($e) => $e['kind'], $drifted);

        return [
            'kinds'            => $drifted,
            'highest'          => self::highest($kinds),
            'summary'          => self::summary($kinds),
            'metadata_date'    => $persistedDate,
            'metadata_seen_at' => $persistedSeen,
            'key_created_at'   => $keyCreated !== null ? date('Y-m-d H:i:s', $keyCreated) : null,
            'key_expires_at'   => $keyExpires !== null ? date('Y-m-d H:i:s', $keyExpires) : null,
            // A success stamps the moment; a failure leaves the previous stamp in
            // place, so "previously verified" survives a bad day and the next
            // failure is still recognisably a regression.
            'last_verified_at' => ($test['ok'] ?? false) === true
                ? date('Y-m-d H:i:s', $nowTs)
                : ($repo['last_verified_at'] ?? null),
        ];
    }

    /**
     * The worst severity in a set of kinds, or '' when there are none.
     *
     * @param list<string> $kinds
     */
    public static function highest(array $kinds): string
    {
        if (in_array(self::CRITICAL, array_map([self::class, 'severityOf'], $kinds), true)) {
            return self::CRITICAL;
        }

        return $kinds === [] ? '' : self::WARNING;
    }

    /**
     * A one-line verdict, in the same vocabulary as the per-kind labels.
     *
     * @param list<string> $kinds
     */
    public static function summary(array $kinds): string
    {
        if ($kinds === []) {
            return 'Healthy';
        }

        return implode(' + ', array_map([self::class, 'labelOf'], array_values(array_unique($kinds))));
    }

    // ---------------------------------------------------------------------
    // Observing
    // ---------------------------------------------------------------------

    /**
     * Judge and persist one repository's drift state.
     *
     * @param array<string, mixed> $repo
     * @param array<string, mixed> $test
     *
     * @return array<string, mixed> the observation, with `started` / `cleared`
     *                              telling a caller whether this sweep *changed*
     *                              the state — which is what an alert should key
     *                              on, so a standing problem is not re-announced
     *                              every day
     */
    public function observe(array $repo, array $test, ?string $now = null): array
    {
        $now     = $now ?? date('Y-m-d H:i:s');
        $verdict = self::evaluate($repo, $test, $now);

        $kinds = array_map(static fn ($e) => $e['kind'], $verdict['kinds']);
        $was   = is_array($repo['drift_kinds'] ?? null) ? $repo['drift_kinds'] : [];
        $since = $repo['drift_since'] ?? null;

        $started = $kinds !== [] && $was === [];
        $cleared = $kinds === [] && $was !== [];

        if ($kinds === []) {
            $since = null;
        } elseif ($started || $since === null) {
            $since = $now;
        }

        $this->persist($repo, $verdict, $kinds, $since, $now);

        return [
            'id'         => (string) ($repo['id'] ?? ''),
            'name'       => (string) ($repo['name'] ?? ''),
            'checked'    => ($test['ok'] ?? false) === true,
            'reason'     => (string) ($test['reason'] ?? ''),
            'drifting'   => $kinds !== [],
            'highest'    => $verdict['highest'],
            'kinds'      => $kinds,
            'entries'    => $verdict['kinds'],
            'summary'    => $verdict['summary'],
            'detail'     => (string) ($test['message'] ?? ''),
            'started'    => $started,
            'cleared'    => $cleared,
            'since'      => $since,
            'unchanged'  => ! $started && ! $cleared,
            'metadata_date' => $verdict['metadata_date'],
            'key_expires_at' => $verdict['key_expires_at'],
            'last_verified_at' => $verdict['last_verified_at'],
        ];
    }

    /**
     * Refresh metadata and evaluate every enabled repository.
     *
     * The refresh is part of the sweep rather than a separate scheduled task
     * because the two answer the same question — the metadata a drift verdict is
     * drawn from is the metadata apt is holding, and refreshing separately would
     * let the schedule drift apart from the observation.
     *
     * Read-only with respect to what apt may install: `plugin.repo.refresh` is
     * metadata only, and verification never writes a source, keyring or pin.
     *
     * @param list<string>|null $only limit to these repository ids
     *
     * @return array<string, mixed>
     */
    public function sweep(?array $only = null): array
    {
        $registry = $this->registry();
        $now      = date('Y-m-d H:i:s');

        $repos = $only === null
            ? $registry->enabled()
            : array_values(array_filter(
                $registry->all(),
                static fn ($r) => in_array((string) ($r['id'] ?? ''), $only, true),
            ));

        // Metadata first, so the verdict is drawn from what apt now holds.
        $refresh = $registry->refresh();

        $rows = [];
        foreach ($repos as $repo) {
            $rows[] = $this->observe($repo, $registry->test((string) $repo['id']), $now);
        }

        return ['refresh' => $refresh, 'checked_at' => $now, 'rows' => $rows] + self::summarise($rows);
    }

    /**
     * Roll a set of observations up into counts and the ids worth naming.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function summarise(array $rows): array
    {
        $byKind   = [];
        $critical = [];
        $drifting = [];
        $started  = [];
        $cleared  = [];

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');

            if (($row['drifting'] ?? false) === true) {
                $drifting[] = $id;
                foreach ((array) ($row['kinds'] ?? []) as $kind) {
                    $byKind[$kind][] = $id;
                }
                if (($row['highest'] ?? '') === self::CRITICAL) {
                    $critical[] = $id;
                }
            }

            if (($row['started'] ?? false) === true) {
                $started[] = $id;
            }

            if (($row['cleared'] ?? false) === true) {
                $cleared[] = $id;
            }
        }

        return [
            'checked'  => count($rows),
            'drifting' => $drifting,
            'critical' => $critical,
            'started'  => $started,
            'cleared'  => $cleared,
            'by_kind'  => $byKind,
        ];
    }

    /**
     * Whether any repository is currently flagged, and how badly.
     *
     * Used by the Repositories page header and by anything that wants a single
     * boolean for "is there something to look at".
     *
     * @param list<array<string, mixed>> $repos
     *
     * @return array{flagged: int, critical: int, highest: string}
     */
    public static function state(array $repos): array
    {
        $flagged  = 0;
        $critical = 0;

        foreach ($repos as $repo) {
            $kinds = is_array($repo['drift_kinds'] ?? null) ? $repo['drift_kinds'] : [];
            if ($kinds === []) {
                continue;
            }

            $flagged++;
            if (self::highest($kinds) === self::CRITICAL) {
                $critical++;
            }
        }

        return [
            'flagged'  => $flagged,
            'critical' => $critical,
            'highest'  => $critical > 0 ? self::CRITICAL : ($flagged > 0 ? self::WARNING : ''),
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $repo
     * @param array<string, mixed> $verdict
     * @param list<string>         $kinds
     */
    private function persist(array $repo, array $verdict, array $kinds, ?string $since, string $now): void
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return;
        }

        // The drift *observation* is written even when nothing is wrong: an
        // operator needs to see that a repository was checked and found healthy,
        // which an absent row cannot express.
        $this->db->table('plugin_repos')->where('id', (string) $repo['id'])->update([
            'last_check_at'    => $now,
            'metadata_date'    => $verdict['metadata_date'],
            'metadata_seen_at' => $verdict['metadata_seen_at'],
            'key_created_at'   => $verdict['key_created_at'],
            'key_expires_at'   => $verdict['key_expires_at'],
            'last_verified_at' => $verdict['last_verified_at'],
            'drift_kinds'      => implode(',', $kinds),
            'drift_since'      => $since,
            'drift_detail'     => self::detailText($verdict['kinds']),
        ]);
    }

    /**
     * One line per active kind, prefixed with its severity so the stored text
     * reads the same in a console, a modal and a database dump.
     *
     * @param list<array{kind: string, severity: string, detail: string}> $entries
     */
    private static function detailText(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = strtoupper($entry['severity']) . ' — ' . self::labelOf($entry['kind']) . ': ' . $entry['detail'];
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{kind: string, severity: string, detail: string}
     */
    private static function entry(string $kind, string $detail): array
    {
        return [
            'kind'     => $kind,
            'severity' => self::severityOf($kind),
            'detail'   => $detail,
        ];
    }

    /**
     * @param array<string, mixed> $test
     * @param array<string, mixed> $key
     */
    private static function rotationDetail(string $reason, array $test, array $key): string
    {
        $declared = (string) ($key['declared'] ?? '');
        $signer   = (string) ($test['signature']['signer'] ?? '');

        if ($signer !== '' && $declared !== '' && $signer !== $declared) {
            return sprintf(
                'The metadata is now signed by %s, not the declared %s. The vendor has almost certainly rotated its key; verify the new fingerprint out of band before trusting it.',
                $signer,
                $declared,
            );
        }

        if ($reason === 'signature_invalid') {
            return 'The metadata is not validly signed by the declared key. Either the vendor rotated it or the published metadata is corrupt.';
        }

        return 'The stored key material no longer matches the declared fingerprint.';
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function failureDetail(string $reason, array $test): string
    {
        if (in_array($reason, self::UNREACHABLE_REASONS, true)) {
            return 'The repository could not be reached, or served no signed metadata: ' . self::oneLine((string) ($test['message'] ?? 'unknown error'));
        }

        if (in_array($reason, self::DEFINITION_REASONS, true)) {
            return 'The stored definition cannot be verified: ' . self::oneLine((string) ($test['message'] ?? 'unknown error'));
        }

        return 'The check failed: ' . self::oneLine((string) ($test['message'] ?? 'unknown error'));
    }

    private static function oneLine(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return $text === '' ? 'no detail reported' : (strlen($text) > 300 ? substr($text, 0, 297) . '…' : $text);
    }

    private static function humanDays(int $seconds): string
    {
        $days = (int) floor(max(0, $seconds) / 86400);

        return $days === 0 ? 'less than a day' : $days . ' day' . ($days === 1 ? '' : 's');
    }

    /**
     * A tolerant timestamp, because the inputs come from two different worlds:
     * apt-style RFC 2822 (`Valid-Until`) and gpg's ISO dates.
     *
     * Returns null for anything unparsable or absent, so a rule that cannot be
     * judged is skipped rather than guessed at.
     */
    private static function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }
}
