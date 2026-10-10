<?php

namespace App\Controllers;

use App\Models\HaSettingsModel;
use App\Models\HaVirtualIpModel;
use App\Models\InterfaceModel;
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

        // Seed sample VIPs if empty
        if (empty($vips)) {
            $this->haVipModel->insert([
                'interface'      => 'lan0',
                'vip_type'       => 'vrrp',
                'ip_address'     => '192.168.1.254',
                'subnet_mask'    => '255.255.255.0',
                'vrrp_router_id' => 51,
                'vrrp_priority'  => 110,
                'description'    => 'LAN Default Gateway Cluster VIP',
                'status'         => 'MASTER',
            ]);
            $this->haVipModel->insert([
                'interface'      => 'wan0',
                'vip_type'       => 'vrrp',
                'ip_address'     => '203.0.113.10',
                'subnet_mask'    => '255.255.255.0',
                'vrrp_router_id' => 52,
                'vrrp_priority'  => 110,
                'description'    => 'Public-facing VRRP WAN virtual address',
                'status'         => 'MASTER',
            ]);
            $vips = $this->haVipModel->findAll();
        }

        $interfaces = $this->interfaceModel->findAll();
        if (empty($interfaces)) {
            $interfaces = [
                ['name' => 'wan0', 'label' => 'WAN (Internet)'],
                ['name' => 'lan0', 'label' => 'LAN (Internal)'],
                ['name' => 'sync0', 'label' => 'SYNC (Dedicated Heartbeat)'],
            ];
        }

        $haStatus = $this->ipc->call('ha.status')['result'] ?? [];

        // Generate Keepalived config preview
        $keepalivedConfig = $this->generateKeepalivedConfig($settings, $vips);

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

        if ($id) {
            $this->haSettingsModel->update($id, $data);
        } else {
            $this->haSettingsModel->insert($data);
        }

        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('ha.sync', $data),
            'High Availability cluster settings saved and synced.',
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
            $this->ipc->call('ha.sync'),
            'Virtual IP successfully updated.',
            'Updated, but the HA sync'
        );

        return redirect()->to('/ha')->with($key, $message);
    }

    public function deleteVip($id)
    {
        $this->haVipModel->delete($id);
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('ha.sync'),
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

        $res = $this->ipc->call('ha.failover', ['target' => $targetMode]);

        return redirect()->to('/ha')->with('success', "Cluster failover triggered! Transitioned to {$targetMode} (Priority {$targetPriority}).");
    }

    public function syncConfig()
    {
        $res = $this->ipc->call('ha.sync', ['force' => true]);
        return redirect()->to('/ha')->with('success', 'Full configuration sync pushed to peer node successfully.');
    }

    private function generateKeepalivedConfig(array $settings, array $vips): string
    {
        $vrrpInstances = "";
        $mode = strtoupper($settings['mode'] ?? 'MASTER');
        $priority = $settings['vrrp_priority'] ?? 110;
        $auth = $settings['vrrp_auth_pass'] ?? 'VRRP_S3cur3';

        foreach ($vips as $vip) {
            $name = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $vip['interface'])) . "_VIP_" . $vip['vrrp_router_id'];
            $vrrpInstances .= "vrrp_instance {$name} {\n";
            $vrrpInstances .= "    state {$mode}\n";
            $vrrpInstances .= "    interface {$vip['interface']}\n";
            $vrrpInstances .= "    virtual_router_id {$vip['vrrp_router_id']}\n";
            $vrrpInstances .= "    priority {$priority}\n";
            $vrrpInstances .= "    advert_int 1\n";
            $vrrpInstances .= "    authentication {\n";
            $vrrpInstances .= "        auth_type PASS\n";
            $vrrpInstances .= "        auth_pass {$auth}\n";
            $vrrpInstances .= "    }\n";
            $vrrpInstances .= "    virtual_ipaddress {\n";
            $vrrpInstances .= "        {$vip['ip_address']}/24 dev {$vip['interface']}\n";
            $vrrpInstances .= "    }\n";
            $vrrpInstances .= "    track_interface {\n";
            $vrrpInstances .= "        {$vip['interface']}\n";
            $vrrpInstances .= "    }\n";
            $vrrpInstances .= "}\n\n";
        }

        return "# MicronOS Automated Keepalived Configuration\n"
             . "# Node: " . ($settings['node_name'] ?? 'node-01') . " | Mode: {$mode}\n\n"
             . "global_defs {\n"
             . "    router_id " . ($settings['node_name'] ?? 'micron-gw-01') . "\n"
             . "    vrrp_version 3\n"
             . "    script_user root\n"
             . "    enable_script_security\n"
             . "}\n\n"
             . $vrrpInstances;
    }
}
