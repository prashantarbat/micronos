<?php

namespace App\Commands;

use App\Libraries\CronJobLog;
use App\Libraries\IpcClient;
use App\Libraries\PppoeBackoff;
use App\Libraries\PppoeCompiler;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Redials PPPoE links that are down and restarts the access concentrator.
 *
 *     php spark micron:pppoe-redial
 *     php spark micron:pppoe-redial --interface=eth0
 *     php spark micron:pppoe-redial --down
 *     php spark micron:pppoe-redial --force
 *     php spark micron:pppoe-redial --reset-backoff
 *
 * A PPPoE link that has gone stale — an ISP-side session drop the echo timer
 * did not catch, a half-open session after a provider outage — needs the link
 * torn down and rebuilt rather than nudged. That is what this does, and it is
 * the maintenance half of the PPPoE feature: the browser `Apply` button is the
 * configuration half.
 *
 * Only links that are actually down are rebuilt. Tearing a healthy link down
 * costs a fresh handshake and a few seconds of outage, so a task that ran every
 * twenty minutes would create the very interruption it exists to prevent. The
 * daemon inspects each link and repairs the ones that are not up; this command
 * previews that decision and passes `--force` through when an operator wants
 * the old unconditional behaviour.
 *
 * Escalating backoff:
 * If a link repeatedly fails to recover, it is placed into escalating backoff
 * (5m -> 15m -> 30m -> 60m -> 120m) so it is retried less aggressively, and a
 * persistent alert is raised in audit logs and system logs.
 */
class PppoeRedial extends BaseCommand
{
    /**
     * The scheduled task this command's outcome is recorded against.
     */
    private const CRON_JOB_ID = CronJobLog::PPPOE_REDIAL_JOB;

    protected $group       = 'MicronOS';
    protected $name        = 'micron:pppoe-redial';
    protected $description = 'Redials down PPPoE client links with escalating backoff and restarts the PPPoE access concentrator.';
    protected $usage       = 'micron:pppoe-redial [--interface=eth0,eth1] [--down] [--force] [--ipc=1] [--reset-backoff]';
    protected $options     = [
        '--interface'      => 'Comma-separated physical devices to redial (default: every PPPoE interface).',
        '--down'           => 'Only take the links down; do not bring them back up.',
        '--force'          => 'Redial every link, even ones that are already up, bypassing backoff.',
        '--ipc'            => 'Report the plan without touching the links (used when the Cron page runs this).',
        '--reset-backoff'  => 'Reset escalating backoff counters for target interfaces.',
    ];

    public function run(array $params)
    {
        $downOnly     = $this->flag($params, 'down');
        $reportOnly   = $this->flag($params, 'ipc');
        $force        = $this->flag($params, 'force');
        $resetBackoff = $this->flag($params, 'reset-backoff');

        $compiler = new PppoeCompiler();
        $history  = new CronJobLog(self::CRON_JOB_ID);
        $backoff  = new PppoeBackoff();

        CLI::write('MicronOS — PPPoE redial', 'yellow');
        CLI::write('=========================', 'yellow');
        if ($reportOnly) {
            CLI::write('Report-only mode — the links will not be touched.', 'light_gray');
        }
        CLI::write('');

        // Resolve the interfaces to act on: an explicit list, or every
        // interface currently configured to dial PPPoE.
        $requested = [];
        foreach (preg_split('/[\s,]+/', $this->optionString($params, 'interface')) as $device) {
            $device = trim((string) $device);
            if ($device !== '') {
                $requested[] = $device;
            }
        }

        if ($resetBackoff) {
            if ($requested !== []) {
                foreach ($requested as $device) {
                    $backoff->reset($device);
                    CLI::write("  {$device} → backoff reset", 'green');
                }
            } else {
                $backoff->reset();
                CLI::write('  All PPPoE interface backoff counters reset.', 'green');
            }
        }

        $links = [];
        foreach ($compiler->fetchClientInterfaces() as $iface) {
            $device = (string) $iface['physical_device'];
            if ($requested !== [] && ! in_array($device, $requested, true)) {
                continue;
            }
            $links[] = [
                'interface' => $device,
                'name'      => (string) ($iface['name'] ?? $device),
                'peer'      => $compiler->peerFileName($device),
            ];
        }

        if ($requested !== []) {
            // An interface named on the command line that is not a PPPoE link is
            // a mistake worth reporting rather than silently ignoring.
            foreach ($requested as $device) {
                $known = array_column($links, 'interface');
                if (! in_array($device, $known, true)) {
                    CLI::write("  {$device} → not configured as a PPPoE link", 'light_gray');
                }
            }
        }

        if ($links === []) {
            CLI::write('No PPPoE client links are configured; nothing to do.', 'light_gray');
            CLI::write('');
            CLI::write('Redial complete: 0 link(s) up, 0 failed.', 'green');

            $this->recordRun($history, 'no PPPoE client links configured; nothing to do', 'success');

            return EXIT_SUCCESS;
        }

        // Which links are already up, so healthy ones are left alone.
        $healthy = [];
        if (! $force && ! $downOnly) {
            $status = (new IpcClient())->call('pppoe.status', []);
            foreach ($status['result']['sessions'] ?? [] as $session) {
                if (($session['role'] ?? '') !== 'client' || empty($session['device'])) {
                    continue;
                }
                if (strtoupper((string) ($session['state'] ?? '')) === 'UP') {
                    $healthy[(string) $session['device']] = true;
                }
            }
        }

        $targets        = [];
        $backedOffLinks = [];

        foreach ($links as $link) {
            $device      = $link['interface'];
            $isHealthy   = ! $force && ! $downOnly && isset($healthy[$device]);
            $isBackedOff = ! $force && ! $downOnly && ! $isHealthy && $backoff->isBackedOff($device);

            if ($isHealthy) {
                CLI::write(sprintf('  %-10s %-20s → %s', $device, $link['name'], 'healthy, left alone'), 'light_gray');
                continue;
            }

            if ($isBackedOff) {
                $bState = $backoff->getLinkState($device);
                $backedOffLinks[] = [
                    'interface' => $device,
                    'name'      => $link['name'],
                    'state'     => $bState,
                ];
                CLI::write(sprintf(
                    '  %-10s %-20s → in escalating backoff (failed %d time(s), retry after %s, %s remaining)',
                    $device,
                    $link['name'],
                    $bState['failure_count'],
                    $bState['next_retry_at'],
                    $bState['remaining_human'] ?: 'soon'
                ), 'yellow');
                continue;
            }

            $targets[] = $link;
            CLI::write(sprintf(
                '  %-10s %-20s → %s',
                $device,
                $link['name'],
                $reportOnly
                    ? ($downOnly ? 'would take down' : 'would redial')
                    : ($downOnly ? 'taking down' : 'redialing')
            ), 'white');
        }

        $ipc = new IpcClient();

        if ($reportOnly) {
            CLI::write('');
            CLI::write(sprintf(
                '%d link(s) planned, %d healthy, %d backed off, 0 actioned (report-only).',
                count($targets),
                count($links) - count($targets) - count($backedOffLinks),
                count($backedOffLinks),
            ), 'green');

            return EXIT_SUCCESS;
        }

        if ($targets === []) {
            if ($backedOffLinks !== []) {
                CLI::write('');
                CLI::write(sprintf(
                    '0 link(s) redialed: %d link(s) in escalating backoff, %d healthy.',
                    count($backedOffLinks),
                    count($links) - count($backedOffLinks),
                ), 'yellow');

                $summary = sprintf(
                    '0 repaired, %d healthy, %d backed off (retrying less aggressively)',
                    count($links) - count($backedOffLinks),
                    count($backedOffLinks)
                );

                $detailLines = [];
                foreach ($backedOffLinks as $bo) {
                    $detailLines[] = sprintf(
                        '%s (%s): backed off (%d failures, next retry %s)',
                        $bo['interface'],
                        $bo['name'],
                        $bo['state']['failure_count'],
                        $bo['state']['next_retry_at']
                    );
                }

                $this->recordRun($history, $summary, 'error', implode("\n", $detailLines));

                return EXIT_SUCCESS;
            }

            CLI::write('');
            CLI::write(sprintf('All %d link(s) are healthy; nothing to redial.', count($links)), 'green');

            $this->recordRun(
                $history,
                sprintf('all %d link(s) healthy; nothing to redial', count($links)),
                'success',
                $this->linkDetail($links),
            );

            return EXIT_SUCCESS;
        }

        // The daemon owns poff/pon and the concentrator, so the work is handed
        // to it in one call rather than shelled out here.
        $resp = $ipc->call('pppoe.redial', [
            'interfaces' => array_column($targets, 'interface'),
            'down_only'  => $downOnly,
            'force'      => $force,
        ]);

        if (! empty($resp['error'])) {
            CLI::error('Redial failed: ' . ($resp['error']['message'] ?? 'daemon error'));

            return EXIT_ERROR;
        }

        $result = $resp['result'] ?? [];
        $status = $result['status'] ?? 'unknown';

        if (in_array($status, ['error', 'apply_failed'], true)) {
            $message = $result['message'] ?? $status;
            CLI::error('Redial failed: ' . $message);
            $this->recordRun($history, 'redial failed: ' . $message, 'error', $this->resultDetail($result));

            return EXIT_ERROR;
        }

        $alertsRaised = 0;

        foreach ($result['links'] ?? [] as $link) {
            $iface   = (string) ($link['interface'] ?? '?');
            $ok      = ($link['status'] ?? '') === 'up';
            $skipped = ($link['action'] ?? '') === 'skipped';

            if ($ok) {
                // Link is recovered/healthy: clear backoff state
                $backoff->recordSuccess($iface);
                CLI::write(sprintf('  %-10s → up', $iface), 'green');
            } elseif ($skipped) {
                CLI::write(sprintf('  %-10s → healthy, left alone', $iface), 'light_gray');
            } else {
                // Redial failed: escalate backoff and raise alert if repeated
                $errMsg  = $link['message'] ?? 'redial failed';
                $bResult = $backoff->recordFailure($iface, $errMsg);

                CLI::write(sprintf(
                    '  %-10s → %s (backoff: %s, next retry: %s)',
                    $iface,
                    $errMsg,
                    $bResult['remaining_human'],
                    $bResult['next_retry_at']
                ), 'red');

                if ($bResult['alert_raised']) {
                    CLI::error(sprintf(
                        '  [ALERT] Link %s repeatedly failed to recover (%d consecutive failures). Escalating backoff active until %s.',
                        $iface,
                        $bResult['failure_count'],
                        $bResult['next_retry_at']
                    ));
                    $alertsRaised++;
                }
            }
        }

        if (! empty($result['concentrator'])) {
            CLI::write('  access concentrator → ' . $result['concentrator'], 'green');
        }

        $failed   = (int) ($result['failed'] ?? 0);
        $repaired = (int) ($result['repaired'] ?? 0);
        $skipped  = (int) ($result['skipped'] ?? 0);

        CLI::write('');
        CLI::write(
            sprintf(
                'Redial complete: %d link(s) %s, %d failed%s%s.',
                (int) ($result['up'] ?? 0),
                $downOnly ? 'taken down' : 'up',
                $failed,
                count($backedOffLinks) > 0 ? sprintf(', %d in backoff', count($backedOffLinks)) : '',
                $ipc->isMock() ? ' (simulated)' : '',
            ),
            $failed > 0 ? 'yellow' : 'green',
        );

        if ($downOnly) {
            $summary = sprintf('%d link(s) taken down on request', count($targets));
            $status  = 'success';
        } elseif ($failed > 0 || $alertsRaised > 0) {
            $summary = sprintf(
                '%d repaired, %d healthy, %d failed%s%s',
                $repaired,
                $skipped,
                $failed,
                count($backedOffLinks) > 0 ? ', ' . count($backedOffLinks) . ' backed off' : '',
                $alertsRaised > 0 ? ' (ALERT raised)' : ''
            );
            $status  = 'error';
        } else {
            $summary = sprintf(
                '%d repaired, %d healthy, %d failed%s',
                $repaired,
                $skipped,
                $failed,
                count($backedOffLinks) > 0 ? ', ' . count($backedOffLinks) . ' backed off' : ''
            );
            $status  = 'success';
        }

        $this->recordRun($history, $summary, $status, $this->resultDetail($result, $backedOffLinks));

        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * Writes one run outcome into the scheduled task's history.
     *
     * Recording must never mask the command's real result, so a missing task row
     * or a database error is reported and ignored rather than thrown.
     */
    private function recordRun(CronJobLog $history, string $summary, string $status, string $detail = ''): void
    {
        if (! $history->exists()) {
            CLI::write('  (run not recorded: scheduled task ' . self::CRON_JOB_ID . ' is not present)', 'light_gray');

            return;
        }

        if (! $history->record($summary, $status, $detail)) {
            CLI::write('  (run could not be recorded in the task history)', 'light_gray');
        }
    }

    /**
     * A per-link detail block for the history, one line per link.
     *
     * @param array<int,array{interface:string,name:string}> $links
     */
    private function linkDetail(array $links): string
    {
        $lines = [];
        foreach ($links as $link) {
            $lines[] = sprintf('%s (%s): healthy', $link['interface'], $link['name']);
        }

        return implode("\n", $lines);
    }

    /**
     * A per-link detail block built from the daemon's result rows and backed-off links.
     *
     * @param array<string,mixed> $result
     * @param list<array>         $backedOffLinks
     */
    private function resultDetail(array $result, array $backedOffLinks = []): string
    {
        $lines = [];
        foreach ($result['links'] ?? [] as $link) {
            if (! is_array($link)) {
                continue;
            }

            $lines[] = sprintf(
                '%s: %s (%s)',
                $link['interface'] ?? '?',
                $link['status'] ?? 'unknown',
                $link['action'] ?? 'unknown',
            );
        }

        foreach ($backedOffLinks as $bo) {
            $lines[] = sprintf(
                '%s: backed off (%d failures, next retry %s)',
                $bo['interface'] ?? '?',
                $bo['state']['failure_count'] ?? 0,
                $bo['state']['next_retry_at'] ?? 'pending'
            );
        }

        if (! empty($result['concentrator'])) {
            $lines[] = 'access concentrator: ' . $result['concentrator'];
        }

        return implode("\n", $lines);
    }

    /**
     * Whether a flag option was supplied.
     *
     * CI4's parser does not split `--key=value`, so a caller writing `--ipc=1`
     * produces an option literally named `ipc=1`. Both that and a bare `--ipc`
     * have to be recognised, or the flag is silently ignored.
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

                // `--ipc=0` is an explicit off.
                return ! in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
            }
        }

        $option = CLI::getOption($name);

        return $option !== null && ! in_array(strtolower((string) $option), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Reads an option value given as either `--key value` or `--key=value`.
     *
     * CI4's CLI parser does not split `--key=value`; it arrives as an option
     * literally named `key=value`, so both spellings are accepted.
     *
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
