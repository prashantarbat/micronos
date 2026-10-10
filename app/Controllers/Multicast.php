<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\MulticastCompiler;
use App\Models\MulticastSettingsModel;

/**
 * Multicast Controller — IGMP proxy, multicast routing (PIM/IGMP) and bridge
 * snooping / mDNS reflection.
 *
 * All three capabilities are configured on one page and applied to the data
 * plane in a single `multicast.apply` RPC: the PHP side compiles/validates the
 * artifacts, micrond writes them and restarts the affected daemons. A failed
 * apply is surfaced to the operator rather than reported as success.
 */
class Multicast extends BaseController
{
    private IpcClient $ipc;
    private MulticastSettingsModel $settingsModel;
    private MulticastCompiler $compiler;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->settingsModel = new MulticastSettingsModel();
        $this->compiler      = new MulticastCompiler();
    }

    public function index(): string
    {
        $settings = $this->settingsModel->getSettings();
        $compiled = $this->compiler->compile($settings);

        $statusRes = $this->ipc->call('multicast.status')['result'] ?? null;
        $status = is_array($statusRes) ? $statusRes : [
            'available' => false,
            'igmpproxy' => 'unknown',
            'pim'       => 'unknown',
            'mdns'      => 'unknown',
        ];

        // Interfaces available for the pickers (best-effort).
        $interfaces = [];
        try {
            $rows = \Config\Database::connect()
                ->table('interfaces')
                ->select('name, ifname, enabled')
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $id = $row['name'] ?: $row['ifname'];
                $interfaces[] = [
                    'id'    => $id,
                    'label' => strtoupper((string) $id) . ' (' . ($row['ifname'] ?: 'eth0') . ')',
                ];
            }
        } catch (\Throwable $e) {
            $interfaces = [];
        }

        return view('multicast/index', [
            'title'      => 'Multicast & IGMP — MicronOS',
            'breadcrumb' => 'Multicast & IGMP',
            'settings'   => $settings,
            'compiled'   => $compiled,
            'status'     => $status,
            'interfaces' => $interfaces,
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    /**
     * Persist the multicast settings and apply them to the data plane.
     */
    public function save(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = $this->readSettings();

        $this->settingsModel->saveSettings($data);

        [$key, $message] = $this->ipcFlash(
            $this->applyToDataPlane($data),
            'Multicast & IGMP configuration saved and applied.',
            'Multicast apply'
        );

        return redirect()->to(base_url('multicast'))->with($key, $message);
    }

    /**
     * Re-apply the stored configuration without changing it.
     */
    public function apply(): \CodeIgniter\HTTP\RedirectResponse
    {
        $settings = $this->settingsModel->getSettings();

        [$key, $message] = $this->ipcFlash(
            $this->applyToDataPlane($settings),
            'Multicast & IGMP configuration applied.',
            'Multicast apply'
        );

        return redirect()->to(base_url('multicast'))->with($key, $message);
    }

    /**
     * Sends the compiled artifacts to the daemon.
     *
     * @param array<string,mixed> $settings
     *
     * @return array{result?: mixed, error?: mixed}
     */
    private function applyToDataPlane(array $settings): array
    {
        $compiled = $this->compiler->compile($settings);

        $res = $this->ipc->call('multicast.apply', [
            'enabled'    => ! empty($settings['enabled']),
            'igmp_proxy' => [
                'enabled' => ! empty($settings['igmp_proxy_enabled']),
                'config'  => $compiled['igmp_proxy'],
            ],
            'pim' => [
                'enabled'  => ! empty($settings['pim_enabled']),
                'commands' => $compiled['pim'],
            ],
            'snooping' => $compiled['snooping'],
            'mdns'     => [
                'enabled'    => ! empty($settings['mdns_enabled']),
                'command'    => $compiled['mdns']['command'],
                'interfaces' => $compiled['mdns']['interfaces'],
            ],
            'warnings' => $compiled['warnings'],
        ]);

        // Mirror the daemons' new run-state into system_services so the shared
        // page-header service widget reflects reality after an apply. Prefer
        // micrond's live report; fall back to the requested state when the
        // daemon did not return one.
        $services = $res['result']['services'] ?? null;
        if (is_array($services) && $services !== []) {
            $this->syncServiceState($services);
        } elseif (($res['result']['success'] ?? false) === true) {
            // A clean apply that did not report state: derive it from what we
            // asked the daemon to do. A hard failure syncs nothing — the
            // daemons were not touched and their stale state is the truth.
            $this->syncServiceState([
                'igmpproxy'     => (! empty($settings['enabled']) && ! empty($settings['igmp_proxy_enabled'])) ? 'running' : 'stopped',
                'mdns-repeater' => (! empty($settings['enabled']) && ! empty($settings['mdns_enabled'])) ? 'running' : 'stopped',
            ]);
        }

        return $res;
    }

    /**
     * Persist daemon run-state reported by micrond into system_services.
     *
     * FRR is intentionally excluded: it also serves general routing, so
     * multicast must not claim ownership of its run-state.
     *
     * @param array<string,string> $states daemon id (or multicast key) => state
     */
    private function syncServiceState(array $states): void
    {
        $ids = [
            'igmpproxy'     => 'igmpproxy',
            'mdns'          => 'mdns-repeater',
            'mdns-repeater' => 'mdns-repeater',
        ];

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        foreach ($states as $key => $state) {
            $id = $ids[$key] ?? null;
            if ($id === null || $state === null || $state === '') {
                continue;
            }

            $row = $db->table('system_services')->where('id', $id)->get()->getRowArray();
            if (! $row) {
                continue;
            }

            $running = strtolower((string) $state) === 'running';
            $db->table('system_services')->where('id', $id)->update([
                'status'     => $running ? 'running' : 'stopped',
                'pid'        => $running ? ($row['pid'] ?: rand(1800, 4500)) : null,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reads and clamps the submitted form values.
     *
     * @return array<string,mixed>
     */
    private function readSettings(): array
    {
        $pimMode = (string) $this->request->getPost('pim_mode');
        if (! in_array($pimMode, ['sparse', 'ssm'], true)) {
            $pimMode = 'sparse';
        }

        $mdnsMode = (string) $this->request->getPost('mdns_mode');
        if (! in_array($mdnsMode, ['reflect', 'relay'], true)) {
            $mdnsMode = 'reflect';
        }

        $igmpVersion = (int) $this->request->getPost('igmp_version');
        if (! in_array($igmpVersion, [1, 2, 3], true)) {
            $igmpVersion = 3;
        }

        return [
            'enabled'                 => $this->request->getPost('enabled') ? 1 : 0,
            'igmp_proxy_enabled'      => $this->request->getPost('igmp_proxy_enabled') ? 1 : 0,
            'igmp_upstream'           => trim((string) $this->request->getPost('igmp_upstream')),
            'igmp_downstreams'        => trim((string) $this->request->getPost('igmp_downstreams')),
            'igmp_altnets'            => trim((string) $this->request->getPost('igmp_altnets')),
            'igmp_quickleave'         => $this->request->getPost('igmp_quickleave') ? 1 : 0,
            'pim_enabled'             => $this->request->getPost('pim_enabled') ? 1 : 0,
            'pim_mode'                => $pimMode,
            'pim_rp_address'          => trim((string) $this->request->getPost('pim_rp_address')),
            'pim_interfaces'          => trim((string) $this->request->getPost('pim_interfaces')),
            'pim_ssm_range'           => trim((string) $this->request->getPost('pim_ssm_range')),
            'igmp_querier_enabled'    => $this->request->getPost('igmp_querier_enabled') ? 1 : 0,
            'igmp_version'            => $igmpVersion,
            'snooping_enabled'        => $this->request->getPost('snooping_enabled') ? 1 : 0,
            'snooping_bridge'         => trim((string) $this->request->getPost('snooping_bridge')),
            'snooping_querier'        => $this->request->getPost('snooping_querier') ? 1 : 0,
            'snooping_query_interval' => max(1, (int) ($this->request->getPost('snooping_query_interval') ?: 125)),
            'mdns_enabled'            => $this->request->getPost('mdns_enabled') ? 1 : 0,
            'mdns_interfaces'         => trim((string) $this->request->getPost('mdns_interfaces')),
            'mdns_mode'               => $mdnsMode,
            'mdns_filter'             => trim((string) $this->request->getPost('mdns_filter')),
        ];
    }
}
