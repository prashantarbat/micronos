<?php

namespace App\Commands;

use App\Libraries\CronJobLog;
use App\Libraries\RepoDrift;
use App\Libraries\RepoRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Refreshes curated-repository metadata and reports drift.
 *
 *     php spark micron:repo-drift
 *     php spark micron:repo-drift --repo=netbird
 *     php spark micron:repo-drift --dry-run
 *     php spark micron:repo-drift --json
 *
 * The scheduled half of the Repositories screen. Installing from a curated
 * repository is an explicit operator action (§5.2); keeping an eye on repositories
 * that have quietly stopped working is not, because nobody notices a vendor that
 * stopped publishing until an upgrade fails.
 *
 * What it looks for, and why each needs history rather than a single reading:
 *
 *  - **A vendor that stopped publishing** — the release metadata keeps declaring
 *    the same `Date:`. A single observation cannot see that; comparing it with the
 *    previous sweep can.
 *  - **A signing key aging out** — the key has its own validity, distinct from the
 *    metadata's `Valid-Until`, and it is what actually stops verification.
 *  - **A rotated key** — the metadata is signed by a key other than the declared
 *    one, reported by the daemon as a `reason` code so it can be told apart from a
 *    vendor whose CDN was simply unreachable.
 *
 * Metadata refresh and verification are both read-only with respect to what apt
 * may install, so this task cannot widen the package surface however long it runs.
 *
 * Exits non-zero when any repository is in a **critical** drift state, so an
 * external monitor watching cron sees it too. A standing critical finding will
 * fail every day until it is fixed — deliberately: it stays broken until a person
 * acts, and a task that reports success while a key has expired is worse than no
 * task at all.
 */
class RepoDriftCommand extends BaseCommand
{
    /**
     * The scheduled task this command's outcome is recorded against.
     */
    private const CRON_JOB_ID = CronJobLog::REPO_DRIFT_JOB;

    protected $group       = 'MicronOS';
    protected $name        = 'micron:repo-drift';
    protected $description = 'Refresh and re-verify curated plugin repository metadata and flag drift (stale or expired metadata, keys expiring or rotated, repositories no longer publishing, or a source that previously verified and no longer does).';
    protected $usage       = 'micron:repo-drift [--repo=netbird,vendor] [--dry-run] [--json]';
    protected $options     = [
        '--repo'    => 'Comma-separated repository ids to check (default: every enabled repository).',
        '--dry-run' => 'Evaluate and report using the stored observations without refreshing or writing anything.',
        '--json'    => 'Emit the report as JSON instead of a console table.',
    ];

    public function run(array $params)
    {
        $dryRun = $this->flag($params, 'dry-run');
        $json   = $this->flag($params, 'json');
        $only   = $this->repoIds($params);

        $registry = new RepoRegistry();
        $drift    = new RepoDrift($registry);

        if (! $json) {
            CLI::write('MicronOS — curated repository drift sweep', 'yellow');
            CLI::write('===========================================', 'yellow');
            if ($dryRun) {
                CLI::write('Dry run — nothing is refreshed or written.', 'light_gray');
            }
            CLI::write('');
        }

        $report = $dryRun
            ? $this->inspectOnly($registry, $drift, $only)
            : $drift->sweep($only);

        if ($json) {
            CLI::write(json_encode($this->jsonShape($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $report['critical'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
        }

        if ($only === null && ! $dryRun) {
            $refreshOk = ($report['refresh']['success'] ?? false) === true;
            CLI::write(
                '  apt metadata refresh → ' . ($refreshOk ? 'ok' : 'failed: ' . (string) ($report['refresh']['message'] ?? 'unknown error')),
                $refreshOk ? 'light_gray' : 'red',
            );
        }

        if ($report['rows'] === []) {
            CLI::write('No enabled curated repositories to check; nothing to do.', 'light_gray');

            $this->finish($report, $dryRun, 'no enabled curated repositories to check');

            return EXIT_SUCCESS;
        }

        foreach ($report['rows'] as $row) {
            $this->renderRow($row);
        }

        $summary = $this->consoleSummary($report);

        CLI::write('');
        CLI::write($summary['line'], $summary['colour']);

        $this->finish($report, $dryRun, $summary['summary']);

        return $report['critical'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * Evaluate from stored observations without touching the network or the
     * database. Useful for "what is flagged right now", and safe to run anywhere.
     *
     * @param list<string>|null $only
     *
     * @return array<string, mixed>
     */
    private function inspectOnly(RepoRegistry $registry, RepoDrift $drift, ?array $only): array
    {
        $repos = $only === null
            ? $registry->enabled()
            : array_values(array_filter(
                $registry->all(),
                static fn ($r) => in_array((string) ($r['id'] ?? ''), $only, true),
            ));

        $rows = [];
        foreach ($repos as $repo) {
            $verdict = RepoDrift::evaluate($repo, []);
            $kinds   = array_map(static fn ($e) => $e['kind'], $verdict['kinds']);
            $was     = is_array($repo['drift_kinds'] ?? null) ? $repo['drift_kinds'] : [];

            // A dry run reports what the last real sweep concluded, not a fresh
            // verdict built from a check that never ran.
            $rows[] = [
                'id'       => (string) ($repo['id'] ?? ''),
                'name'     => (string) ($repo['name'] ?? ''),
                'drifting' => $was !== [],
                'highest'  => RepoDrift::highest($was),
                'kinds'    => $was,
                'summary'  => RepoDrift::summary($was),
                'detail'   => (string) ($repo['drift_detail'] ?? ''),
                'since'    => $repo['drift_since'] ?? null,
                'started'  => false,
                'cleared'  => false,
                'checked'  => $repo['last_check_at'] !== null,
                'reason'   => '',
                'last_verified_at' => $repo['last_verified_at'] ?? null,
            ];
            unset($verdict, $kinds);
        }

        return [
            'refresh'    => ['success' => true, 'message' => '(dry run)'],
            'checked_at' => date('Y-m-d H:i:s'),
            'rows'       => $rows,
        ] + RepoDrift::summarise($rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderRow(array $row): void
    {
        $id   = (string) $row['id'];
        $kinds = (array) $row['kinds'];

        if ($kinds === []) {
            CLI::write(sprintf('  %-16s → healthy', $id), 'green');

            return;
        }

        $colour = ($row['highest'] ?? '') === RepoDrift::CRITICAL ? 'red' : 'yellow';
        CLI::write(sprintf('  %-16s → %s', $id, (string) $row['summary']), $colour);

        foreach ((array) $row['entries'] as $entry) {
            CLI::write(sprintf('      %-8s %s: %s', strtoupper((string) $entry['severity']), RepoDrift::labelOf((string) $entry['kind']), (string) $entry['detail']), $colour);
        }

        if ($row['since'] !== null) {
            CLI::write(sprintf('      flagged since %s%s', (string) $row['since'], ($row['started'] ?? false) ? ' (new)' : ''), 'light_gray');
        }
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array{line: string, colour: string, summary: string}
     */
    private function consoleSummary(array $report): array
    {
        $checked  = (int) $report['checked'];
        $drifting = count((array) $report['drifting']);
        $critical = count((array) $report['critical']);

        $line = sprintf(
            '%d checked, %d healthy, %d drifting (%d critical).',
            $checked,
            $checked - $drifting,
            $drifting,
            $critical,
        );

        if ($report['started'] !== []) {
            $line .= ' Newly flagged: ' . implode(', ', (array) $report['started']) . '.';
        }

        if ($report['cleared'] !== []) {
            $line .= ' Cleared: ' . implode(', ', (array) $report['cleared']) . '.';
        }

        return [
            'line'    => $line,
            'colour'  => $critical > 0 ? 'red' : ($drifting > 0 ? 'yellow' : 'green'),
            'summary' => sprintf('%d checked, %d drifting, %d critical', $checked, $drifting, $critical),
        ];
    }

    /**
     * Record the run and raise the alerts, then get out of the way.
     *
     * Alerting keys on *change*: a repository newly flagged, or one that has just
     * recovered, is worth an audit entry. Repeating the same standing finding
     * every day would bury the change that actually matters. The run itself is
     * always recorded in the task history, so a standing problem still shows up
     * as a pattern.
     *
     * @param array<string, mixed> $report
     */
    private function finish(array $report, bool $dryRun, string $summary): void
    {
        $critical = count((array) $report['critical']);
        $drifting = count((array) $report['drifting']);

        $detail = [];
        foreach ((array) $report['rows'] as $row) {
            if (($row['drifting'] ?? false) !== true) {
                continue;
            }
            $detail[] = sprintf('%s: %s', (string) $row['id'], (string) $row['summary']);
        }

        $stage = $dryRun ? 'dry run: ' : '';
        $this->audit($stage . $summary . ($drifting > 0 ? ' — ' . implode('; ', $detail) : ''));

        if ($dryRun) {
            return;
        }

        $history = new CronJobLog(self::CRON_JOB_ID);

        if (! $history->exists()) {
            CLI::write('  (run not recorded: scheduled task ' . self::CRON_JOB_ID . ' is not present)', 'light_gray');

            return;
        }

        $history->record(
            $summary . ($critical > 0 ? ' — attention required' : ''),
            $critical > 0 ? 'error' : 'success',
            implode("\n", $detail),
        );
    }

    /**
     * One audit entry per run, naming only the repositories that are flagged —
     * and whether this run was the one that changed their state.
     *
     * @param array<string, mixed> $report
     */
    private function audit(string $details): void
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('audit_logs')) {
                return;
            }

            $db->table('audit_logs')->insert([
                // `admin_user`/`client_ip`/`action_type`/`target_resource` are
                // NOT NULL on an appliance whose database predates the migration
                // file's later edits, so every writer supplies them.
                'admin_user'      => 'system',
                'client_ip'       => '127.0.0.1',
                'user_id'         => null,
                'username'        => 'system',
                'action_type'     => 'plugin',
                'target_resource' => 'Plugin Repositories',
                'action'          => 'plugin.repo.drift',
                // `audit_logs` has no `details` column; the free-text description
                // of what happened belongs in `new_state` (TEXT), which is where
                // the other writers put it.
                'new_state'       => $details,
                'ip_address'      => '127.0.0.1',
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Recording must never mask the sweep's own result.
        }
    }

    /**
     * A stable machine-readable shape, so an external monitor does not have to
     * parse the console table.
     *
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function jsonShape(array $report): array
    {
        return [
            'checked_at' => $report['checked_at'] ?? date('Y-m-d H:i:s'),
            'refresh_ok' => ($report['refresh']['success'] ?? false) === true,
            'checked'    => $report['checked'],
            'drifting'   => $report['drifting'],
            'critical'   => $report['critical'],
            'started'    => $report['started'],
            'cleared'    => $report['cleared'],
            'repositories' => array_map(static fn ($row) => [
                'id'       => $row['id'],
                'drifting' => $row['drifting'],
                'highest'  => $row['highest'],
                'kinds'    => $row['kinds'],
                'summary'  => $row['summary'],
                'since'    => $row['since'],
                'last_verified' => $row['last_verified_at'] ?? null,
                'detail'   => array_map(
                    static fn ($entry) => ['kind' => $entry['kind'], 'severity' => $entry['severity'], 'detail' => $entry['detail']],
                    (array) ($row['entries'] ?? []),
                ),
            ], (array) $report['rows']),
        ];
    }

    /**
     * @param array<int|string,string|null> $params
     *
     * @return list<string>|null
     */
    private function repoIds(array $params): ?array
    {
        $raw = $this->optionString($params, 'repo');
        if (trim($raw) === '') {
            return null;
        }

        $ids = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids === [] ? null : $ids;
    }

    /**
     * Whether a flag option was supplied.
     *
     * CI4's parser does not split `--key=value`, so a caller writing `--dry-run=1`
     * produces an option literally named `dry-run=1`. Both that and a bare
     * `--dry-run` have to be recognised, or the flag is silently ignored.
     *
     * @param array<int|string,string|null> $params
     */
    private function flag(array $params, string $name): bool
    {
        if (array_key_exists($name, $params)) {
            return true;
        }

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                $value = substr($key, strlen($name) + 1);

                return ! in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
            }
        }

        $option = CLI::getOption($name);

        return $option !== null && ! in_array(strtolower((string) $option), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param array<int|string,string|null> $params
     */
    private function optionString(array $params, string $name): string
    {
        if (isset($params[$name]) && is_string($params[$name])) {
            return $params[$name];
        }

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return substr($key, strlen($name) + 1);
            }
        }

        $option = CLI::getOption($name);

        return is_string($option) ? $option : '';
    }
}
