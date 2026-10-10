<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Curated Plugin Repositories (Plugin Spec §5.2, §10)
 * ==========================================================
 *
 * Turns the `plugin_repos` table into the apt sources, signing keyrings and
 * preference pins the data plane needs. Compilation is pure and daemon-free —
 * exactly like {@see DhcpRelayCompiler} — so the page can show the generated
 * stanzas, the pins and every conflict *before* anything is applied.
 *
 * An apt repository is arbitrary root code, so this class is deliberately
 * hostile to its own input:
 *
 *  - A repository is a **generated** thing. The operator supplies fields; this
 *    class and the daemon render the `.sources`, `.pref` and keyring files. No
 *    operator string ever reaches a file verbatim, and the `deb822` grammar in
 *    particular cannot be broken out of: there is no field for apt options, so
 *    `[trusted=yes]` and friends are unexpressible.
 *  - The **fingerprint is the trust anchor** and is supplied out of band. It is
 *    a 40- or 64-character hex value; the daemon imports the key material,
 *    compares fingerprints, and refuses a key that does not match.
 *  - Every repository is **pinned below the distribution** (priority 100), and
 *    the daemon additionally pins the protected core packages to the appliance's
 *    own release, so a curated repository can supply packages the distribution
 *    lacks but can never outbid it for a core dependency.
 *  - A repository may not point at the appliance's own distribution hosts — that
 *    is either a typo or an attempt to shadow the sources the image ships.
 */
final class RepoRegistry
{
    /** Where apt reads curated signing keys from. */
    public const KEYRING_DIR = '/etc/apt/keyrings';

    /** Where the generated `.sources` stanzas live. */
    public const SOURCES_DIR = '/etc/apt/sources.list.d';

    /** Where the generated apt pins live (`.pref` is a valid apt suffix). */
    public const PREFERENCES_DIR = '/etc/apt/preferences.d';

    /** Every file this class generates is namespaced, so cleanup is exact. */
    public const FILE_PREFIX = 'micron-';

    /**
     * Curated repositories sit below the distribution's own priority (500), so
     * a package that exists in the distribution is always taken from it.
     */
    public const PIN_PRIORITY = 100;

    /**
     * Debian architectures a curated repository may serve. A closed set, because
     * the value is written into the generated stanza.
     */
    public const ARCHITECTURES = ['amd64', 'arm64', 'armhf', 'i386', 'ppc64el', 'riscv64', 's390x', 'all'];

    /**
     * Hosts that already serve the appliance's own distribution.
     *
     * @var list<string>
     */
    public const DISTRO_HOSTS = [
        'deb.debian.org', 'security.debian.org', 'archive.ubuntu.com',
        'security.ubuntu.com', 'ports.ubuntu.com', 'snapshot.debian.org',
    ];

    public const MAX_REPOS     = 16;
    public const MAX_KEY_BYTES = 65536;

    /**
     * A Release/InRelease file is metadata, not a package index: a vendor's is
     * tens to a few hundred kilobytes. The cap keeps the verification path from
     * pulling an arbitrary blob through the daemon.
     */
    public const MAX_RELEASE_BYTES = 2097152;

    /** Public so the controller can validate route/query ids the same way. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    private const SUITE_RE       = '/^[a-z0-9][a-z0-9._-]{0,31}$/';
    private const COMPONENT_RE   = '/^[a-z0-9][a-z0-9._-]{0,31}$/';
    private const FINGERPRINT_RE = '/^(?:[0-9A-F]{40}|[0-9A-F]{64})$/';

    /**
     * A single-line `https://` address with no credentials, no query, no
     * fragment and no whitespace or apt-option punctuation. This regex *is* the
     * injection boundary: it is the only shape that may be written into a stanza.
     */
    private const URL_RE = '#^https://[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~%+\-/]*)?$#';

    private ?BaseConnection $db;

    private ?IpcClient $ipc;

    private ?ConnectorEgressGuard $guard;

    public function __construct(
        ?IpcClient $ipc = null,
        ?BaseConnection $db = null,
        ?ConnectorEgressGuard $guard = null,
    ) {
        $this->ipc   = $ipc;
        $this->db    = $db ?? \Config\Database::connect();
        $this->guard = $guard;
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * Every registered repository, shaped for the view.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return [];
        }

        $out = [];
        foreach ($this->db->table('plugin_repos')->orderBy('id', 'ASC')->get()->getResultArray() as $row) {
            $out[] = $this->shape($row);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return null;
        }

        $row = $this->db->table('plugin_repos')->where('id', $id)->get()->getRowArray();

        return $row === null ? null : $this->shape($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), static fn ($r) => $r['enabled'] === true));
    }

    /**
     * Whether a set of repository ids named by a plugin manifest
     * (`requires.repos`) is actually available and enabled here.
     *
     * @param list<string> $ids
     *
     * @return array{ok: bool, missing: list<string>}
     */
    /**
     * The image-shipped presets, each marked with whether it is already here.
     *
     * @return list<array<string, mixed>>
     */
    public function presets(): array
    {
        $out = [];
        foreach ((new RepoPresets())->all() as $preset) {
            $existing = $this->get((string) $preset['id']);
            $preset['registered'] = $existing !== null;
            $preset['enabled']    = $existing['enabled'] ?? false;
            // The operator's stored fingerprint wins: a preset never rewrites a
            // repository that is already registered.
            $preset['stored_fingerprint'] = $existing['fingerprint'] ?? '';
            $out[] = $preset;
        }

        return $out;
    }

    /**
     * Register an image-shipped preset repository (Plugin Spec §5.2, P3).
     *
     * Goes through the same validation as a hand-entered repository, so a preset
     * cannot introduce a field the grammars refuse. An already-registered id is
     * left exactly as it is — including the fingerprint the operator reviewed —
     * because overwriting a live trust anchor is not a convenience.
     *
     * @return array{ok: bool, id: string, errors: list<string>, created: bool}
     */
    public function seedPreset(string $id, string $by = 'preset'): array
    {
        $preset = (new RepoPresets())->get($id);
        if ($preset === null) {
            return ['ok' => false, 'id' => $id, 'errors' => ["unknown preset: {$id}"], 'created' => false];
        }

        $normalised = self::normalize(RepoPresets::input($preset));
        if ($normalised['ok'] !== true) {
            return ['ok' => false, 'id' => $id, 'errors' => $normalised['errors'], 'created' => false];
        }

        if ($this->get((string) $preset['id']) !== null) {
            return ['ok' => true, 'id' => (string) $preset['id'], 'errors' => [], 'created' => false];
        }

        $saved = $this->save(RepoPresets::input($preset), $by);

        return [
            'ok'      => $saved['ok'],
            'id'      => $saved['id'],
            'errors'  => $saved['errors'],
            'created' => $saved['ok'],
        ];
    }

    public function satisfied(array $ids): array
    {
        $missing = [];
        foreach ($ids as $id) {
            $repo = $this->get((string) $id);
            if ($repo === null || $repo['enabled'] !== true) {
                $missing[] = (string) $id;
            }
        }

        return ['ok' => $missing === [], 'missing' => array_values(array_unique($missing))];
    }

    // ---------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------

    /**
     * Validate and upsert a repository.
     *
     * @param array<string, mixed> $input raw form input (or a shaped repo)
     *
     * @return array{ok: bool, id: string, errors: list<string>}
     */
    public function save(array $input, string $by = ''): array
    {
        $normalised = self::normalize($input);
        $id         = strtolower(trim((string) ($input['id'] ?? '')));

        if (! $normalised['ok']) {
            return ['ok' => false, 'id' => $id, 'errors' => $normalised['errors']];
        }

        if (! $this->db->tableExists('plugin_repos')) {
            return ['ok' => false, 'id' => $id, 'errors' => ['The plugin_repos table is missing.']];
        }

        /** @var array<string, mixed> $repo */
        $repo   = $normalised['repo'];
        $id     = (string) $repo['id'];
        $exists = $this->db->table('plugin_repos')->where('id', $id)->countAllResults() > 0;

        if (! $exists && $this->db->table('plugin_repos')->countAllResults() >= self::MAX_REPOS) {
            return [
                'ok'     => false,
                'id'     => $id,
                'errors' => [sprintf('At most %d curated repositories may be registered.', self::MAX_REPOS)],
            ];
        }

        $row = [
            'id'              => $id,
            'name'            => (string) $repo['name'],
            'url'             => (string) $repo['url'],
            'suite'           => (string) $repo['suite'],
            'components'      => implode(', ', $repo['components']),
            'architectures'   => implode(', ', $repo['architectures']),
            'key_fingerprint' => (string) $repo['fingerprint'],
            'key_url'         => (string) $repo['key_url'],
            'key_armor'       => (string) $repo['key_armor'],
            'enabled'         => $repo['enabled'] ? 1 : 0,
        ];

        if ($exists) {
            $this->db->table('plugin_repos')->where('id', $id)->update($row);
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['added_by']   = $by;
            $this->db->table('plugin_repos')->insert($row);
        }

        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    public function delete(string $id): bool
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return false;
        }

        $this->db->table('plugin_repos')->where('id', $id)->delete();

        return $this->db->affectedRows() > 0;
    }

    public function setEnabled(string $id, bool $enabled): bool
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return false;
        }

        if ($this->db->table('plugin_repos')->where('id', $id)->countAllResults() === 0) {
            return false;
        }

        $this->db->table('plugin_repos')->where('id', $id)->update(['enabled' => $enabled ? 1 : 0]);

        return true;
    }

    // ---------------------------------------------------------------------
    // Compiling (daemon-free)
    // ---------------------------------------------------------------------

    /**
     * The compiled plan: the enabled repositories with their generated stanzas,
     * plus every reason the plan could not be applied as it stands.
     *
     * @return array{repos: list<array<string, mixed>>, registered: int, enabled: int,
     *               conflicts: list<string>, warnings: list<string>, ready: bool}
     */
    public function plan(): array
    {
        $registered = $this->all();
        $conflicts  = [];
        $warnings   = [];
        $desired    = [];

        $seenSource = [];
        $seenKey    = [];

        foreach ($registered as $repo) {
            if ($repo['enabled'] !== true) {
                continue;
            }

            $desired[] = $repo;

            if ($repo['has_key'] !== true) {
                $conflicts[] = sprintf(
                    'Repository "%s" has no signing key; add the armored public key or an https:// key URL.',
                    $repo['id'],
                );
            }

            $sourceKey = strtolower($repo['url'] . '|' . $repo['suite'] . '|' . implode(',', $repo['components']));
            if (isset($seenSource[$sourceKey])) {
                $conflicts[] = sprintf(
                    'Repositories "%s" and "%s" declare the same source (%s); duplicate sources make apt fetch it twice.',
                    $seenSource[$sourceKey],
                    $repo['id'],
                    $repo['url'],
                );
            }
            $seenSource[$sourceKey] = $repo['id'];

            if (in_array(strtolower($repo['origin']), self::DISTRO_HOSTS, true)) {
                $conflicts[] = sprintf(
                    'Repository "%s" points at "%s", which already serves the appliance\'s own distribution.',
                    $repo['id'],
                    $repo['origin'],
                );
            }

            if ($repo['fingerprint'] !== '') {
                $seenKey[$repo['fingerprint']][] = $repo['id'];
            }
        }

        foreach ($seenKey as $fingerprint => $ids) {
            if (count($ids) > 1) {
                $warnings[] = sprintf(
                    'Repositories %s share signing key %s — legitimate for one vendor with several repositories, but worth confirming.',
                    '"' . implode('", "', $ids) . '"',
                    substr($fingerprint, -16),
                );
            }
        }

        $rendered = [];
        foreach ($desired as $repo) {
            $rendered[] = $repo + [
                'source' => self::sourceStanza($repo),
                'pin'    => self::pinStanza($repo),
            ];
        }

        return [
            'repos'      => $rendered,
            'registered' => count($registered),
            'enabled'    => count($desired),
            'conflicts'  => $conflicts,
            'warnings'   => $warnings,
            'ready'      => $conflicts === [],
        ];
    }

    /**
     * The `deb822` stanza apt will read for one repository.
     *
     * `Types: deb` only — a source repository (`deb-src`) is never generated, so
     * a curated repository cannot feed the build toolchain.
     *
     * @param array<string, mixed> $repo
     */
    public static function sourceStanza(array $repo): string
    {
        $lines = [
            'Types: deb',
            'URIs: ' . (string) $repo['url'],
            'Suites: ' . (string) $repo['suite'],
        ];

        if ((array) $repo['components'] !== []) {
            $lines[] = 'Components: ' . implode(' ', $repo['components']);
        }

        $lines[] = 'Architectures: ' . implode(' ', $repo['architectures']);
        $lines[] = 'Signed-By: ' . self::keyringPath((string) $repo['id']);
        $lines[] = 'Enabled: ' . ($repo['enabled'] ? 'yes' : 'no');

        return implode("\n", $lines) . "\n";
    }

    /**
     * The apt pin holding one repository below the distribution.
     *
     * @param array<string, mixed> $repo
     */
    public static function pinStanza(array $repo): string
    {
        return "# MicronOS curated plugin repository: {$repo['id']}\n"
            . "Package: *\n"
            . 'Pin: origin "' . (string) $repo['origin'] . "\"\n"
            . 'Pin-Priority: ' . self::PIN_PRIORITY . "\n";
    }

    public static function keyringPath(string $id): string
    {
        return self::KEYRING_DIR . '/' . self::FILE_PREFIX . $id . '.gpg';
    }

    public static function sourcesPath(string $id): string
    {
        return self::SOURCES_DIR . '/' . self::FILE_PREFIX . $id . '.sources';
    }

    public static function preferencesPath(string $id): string
    {
        return self::PREFERENCES_DIR . '/' . self::FILE_PREFIX . $id . '.pref';
    }

    // ---------------------------------------------------------------------
    // Daemon
    // ---------------------------------------------------------------------

    /**
     * Reconcile the daemon with the compiled plan and refresh the metadata.
     *
     * @return array<string, mixed>
     */
    public function apply(): array
    {
        $plan = $this->plan();
        if ($plan['ready'] !== true) {
            return [
                'success'   => false,
                'status'    => 'blocked',
                'message'   => implode('; ', $plan['conflicts']),
                'conflicts' => $plan['conflicts'],
            ];
        }

        $payload = [];
        foreach ($plan['repos'] as $repo) {
            $payload[] = [
                'id'            => $repo['id'],
                'name'          => $repo['name'],
                'url'           => $repo['url'],
                'suite'         => $repo['suite'],
                'components'    => $repo['components'],
                'architectures' => $repo['architectures'],
                'fingerprint'   => $repo['fingerprint'],
                'key_url'       => $repo['key_url'],
                'key_armor'     => $repo['key_armor'],
                'enabled'       => true,
            ];
        }

        // A key that is only stored as a URL is fetched through the shared
        // egress guard — the same SSRF defence, byte caps and TLS enforcement
        // the connector and catalogue paths use. A repository whose key cannot
        // be fetched is not applied at all.
        $fetched = $this->fetchKeys();
        if ($fetched['ok'] !== true) {
            $this->recordOutcome(['success' => false, 'message' => implode('; ', $fetched['errors'])]);

            return [
                'success' => false,
                'status'  => 'blocked',
                'message' => implode('; ', $fetched['errors']),
            ];
        }

        foreach ($payload as $index => $repo) {
            if (isset($fetched['keys'][$repo['id']])) {
                $payload[$index]['key_armor'] = $fetched['keys'][$repo['id']];
            }
        }

        $result = $this->call('plugin.repo.apply', ['repos' => $payload]);
        $this->recordOutcome($result, count($payload));

        // A successful apply means the daemon verified every fingerprint against
        // the key it imported, so the fetched material is known-good and can be
        // cached inline — the appliance then survives a restore without a fetch.
        if (($result['success'] ?? false) === true && $fetched['keys'] !== []) {
            $this->cacheKeys($fetched['keys']);
        }

        return $result;
    }

    /**
     * The signing key for one repository: the stored material, or the key URL
     * resolved through the shared egress guard.
     *
     * @param array<string, mixed> $repo
     *
     * @return array{ok: bool, armor: string, error: string|null}
     */
    public function fetchKey(array $repo): array
    {
        if (($repo['key_armor'] ?? '') !== '') {
            return ['ok' => true, 'armor' => (string) $repo['key_armor'], 'error' => null];
        }

        $keyUrl = (string) ($repo['key_url'] ?? '');
        if ($keyUrl === '') {
            return ['ok' => false, 'armor' => '', 'error' => 'the repository has no signing key'];
        }

        $staged = $this->stage($keyUrl, self::MAX_KEY_BYTES);
        if ($staged['ok'] !== true) {
            return ['ok' => false, 'armor' => '', 'error' => 'the signing key could not be fetched (' . $staged['error'] . ')'];
        }

        if (! self::looksLikeArmoredKey($staged['body'])) {
            return ['ok' => false, 'armor' => '', 'error' => 'the fetched key is not an ASCII-armored PGP public key block'];
        }

        return ['ok' => true, 'armor' => trim($staged['body']), 'error' => null];
    }

    /**
     * Fetch every signing key that is declared by URL rather than inline.
     *
     * @return array{ok: bool, keys: array<string, string>, errors: list<string>}
     */
    public function fetchKeys(): array
    {
        $keys   = [];
        $errors = [];

        foreach ($this->enabled() as $repo) {
            if ($repo['key_armor'] !== '' || $repo['key_url'] === '') {
                continue;
            }

            $fetched = $this->fetchKey($repo);
            if ($fetched['ok'] !== true) {
                $errors[] = sprintf('Repository "%s": %s.', $repo['id'], $fetched['error']);

                continue;
            }

            $keys[$repo['id']] = $fetched['armor'];
        }

        return ['ok' => $errors === [], 'keys' => $keys, 'errors' => $errors];
    }

    /**
     * Persist fetched key material so the repository no longer depends on the
     * URL being reachable.
     *
     * @param array<string, string> $keys repository id => armored key
     */
    public function cacheKeys(array $keys): void
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return;
        }

        foreach ($keys as $id => $armor) {
            $this->db->table('plugin_repos')->where('id', $id)->update(['key_armor' => $armor]);
        }
    }

    /**
     * Record that a repository verified successfully.
     *
     * Only ever **sets** the stamp. A failure is recorded by leaving it in place,
     * which is what makes "previously verified" survive a bad day and lets the
     * drift sweep call a later failure a lost verification rather than a
     * repository that never worked. Called on the operator's own successful
     * register/enable (§5.2) and by the scheduled sweep.
     */
    public function markVerified(string $id, ?string $at = null): void
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return;
        }

        $this->db->table('plugin_repos')->where('id', $id)->update([
            'last_verified_at' => $at ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Metadata-only refresh (`apt-get update` against the applied sources).
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        return $this->call('plugin.repo.refresh', []);
    }

    /**
     * What the daemon actually has on disk — presence means "applied", the same
     * presence-based contract the DHCP relay widget uses.
     *
     * @return array<string, mixed>
     */
    public function daemonStatus(): array
    {
        return $this->call('plugin.repo_status', []);
    }

    // ---------------------------------------------------------------------
    // Verification (read-only)
    // ---------------------------------------------------------------------

    /**
     * Verify one repository without changing anything.
     *
     * The management plane fetches the repository's **release metadata** through
     * the egress guard (small, signed, and the only artifact apt itself uses to
     * decide whether a source is trustworthy), resolves the signing key the same
     * way an apply would, and hands both to the daemon. The daemon imports the
     * key into a *private* keyring, verifies the signature against it, and
     * compares the signer to the declared fingerprint — so one call answers all
     * three questions: is it reachable, is it signed, and is it signed by the key
     * the operator named.
     *
     * Nothing is written: no source, pin, keyring or apt list, and no package
     * operation runs.
     *
     * @return array<string, mixed> report; `ok` is the outcome, plus the
     *                            daemon's key/signature/release detail
     */
    public function test(string $id): array
    {
        $repo = $this->get($id);
        if ($repo === null) {
            return self::testFailure($id, "unknown repository: {$id}", null, 'unknown_repository');
        }

        return $this->verify($repo);
    }

    /**
     * Verify a repository definition, whether or not it is stored yet.
     *
     * Separate from {@see self::test()} so a **candidate** row can be checked
     * before it is written. Registering and enabling are the two moments at which
     * the appliance is asked to trust a source, and both must be able to refuse;
     * neither has a stored row to look up until *after* it has decided. Takes the
     * shaped form {@see self::shape()} or the normalized form
     * {@see self::normalize()} returns — the fields this uses are the same.
     *
     * Read-only, exactly like `test()`: no source, pin, keyring or apt list is
     * written, nothing is cached, and no package operation runs.
     *
     * @param array<string, mixed> $repo
     *
     * @return array<string, mixed> report; `ok` is the outcome
     */
    public function verify(array $repo): array
    {
        $id = (string) ($repo['id'] ?? '');

        $stagedKey = $this->fetchKey($repo);
        if ($stagedKey['ok'] !== true) {
            return self::testFailure($id, (string) $stagedKey['error'], $repo, 'key_fetch_failed');
        }

        $release = $this->fetchRelease($repo, $releaseUrl);
        if ($release['ok'] !== true) {
            return self::testFailure(
                $id,
                'the repository did not serve signed release metadata (' . $release['error'] . ')',
                $repo,
                'release_unreachable',
            );
        }

        $result = $this->call('plugin.repo_test', [
            'repo'      => [
                'id'            => $id,
                'name'          => (string) ($repo['name'] ?? ''),
                'url'           => (string) ($repo['url'] ?? ''),
                'suite'         => (string) ($repo['suite'] ?? ''),
                'components'    => (array) ($repo['components'] ?? []),
                'architectures' => (array) ($repo['architectures'] ?? []),
                'fingerprint'   => (string) ($repo['fingerprint'] ?? ''),
                'key_url'       => '',
                'key_armor'     => $stagedKey['armor'],
                'enabled'       => true,
            ],
            'release'   => $release['body'],
            'signature' => $release['signature'],
        ]);

        return $result + [
            'ok'           => ($result['success'] ?? false) === true,
            'id'           => $id,
            'release_url'  => $releaseUrl,
            'release_mode' => $release['mode'],
            'checked_at'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Whether a verification report must stop a repository being registered or
     * enabled.
     *
     * Every failure blocks. A check that could not complete is not a pass, and
     * the genuinely-offline case is served by an explicit operator override
     * rather than by an allow-list of reasons that would quietly admit the next
     * failure mode somebody adds.
     *
     * @param array<string, mixed> $report a report from {@see self::test()} or
     *                                     {@see self::verify()}
     */
    public static function blocksSave(array $report): bool
    {
        return ($report['ok'] ?? false) !== true;
    }

    /**
     * The release metadata URLs to try, in order.
     *
     * Modern repositories publish a clearsigned `InRelease`. A flat repository
     * (`suite ./`) keeps it at the root instead of under `dists/`, and older
     * archives publish a detached `Release` + `Release.gpg` instead.
     *
     * @param array<string, mixed> $repo
     *
     * @return list<array{mode: string, url: string, signature_url: string|null}>
     */
    public static function releaseCandidates(array $repo): array
    {
        $base = rtrim((string) $repo['url'], '/');
        $dir  = $repo['suite'] === './' ? $base : $base . '/dists/' . $repo['suite'];

        return [
            ['mode' => 'inrelease', 'url' => $dir . '/InRelease', 'signature_url' => null],
            ['mode' => 'release', 'url' => $dir . '/Release', 'signature_url' => $dir . '/Release.gpg'],
        ];
    }

    /**
     * Fetch the release metadata for a repository through the egress guard.
     *
     * @param array<string, mixed> $repo
     * @param string|null          $url  the candidate that succeeded (out)
     *
     * @return array{ok: bool, mode: string, body: string, signature: string, error: string|null}
     */
    private function fetchRelease(array $repo, ?string &$url = null): array
    {
        $errors = [];

        foreach (self::releaseCandidates($repo) as $candidate) {
            $staged = $this->stage($candidate['url'], self::MAX_RELEASE_BYTES);
            if ($staged['ok'] !== true) {
                $errors[] = $candidate['url'] . ': ' . $staged['error'];

                continue;
            }

            $signature = '';
            if ($candidate['signature_url'] !== null) {
                $detached = $this->stage($candidate['signature_url'], self::MAX_RELEASE_BYTES);
                if ($detached['ok'] !== true) {
                    $errors[] = $candidate['signature_url'] . ': ' . $detached['error'];

                    continue;
                }
                $signature = $detached['body'];
            }

            $url = $candidate['url'];

            return [
                'ok'        => true,
                'mode'      => $candidate['mode'],
                'body'      => $staged['body'],
                'signature' => $signature,
                'error'     => null,
            ];
        }

        return ['ok' => false, 'mode' => '', 'body' => '', 'signature' => '', 'error' => implode('; ', $errors)];
    }

    /**
     * @param array<string, mixed>|null $repo
     *
     * @return array<string, mixed>
     */
    private static function testFailure(string $id, string $message, ?array $repo = null, string $reason = 'check_failed'): array
    {
        return [
            'ok'         => false,
            'success'    => false,
            'status'     => 'error',
            'id'         => $id,
            'message'    => $message,
            // A stable classification, so a monitoring task can act on *why* a
            // check failed instead of matching English in `message`.
            'reason'     => $reason,
            'checked_at' => date('Y-m-d H:i:s'),
            'steps'      => [],
            'warnings'   => [],
            'key'       => [
                'declared' => (string) ($repo['fingerprint'] ?? ''),
                'found'    => [],
                'matched'  => false,
            ],
            'signature' => ['verified' => false, 'signer' => '', 'mode' => ''],
            'release'   => [],
        ];
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    /**
     * Validate a repository definition.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, repo: array<string, mixed>|null, errors: list<string>}
     */
    public static function normalize(array $input): array
    {
        $errors = [];

        $id = strtolower(trim((string) ($input['id'] ?? '')));
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[] = 'The repository id must be 1–32 characters of lowercase letters, digits and dashes.';
        }

        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) > 128) {
            $errors[] = 'The display name may be at most 128 characters.';
        }

        $url  = trim((string) ($input['url'] ?? ''));
        $host = '';
        if (! self::validUrl($url)) {
            $errors[] = 'The repository URL must be a single-line https:// address with no credentials, whitespace or apt option syntax.';
        } else {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        }

        $suite = strtolower(trim((string) ($input['suite'] ?? '')));
        $flat  = $suite === './';
        if (! $flat && preg_match(self::SUITE_RE, $suite) !== 1) {
            $errors[] = 'The suite must look like "stable", or be "./" for a flat repository.';
        }

        $components = self::list((string) ($input['components'] ?? ''));
        if ($flat) {
            if ($components !== []) {
                $errors[] = 'A flat repository ("suite ./") carries no components.';
            }
            $components = [];
        } else {
            if ($components === []) {
                $components = ['main'];
            }
            if (count($components) > 8) {
                $errors[] = 'At most eight components are allowed.';
            }
            foreach ($components as $component) {
                if (preg_match(self::COMPONENT_RE, $component) !== 1) {
                    $errors[] = "Invalid component \"{$component}\".";
                }
            }
        }

        $architectures = self::list((string) ($input['architectures'] ?? ''));
        if ($architectures === []) {
            $architectures = ['amd64'];
        }
        if (count($architectures) > 8) {
            $errors[] = 'At most eight architectures are allowed.';
        }
        foreach ($architectures as $architecture) {
            if (! in_array($architecture, self::ARCHITECTURES, true)) {
                $errors[] = "Unsupported architecture \"{$architecture}\".";
            }
        }

        $fingerprint = self::fingerprint((string) ($input['key_fingerprint'] ?? ''));
        if ($fingerprint === '') {
            $errors[] = 'A 40- or 64-character hex signing-key fingerprint is required — it is the trust anchor and must come from the vendor out of band.';
        }

        $keyUrl   = trim((string) ($input['key_url'] ?? ''));
        $keyArmor = trim((string) ($input['key_armor'] ?? ''));

        if ($keyUrl !== '' && ! self::validUrl($keyUrl)) {
            $errors[] = 'The key URL must be an https:// address, like the repository URL.';
        }
        if ($keyArmor !== '') {
            if (strlen($keyArmor) > self::MAX_KEY_BYTES) {
                $errors[] = 'The armored key is too large (64 KiB limit).';
            } elseif (! self::looksLikeArmoredKey($keyArmor)) {
                $errors[] = 'The key must be an ASCII-armored PGP public key block.';
            }
        }
        if ($keyUrl !== '' && $keyArmor !== '') {
            $errors[] = 'Provide the signing key either inline or by URL, not both.';
        }
        if ($keyUrl === '' && $keyArmor === '') {
            $errors[] = 'A signing key is required: paste the armored public key or give an https:// key URL.';
        }

        if ($host !== '' && in_array(strtolower($host), self::DISTRO_HOSTS, true)) {
            $errors[] = "The host \"{$host}\" already serves the appliance's own distribution; a curated repository must not shadow it.";
        }

        if ($errors !== []) {
            return ['ok' => false, 'repo' => null, 'errors' => $errors];
        }

        return ['ok' => true, 'repo' => [
            'id'            => $id,
            'name'          => $name !== '' ? $name : $id,
            'url'           => $url,
            'origin'        => $host,
            'suite'         => $suite,
            'components'    => $components,
            'architectures' => $architectures,
            'fingerprint'   => $fingerprint,
            'key_url'       => $keyUrl,
            'key_armor'     => $keyArmor,
            'has_key'       => true,
            'flat'          => $flat,
            'enabled'       => (bool) ($input['enabled'] ?? true),
        ], 'errors' => []];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Shape a stored row the way the view and the planner expect.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $url         = (string) ($row['url'] ?? '');
        $fingerprint = self::fingerprint((string) ($row['key_fingerprint'] ?? ''));
        $keyArmor    = trim((string) ($row['key_armor'] ?? ''));
        $keyUrl      = trim((string) ($row['key_url'] ?? ''));
        $id          = (string) ($row['id'] ?? '');

        return [
            'id'            => $id,
            'name'          => (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : $id,
            'url'           => $url,
            'origin'        => (string) (parse_url($url, PHP_URL_HOST) ?? ''),
            'suite'         => (string) ($row['suite'] ?? ''),
            'components'    => self::list((string) ($row['components'] ?? '')),
            'architectures' => self::list((string) ($row['architectures'] ?? '')),
            'fingerprint'   => $fingerprint,
            'key_url'       => $keyUrl,
            'key_armor'     => $keyArmor,
            'has_key'       => $keyArmor !== '' || $keyUrl !== '',
            'flat'          => (string) ($row['suite'] ?? '') === './',
            'enabled'       => (int) ($row['enabled'] ?? 0) === 1,
            'last_refresh'  => $row['last_refresh'] ?? null,
            'last_outcome'  => (string) ($row['last_outcome'] ?? ''),
            'last_detail'   => (string) ($row['last_detail'] ?? ''),
            'added_by'      => (string) ($row['added_by'] ?? ''),
            'created_at'    => $row['created_at'] ?? null,
            // Drift state (§5.2). Carried on the row because a page cannot
            // re-derive it: "the vendor stopped publishing" is a comparison
            // between observations, not a property of one.
            'metadata_date'    => $row['metadata_date'] ?? null,
            'metadata_seen_at' => $row['metadata_seen_at'] ?? null,
            'key_created_at'   => $row['key_created_at'] ?? null,
            'key_expires_at'   => $row['key_expires_at'] ?? null,
            'last_check_at'    => $row['last_check_at'] ?? null,
            // The last *successful* verification, sticky across failures: a
            // repository that has ever verified can be told apart from one that
            // never has, which is what the drift sweep's regression warning needs.
            'last_verified_at' => $row['last_verified_at'] ?? null,
            'drift_kinds'      => self::list((string) ($row['drift_kinds'] ?? '')),
            'drift_since'      => $row['drift_since'] ?? null,
            'drift_detail'     => (string) ($row['drift_detail'] ?? ''),
            'keyring'       => self::keyringPath($id),
            'sources_path'  => self::sourcesPath($id),
            'preferences_path' => self::preferencesPath($id),
        ];
    }

    /**
     * Record the outcome of the last apply/refresh against every enabled repo,
     * so the page can show "applied" versus "last attempt failed, and why".
     *
     * @param array<string, mixed> $result
     */
    private function recordOutcome(array $result, int $applied = 0): void
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return;
        }

        $ok     = ($result['success'] ?? false) === true;
        $detail = $ok ? '' : (string) ($result['message'] ?? 'the daemon rejected the request');

        foreach ($this->enabled() as $repo) {
            $this->db->table('plugin_repos')->where('id', $repo['id'])->update([
                'last_refresh' => $ok ? date('Y-m-d H:i:s') : $repo['last_refresh'],
                'last_outcome' => $ok ? 'ok' : 'error',
                'last_detail'  => $detail,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    /**
     * Fetch one URL through the shared egress guard into a temp file and return
     * its body, enforcing a byte cap. The guard never returns the body itself, so
     * the staged file is the only way to read it back.
     *
     * @return array{ok: bool, body: string, error: string|null}
     */
    private function stage(string $url, int $maxBytes): array
    {
        $guard = $this->guard ?? \Config\Services::egressGuard();

        $staging = tempnam(sys_get_temp_dir(), 'micron-repo-fetch-');
        if ($staging === false) {
            return ['ok' => false, 'body' => '', 'error' => 'no writable staging path'];
        }

        $result = $guard->fetch($url, ['destination' => $staging]);

        if (($result['ok'] ?? false) !== true) {
            @unlink($staging);

            return ['ok' => false, 'body' => '', 'error' => (string) ($result['error'] ?? 'fetch failed')];
        }

        if ((int) ($result['bytes_in'] ?? 0) > $maxBytes) {
            @unlink($staging);

            return ['ok' => false, 'body' => '', 'error' => sprintf('over the %d KiB limit', intdiv($maxBytes, 1024))];
        }

        $body = (string) @file_get_contents($staging);
        @unlink($staging);

        return ['ok' => true, 'body' => $body, 'error' => null];
    }

    private function call(string $method, array $params): array
    {
        $ipc = $this->ipc ?? \Config\Services::ipc();

        $response = $ipc->call($method, $params);

        $error = $response['error'] ?? null;
        if (is_array($error) && $error !== []) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => (string) ($error['message'] ?? 'the daemon rejected the request'),
            ];
        }

        $result = $response['result'] ?? null;
        if (! is_array($result)) {
            return ['success' => false, 'status' => 'error', 'message' => 'the daemon returned no result'];
        }

        return $result;
    }

    /**
     * A comma- or whitespace-separated list field, normalised and deduplicated.
     *
     * @return list<string>
     */
    public static function list(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($value)) ?: [] as $token) {
            $token = trim($token);
            if ($token !== '') {
                $out[] = $token;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Normalise a fingerprint: gpg prints it in groups of four, operators paste
     * it with spaces or colons. Empty string means "not usable".
     */
    public static function fingerprint(string $value): string
    {
        $clean = strtoupper(preg_replace('/[\s:]+/', '', trim($value)) ?? '');

        return preg_match(self::FINGERPRINT_RE, $clean) === 1 ? $clean : '';
    }

    public static function validUrl(string $url): bool
    {
        return $url !== ''
            && strlen($url) <= 255
            && ! str_contains($url, '..')
            && preg_match(self::URL_RE, $url) === 1
            && is_string(parse_url($url, PHP_URL_HOST))
            && parse_url($url, PHP_URL_HOST) !== '';
    }

    public static function looksLikeArmoredKey(string $armor): bool
    {
        return str_contains($armor, '-----BEGIN PGP PUBLIC KEY BLOCK-----')
            && str_contains($armor, '-----END PGP PUBLIC KEY BLOCK-----')
            && preg_match('/[^\x20-\x7E\r\n\t]/', $armor) !== 1;
    }
}
