<?php

namespace App\Commands;

use App\Libraries\AcmeManager;
use App\Libraries\CronJobLog;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Renews publicly-trusted certificates before they expire.
 *
 *     php spark micron:acme-renew
 *     php spark micron:acme-renew --id=12
 *     php spark micron:acme-renew --window=45
 *     php spark micron:acme-renew --dry-run
 *     php spark micron:acme-renew --json
 *
 * The scheduled half of the ACME panel. A certificate that expires takes down
 * whatever presents it — the Web GUI, a VPN tunnel, LDAP over TLS — and the
 * failure arrives without warning unless something looks ahead of it. `valid_until`
 * on the certificate is the only clock, so this is a read of the PKI table plus
 * an issuance, and no state of its own.
 *
 * Two things it deliberately does **not** do:
 *
 *  - **It never renews over DNS-01.** A DNS-01 order needs a TXT record published
 *    by a human or a DNS provider integration this appliance does not have, so a
 *    scheduled run would open an order and then sit there failing. Those
 *    certificates are reported as needing attention and left to the operator,
 *    who can see the records on the Certificates page.
 *  - **It does not force.** A certificate outside the window is left alone; there
 *    is no `--force` that would burn a CA's rate limit on a task that runs daily.
 *
 * Exits non-zero when any renewal failed, so an external monitor watching cron
 * sees the same thing the Cron page does. A failed renewal also keeps its
 * certificate in the due list, so the next run retries it.
 */
class AcmeRenewCommand extends BaseCommand
{
    /** The scheduled task this command's outcome is recorded against. */
    private const CRON_JOB_ID = CronJobLog::ACME_RENEW_JOB;

    protected $group       = 'MicronOS';
    protected $name        = 'micron:acme-renew';
    protected $description = 'Renew ACME / Let\'s Encrypt certificates inside their renewal window and install the result into the PKI store.';
    protected $usage       = 'micron:acme-renew [--id=12,14] [--window=30] [--dry-run] [--json]';
    protected $options     = [
        '--id'      => 'Comma-separated certificate ids to renew (default: every ACME certificate inside the window).',
        '--window'  => 'Renew this many days before expiry (default: ' . AcmeManager::RENEW_WINDOW_DAYS . ').',
        '--dry-run' => 'List what is due without contacting the CA or writing anything.',
        '--json'    => 'Emit the report as JSON instead of console lines.',
    ];

    public function run(array $params)
    {
        $dryRun = $this->flag($params, 'dry-run');
        $json   = $this->flag($params, 'json');
        $window = $this->intOption($params, 'window', AcmeManager::RENEW_WINDOW_DAYS);
        $only   = $this->ids($params);

        // Resolved from the container so a test can inject a manager built on a
        // stubbed CA, exactly as the page and the public route do.
        $acme = \Config\Services::acme();

        if (! $json) {
            CLI::write('MicronOS — ACME certificate renewal', 'yellow');
            CLI::write('====================================', 'yellow');
            if ($dryRun) {
                CLI::write('Dry run — nothing is requested from the CA and nothing is written.', 'light_gray');
            }
            CLI::write('');
        }

        $report = $dryRun
            ? $this->inspect($acme, $window, $only)
            : $this->renew($acme, $window, $only);

        // Recorded before the output is chosen, so a `--json` run — the mode a
        // monitor or a configuration-management task uses — leaves the same
        // history a console run does. Recording only on the console path would
        // make the Cron page blind to exactly the runs that were automated.
        $this->finish($report, $dryRun);

        if ($json) {
            CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $report['failed'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
        }

        if ($report['due'] === []) {
            CLI::write('  No certificate is inside the ' . $window . '-day renewal window.', 'light_gray');

            return EXIT_SUCCESS;
        }

        foreach ($report['rows'] as $row) {
            $this->renderRow($row);
        }

        CLI::write('');

        $line = sprintf(
            '  %d due, %d renewed, %d failed%s',
            count($report['due']),
            $report['renewed'],
            count($report['failed']),
            $report['skipped'] === [] ? '' : ', ' . count($report['skipped']) . ' waiting or needing DNS-01 by hand',
        );

        CLI::write($line, $report['failed'] === [] ? 'green' : 'red');

        return $report['failed'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * What the sweep would consider, with no CA conversation.
     *
     * @param list<int>|null $only
     *
     * @return array<string, mixed>
     */
    private function inspect(AcmeManager $acme, int $window, ?array $only): array
    {
        $due = $this->filtered($acme->due($window), $only);

        $rows = [];
        foreach ($due as $row) {
            $rows[] = $this->row($row, 'would renew', '', false);
        }

        return [
            'checked_at' => date('Y-m-d H:i:s'),
            'window_days' => $window,
            'dry_run'    => true,
            'due'        => $this->dueEcho($due),
            'rows'       => $rows,
            'renewed'    => 0,
            'failed'     => [],
            'skipped'    => [],
        ];
    }

    /**
     * Renew everything due.
     *
     * @param list<int>|null $only
     *
     * @return array<string, mixed>
     */
    private function renew(AcmeManager $acme, int $window, ?array $only): array
    {
        $due   = $this->filtered($acme->due($window), $only);
        $rows  = [];
        $failed = [];
        $skipped = [];
        $renewed = 0;

        foreach ($due as $dueRow) {
            $id = (int) $dueRow['id'];

            // DNS-01 cannot be completed unattended: opening the order would only
            // leave a pending row behind and a challenge that never resolves.
            if ((string) ($dueRow['challenge_type'] ?? 'http-01') !== 'http-01') {
                $skipped[] = [
                    'id'      => $id,
                    'name'    => (string) $dueRow['name'],
                    'reason'  => 'dns_01',
                    'message' => 'published by hand: this certificate renews over DNS-01',
                ];
                $rows[] = $this->row($dueRow, 'needs DNS-01 by hand', AcmeManager::directoryLabel((string) ($dueRow['directory'] ?? '')), false);

                continue;
            }

            $result = $acme->renew($id);

            if ($result['ok'] === true) {
                $renewed++;
                $rows[] = $this->row($dueRow, 'renewed', 'certificate #' . (int) $result['cert_id'] . ' re-issued', true);

                continue;
            }

            // "Not finished yet" is a bounded wait, not a failure: the next run
            // continues the same order, and the certificate stays due.
            $pending = ($result['status'] ?? '') === 'pending';

            $entry = [
                'id'      => $id,
                'name'    => (string) $dueRow['name'],
                'reason'  => (string) $result['reason'],
                'message' => (string) $result['message'],
            ];

            if ($pending) {
                $skipped[] = $entry;
                $rows[]    = $this->row($dueRow, 'awaiting validation', (string) $result['message'], false);

                continue;
            }

            $failed[] = $entry;
            $rows[]   = $this->row($dueRow, 'FAILED (' . $result['reason'] . ')', (string) $result['message'], false);
        }

        return [
            'checked_at'  => date('Y-m-d H:i:s'),
            'window_days' => $window,
            'dry_run'     => false,
            'due'         => $this->dueEcho($due),
            'rows'        => $rows,
            'renewed'     => $renewed,
            'failed'      => $failed,
            'skipped'     => $skipped,
        ];
    }

    /**
     * The due list, optionally narrowed to named certificates.
     *
     * @param list<array<string, mixed>> $due
     * @param list<int>|null             $only
     *
     * @return list<array<string, mixed>>
     */
    private function filtered(array $due, ?array $only): array
    {
        if ($only === null) {
            return $due;
        }

        return array_values(array_filter($due, static fn (array $row): bool => in_array((int) $row['id'], $only, true)));
    }

    /**
     * @param array<string, mixed> $due
     *
     * @return list<array<string, mixed>>
     */
    private function dueEcho(array $due): array
    {
        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'domains'    => (string) ($row['domains'] ?? ''),
            'valid_until' => (string) ($row['valid_until'] ?? ''),
            'days_left'  => (int) ($row['days_left'] ?? 0),
            'directory'  => (string) ($row['directory'] ?? ''),
            'challenge'  => (string) ($row['challenge_type'] ?? ''),
        ], $due);
    }

    /**
     * @param array<string, mixed> $due
     *
     * @return array<string, mixed>
     */
    private function row(array $due, string $outcome, string $detail, bool $ok): array
    {
        return [
            'id'      => (int) $due['id'],
            'name'    => (string) $due['name'],
            'days'    => (int) ($due['days_left'] ?? 0),
            'names'   => (string) ($due['domains'] ?? $due['san_dns'] ?? ''),
            'outcome' => $outcome,
            'detail'  => $detail,
            'ok'      => $ok,
        ];
    }

    private function renderRow(array $row): void
    {
        $colour = match (true) {
            $row['ok'] === true                        => 'green',
            str_starts_with((string) $row['outcome'], 'FAILED') => 'red',
            $row['outcome'] === 'would renew'          => 'light_gray',
            default                                    => 'yellow',
        };

        CLI::write(sprintf('  %-32s %4d days  → %s', substr((string) $row['name'], 0, 32), (int) $row['days'], (string) $row['outcome']), $colour);

        if ((string) $row['detail'] !== '') {
            CLI::write('      ' . $row['detail'], 'light_gray');
        }
    }

    /**
     * Record the outcome in the task's history, so the Cron page shows the
     * pattern across runs rather than only the last line.
     *
     * @param array<string, mixed> $report
     */
    private function finish(array $report, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $summary = sprintf(
            'acme renewal: %d due, %d renewed, %d failed, %d awaiting/needing DNS-01',
            count($report['due']),
            $report['renewed'],
            count($report['failed']),
            count($report['skipped']),
        );

        $detail = [];
        foreach ($report['failed'] as $failure) {
            $detail[] = 'FAILED ' . $failure['name'] . ' (' . $failure['reason'] . '): ' . $failure['message'];
        }
        foreach ($report['skipped'] as $skipped) {
            $detail[] = 'DEFERRED ' . $skipped['name'] . ' (' . $skipped['reason'] . '): ' . $skipped['message'];
        }

        $log = new CronJobLog(self::CRON_JOB_ID);
        if ($log->exists()) {
            $log->record($summary, $report['failed'] === [] ? 'success' : 'error', implode("\n", $detail));
        }
    }

    /**
     * Whether a flag was passed.
     *
     * Spark hands options through as `name => value`, and also accepts
     * `--name=value` as a single key, so both spellings have to be understood.
     */
    private function flag(array $params, string $name): bool
    {
        if (array_key_exists($name, $params) || array_key_exists('--' . $name, $params)) {
            return true;
        }

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return ! in_array(strtolower((string) $value), ['0', 'false', 'no', 'off'], true)
                    && ! in_array(strtolower(substr($key, strlen($name) + 1)), ['0', 'false', 'no', 'off'], true);
            }
        }

        return false;
    }

    private function intOption(array $params, string $name, int $default): int
    {
        $value = $this->option($params, $name);

        return $value > 0 ? $value : $default;
    }

    /** The value of an option, however Spark delivered it. */
    private function option(array $params, string $name): string
    {
        $value = $params[$name] ?? $params['--' . $name] ?? null;

        if (! is_string($value) || $value === '') {
            foreach ($params as $key => $candidate) {
                if (is_string($key) && str_starts_with($key, $name . '=')) {
                    $value = substr($key, strlen($name) + 1);

                    break;
                }
            }
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<int>|null
     */
    private function ids(array $params): ?array
    {
        $raw = $this->option($params, 'id');
        if (trim($raw) === '') {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $candidate) {
            $id = (int) trim($candidate);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids === [] ? null : $ids;
    }
}
