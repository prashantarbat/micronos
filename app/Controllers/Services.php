<?php

namespace App\Controllers;

use App\Libraries\IpcClient;

/**
 * Services Controller — Enterprise Daemon Lifecycle & Watchdog Management.
 * Service status and run-state control:
 *  - Inspect daemon status (running, stopped, failed)
 *  - Start / Stop / Restart daemons (WireGuard, OpenVPN, FRR, nftables, Kea, Unbound, etc.)
 *  - Enable / Disable daemon at boot (systemctl enable/disable)
 *  - Configure Service Watchdog auto-restart monitoring
 *  - Inspect real-time daemon logs & diagnostic outputs
 */
class Services extends BaseController
{
    private IpcClient $ipc;

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
        $subsystem = $this->request->getGet('subsystem');

        $query = $db->table('system_services');
        if (!empty($subsystem) && $subsystem !== 'all') {
            $query->where('subsystem', $subsystem);
        }
        $services = $query->orderBy('name', 'ASC')->get()->getResultArray();
        $allServices = $db->table('system_services')->get()->getResultArray();

        // On an appliance the stored state is only a cache: overlay what
        // systemd reports right now. Units the daemon does not manage keep
        // their stored state and are marked as such.
        $live = $this->liveStates();
        $overlay = static function (array $rows) use ($live): array {
            foreach ($rows as &$r) {
                if (isset($live[$r['id']])) {
                    $r['status']    = $live[$r['id']]['state'];
                    $r['pid']       = $live[$r['id']]['pid'] ?: null;
                    $r['memory_mb'] = null;
                    $r['live']      = true;
                }
            }

            return $rows;
        };
        $services    = $overlay($services);
        $allServices = $overlay($allServices);

        // Count metrics
        $totalCount   = count($allServices);
        $runningCount = count(array_filter($allServices, fn($s) => $s['status'] === 'running'));
        $stoppedCount = count(array_filter($allServices, fn($s) => $s['status'] === 'stopped'));
        $watchdogCount = count(array_filter($allServices, fn($s) => !empty($s['watchdog_monitored'])));

        return view('services/index', [
            'title'         => 'System Services & Daemon Manager — MicronOS',
            'breadcrumb'    => 'System: Services',
            'services'      => $services,
            'subsystem'     => $subsystem ?: 'all',
            'totalCount'    => $totalCount,
            'runningCount'  => $runningCount,
            'stoppedCount'  => $stoppedCount,
            'watchdogCount' => $watchdogCount,
            'liveState'     => $live !== [],
            'isMock'        => $this->ipc->isMock(),
        ]);
    }

    public function start(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->lifecycle($id, 'start');
    }

    public function stop(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->lifecycle($id, 'stop');
    }

    public function restart(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->lifecycle($id, 'restart');
    }

    /**
     * Start / stop / restart through the daemon, then record what systemd
     * actually reports — never an assumed state or an invented PID.
     */
    private function lifecycle(string $id, string $action): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $srv = $db->table('system_services')->where('id', $id)->get()->getRowArray();
        if (! $srv) {
            return redirect()->to('services')->with('error', "Service '{$id}' not found.");
        }
        if ($id === 'nftables' && $action === 'stop') {
            log_message('critical', 'Administrator stopped the nftables firewall service.');
        }

        $response = $this->ipc->call('system.service', ['service' => $id, 'action' => $action]);
        $outcome  = $this->ipcOutcome($response);
        $this->auditLog("service.{$action}", ucfirst($action) . " daemon {$srv['name']} ({$srv['id']}): " . ($outcome['ok'] ? 'ok' : 'failed'));
        if (! $outcome['ok']) {
            return redirect()->to('services')->with('error', "Could not {$action} '{$srv['name']}': " . $outcome['reason']);
        }

        $live = $this->liveStates()[$id] ?? null;
        if ($live !== null) {
            $db->table('system_services')->where('id', $id)->update([
                'status' => $live['state'], 'pid' => $live['pid'] ?: null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($this->ipc->isMock()) {
            // Development client: keep the page usable with a plainly fake PID.
            $db->table('system_services')->where('id', $id)->update([
                'status' => $action === 'stop' ? 'stopped' : 'running',
                'pid'    => $action === 'stop' ? null : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $state = $live['state'] ?? ($action === 'stop' ? 'stopped' : 'running');
        $warn  = implode(' ', (array) ($response['result']['warnings'] ?? []));

        $msg = "Service '{$srv['name']}': {$action} completed; it is now {$state}." . ($warn !== '' ? " Note: {$warn}" : '');
        $back = (string) $this->request->getServer('HTTP_REFERER');
        return !empty($back) ? redirect()->to($back)->with('success', $msg) : redirect()->to('services')->with('success', $msg);
    }

    /**
     * Live systemd state for every managed unit, keyed by service id, or []
     * when the daemon cannot be asked (development client / daemon down).
     *
     * @return array<string, array{state: string, pid: int, installed: bool, since: string}>
     */
    private function liveStates(): array
    {
        if ($this->ipc->isMock()) {
            return [];
        }
        $read = $this->ipcRead($this->ipc->call('system.services_status'));
        $out  = [];
        foreach ((array) ($read['services'] ?? []) as $id => $s) {
            $out[(string) $id] = [
                'state'     => ! empty($s['installed']) ? (string) ($s['state'] ?? 'stopped') : 'not-installed',
                'pid'       => (int) ($s['pid'] ?? 0),
                'installed' => ! empty($s['installed']),
                'since'     => (string) ($s['since'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Bulk run-state lookup for the per-page service-control widget.
     *
     *   GET services/status?ids=squid,unbound
     *
     * Returns a flat id => status map so a page can refresh every widget LED
     * in one request. Unknown ids are reported as "unknown" rather than omitted.
     */
    public function status(): \CodeIgniter\HTTP\ResponseInterface
    {
        $raw = (string) ($this->request->getGet('ids') ?? '');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
        $ids = array_slice(array_unique($ids), 0, 25);

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = 'unknown';
        }

        if (! empty($ids)) {
            $rows = \Config\Database::connect()->table('system_services')->whereIn('id', $ids)->get()->getResultArray();
            $live = $this->liveStates();
            foreach ($rows as $row) {
                $map[$row['id']] = $live[$row['id']]['state'] ?? $row['status'];
            }
            $missing = array_filter($ids, static fn ($i) => ! isset($map[$i]) || $map[$i] === 'unknown');
            if (! empty($missing)) {
                $manager = \Config\Services::packageManager();
                foreach ($missing as $mId) {
                    $resolved = $this->resolvePluginDaemon($mId);
                    if ($resolved !== null) {
                        $res = $manager->status($resolved['pluginId']);
                        if (($res['success'] ?? false) === true && isset($res['state'])) {
                            $map[$mId] = (string) $res['state'];
                        }
                    }
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'services' => $map]);
    }

    /**
     * JSON start/stop/restart used by the per-page service-control widget.
     * Updates the run-state in place without a full page navigation.
     */
    public function control(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $action = (string) ($this->request->getPost('action') ?? '');
        if ($action === '') {
            $json   = $this->request->getJSON(true) ?: [];
            $action = (string) ($json['action'] ?? '');
        }

        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok'      => false,
                'message' => 'Invalid action.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $db  = \Config\Database::connect();
        $srv = $db->table('system_services')->where('id', $id)->get()->getRowArray();
        if (! $srv) {
            $resolved = $this->resolvePluginDaemon($id);
            if ($resolved !== null) {
                $manager  = \Config\Services::packageManager();
                $pluginId = $resolved['pluginId'];
                $manifest = $resolved['manifest'];
                $result   = $manager->service($pluginId, $action);
                $ok       = ($result['success'] ?? false) === true;
                $status   = strtolower((string) ($result['state'] ?? ''));
                if (! in_array($status, ['running', 'stopped'], true)) {
                    $status = $action === 'stop' ? 'stopped' : 'running';
                }
                $past = ['start' => 'Started', 'stop' => 'Stopped', 'restart' => 'Restarted'][$action];
                $this->auditLog("service.{$action}", "{$past} daemon {$manifest->name()} ({$id})");

                return $this->response->setJSON([
                    'ok'      => $ok,
                    'service' => $id,
                    'name'    => $manifest->name(),
                    'status'  => $ok ? $status : null,
                    'message' => $ok
                        ? "{$past} {$manifest->name()}"
                        : sprintf('Plugin %s failed: %s', $action, (string) ($result['message'] ?? 'unknown error')),
                    'csrf'    => csrf_hash(),
                ]);
            }

            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => "Service '{$id}' not found.",
                'csrf'    => csrf_hash(),
            ]);
        }

        // Ask micrond to act on the real service, then reflect what systemd reports.
        $response = $this->ipc->call('system.service', ['service' => $id, 'action' => $action]);
        $outcome  = $this->ipcOutcome($response);
        $past     = ['start' => 'Started', 'stop' => 'Stopped', 'restart' => 'Restarted'][$action];
        $this->auditLog("service.{$action}", "{$past} daemon {$srv['name']} ({$id}): " . ($outcome['ok'] ? 'ok' : 'failed'));
        if (! $outcome['ok']) {
            return $this->response->setStatusCode(502)->setJSON([
                'ok' => false, 'service' => $id, 'name' => $srv['name'], 'status' => null,
                'message' => "Could not {$action} {$srv['name']}: " . $outcome['reason'], 'csrf' => csrf_hash(),
            ]);
        }

        $live      = $this->liveStates()[$id] ?? null;
        $newStatus = $live['state'] ?? (string) ($response['result']['state'] ?? ($action === 'stop' ? 'stopped' : 'running'));
        $db->table('system_services')->where('id', $id)->update([
            'status' => $newStatus, 'pid' => $live['pid'] ?? null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'ok'      => true,
            'service' => $id,
            'name'    => $srv['name'],
            'status'  => $newStatus,
            'message' => "{$past} {$srv['name']} — now {$newStatus}",
            'csrf'    => csrf_hash(),
        ]);
    }

    public function toggleBoot(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $srv = $db->table('system_services')->where('id', $id)->get()->getRowArray();
        if (!$srv) {
            return redirect()->to('services')->with('error', "Service '{$id}' not found.");
        }

        $newVal = empty($srv['enabled_at_boot']) ? 1 : 0;
        $action = $newVal ? 'enable' : 'disable';

        // Call micrond to execute systemctl enable / disable on the live systemd unit
        $this->ipc->call('system.service', ['service' => $id, 'action' => $action]);

        $db->table('system_services')->where('id', $id)->update([
            'enabled_at_boot' => $newVal,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $actionWord = $newVal ? 'enabled at boot' : 'disabled from boot';
        $this->auditLog("service.boot_toggle", "Service {$srv['name']} is now {$actionWord}");

        $msg = "Service '{$srv['name']}' boot state updated ({$actionWord}).";
        $back = (string) $this->request->getServer('HTTP_REFERER');
        return !empty($back) ? redirect()->to($back)->with('success', $msg) : redirect()->to('services')->with('success', $msg);
    }

    public function toggleWatchdog(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $srv = $db->table('system_services')->where('id', $id)->get()->getRowArray();
        if (!$srv) {
            return redirect()->to('services')->with('error', "Service '{$id}' not found.");
        }

        $newVal = empty($srv['watchdog_monitored']) ? 1 : 0;
        $db->table('system_services')->where('id', $id)->update([
            'watchdog_monitored' => $newVal,
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $actionWord = $newVal ? 'enrolled in Watchdog monitor' : 'removed from Watchdog monitor';
        $this->auditLog("service.watchdog_toggle", "Service {$srv['name']} {$actionWord}");

        return redirect()->to('services')->with('success', "Service '{$srv['name']}' {$actionWord}.");
    }

    public function logs(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $srv = $db->table('system_services')->where('id', $id)->get()->getRowArray();
        if (!$srv) {
            $resolved = $this->resolvePluginDaemon($id);
            if ($resolved !== null) {
                $manager = \Config\Services::packageManager();
                $res     = $manager->logs($resolved['pluginId'], 30);
                $logs    = $res['logs'] ?? [];
                if (is_array($logs)) {
                    $logs = implode("\n", $logs);
                }
                return $this->response->setJSON([
                    'service' => $resolved['manifest']->name(),
                    'status'  => $res['state'] ?? 'running',
                    'logs'    => (string) $logs,
                ]);
            }

            return $this->response->setStatusCode(404)->setJSON(['error' => 'Service not found']);
        }

        // On Linux: journalctl -u {service} -n 30 --no-pager
        $ipcRes = $this->ipc->call('system.service_logs', ['service' => $id, 'lines' => 30]);
        $result = is_array($ipcRes['result'] ?? null) ? $ipcRes['result'] : [];
        if (($result['success'] ?? false) === true) {
            return $this->response->setJSON([
                'service' => $srv['name'],
                'status'  => $srv['status'],
                'logs'    => (string) ($result['logs'] ?? '') !== '' ? (string) $result['logs'] : '(the journal has no entries for this unit)',
            ]);
        }
        if (! $this->ipc->isMock()) {
            $reason = (string) ($result['message'] ?? $ipcRes['error']['message'] ?? 'the daemon did not answer');

            return $this->response->setStatusCode(503)->setJSON([
                'service' => $srv['name'],
                'status'  => $srv['status'],
                'error'   => 'Logs unavailable: ' . $reason,
                'logs'    => 'Logs unavailable: ' . $reason,
            ]);
        }

        // Development (mock) client only: sample journal lines
        $now = date('M d H:i:s');
        $name = $srv['name'];
        $pid = $srv['pid'] ?? 2410;

        $logTemplates = [
            "{$now} micronos-fw01 systemd[1]: Starting {$name}...",
            "{$now} micronos-fw01 {$name}[{$pid}]: Configuration file validated successfully.",
            "{$now} micronos-fw01 {$name}[{$pid}]: Initializing cryptographic state and memory pools.",
            "{$now} micronos-fw01 {$name}[{$pid}]: Binding sockets and starting worker threads.",
            "{$now} micronos-fw01 systemd[1]: Started {$name}.",
            "{$now} micronos-fw01 {$name}[{$pid}]: Subsystem active and processing packets/events.",
            "{$now} micronos-fw01 {$name}[{$pid}]: Healthcheck ping OK. Zero dropped frames.",
        ];

        return $this->response->setJSON([
            'service' => $srv['name'],
            'status'  => $srv['status'],
            'logs'    => implode("\n", $logTemplates),
        ]);
    }

    public function restartAll(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db      = \Config\Database::connect();
        $live    = $this->liveStates();
        $running = array_filter($db->table('system_services')->get()->getResultArray(),
            static fn ($s) => ($live[$s['id']]['state'] ?? $s['status']) === 'running');

        $ok = [];
        $failed = [];
        foreach ($running as $s) {
            $outcome = $this->ipcOutcome($this->ipc->call('system.service', ['service' => $s['id'], 'action' => 'restart']));
            if ($outcome['ok']) {
                $ok[] = $s['name'];
            } else {
                $failed[] = $s['name'] . ' (' . $outcome['reason'] . ')';
            }
        }
        $this->auditLog('service.restart_all', 'Restarted ' . count($ok) . ' service(s); ' . count($failed) . ' failed');

        if ($failed !== []) {
            return redirect()->to('services')->with('error', 'Restarted ' . count($ok) . ' service(s); these failed: ' . implode('; ', $failed));
        }

        return redirect()->to('services')->with('success', 'Restarted ' . count($ok) . ' running service(s).');
    }

    private function auditLog(string $action, string $details): void
    {
        try {
            $db = \Config\Database::connect();
            $db->table('audit_logs')->insert([
                // See Cron::auditLog() for why every column is supplied.
                'admin_user'      => session('username') ?? 'admin',
                'client_ip'       => $this->request->getIPAddress(),
                'user_id'         => session('user_id') ?? 1,
                'username'        => session('username') ?? 'admin',
                'action_type'     => 'service',
                'target_resource' => 'System Services',
                'action'          => $action,
                // `audit_logs` has no `details` column; see Cron::auditLog().
                'new_state'       => $details,
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * Resolve an id to a plugin entry if it is not in system_services.
     * Matches either the plugin id directly (e.g. 'darkstat', 'lldpd', 'netbird')
     * or a declared unit/service name (e.g. 'zerotier-one' -> 'zerotier', 'tayga' -> 'nat64').
     *
     * @return array{pluginId: string, manifest: \App\Plugins\PluginManifest}|null
     */
    private function resolvePluginDaemon(string $id): ?array
    {
        try {
            $manager = \Config\Services::packageManager();
            $manifest = $manager->registry()->get($id);
            if ($manifest !== null) {
                return ['pluginId' => $id, 'manifest' => $manifest];
            }
            foreach ($manager->catalogue() as $cat) {
                $m = $manager->registry()->get($cat['id']);
                if ($m && (in_array($id, $m->units(), true) || in_array($id, $cat['services'] ?? [], true))) {
                    return ['pluginId' => (string) $cat['id'], 'manifest' => $m];
                }
            }
        } catch (\Throwable $e) {}

        return null;
    }
}
