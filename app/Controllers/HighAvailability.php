<?php

namespace App\Controllers;

use App\Models\HaSettingsModel;
use App\Models\HaVirtualIpModel;
use App\Models\InterfaceModel;
use App\Libraries\HaConfigSync;
use App\Libraries\IpcClient;

class HighAvailability extends BaseController
{
    protected HaSettingsModel $haSettingsModel;
    protected HaVirtualIpModel $haVipModel;
    protected InterfaceModel $interfaceModel;
    protected IpcClient $ipc;

    public function __construct()
    {
        $this->haSettingsModel = new HaSettingsModel();
        $this->haVipModel      = new HaVirtualIpModel();
        $this->interfaceModel  = new InterfaceModel();
        $this->ipc             = \Config\Services::ipc();
    }

    public function index()
    {
        $settings = $this->haSettingsModel->getSettings();
        $vips     = $this->haVipModel->findAll();

        // Only real, configured interfaces are offered — an empty list is
        // shown as such rather than padded with invented ones.
        $interfaces = $this->interfaceModel->findAll();

        $haStatus = $this->ipc->call('ha.status')['result'] ?? [];

        // Preview of exactly what `ha.sync` sends, with the VRRP password masked.
        $keepalivedConfig = (new \App\Libraries\ServiceConfigCompiler())->keepalivedPreview();

        $data = [
            'title'            => 'High Availability & Cluster Failover',
            'settings'         => $settings,
            'vips'             => $vips,
            'interfaces'       => $interfaces,
            'haStatus'         => $haStatus,
            'keepalivedConfig' => $keepalivedConfig,
        ];

        return view('ha/index', $data);
    }

    public function saveSettings()
    {
        $id = $this->request->getPost('id');
        $data = [
            'enabled'              => $this->request->getPost('enabled') ? 1 : 0,
            'node_name'            => $this->request->getPost('node_name'),
            'mode'                 => $this->request->getPost('mode'),
            'vrrp_router_id'       => (int) $this->request->getPost('vrrp_router_id'),
            'vrrp_interface'       => $this->request->getPost('vrrp_interface'),
            'vrrp_priority'        => (int) $this->request->getPost('vrrp_priority'),
            'vrrp_auth_pass'       => $this->request->getPost('vrrp_auth_pass'),
            'preempt'              => $this->request->getPost('preempt') ? 1 : 0,
            'conntrackd_enabled'   => $this->request->getPost('conntrackd_enabled') ? 1 : 0,
            'conntrackd_interface' => $this->request->getPost('conntrackd_interface'),
            'peer_sync_ip'         => $this->request->getPost('peer_sync_ip'),
            'peer_sync_port'       => (int) $this->request->getPost('peer_sync_port'),
            'config_sync_enabled'  => $this->request->getPost('config_sync_enabled') ? 1 : 0,
            'config_sync_peer_ip'  => $this->request->getPost('config_sync_peer_ip'),
            'config_sync_api_key'  => $this->request->getPost('config_sync_api_key'),
        ];

        // Secrets are never sent back to the browser; an empty field means
        // "keep the stored value", so forms that do not carry them cannot wipe them.
        foreach (['vrrp_auth_pass', 'config_sync_api_key'] as $secret) {
            if (trim((string) ($data[$secret] ?? '')) === '') {
                unset($data[$secret]);
            }
        }

        if ($id) {
            $this->haSettingsModel->update($id, $data);
        } else {
            $this->haSettingsModel->insert($data);
        }

        [$key, $message] = $this->ipcFlash(
            $this->applier()->haSync(),
            'High Availability cluster settings saved and applied.',
            'Saved, but the HA sync'
        );

        return redirect()->to('/ha')->with($key, $message);
    }

    public function saveVip()
    {
        $id = $this->request->getPost('id');
        $data = [
            'interface'      => $this->request->getPost('interface'),
            'vip_type'       => $this->request->getPost('vip_type') ?? 'vrrp',
            'ip_address'     => $this->request->getPost('ip_address'),
            'subnet_mask'    => $this->request->getPost('subnet_mask') ?? '255.255.255.0',
            'vrrp_router_id' => (int) $this->request->getPost('vrrp_router_id'),
            'vrrp_priority'  => (int) $this->request->getPost('vrrp_priority'),
            'description'    => $this->request->getPost('description'),
            'status'         => 'MASTER',
        ];

        if ($id) {
            $this->haVipModel->update($id, $data);
        } else {
            $this->haVipModel->insert($data);
        }

        [$key, $message] = $this->ipcFlash(
            $this->applier()->haSync(),
            'Virtual IP successfully updated.',
            'Updated, but the HA sync'
        );

        return redirect()->to('/ha')->with($key, $message);
    }

    public function deleteVip($id)
    {
        $this->haVipModel->delete($id);
        [$key, $message] = $this->ipcFlash(
            $this->applier()->haSync(),
            'Virtual IP removed.',
            'Removed, but the HA sync'
        );

        return redirect()->to('/ha')->with($key, $message);
    }

    public function failover()
    {
        $settings = $this->haSettingsModel->getSettings();
        $targetMode = ($settings['mode'] === 'master') ? 'backup' : 'master';
        $targetPriority = ($targetMode === 'master') ? 110 : 90;

        $this->haSettingsModel->update($settings['id'], [
            'mode'          => $targetMode,
            'vrrp_priority' => $targetPriority,
        ]);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->haFailover($targetMode),
            "Failover applied: this node now runs as {$targetMode} (VRRP priority {$targetPriority}).",
            'The failover'
        );
        if ($key !== 'success') {
            // The node did not change role, so the stored role must not either.
            $this->haSettingsModel->update($settings['id'], [
                'mode'          => $settings['mode'],
                'vrrp_priority' => $settings['vrrp_priority'],
            ]);
        }

        return redirect()->to('/ha')->with($key, $message);
    }

    /**
     * Copy the shared configuration (rules, NAT, objects, VIPs, local DNS,
     * administrators) to the HA peer, which imports and applies it.
     */
    public function syncConfig()
    {
        $result = (new HaConfigSync())->push($this->haSettingsModel->getSettings());
        log_message($result['ok'] ? 'info' : 'warning', 'HA config sync: {msg}', ['msg' => $result['message']]);

        return redirect()->to('/ha')->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Peer endpoint for `syncConfig()`. Not behind the session login: it is
     * authenticated by the sealed payload (HMAC with the shared key, sender
     * address, timestamp, replay check), see HaConfigSync::open().
     */
    public function receiveSync()
    {
        $sync     = new HaConfigSync();
        $settings = $this->haSettingsModel->getSettings();
        $opened   = $sync->open(
            $settings,
            (string) $this->request->getIPAddress(),
            (string) $this->request->getBody(),
            (string) $this->request->getHeaderLine('X-Micron-Sync-Time'),
            (string) $this->request->getHeaderLine('X-Micron-Sync-Signature'),
        );

        if (! $opened['ok']) {
            log_message('warning', 'HA config sync refused from {ip}: {msg}', ['ip' => $this->request->getIPAddress(), 'msg' => $opened['message']]);

            return $this->response->setStatusCode($opened['status'])->setJSON(['ok' => false, 'message' => $opened['message']]);
        }

        try {
            $written = $sync->import($opened['payload']);
        } catch (\Throwable $e) {
            log_message('error', 'HA config sync import failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'import failed, nothing was changed']);
        }

        // Apply what changed on this node; report anything the daemon refused.
        $failed  = [];
        $applier = $this->applier();
        foreach (['firewall' => fn () => $applier->firewall(), 'DNS' => fn () => $applier->dns(), 'VRRP' => fn () => $applier->haSync()] as $what => $apply) {
            $outcome = $this->ipcOutcome($apply());
            if (! $outcome['ok']) {
                $failed[] = "{$what} ({$outcome['reason']})";
            }
        }

        $rows    = array_sum($written);
        $message = $failed === []
            ? "Imported {$rows} rows and applied firewall, DNS and VRRP on the peer."
            : "Imported {$rows} rows; apply failed for " . implode(', ', $failed) . '.';
        log_message($failed === [] ? 'info' : 'warning', 'HA config sync received: {msg}', ['msg' => $message]);

        return $this->response->setJSON(['ok' => $failed === [], 'message' => $message, 'rows' => $written]);
    }
}
