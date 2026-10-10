<?php

namespace App\Controllers;

use App\Libraries\CronJobLog;
use App\Libraries\RepoDrift;
use App\Libraries\RepoPresets;
use App\Libraries\RepoRegistry;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Packages — curated plugin repositories (Plugin Spec §5.2, §9).
 *
 * The registry UI for the apt repositories plugin packages are installed from.
 * Everything that touches a system file happens in `micrond`: this controller
 * validates through {@see RepoRegistry}, shows the generated `deb822` stanza and
 * apt pin for each repository *before* they exist, and dispatches a declarative
 * apply. The page therefore renders, and refuses, identically with no appliance
 * behind it.
 *
 * The trust model is deliberately visible on the page: the signing-key
 * fingerprint the operator supplies out of band, the pin that holds every
 * curated repository below the distribution, and the pinned core packages that
 * no curated repository can ever replace.
 *
 * Trustworthiness and health are different questions, and the page answers both.
 * `Test` says "is this repository trustworthy right now?", which is a statement
 * about one moment. {@see RepoDrift} answers the slower question — is this
 * repository still being maintained? — by comparing observations over time, and
 * its verdict is rendered here as a badge per row plus a panel for the flagged
 * ones. The observation itself is scheduled (`micron:repo-drift`), because a
 * vendor that quietly stops publishing is not something an administrator
 * remembers to look for.
 */
class Packages extends BaseController
{
    public function index(): string
    {
        $registry = $this->registry();
        $plan     = $registry->plan();
        $status   = $registry->daemonStatus();

        // Presence on the daemon means applied — the same contract the DHCP
        // relay page uses, so "registered" and "applied" can be told apart.
        $applied = [];
        foreach ((array) ($status['repos'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $applied[(string) $entry['id']] = $entry;
            }
        }

        $repositories = [];
        foreach ($registry->all() as $repo) {
            $repo['applied']       = isset($applied[$repo['id']]);
            $repo['daemon']        = $applied[$repo['id']] ?? null;
            $repositories[]        = $repo;
            unset($applied[$repo['id']]);
        }

        // Files MicronOS wrote for a repository that is gone or disabled: the
        // next apply retires them.
        $orphans = array_values($applied);

        // The last verification report, if any. It is far too rich for a flash
        // message (key, signature, release fields, warnings), so it is rendered
        // as a panel — the same pattern the System Updates check uses.
        $testReport = session()->get('repo_test_report');
        $testReport = is_array($testReport) ? $testReport : null;

        $edit = null;
        $editId = (string) ($this->request->getGet('edit') ?? '');
        if ($editId !== '' && preg_match(RepoRegistry::ID_PATTERN, $editId) === 1) {
            $edit = $registry->get($editId);
        }

        return view('plugins/repositories', [
            'title'        => 'Plugin Repositories — MicronOS',
            'breadcrumb'   => 'Plugins: Repositories',
            'repositories' => $repositories,
            'orphans'      => $orphans,
            'plan'         => $plan,
            'daemon'       => $status,
            'testReport'   => $testReport,
            'edit'         => $edit,
            'presets'      => $registry->presets(),
            'drift'        => RepoDrift::state($repositories),
            'driftKinds'   => RepoDrift::kinds(),
            'driftWindow'  => RepoDrift::NOT_UPDATING_DAYS,
            'driftSchedule' => $this->driftSchedule(),
            'maxRepos'     => RepoRegistry::MAX_REPOS,
            'pinPriority'  => RepoRegistry::PIN_PRIORITY,
            'architectures' => RepoRegistry::ARCHITECTURES,
        ]);
    }

    /**
     * Register or update a repository (`plugins/repositories/store`).
     *
     * Validation is the library's: the row is only persisted once every field
     * matches a closed grammar, so a stored repository always compiles.
     */
    public function store(): RedirectResponse
    {
        $input = $this->request->getPost();

        $candidate = [
            'id'              => $input['id'] ?? '',
            'name'            => $input['name'] ?? '',
            'url'             => $input['url'] ?? '',
            'suite'           => $input['suite'] ?? '',
            'components'      => $input['components'] ?? '',
            'architectures'   => $input['architectures'] ?? '',
            'key_fingerprint' => $input['key_fingerprint'] ?? '',
            'key_url'         => $input['key_url'] ?? '',
            'key_armor'       => $input['key_armor'] ?? '',
            'enabled'         => true,
        ];

        // Validate before verifying: a malformed definition should fail on its
        // fields, and a bad row must never be persisted even under the override.
        $normalised = RepoRegistry::normalize($candidate);
        if ($normalised['ok'] !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', 'Repository not saved: ' . implode(' ', $normalised['errors']));
        }

        // Registering is the moment the appliance is asked to trust a source, so
        // the source is checked *before* the row exists: an unreachable
        // repository, or one whose metadata is not signed by the declared key,
        // is refused rather than stored. The override is for a deliberately
        // offline check, never a silent fallback.
        $override = $this->skipVerification();
        if (! $override) {
            $report = $this->registry()->verify($normalised['repo']);
            if (RepoRegistry::blocksSave($report)) {
                // Keep the report so the panel can show exactly why it was refused.
                session()->set('repo_test_report', $report);

                return redirect()->to(base_url('plugins/repositories'))
                    ->with('error', $this->verificationRefusal('registered', $normalised['repo'], $report));
            }
        }

        $result = $this->registry()->save($candidate, (string) (session('username') ?? ''));

        if ($result['ok'] !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', 'Repository not saved: ' . implode(' ', $result['errors']));
        }

        // The candidate verified a moment ago (unless the operator overrode it),
        // so stamp the success: a later sweep that fails can then report a *lost*
        // verification rather than a repository that never worked.
        if (! $override) {
            $this->registry()->markVerified($result['id']);
        }

        // The definition just changed, so a previous verification report no
        // longer describes this row. A rejected submit changes nothing and keeps
        // the report it had.
        $this->forgetTestReport();

        return redirect()->to(base_url('plugins/repositories'))->with(
            'success',
            $override
                ? sprintf('Repository "%s" saved WITHOUT verification (offline override). Apply the repositories to make it live.', $result['id'])
                : sprintf('Repository "%s" verified and saved. Apply the repositories to make it live.', $result['id']),
        );
    }

    public function toggle(string $id): RedirectResponse
    {
        $registry = $this->registry();
        $repo     = $registry->get($id);

        if ($repo === null) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', "Unknown repository: {$id}.");
        }

        $enabling = $repo['enabled'] !== true;
        $override = $this->skipVerification();

        // Enabling is the other moment trust is asked for. Disabling never is:
        // retiring a source must always be possible.
        if ($enabling && ! $override) {
            $report = $registry->test($id);
            session()->set('repo_test_report', $report);

            if (RepoRegistry::blocksSave($report)) {
                return redirect()->to(base_url('plugins/repositories'))
                    ->with('error', $this->verificationRefusal('enabled', $repo, $report));
            }
        }

        $registry->setEnabled($id, $enabling);

        if ($enabling && ! $override) {
            $registry->markVerified($id);
        }

        $this->forgetTestReport();

        if (! $enabling) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('success', sprintf('Repository "%s" disabled. Apply the repositories to update the appliance.', $id));
        }

        return redirect()->to(base_url('plugins/repositories'))->with(
            'success',
            $override
                ? sprintf('Repository "%s" enabled WITHOUT verification (offline override). Apply the repositories to make it live.', $id)
                : sprintf('Repository "%s" verified and enabled. Apply the repositories to make it live.', $id),
        );
    }

    /**
     * Forget a repository. The generated files are retired by the next apply,
     * which is where reading and writing system state belongs.
     */
    public function delete(string $id): RedirectResponse
    {
        if ($this->registry()->delete($id)) {
            $this->forgetTestReport();

            return redirect()->to(base_url('plugins/repositories'))
                ->with('success', "Repository \"{$id}\" removed. Apply the repositories to retire its files.");
        }

        return redirect()->to(base_url('plugins/repositories'))
            ->with('error', "Unknown repository: {$id}.");
    }

    /**
     * Reconcile the appliance with the enabled repositories.
     */
    public function apply(): RedirectResponse
    {
        $result = $this->registry()->apply();

        if (($result['success'] ?? false) !== true) {
            $message = (string) ($result['message'] ?? 'the daemon rejected the request');
            if (($result['status'] ?? '') === 'blocked') {
                $message = 'Repository plan is not applicable: ' . $message;
            }

            return redirect()->to(base_url('plugins/repositories'))->with('error', $message);
        }

        $count = count((array) ($result['repos'] ?? []));

        return redirect()->to(base_url('plugins/repositories'))
            ->with('success', sprintf(
                'Repositories applied: %d source(s) reconciled, signing keys verified, metadata refreshed.',
                $count,
            ));
    }

    /**
     * Verify a repository without touching the appliance.
     *
     * The plan shows what *would* be written; this checks what *is* being
     * served: the release metadata is fetched, handed to the daemon, verified
     * against a private keyring built from the signing key, and the signer is
     * compared to the declared fingerprint. Nothing is written and no package
     * operation runs, so a vendor's repository can be checked before an apply.
     */
    public function test(string $id): RedirectResponse
    {
        $report = $this->registry()->test($id);
        session()->set('repo_test_report', $report);

        if (($report['ok'] ?? false) !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', sprintf(
                    'Repository "%s" failed verification: %s',
                    $id,
                    (string) ($report['message'] ?? 'unknown error'),
                ));
        }

        $signer = (string) ($report['signature']['signer'] ?? '');

        return redirect()->to(base_url('plugins/repositories'))
            ->with('success', sprintf(
                'Repository "%s" verified: the source is reachable and its release metadata is signed by the declared key %s. Nothing was changed.',
                $id,
                $signer === '' ? '' : '…' . substr($signer, -16),
            ));
    }

    /**
     * Prove an image-shipped **preset** against its vendor
     * (`plugins/repositories/preset/<id>/test`).
     *
     * A preset's entire value is the claim that the fingerprint it declares really
     * signs the vendor's metadata. That claim was established out of band when the
     * preset was authored; this action is the operator's own way to re-check it,
     * from the page, without first registering the repository and trusting it.
     *
     * It checks the **shipped** trust data, not a stored row: the URL, suite, key
     * and fingerprint all come from {@see RepoPresets}, so the answer is about what
     * *this image* would install from. That is deliberately independent of any row
     * an operator has registered — and of a row they may have re-pointed at a
     * different key after a rotation. `$id` need not be registered at all.
     *
     * Read-only, exactly like the per-row Test: the release metadata is fetched
     * through the egress guard, the daemon verifies it against a private keyring
     * built from the shipped key and compares gpg's signer to the shipped
     * fingerprint. No source, pin, keyring or apt list is written, no repository
     * row is created or changed, and no package operation runs.
     */
    public function testPreset(string $id): RedirectResponse
    {
        $preset = (new RepoPresets())->get($id);
        if ($preset === null) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', sprintf('Unknown preset: %s.', $id));
        }

        // The preset's trust data goes through the same grammars a hand-entered
        // repository must satisfy, so a preset cannot express a definition the
        // registry would refuse. `verify()` takes the *normalized* form, which is
        // where the declared fingerprint lives.
        $normalised = RepoRegistry::normalize(RepoPresets::input($preset));
        if ($normalised['ok'] !== true || $normalised['repo'] === null) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', sprintf(
                    'Preset "%s" cannot be verified: %s',
                    $id,
                    implode(' ', $normalised['errors']),
                ));
        }

        $report = $this->registry()->verify($normalised['repo']);

        // Tag the report so the panel can say *what* was checked — the image's
        // shipped preset, and its own provenance — rather than a node the operator
        // authored. It is the same session key the per-row report uses, so the two
        // cannot both be on screen claiming to describe different things.
        $report['preset']              = (string) $preset['id'];
        $report['preset_name']         = (string) $preset['name'];
        $report['preset_url']          = (string) $preset['url'];
        $report['preset_key_source']   = (string) $preset['key_source_url'];
        $report['preset_verified_at']  = (string) $preset['verified_at'];
        session()->set('repo_test_report', $report);

        if (($report['ok'] ?? false) !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', sprintf(
                    'Preset "%s" failed verification: %s',
                    $id,
                    (string) ($report['message'] ?? 'unknown error'),
                ));
        }

        $signer = (string) ($report['signature']['signer'] ?? '');

        return redirect()->to(base_url('plugins/repositories'))
            ->with('success', sprintf(
                'Preset "%s" verified: the vendor is reachable and its release metadata is signed by the shipped fingerprint %s. Nothing was written and no repository was registered.',
                $id,
                $signer === '' ? '' : '…' . substr($signer, -16),
            ));
    }

    /**
     * Register an image-shipped preset repository (`plugins/repositories/preset/<id>`).
     *
     * A Class C plugin needs a vendor repository, and typing a 40-character
     * fingerprint from a vendor's documentation is exactly the step an operator
     * gets wrong. Adopting the preset uses the image's verified trust data and
     * lands the row **disabled**: the operator still enables it, and enabling is
     * still the consent for talking to the vendor.
     */
    public function seed(string $id): RedirectResponse
    {
        $result = $this->registry()->seedPreset($id, (string) (session('username') ?? 'preset'));

        if ($result['ok'] !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', 'Preset not registered: ' . implode(' ', $result['errors']));
        }

        if (($result['created'] ?? false) !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('success', sprintf(
                    'Repository "%s" is already registered — its stored fingerprint was left untouched.',
                    $result['id'],
                ));
        }

        return redirect()->to(base_url('plugins/repositories'))
            ->with('success', sprintf(
                'Preset "%s" registered, disabled. Enable it and apply the repositories to let its key be verified.',
                $result['id'],
            ));
    }

    /**
     * Drop the stored verification report.
     *
     * A test result is a statement about one exact repository definition at one
     * moment. Editing, disabling or removing that repository invalidates it, and
     * the panel must not keep claiming the old answer. Returns nothing so it can
     * be called before a redirect.
     */
    public function clearTest(): RedirectResponse
    {
        $this->forgetTestReport();

        return redirect()->to(base_url('plugins/repositories'));
    }

    private function forgetTestReport(): void
    {
        session()->remove('repo_test_report');
    }

    /**
     * Whether the operator explicitly waived verification for this request.
     *
     * A deliberately offline check is a legitimate reason to register or enable a
     * repository without reaching the vendor — but it is a *choice*, never a
     * silent fallback, so it travels as a form field rather than being inferred
     * from a failure.
     */
    private function skipVerification(): bool
    {
        $value = $this->request->getPost('skip_verify');

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * The refusal message for a repository that failed verification, naming the
     * reason and pointing at the only way past it.
     *
     * @param array<string, mixed> $repo
     * @param array<string, mixed> $report
     */
    private function verificationRefusal(string $verb, array $repo, array $report): string
    {
        $message = trim((string) ($report['message'] ?? ''));

        return sprintf(
            'Repository "%s" was not %s: verification failed (%s).%s Use the offline override to %s it unverified.',
            (string) ($repo['id'] ?? ''),
            $verb,
            (string) ($report['reason'] ?? 'check_failed'),
            $message === '' ? '' : ' ' . $message,
            $verb,
        );
    }

    /**
     * Refresh curated-repository metadata and flag drift, on demand
     * (`plugins/repositories/drift`).
     *
     * The same sweep the scheduled task runs, for the operator who wants the
     * answer now rather than tomorrow at 04:45. Read-only with respect to what
     * apt may install: metadata is refreshed and verified, never installed from,
     * so pressing this cannot widen the package surface.
     */
    public function drift(): RedirectResponse
    {
        $report = (new RepoDrift($this->registry()))->sweep();

        $checked  = (int) ($report['checked'] ?? 0);
        $drifting = count((array) ($report['drifting'] ?? []));
        $critical = count((array) ($report['critical'] ?? []));

        if ($checked === 0) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('success', 'No enabled curated repositories to check — nothing to flag.');
        }

        // The refresh result matters: a verdict drawn from metadata that could not
        // be fetched is a statement about the fetch, not about the vendor.
        if (($report['refresh']['success'] ?? false) !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', sprintf(
                    'Drift check ran against %d repository(ies) but the apt metadata refresh failed: %s',
                    $checked,
                    (string) ($report['refresh']['message'] ?? 'unknown error'),
                ));
        }

        if ($drifting === 0) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('success', sprintf('%d repository(ies) checked: metadata refreshed, no drift flagged.', $checked));
        }

        $named = implode(', ', array_slice((array) $report['drifting'], 0, 5));

        return redirect()->to(base_url('plugins/repositories'))->with(
            $critical > 0 ? 'error' : 'warning',
            sprintf(
                'Drift flagged on %d of %d repository(ies) (%d critical): %s.',
                $drifting,
                $checked,
                $critical,
                $named,
            ),
        );
    }

    /**
     * The scheduled task that keeps the drift verdicts current, so the page can
     * say whether anything is actually watching — a badge nobody refreshes is
     * worse than no badge, because it looks like an assurance.
     *
     * @return array<string, mixed>|null
     */
    private function driftSchedule(): ?array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('cron_jobs')) {
            return null;
        }

        $row = $db->table('cron_jobs')->where('id', CronJobLog::REPO_DRIFT_JOB)->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    public function refresh(): RedirectResponse
    {
        $result = $this->registry()->refresh();

        if (($result['success'] ?? false) !== true) {
            return redirect()->to(base_url('plugins/repositories'))
                ->with('error', 'Repository refresh failed: ' . (string) ($result['message'] ?? 'unknown error'));
        }

        return redirect()->to(base_url('plugins/repositories'))
            ->with('success', 'Repository metadata refreshed.');
    }

    private function registry(): RepoRegistry
    {
        return \Config\Services::repoRegistry();
    }
}
