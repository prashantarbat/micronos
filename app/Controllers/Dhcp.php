<?php

namespace App\Controllers;

use App\Models\DhcpServerSettingsModel;
use App\Models\InterfaceModel;
use App\Libraries\IpcClient;
use App\Libraries\KeaDhcpCompiler;

/**
 * Dhcp Controller — per-interface DHCP server configuration,
 * dynamic lease monitoring, PXE/TFTP boot options, and static hardware reservations
 */
class Dhcp extends BaseController
{
    private IpcClient $ipc;
    private DhcpServerSettingsModel $settingsModel;
    private InterfaceModel $interfaceModel;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->settingsModel = new DhcpServerSettingsModel();
        $this->interfaceModel= new InterfaceModel();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();

        // 1. Fetch all configurable interfaces
        $allInterfaces = $this->interfaceModel->findAll();
        if (empty($allInterfaces)) {
            $allInterfaces = [
                [
                    'id'                 => 'lan',
                    'name'               => 'LAN',
                    'physical_device'    => 'eth1',
                    'enabled'            => 1,
                    'ip_assignment_type' => 'static',
                    'ipv4_address'       => '192.168.1.1',
                    'ipv4_netmask'       => 24,
                ],
                [
                    'id'                 => 'dmz',
                    'name'               => 'DMZ',
                    'physical_device'    => 'eth2',
                    'enabled'            => 1,
                    'ip_assignment_type' => 'static',
                    'ipv4_address'       => '172.16.0.1',
                    'ipv4_netmask'       => 24,
                ],
                [
                    'id'                 => 'wan',
                    'name'               => 'WAN',
                    'physical_device'    => 'eth0',
                    'enabled'            => 1,
                    'ip_assignment_type' => 'dhcp',
                    'ipv4_address'       => '',
                    'ipv4_netmask'       => '',
                ],
            ];
        }

        // Filter interfaces where DHCP can run (static IP assigned, typically non-WAN)
        $dhcpEligibleIfaces = [];
        foreach ($allInterfaces as $iface) {
            $ifaceId = $iface['id'] ?? $iface['name'];
            $isWan = (strtolower($ifaceId) === 'wan' || strtolower($iface['name'] ?? '') === 'wan');
            // Allow WAN to be viewed with a note or configure LAN/DMZ/OPT
            $dhcpEligibleIfaces[] = array_merge($iface, ['is_wan' => $isWan]);
        }

        // Determine currently active tab interface
        $currentIfaceId = $this->request->getGet('iface') ?: 'lan';
        $currentIface = null;
        foreach ($dhcpEligibleIfaces as $i) {
            if (strtolower($i['id']) === strtolower($currentIfaceId) || strtolower($i['name']) === strtolower($currentIfaceId)) {
                $currentIface = $i;
                break;
            }
        }
        if (!$currentIface) {
            $currentIface = $dhcpEligibleIfaces[0];
            $currentIfaceId = $currentIface['id'];
        }

        // Subnet math for selected interface
        $ipv4 = $currentIface['ipv4_address'] ?? '192.168.1.1';
        $cidr = (int) ($currentIface['ipv4_netmask'] ?: 24);
        if (!$ipv4) {
            $ipv4 = '192.168.1.1';
            $cidr = 24;
        }

        $ipLong   = ip2long($ipv4) ?: ip2long('192.168.1.1');
        $maskLong = ~((1 << (32 - $cidr)) - 1);
        $subnetBaseLong = $ipLong & $maskLong;
        $broadcastLong  = $subnetBaseLong | (~$maskLong);

        $subnetStr      = long2ip($subnetBaseLong) . '/' . $cidr;
        $subnetMaskStr  = long2ip($maskLong);
        $availFromStr   = long2ip($subnetBaseLong + 1);
        $availToStr     = long2ip($broadcastLong - 1);

        // Fetch settings for this interface
        $settings = $this->settingsModel->getForInterface($currentIfaceId, $currentIface);

        // Development client only: one example reservation so the page is not
        // empty. An appliance starts with none — anything here would be pushed
        // into the live Kea configuration.
        if ($this->ipc->isMock() && $db->table('dhcp_reservations')->countAllResults() === 0) {
            $db->table('dhcp_reservations')->insert([
                'id'           => 'res_' . bin2hex(random_bytes(6)),
                'interface_id' => 'lan',
                'ip_address'   => '192.168.1.50',
                'mac_address'  => '00:11:22:33:44:55',
                'hostname'     => 'core-printer.lan',
                'description'  => 'HP LaserJet Department Printer',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        // Fetch reservations for this interface
        $reservations = $db->table('dhcp_reservations')
                           ->where('interface_id', $currentIfaceId)
                           ->orWhere('interface_id', null)
                           ->orderBy('ip_address', 'ASC')
                           ->get()
                           ->getResultArray();

        // Leases: Kea's lease file on an appliance (`dhcp.leases`), limited to
        // this interface's subnet. The development client shows samples.
        $leases = [];
        if (! $this->ipc->isMock()) {
            $resp = $this->ipc->call('dhcp.leases');
            foreach ((array) ($resp['result']['leases'] ?? []) as $lease) {
                $leaseLong = ip2long((string) ($lease['ip'] ?? ''));
                if ($leaseLong !== false && ($leaseLong & $maskLong) === $subnetBaseLong) {
                    $leases[] = $lease;
                }
            }
        } else {
            $prefix = implode('.', array_slice(explode('.', $ipv4), 0, 3));
            $leases = [
                [
                    'ip'         => "{$prefix}.101",
                    'mac'        => 'bc:24:11:4d:9a:10',
                    'hostname'   => 'workstation-dev01',
                    'client_id'  => 'MSFT 5.0',
                    'start'      => date('Y-m-d H:i', strtotime('-1 hour')),
                    'expires'    => date('Y-m-d H:i', strtotime('+1 hour')),
                    'status'     => 'active',
                    'type'       => 'dynamic',
                ],
                [
                    'ip'         => "{$prefix}.102",
                    'mac'        => '00:1a:2b:3c:4d:5e',
                    'hostname'   => 'nas-storage.lan',
                    'client_id'  => 'Synology-DS920',
                    'start'      => date('Y-m-d H:i', strtotime('-18 hours')),
                    'expires'    => date('Y-m-d H:i', strtotime('+6 hours')),
                    'status'     => 'active',
                    'type'       => 'dynamic',
                ],
                [
                    'ip'         => "{$prefix}.105",
                    'mac'        => 'd8:3b:bf:22:91:04',
                    'hostname'   => 'iphone-alice',
                    'client_id'  => 'Apple-iPhone',
                    'start'      => date('Y-m-d H:i', strtotime('-30 mins')),
                    'expires'    => date('Y-m-d H:i', strtotime('+90 mins')),
                    'status'     => 'active',
                    'type'       => 'dynamic',
                ],
            ];
        }

        return view('dhcp/index', [
            'title'              => 'DHCP Server & Leases — MicronOS',
            'breadcrumb'         => 'DHCP',
            'interfaces'         => $dhcpEligibleIfaces,
            'currentIfaceId'     => $currentIfaceId,
            'currentIface'       => $currentIface,
            'subnetStr'          => $subnetStr,
            'subnetMaskStr'      => $subnetMaskStr,
            'availFromStr'       => $availFromStr,
            'availToStr'         => $availToStr,
            'settings'           => $settings,
            'leases'             => $leases,
            'reservations'       => $reservations,
            'isMock'             => $this->ipc->isMock(),
        ]);
    }

    public function leases(): string
    {
        return $this->index();
    }

    /**
     * Compile the current DHCP state and push it to the daemon.
     *
     * `dhcp.apply` has the daemon validate the Kea JSON (`kea-dhcp4 -t`), write
     * /etc/kea/kea-dhcp4.conf atomically and restart kea-dhcp4-server; a rejected
     * config is reported through ipcFlash rather than silently left in place.
     */
    private function applyDhcpConfig(): array
    {
        $config = (new KeaDhcpCompiler())->compile();

        return $this->ipc->call('dhcp.apply', ['config' => $config]);
    }

    public function saveSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $ifaceId = $this->request->getPost('interface_id');
        if (!$ifaceId) {
            return redirect()->to(base_url('dhcp'))->with('error', 'Interface ID is missing.');
        }

        // Custom options parsing (extra DHCP options)
        $optNumbers = $this->request->getPost('custom_opt_number') ?? [];
        $optTypes   = $this->request->getPost('custom_opt_type') ?? [];
        $optValues  = $this->request->getPost('custom_opt_value') ?? [];
        $optDescs   = $this->request->getPost('custom_opt_desc') ?? [];

        $customOptions = [];
        for ($i = 0; $i < count($optNumbers); $i++) {
            $num = trim($optNumbers[$i]);
            $val = trim($optValues[$i] ?? '');
            if ($num !== '' && $val !== '') {
                $customOptions[] = [
                    'number'      => (int) $num,
                    'type'        => $optTypes[$i] ?? 'Text',
                    'value'       => $val,
                    'description' => $optDescs[$i] ?? '',
                ];
            }
        }

        $data = [
            'interface_id'       => $ifaceId,
            'enabled'            => $this->request->getPost('enabled') ? 1 : 0,
            'deny_unknown'       => $this->request->getPost('deny_unknown') ? 1 : 0,
            'range_from'         => trim($this->request->getPost('range_from') ?? ''),
            'range_to'           => trim($this->request->getPost('range_to') ?? ''),
            'gateway'            => trim($this->request->getPost('gateway') ?? '') ?: null,
            'domain_name'        => trim($this->request->getPost('domain_name') ?? '') ?: null,
            'domain_search'      => trim($this->request->getPost('domain_search') ?? '') ?: null,
            'dns_1'              => trim($this->request->getPost('dns_1') ?? '') ?: null,
            'dns_2'              => trim($this->request->getPost('dns_2') ?? '') ?: null,
            'dns_3'              => trim($this->request->getPost('dns_3') ?? '') ?: null,
            'dns_4'              => trim($this->request->getPost('dns_4') ?? '') ?: null,
            'default_lease_time' => (int) ($this->request->getPost('default_lease_time') ?: 7200),
            'max_lease_time'     => (int) ($this->request->getPost('max_lease_time') ?: 86400),
            'ntp_1'              => trim($this->request->getPost('ntp_1') ?? '') ?: null,
            'ntp_2'              => trim($this->request->getPost('ntp_2') ?? '') ?: null,
            'tftp_server'        => trim($this->request->getPost('tftp_server') ?? '') ?: null,
            'next_server'        => trim($this->request->getPost('next_server') ?? '') ?: null,
            'bootfile_name'      => trim($this->request->getPost('bootfile_name') ?? '') ?: null,
            'wins_1'             => trim($this->request->getPost('wins_1') ?? '') ?: null,
            'wins_2'             => trim($this->request->getPost('wins_2') ?? '') ?: null,
            'custom_options'     => json_encode($customOptions),
        ];

        $existing = $this->settingsModel->where('interface_id', $ifaceId)->first();
        if ($existing) {
            $this->settingsModel->update($existing['id'], $data);
        } else {
            $this->settingsModel->insert($data);
        }

        [$key, $message] = $this->ipcFlash(
            $this->applyDhcpConfig(),
            "DHCP server options for interface '" . strtoupper($ifaceId) . "' saved and reloaded.",
            'Saved, but the DHCP reload'
        );

        return redirect()->to(base_url('dhcp?iface=' . urlencode($ifaceId)))->with($key, $message);
    }

    public function storeReservation(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ifaceId  = trim($this->request->getPost('interface_id') ?? 'lan');
        $ip       = trim($this->request->getPost('ip_address') ?? '');
        $mac      = trim($this->request->getPost('mac_address') ?? '');
        $hostname = trim($this->request->getPost('hostname') ?? '');
        $desc     = trim($this->request->getPost('description') ?? '');

        if (empty($ip) || empty($mac) || empty($hostname)) {
            return redirect()->to(base_url('dhcp?iface=' . urlencode($ifaceId)))->with('error', 'IP address, MAC address, and Hostname are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dhcp_reservations')->insert([
            'id'           => 'res_' . bin2hex(random_bytes(6)),
            'interface_id' => $ifaceId,
            'ip_address'   => $ip,
            'mac_address'  => strtolower($mac),
            'hostname'     => $hostname,
            'description'  => $desc,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        [$key, $message] = $this->ipcFlash(
            $this->applyDhcpConfig(),
            "Static DHCP reservation for {$hostname} ({$ip}) created.",
            'Reservation saved, but the DHCP reload'
        );

        return redirect()->to(base_url('dhcp?iface=' . urlencode($ifaceId)))->with($key, $message);
    }

    public function updateReservation(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $res = $db->table('dhcp_reservations')->where('id', $id)->get()->getRowArray();
        if (!$res) {
            return redirect()->to(base_url('dhcp'))->with('error', 'Reservation not found.');
        }

        $ifaceId = $this->request->getPost('interface_id') ?: ($res['interface_id'] ?? 'lan');

        $data = [
            'interface_id' => $ifaceId,
            'ip_address'   => trim($this->request->getPost('ip_address') ?: $res['ip_address']),
            'mac_address'  => strtolower(trim($this->request->getPost('mac_address') ?: $res['mac_address'])),
            'hostname'     => trim($this->request->getPost('hostname') ?: $res['hostname']),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $db->table('dhcp_reservations')->where('id', $id)->update($data);

        [$key, $message] = $this->ipcFlash(
            $this->applyDhcpConfig(),
            'Reservation updated.',
            'Reservation saved, but the DHCP reload'
        );

        return redirect()->to(base_url('dhcp?iface=' . urlencode($ifaceId)))->with($key, $message);
    }

    public function deleteReservation(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $res = $db->table('dhcp_reservations')->where('id', $id)->get()->getRowArray();
        $ifaceId = $res['interface_id'] ?? 'lan';

        $db->table('dhcp_reservations')->where('id', $id)->delete();

        [$key, $message] = $this->ipcFlash(
            $this->applyDhcpConfig(),
            'Reservation deleted.',
            'Reservation deleted, but the DHCP reload'
        );

        return redirect()->to(base_url('dhcp?iface=' . urlencode($ifaceId)))->with($key, $message);
    }

    public function restart(): \CodeIgniter\HTTP\RedirectResponse
    {
        [$key, $message] = $this->ipcFlash(
            $this->applyDhcpConfig(),
            'DHCP service daemon reloaded successfully.',
            'DHCP restart'
        );

        return redirect()->to(base_url('dhcp'))->with($key, $message);
    }
}
