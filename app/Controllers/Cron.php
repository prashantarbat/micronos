<?php

namespace App\Controllers;

use App\Libraries\CronJobLog;
use App\Libraries\IpcClient;

/**
 * Cron Controller — Scheduled Task Management Engine.
 * Scheduled task engine:
 *  - View all scheduled maintenance & periodic background tasks
 *  - Add, edit, delete, toggle cron schedules
 *  - Standard cron format (Minute, Hour, Day of Month, Month, Day of Week, User, Command)
 *  - "Run Now" execution with real-time log capturing
 *  - Preset templates for automated backups, DNSBL updates, logrotate, cert renewals, dyndns
 */
class Cron extends BaseController
{
    private IpcClient $ipc;

    /** The daemon's answer to the last crontab sync, for truthful flashes. */
    private array $lastCrontabSync = [];

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();
        $jobs = $db->table('cron_jobs')->orderBy('created_at', 'ASC')->get()->getResultArray();

        $activeCount = count(array_filter($jobs, fn($j) => !empty($j['enabled'])));

        return view('cron/index', [
            'title'       => 'Cron & Scheduled Tasks — MicronOS',
            'breadcrumb'  => 'System: Cron',
            'jobs'        => $jobs,
            'activeCount' => $activeCount,
            'totalCount'  => count($jobs),
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $minute     = trim($this->request->getPost('minute') ?? '*');
        $hour       = trim($this->request->getPost('hour') ?? '*');
        $dayOfMonth = trim($this->request->getPost('day_of_month') ?? '*');
        $month      = trim($this->request->getPost('month') ?? '*');
        $dayOfWeek  = trim($this->request->getPost('day_of_week') ?? '*');
        $user       = trim($this->request->getPost('user') ?? 'root');
        $command    = trim($this->request->getPost('command') ?? '');
        $description = trim($this->request->getPost('description') ?? '');
        $enabled    = (int)($this->request->getPost('enabled') ?? 1);

        if (empty($command)) {
            return redirect()->to('cron')->with('error', 'Cron command cannot be empty.');
        }

        $id = 'cron_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 12);

        $db = \Config\Database::connect();
        $db->table('cron_jobs')->insert([
            'id'           => $id,
            'minute'       => $minute,
            'hour'         => $hour,
            'day_of_month' => $dayOfMonth,
            'month'        => $month,
            'day_of_week'  => $dayOfWeek,
            'user'         => $user,
            'command'      => $command,
            'description'  => $description,
            'enabled'      => $enabled,
            'last_status'  => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->syncCrontab();
        $this->auditLog("cron.create", "Created scheduled cron job {$id} ({$description})");

        return redirect()->to('cron')->with(...$this->cronFlash("Scheduled task '{$description}' created successfully."));
    }

    public function update(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $job = $db->table('cron_jobs')->where('id', $id)->get()->getRowArray();
        if (!$job) {
            return redirect()->to('cron')->with('error', 'Cron job not found.');
        }

        $minute     = trim($this->request->getPost('minute') ?? '*');
        $hour       = trim($this->request->getPost('hour') ?? '*');
        $dayOfMonth = trim($this->request->getPost('day_of_month') ?? '*');
        $month      = trim($this->request->getPost('month') ?? '*');
        $dayOfWeek  = trim($this->request->getPost('day_of_week') ?? '*');
        $user       = trim($this->request->getPost('user') ?? 'root');
        $command    = trim($this->request->getPost('command') ?? '');
        $description = trim($this->request->getPost('description') ?? '');
        $enabled    = (int)($this->request->getPost('enabled') ?? 1);

        $db->table('cron_jobs')->where('id', $id)->update([
            'minute'       => $minute,
            'hour'         => $hour,
            'day_of_month' => $dayOfMonth,
            'month'        => $month,
            'day_of_week'  => $dayOfWeek,
            'user'         => $user,
            'command'      => $command,
            'description'  => $description,
            'enabled'      => $enabled,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->syncCrontab();
        $this->auditLog("cron.update", "Updated scheduled cron job {$id}");

        return redirect()->to('cron')->with(...$this->cronFlash("Scheduled task '{$description}' updated successfully."));
    }

    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $job = $db->table('cron_jobs')->where('id', $id)->get()->getRowArray();
        if (!$job) {
            return redirect()->to('cron')->with('error', 'Cron job not found.');
        }

        $newVal = empty($job['enabled']) ? 1 : 0;
        $db->table('cron_jobs')->where('id', $id)->update([
            'enabled'    => $newVal,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->syncCrontab();
        $statusWord = $newVal ? 'enabled' : 'disabled';
        $this->auditLog("cron.toggle", "Task {$id} is now {$statusWord}");

        return redirect()->to('cron')->with(...$this->cronFlash("Task '{$job['description']}' is now {$statusWord}."));
    }

    public function delete(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $job = $db->table('cron_jobs')->where('id', $id)->get()->getRowArray();
        if ($job) {
            $db->table('cron_jobs')->where('id', $id)->delete();
            $this->syncCrontab();
            $this->auditLog("cron.delete", "Deleted cron job {$id} ({$job['description']})");
        }

        return redirect()->to('cron')->with(...$this->cronFlash("Scheduled task deleted."));
    }

    public function runNow(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $job = $db->table('cron_jobs')->where('id', $id)->get()->getRowArray();
        if (!$job) {
            return redirect()->to('cron')->with('error', 'Cron job not found.');
        }

        // On Linux: dispatch via IPC daemon
        $ipcRes  = $this->ipc->call('system.cron_run', ['id' => $id, 'command' => $job['command']]);
        $outcome = $this->ipcOutcome($ipcRes);
        $result  = is_array($ipcRes['result'] ?? null) ? $ipcRes['result'] : [];
        $status  = $outcome['ok'] ? 'success' : 'failed';
        $output  = trim((string) ($result['output'] ?? ''));
        if ($output === '') {
            $output = $outcome['ok']
                ? 'Completed with no output (exit ' . (int) ($result['returncode'] ?? 0) . ').'
                : (string) $outcome['reason'];
        }
        if ($this->ipc->isMock()) {
            $output = '[simulated — development mode] ' . $output;
        }

        // A task that keeps its own history (the PPPoE redial and the repository
        // drift sweep do) must not have that history overwritten by a manual run:
        // the whole point is to see the pattern across runs, and replacing the log
        // with one blob erases exactly that.
        if (CronJobLog::keepsHistory($id)) {
            $history = new CronJobLog($id, null, $db);
            $history->record($output, $status, 'triggered by an administrator');
        } else {
            $db->table('cron_jobs')->where('id', $id)->update([
                'last_run_at' => date('Y-m-d H:i:s'),
                'last_status' => $status,
                'last_output' => mb_substr($output, 0, 16000),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        $this->auditLog("cron.run_now", "Manually executed cron job {$id} ({$status})");

        return $outcome['ok']
            ? redirect()->to('cron')->with('success', "Task '{$job['description']}' completed successfully.")
            : redirect()->to('cron')->with('error', "Task '{$job['description']}' failed: " . $outcome['reason']);
    }

    public function logs(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $jobs = $db->table('cron_jobs')->get()->getResultArray();

        // IPC to fetch crond / syslog CRON entries
        $ipcRes = $this->ipc->call('system.cron_logs', ['lines' => 30]);
        $entries = [];

        $read        = $this->ipcRead($ipcRes);
        $unavailable = null;
        if ($read !== null) {
            foreach ((array) ($read['logs'] ?? []) as $line) {
                $j = $this->journalLine((string) $line);
                preg_match('/^\((\S+)\)\s+CMD\s+\((.*)\)$/', $j['message'], $cmd);
                $entries[] = ['timestamp' => $j['timestamp'], 'pid' => $j['pid'] ?? 0,
                    'user' => $cmd[1] ?? '', 'command' => $cmd[2] ?? $j['message'],
                    'status' => $cmd ? 'STARTED' : 'INFO', 'duration' => '—', 'output' => $cmd ? '' : $j['message']];
            }
        } elseif (! $this->ipc->isMock()) {
            $unavailable = (string) ($ipcRes['error']['message'] ?? $ipcRes['result']['message'] ?? 'the daemon did not answer');
        } else {
            $now = time();
            // Illustrative entries for a host with no daemon to ask. There is
            // deliberately **no** backup entry: the example that used to sit
            // here announced a `micron-backup` run that created an archive with
            // "Encryption verified", so the modal reported a successful
            // encrypted backup on an appliance with no scheduled snapshot job
            // and no binary able to produce one (§17.6). A run history that
            // invents runs is worse than an empty one.
            $entries = [
                [
                    'timestamp' => date('Y-m-d H:i:s', $now - 340),
                    'pid'       => 3810,
                    'user'      => 'root',
                    'command'   => '/usr/local/bin/micron-dnsbl-update',
                    'status'    => 'SUCCESS',
                    'duration'  => '4.82s',
                    'output'    => 'Synced OISD & StevenBlack blocklists: 142,890 entries compiled to Unbound RPZ.',
                ],
                [
                    'timestamp' => date('Y-m-d H:i:s', $now - 860),
                    'pid'       => 2940,
                    'user'      => 'root',
                    'command'   => '/usr/sbin/logrotate /etc/logrotate.conf',
                    'status'    => 'SUCCESS',
                    'duration'  => '0.85s',
                    'output'    => 'Rotated /var/log/suricata/eve.json and /var/log/nftables.log. Old logs compressed.',
                ],
                [
                    'timestamp' => date('Y-m-d H:i:s', $now - 1500),
                    'pid'       => 2104,
                    'user'      => 'root',
                    'command'   => '/usr/local/bin/micron-cert-renew',
                    'status'    => 'SUCCESS',
                    'duration'  => '1.10s',
                    'output'    => 'Checking certificates: 2 certificates active, 0 due for renewal (>30 days remaining).',
                ],
                [
                    'timestamp' => date('Y-m-d H:i:s', $now - 2400),
                    'pid'       => 1890,
                    'user'      => 'root',
                    'command'   => '/usr/sbin/ip neigh flush nud stale',
                    'status'    => 'SUCCESS',
                    'duration'  => '0.12s',
                    'output'    => 'Flushed 14 stale ARP/NDP cache entries on interface eth1.',
                ],
            ];
        }

        return $this->response->setJSON([
            'entries'   => $entries,
            'unavailable' => $unavailable,
            'mock'      => $this->ipc->isMock(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Flash pair after a change that re-syncs the crontab: success only when
     * the daemon installed it; otherwise the refusal (with the offending line).
     *
     * @return array{0: string, 1: string}
     */
    private function cronFlash(string $saved): array
    {
        if ($this->lastCrontabSync === []) {
            return ['success', $saved];
        }
        $outcome = $this->ipcOutcome($this->lastCrontabSync);
        if ($outcome['ok']) {
            return ['success', $saved];
        }
        $refused = $this->lastCrontabSync['result']['refused'][0] ?? null;
        $detail  = is_array($refused) ? ' — ' . $refused['reason'] . ': ' . $refused['line'] : '';

        return ['error', rtrim($saved, '.') . ', but the crontab was not installed: ' . $outcome['reason'] . $detail];
    }

    private function syncCrontab(): void
    {
        // On Linux appliance: generates /etc/cron.d/micronos from enabled records
        $db = \Config\Database::connect();
        $jobs = $db->table('cron_jobs')->where('enabled', 1)->get()->getResultArray();
        $lines = ["# /etc/cron.d/micronos — Auto-generated by MicronOS Engine. DO NOT EDIT DIRECTLY.", "SHELL=/bin/sh", "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin", ""];
        
        foreach ($jobs as $j) {
            // The id goes on its own comment line: a trailing `# …` would be
            // handed to the shell as part of the command.
            $lines[] = '# ID: ' . preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $j['id']);
            $lines[] = "{$j['minute']} {$j['hour']} {$j['day_of_month']} {$j['month']} {$j['day_of_week']} {$j['user']} {$j['command']}";
        }

        $this->lastCrontabSync = $this->applier()->crontab(implode("\n", $lines));
    }

    private function auditLog(string $action, string $details): void
    {
        try {
            $db = \Config\Database::connect();
            $db->table('audit_logs')->insert([
                // admin_user/client_ip/action_type/target_resource are NOT NULL
                // on an appliance whose database predates the migration file's
                // later edits, so every writer supplies them.
                'admin_user'      => session('username') ?? 'admin',
                'client_ip'       => $this->request->getIPAddress(),
                'user_id'         => session('user_id') ?? 1,
                'username'        => session('username') ?? 'admin',
                'action_type'     => 'cron',
                'target_resource' => 'Cron Jobs',
                'action'          => $action,
                // `audit_logs` has no `details` column. This write used to throw
                // into the empty catch below and vanish, so no audit entry was
                // ever recorded here.
                'new_state'       => $details,
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
    }
}
