<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\NftablesCompiler;
use App\Models\DosPolicyModel;
use App\Models\InterfaceModel;
use App\Models\ScheduleModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * DosPolicy Controller — Layer 4 DDoS & DoS Attack Mitigation Policies
 *
 * Provides:
 *  - TCP SYN flood protection with synproxy and rate limiting
 *  - Per-host concurrent connection caps & rate throttling
 *  - Protocol flood thresholds (UDP, ICMP) & port sweep defense
 *  - Dynamic temporary attacker blacklisting
 *  - Live nftables ruleset compilation & atomic activation
 */
class DosPolicy extends BaseController
{
    protected DosPolicyModel $policyModel;
    protected InterfaceModel $interfaceModel;
    protected IpcClient $ipc;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->policyModel    = new DosPolicyModel();
        $this->interfaceModel = new InterfaceModel();
        $this->ipc            = \Config\Services::ipc();
    }

    /**
     * DoS Policy Dashboard — List & Configure Policies
     */
    public function index(): string
    {
        $policies   = $this->policyModel->orderBy('created_at', 'ASC')->findAll();
        $db = \Config\Database::connect();
        $physicalInterfaces = $this->interfaceModel->findAll();
        $vpnInterfaces      = \App\Libraries\VpnInterfaceProvider::getVpnInterfaces($db);
        $interfaces         = \App\Libraries\VpnInterfaceProvider::getUnifiedInterfaces($db);
        $presets    = DosPolicyModel::getPresets();
        $schedules  = (new ScheduleModel())->orderBy('name', 'ASC')->findAll();

        return view('firewall/dos', [
            'title'              => 'DDoS & DoS Protection Policies — MicronOS',
            'breadcrumb'         => 'Policy: DoS Policies',
            'policies'           => $policies,
            'interfaces'         => $interfaces,
            'physicalInterfaces' => $physicalInterfaces,
            'vpnInterfaces'      => $vpnInterfaces,
            'presets'            => $presets,
            'schedules'          => $schedules,
        ]);
    }

    /**
     * Create a new DoS mitigation policy
     */
    public function store(): RedirectResponse
    {
        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            $name = 'DoS Policy ' . (count($this->policyModel->findAll()) + 1);
        }

        $id = 'dos_' . bin2hex(random_bytes(6));

        $data = [
            'id'                 => $id,
            'name'               => $name,
            'interface_id'       => (string) ($this->request->getPost('interface_id') ?: 'all'),
            'schedule'           => $this->resolveSchedule($this->request->getPost('schedule')),
            'syn_flood_enabled'  => $this->request->getPost('syn_flood_enabled') ? 1 : 0,
            'syn_flood_rate'     => max(1, (int) ($this->request->getPost('syn_flood_rate') ?: 100)),
            'syn_flood_burst'    => max(1, (int) ($this->request->getPost('syn_flood_burst') ?: 200)),
            'conn_limit_enabled' => $this->request->getPost('conn_limit_enabled') ? 1 : 0,
            'conn_limit_max'     => max(1, (int) ($this->request->getPost('conn_limit_max') ?: 50)),
            'conn_rate_enabled'  => $this->request->getPost('conn_rate_enabled') ? 1 : 0,
            'conn_rate_max'      => max(1, (int) ($this->request->getPost('conn_rate_max') ?: 60)),
            'udp_flood_enabled'  => $this->request->getPost('udp_flood_enabled') ? 1 : 0,
            'udp_flood_rate'     => max(1, (int) ($this->request->getPost('udp_flood_rate') ?: 500)),
            'icmp_flood_enabled' => $this->request->getPost('icmp_flood_enabled') ? 1 : 0,
            'icmp_flood_rate'    => max(1, (int) ($this->request->getPost('icmp_flood_rate') ?: 20)),
            'port_scan_enabled'  => $this->request->getPost('port_scan_enabled') ? 1 : 0,
            'action'             => strtolower((string) ($this->request->getPost('action') ?: 'drop')) === 'reject' ? 'reject' : 'drop',
            'ban_duration'       => max(0, (int) ($this->request->getPost('ban_duration') ?? 600)),
            'exempt_addresses'   => trim((string) ($this->request->getPost('exempt_addresses') ?: '')),
            'log_enabled'        => $this->request->getPost('log_enabled') ? 1 : 0,
            'enabled'            => $this->request->getPost('enabled') !== null ? (int) $this->request->getPost('enabled') : 1,
            'description'        => trim((string) ($this->request->getPost('description') ?: '')),
        ];

        $this->policyModel->insert($data);

        return redirect()->to(base_url('firewall/dos'))
            ->with('success', 'DDoS Policy "' . esc($data['name']) . '" created successfully.');
    }

    /**
     * Update an existing DoS policy
     */
    public function update(string $id): RedirectResponse
    {
        $policy = $this->policyModel->find($id);
        if (! $policy) {
            return redirect()->to(base_url('firewall/dos'))->with('error', 'DoS Policy not found.');
        }

        $name = trim((string) ($this->request->getPost('name') ?: $policy['name']));

        $data = [
            'name'               => $name,
            'interface_id'       => (string) ($this->request->getPost('interface_id') ?: $policy['interface_id']),
            'schedule'           => $this->resolveSchedule($this->request->getPost('schedule') ?? ($policy['schedule'] ?? 'always')),
            'syn_flood_enabled'  => $this->request->getPost('syn_flood_enabled') ? 1 : 0,
            'syn_flood_rate'     => max(1, (int) ($this->request->getPost('syn_flood_rate') ?: $policy['syn_flood_rate'])),
            'syn_flood_burst'    => max(1, (int) ($this->request->getPost('syn_flood_burst') ?: $policy['syn_flood_burst'])),
            'conn_limit_enabled' => $this->request->getPost('conn_limit_enabled') ? 1 : 0,
            'conn_limit_max'     => max(1, (int) ($this->request->getPost('conn_limit_max') ?: $policy['conn_limit_max'])),
            'conn_rate_enabled'  => $this->request->getPost('conn_rate_enabled') ? 1 : 0,
            'conn_rate_max'      => max(1, (int) ($this->request->getPost('conn_rate_max') ?: $policy['conn_rate_max'])),
            'udp_flood_enabled'  => $this->request->getPost('udp_flood_enabled') ? 1 : 0,
            'udp_flood_rate'     => max(1, (int) ($this->request->getPost('udp_flood_rate') ?: $policy['udp_flood_rate'])),
            'icmp_flood_enabled' => $this->request->getPost('icmp_flood_enabled') ? 1 : 0,
            'icmp_flood_rate'    => max(1, (int) ($this->request->getPost('icmp_flood_rate') ?: $policy['icmp_flood_rate'])),
            'port_scan_enabled'  => $this->request->getPost('port_scan_enabled') ? 1 : 0,
            'action'             => strtolower((string) ($this->request->getPost('action') ?: $policy['action'])) === 'reject' ? 'reject' : 'drop',
            'ban_duration'       => max(0, (int) ($this->request->getPost('ban_duration') ?? $policy['ban_duration'])),
            'exempt_addresses'   => trim((string) ($this->request->getPost('exempt_addresses') ?: '')),
            'log_enabled'        => $this->request->getPost('log_enabled') ? 1 : 0,
            'enabled'            => $this->request->getPost('enabled') !== null ? (int) $this->request->getPost('enabled') : $policy['enabled'],
            'description'        => trim((string) ($this->request->getPost('description') ?: '')),
        ];

        $this->policyModel->update($id, $data);

        return redirect()->to(base_url('firewall/dos'))
            ->with('success', 'DDoS Policy "' . esc($name) . '" updated successfully.');
    }

    /**
     * Toggle policy enabled status
     */
    public function toggle(string $id): RedirectResponse
    {
        $policy = $this->policyModel->find($id);
        if (! $policy) {
            return redirect()->to(base_url('firewall/dos'))->with('error', 'DoS Policy not found.');
        }

        $newStatus = (int) $policy['enabled'] === 1 ? 0 : 1;
        $this->policyModel->update($id, ['enabled' => $newStatus]);

        $statusStr = $newStatus === 1 ? 'enabled' : 'disabled';
        return redirect()->to(base_url('firewall/dos'))
            ->with('success', 'DDoS Policy "' . esc($policy['name']) . "\" is now {$statusStr}.");
    }

    /**
     * Delete a policy
     */
    public function delete(string $id): RedirectResponse
    {
        $policy = $this->policyModel->find($id);
        if (! $policy) {
            return redirect()->to(base_url('firewall/dos'))->with('error', 'DoS Policy not found.');
        }

        $this->policyModel->delete($id);

        return redirect()->to(base_url('firewall/dos'))
            ->with('success', 'DDoS Policy "' . esc($policy['name']) . '" deleted successfully.');
    }

    /**
     * Normalise a posted schedule name to an existing schedule object,
     * falling back to 'always' for empty or unknown values.
     */
    protected function resolveSchedule($value): string
    {
        $name = strtolower(trim((string) $value));
        if ($name === '' || $name === 'always') {
            return 'always';
        }

        $exists = (new ScheduleModel())->where('name', $name)->countAllResults() > 0;

        return $exists ? $name : 'always';
    }

    /**
     * Compile nftables ruleset and trigger atomic activation
     */
    public function apply(): RedirectResponse
    {
        try {
            $compiler = new NftablesCompiler();
            $ruleset  = $compiler->compile();

            $resp = $this->ipc->call('firewall.apply', [
                'table'     => 'filter',
                'ruleset'   => $ruleset,
                'safe_mode' => false,
            ]);
            $this->ipc->call('firewall.confirm');

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('firewall/dos'))
                    ->with('error', 'Failed to apply ruleset: ' . ($resp['error']['message'] ?? 'IPC error'));
            }

            $result = $resp['result'] ?? [];
            $status = $result['status'] ?? 'applied';

            if (in_array($status, ['syntax_error', 'apply_failed', 'error'], true)) {
                return redirect()->to(base_url('firewall/dos'))
                    ->with('error', 'Ruleset rejected by netfilter: ' . ($result['error'] ?? $result['message'] ?? $status));
            }

            return redirect()->to(base_url('firewall/dos'))
                ->with('success', 'DDoS mitigation policies compiled and activated successfully in nftables.');
        } catch (\Throwable $e) {
            return redirect()->to(base_url('firewall/dos'))
                ->with('error', 'Error compiling DoS policies: ' . $e->getMessage());
        }
    }
}
