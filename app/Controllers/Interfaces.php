<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\PppoeCompiler;
use App\Models\BondModel;
use App\Models\InterfaceModel;
use App\Models\WirelessModel;

/**
 * Interfaces Controller — Network Interface configuration, 802.1Q VLAN virtual interfaces,
 * physical NIC assignment, link addressing (IPv4/IPv6/PPPoE), traffic parameters, and telemetry.
 */
class Interfaces extends BaseController
{
    private IpcClient $ipc;
    private InterfaceModel $model;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc   = \Config\Services::ipc();
        $this->model = new InterfaceModel();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();
        $dbIfaces = $db->table('interfaces')->orderBy('interface_type', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        // Get live runtime state from micrond (or mock on Windows)
        $liveState = $this->ipc->getInterfaces();
        $liveByDev = [];
        $detectedHardware = [];

        if (! empty($liveState['interfaces'])) {
            foreach ($liveState['interfaces'] as $item) {
                $dev = $item['device'] ?? $item['name'] ?? '';
                if ($dev !== '') {
                    $liveByDev[$dev] = $item;
                    $liveByDev[$item['name'] ?? ''] = $item;
                    // Detect if this is a physical port (e.g. eth0, eth1, enp3s0, not a VLAN like eth0.10)
                    if (! str_contains($dev, '.') && ! str_contains($dev, ':')) {
                        $detectedHardware[$dev] = $item;
                    }
                }
            }
        }

        // Standard fallback NICs if none detected
        if (empty($detectedHardware)) {
            foreach (['eth0', 'eth1', 'eth2', 'eth3', 'eth4'] as $fallbackDev) {
                $detectedHardware[$fallbackDev] = ['device' => $fallbackDev, 'status' => 'down'];
            }
        }

        // Which switch and port each device is patched into, from the lldpd
        // plugin's neighbour table (empty when that plugin is not installed).
        $lldpPeers = $this->lldpPeersByDevice();

        $assignedDevices = [];
        $parentDevices   = [];
        $upCount         = 0;
        $downCount       = 0;
        $vlanCount       = 0;
        $physicalCount   = 0;

        // Merge live status with DB record
        foreach ($dbIfaces as &$iface) {
            $dev  = $iface['physical_device'] ?? '';
            $live = $liveByDev[$dev] ?? null;

            $isUp = $live ? ($live['status'] === 'up') : ((bool) ($iface['enabled'] ?? 1));
            $iface['is_up']        = $isUp;
            $iface['rx_bytes']     = $live['rx_bytes'] ?? 0;
            $iface['tx_bytes']     = $live['tx_bytes'] ?? 0;
            $iface['rx_human']     = $this->formatBytes((int) $iface['rx_bytes']);
            $iface['tx_human']     = $this->formatBytes((int) $iface['tx_bytes']);
            $iface['current_ip']   = ! empty($live['ip']) ? $live['ip'] : ($iface['ipv4_address'] ?? ($iface['ip_assignment_type'] === 'dhcp' ? 'DHCP Pending' : 'None'));
            $iface['mac']          = ! empty($iface['mac_override']) ? $iface['mac_override'] : ($live['mac'] ?? '52:54:00:12:34:56');
            $iface['speed']        = ! empty($iface['speed_duplex']) && $iface['speed_duplex'] !== 'auto' ? $iface['speed_duplex'] : ($live['speed'] ?? '1000 Mbps Full Duplex');
            $iface['type_label']   = strtoupper((string) ($iface['interface_type'] ?? 'physical'));

            // The discovered peer is inventory, not configuration: it comes and
            // goes with what is plugged in, and is absent when nothing answers.
            $peer                     = $lldpPeers[$dev] ?? null;
            $iface['lldp_peer']       = $peer;
            $iface['lldp_peer_label'] = $peer !== null ? $this->lldpPeerLabel($peer) : '';

            if ($iface['interface_type'] === 'vlan') {
                $vlanCount++;
            } else {
                $physicalCount++;
                $assignedDevices[$dev] = true;
                $parentDevices[$dev]   = $iface['name'] . " ({$dev})";
            }

            if ($isUp) {
                $upCount++;
            } else {
                $downCount++;
            }
        }
        unset($iface);

        // Find available unassigned physical devices
        $availableDevices = [];
        foreach (array_keys($detectedHardware) as $hDev) {
            if (! isset($assignedDevices[$hDev])) {
                $availableDevices[] = $hDev;
            }
        }

        // Bonding / Link Aggregation (IEEE 802.3ad LACP)
        $bondModel = new BondModel();
        $bonds = $bondModel->getBondsWithDetails();
        $liveBondRes = $this->ipc->bondList();
        $liveBonds = $liveBondRes['bonds'] ?? [];
        $liveBondMap = [];
        foreach ($liveBonds as $lb) {
            $liveBondMap[$lb['name'] ?? ''] = $lb;
        }
        foreach ($bonds as &$bond) {
            $bond['live'] = $liveBondMap[$bond['id']] ?? null;
            // Bonds can also act as parents for 802.1Q VLANs
            $parentDevices[$bond['id']] = $bond['name'] . " ({$bond['id']} LACP Trunk)";
        }
        unset($bond);

        // Wireless LAN interfaces (wlan0 AP / Client)
        $wirelessModel = new WirelessModel();
        $wirelessList = $wirelessModel->orderBy('interface', 'ASC')->findAll();
        $wirelessWithStatus = [];
        foreach ($wirelessList as $w) {
            $wStatus = $this->ipc->wirelessStatus($w['interface']);
            $w['live'] = $wStatus;
            $wirelessWithStatus[] = $w;
        }

        // Ensure at least one eligible parent device exists for VLAN creation
        if (empty($parentDevices)) {
            $parentDevices['eth0'] = 'WAN (eth0)';
            $parentDevices['eth1'] = 'LAN (eth1)';
        }

        $summary = [
            'total'    => count($dbIfaces),
            'up'       => $upCount,
            'down'     => $downCount,
            'physical' => $physicalCount,
            'vlan'     => $vlanCount,
            'bonds'    => count($bonds),
            'wireless' => count($wirelessWithStatus),
        ];

        return view('interfaces/index', [
            'title'            => 'Network Interfaces — MicronOS',
            'breadcrumb'       => 'Interfaces',
            'interfaces'       => $dbIfaces,
            'summary'          => $summary,
            'availableDevices' => $availableDevices,
            'parentDevices'    => $parentDevices,
            'bonds'            => $bonds,
            'wireless'         => $wirelessWithStatus,
            'allPhysicalDevs'  => array_keys($detectedHardware),
            'isMock'           => $this->ipc->isMock(),
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $name          = trim((string) $this->request->getPost('name'));
        $interfaceType = trim((string) ($this->request->getPost('interface_type') ?: 'physical'));
        $rawId         = trim((string) $this->request->getPost('id'));

        if ($name === '') {
            return redirect()->back()->withInput()->with('error', 'Interface identifier name is required.');
        }

        // Generate clean ID slug if not provided
        $id = $rawId !== '' ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId)) : strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name));
        if ($id === '') {
            $id = 'iface_' . bin2hex(random_bytes(3));
        }

        // Check ID and name uniqueness
        $idExists = $db->table('interfaces')->where('id', $id)->countAllResults();
        if ($idExists > 0) {
            return redirect()->back()->withInput()->with('error', "An interface with ID '{$id}' already exists.");
        }

        $nameExists = $db->table('interfaces')->where('name', $name)->countAllResults();
        if ($nameExists > 0) {
            return redirect()->back()->withInput()->with('error', "An interface with name '{$name}' already exists.");
        }

        // Device determination
        $physicalDevice = '';
        $vlanParent     = null;
        $vlanTag        = null;
        $vlanPriority   = null;

        if ($interfaceType === 'vlan') {
            $vlanParent = trim((string) $this->request->getPost('vlan_parent'));
            $vlanTag    = (int) $this->request->getPost('vlan_tag');
            $vlanPrio   = $this->request->getPost('vlan_priority');
            $vlanPriority = ($vlanPrio !== null && $vlanPrio !== '') ? (int) $vlanPrio : null;

            if ($vlanParent === '') {
                return redirect()->back()->withInput()->with('error', 'A parent physical interface is required for 802.1Q VLANs.');
            }
            if ($vlanTag < 1 || $vlanTag > 4094) {
                return redirect()->back()->withInput()->with('error', 'VLAN Tag must be between 1 and 4094.');
            }

            $physicalDevice = "{$vlanParent}.{$vlanTag}";

            // Check if this specific VLAN tag already exists on this parent
            $vlanExists = $db->table('interfaces')
                ->where('vlan_parent', $vlanParent)
                ->where('vlan_tag', $vlanTag)
                ->countAllResults();
            if ($vlanExists > 0) {
                return redirect()->back()->withInput()->with('error', "VLAN {$vlanTag} is already assigned on parent interface '{$vlanParent}'.");
            }
        } else {
            $interfaceType  = 'physical';
            $physicalDevice = trim((string) $this->request->getPost('physical_device'));

            if ($physicalDevice === '__custom__' || $physicalDevice === '') {
                $customDev = trim((string) $this->request->getPost('physical_device_custom'));
                if ($customDev !== '') {
                    $physicalDevice = $customDev;
                }
            }

            if ($physicalDevice === '' || $physicalDevice === '__custom__') {
                return redirect()->back()->withInput()->with('error', 'Physical hardware device binding is required.');
            }

            if (! preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/', $physicalDevice)) {
                return redirect()->back()->withInput()->with('error', "Invalid network interface name format: '{$physicalDevice}'.");
            }

            // Check if physical device is already bound to another physical interface
            $devExists = $db->table('interfaces')
                ->where('physical_device', $physicalDevice)
                ->where('interface_type', 'physical')
                ->countAllResults();
            if ($devExists > 0) {
                return redirect()->back()->withInput()->with('error', "Device '{$physicalDevice}' is already assigned to another physical interface.");
            }
        }

        $assignmentType = $this->request->getPost('ip_assignment_type') ?: 'dhcp';
        $isStatic       = $assignmentType === 'static';
        $isPppoe        = $assignmentType === 'pppoe';

        if ($isStatic) {
            $ip = trim((string) $this->request->getPost('ipv4_address'));
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return redirect()->back()->withInput()->with('error', 'A valid IPv4 address is required for Static configuration.');
            }
        }

        if ($isPppoe && trim((string) $this->request->getPost('pppoe_username')) === '') {
            return redirect()->back()->withInput()->with('error', 'A PPPoE username is required for a PPPoE dialup link.');
        }

        $now = date('Y-m-d H:i:s');
        $mtu = (int) ($this->request->getPost('mtu') ?: ($isPppoe ? 1492 : 1500));
        $mtu = max(576, min(9000, $mtu));

        $data = [
            'id'                   => $id,
            'name'                 => $name,
            'physical_device'      => $physicalDevice,
            'enabled'              => $this->request->getPost('enabled') ? 1 : 0,
            'interface_type'       => $interfaceType,
            'vlan_parent'          => $vlanParent,
            'vlan_tag'             => $vlanTag,
            'vlan_priority'        => $vlanPriority,
            'ip_assignment_type'   => $assignmentType,
            'ipv4_address'         => $isStatic ? trim((string) $this->request->getPost('ipv4_address')) : null,
            'ipv4_netmask'         => $isStatic ? (int) ($this->request->getPost('ipv4_netmask') ?: 24) : null,
            'ipv4_gateway'         => $isStatic ? trim((string) $this->request->getPost('ipv4_gateway')) ?: null : null,
            'ipv6_assignment_type' => $this->request->getPost('ipv6_assignment_type') ?: 'disabled',
            'ipv6_address'         => trim((string) $this->request->getPost('ipv6_address')) ?: null,
            'ipv6_prefix'          => $this->request->getPost('ipv6_prefix') ? (int) $this->request->getPost('ipv6_prefix') : null,
            'mtu'                  => $mtu,
            'mss_clamp'            => $this->request->getPost('mss_clamp') ? (int) $this->request->getPost('mss_clamp') : null,
            'mac_override'         => trim((string) $this->request->getPost('mac_override')) ?: null,
            'speed_duplex'         => trim((string) ($this->request->getPost('speed_duplex') ?: 'auto')),
            'description'          => trim((string) $this->request->getPost('description')) ?: null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        if ($isPppoe) {
            $compiler = new PppoeCompiler();
            $data['pppoe_username']     = trim((string) $this->request->getPost('pppoe_username'));
            $data['pppoe_service']      = trim((string) $this->request->getPost('pppoe_service')) ?: null;
            $data['pppoe_ac_name']      = trim((string) $this->request->getPost('pppoe_ac_name')) ?: null;
            $data['pppoe_mtu']          = (int) ($this->request->getPost('pppoe_mtu') ?: 1492);
            $data['pppoe_mru']          = (int) ($this->request->getPost('pppoe_mru') ?: 1492);
            $data['pppoe_idle_timeout'] = max(0, (int) $this->request->getPost('pppoe_idle_timeout'));
            $data['pppoe_default_route']= $this->request->getPost('pppoe_default_route') ? 1 : 0;

            $password = (string) $this->request->getPost('pppoe_password');
            if ($password !== '') {
                $data['pppoe_password'] = $compiler->encrypt($password);
            }
        }

        $db->table('interfaces')->insert($data);

        $this->audit('interfaces.create', $id, "Created interface '{$name}' ({$physicalDevice}, type: {$interfaceType})");

        return redirect()->to(base_url('interfaces'))
            ->with('success', "Interface '{$name}' created successfully. Remember to Apply Changes.");
    }

    public function edit(string $id): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $iface = $db->table('interfaces')->where('id', $id)->get()->getRowArray();

        if (! $iface) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Interface not found.');
        }

        // Get candidate parent devices for VLANs
        $parentDevices = [];
        $dbPhysical = $db->table('interfaces')->where('interface_type', 'physical')->get()->getResultArray();
        foreach ($dbPhysical as $p) {
            $dev = $p['physical_device'];
            $parentDevices[$dev] = $p['name'] . " ({$dev})";
        }
        if (empty($parentDevices)) {
            $parentDevices['eth0'] = 'WAN (eth0)';
            $parentDevices['eth1'] = 'LAN (eth1)';
        }

        return view('interfaces/edit', [
            'title'         => "Configure Interface: {$iface['name']} — MicronOS",
            'breadcrumb'    => 'Edit Interface',
            'iface'         => $iface,
            'parentDevices' => $parentDevices,
        ]);
    }

    public function save(): \CodeIgniter\HTTP\RedirectResponse
    {
        $id = $this->request->getPost('id');
        $db = \Config\Database::connect();
        $iface = $db->table('interfaces')->where('id', $id)->get()->getRowArray();

        if (! $iface) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Interface not found.');
        }

        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->withInput()->with('error', 'Interface identifier name is required.');
        }

        // Check name uniqueness if changed
        if ($name !== $iface['name']) {
            $nameExists = $db->table('interfaces')->where('name', $name)->where('id !=', $id)->countAllResults();
            if ($nameExists > 0) {
                return redirect()->back()->withInput()->with('error', "Another interface named '{$name}' already exists.");
            }
        }

        $assignmentType = $this->request->getPost('ip_assignment_type') ?: 'dhcp';
        $isStatic       = $assignmentType === 'static';
        $isPppoe        = $assignmentType === 'pppoe';

        if ($isStatic) {
            $ip = trim((string) $this->request->getPost('ipv4_address'));
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return redirect()->back()->withInput()->with('error', 'A valid IPv4 address is required for Static configuration.');
            }
        }

        if ($isPppoe && trim((string) $this->request->getPost('pppoe_username')) === '') {
            return redirect()->back()->withInput()->with('error', 'A PPPoE username is required for a PPPoE link.');
        }

        $mtu = (int) ($this->request->getPost('mtu') ?: 1500);
        $mtu = max(576, min(9000, $mtu));

        $data = [
            'name'                 => $name,
            'enabled'              => $this->request->getPost('enabled') ? 1 : 0,
            'ip_assignment_type'   => $assignmentType,
            'ipv4_address'         => $isStatic ? trim((string) $this->request->getPost('ipv4_address')) : null,
            'ipv4_netmask'         => $isStatic ? (int) ($this->request->getPost('ipv4_netmask') ?: 24) : null,
            'ipv4_gateway'         => $isStatic ? trim((string) $this->request->getPost('ipv4_gateway')) ?: null : null,
            'ipv6_assignment_type' => $this->request->getPost('ipv6_assignment_type') ?: 'disabled',
            'ipv6_address'         => trim((string) $this->request->getPost('ipv6_address')) ?: null,
            'ipv6_prefix'          => $this->request->getPost('ipv6_prefix') ? (int) $this->request->getPost('ipv6_prefix') : null,
            'mtu'                  => $mtu,
            'mss_clamp'            => $this->request->getPost('mss_clamp') ? (int) $this->request->getPost('mss_clamp') : null,
            'mac_override'         => trim((string) $this->request->getPost('mac_override')) ?: null,
            'speed_duplex'         => trim((string) ($this->request->getPost('speed_duplex') ?: 'auto')),
            'description'          => trim((string) $this->request->getPost('description')) ?: null,
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        // Handle VLAN updates if applicable
        if (($iface['interface_type'] ?? '') === 'vlan') {
            $vlanTag = (int) $this->request->getPost('vlan_tag');
            if ($vlanTag >= 1 && $vlanTag <= 4094) {
                $data['vlan_tag'] = $vlanTag;
                if (! empty($iface['vlan_parent'])) {
                    $data['physical_device'] = "{$iface['vlan_parent']}.{$vlanTag}";
                }
            }
            $vlanPrio = $this->request->getPost('vlan_priority');
            $data['vlan_priority'] = ($vlanPrio !== null && $vlanPrio !== '') ? (int) $vlanPrio : null;
        }

        if ($isPppoe) {
            $compiler = new PppoeCompiler();
            $data['pppoe_username']     = trim((string) $this->request->getPost('pppoe_username'));
            $data['pppoe_service']      = trim((string) $this->request->getPost('pppoe_service')) ?: null;
            $data['pppoe_ac_name']      = trim((string) $this->request->getPost('pppoe_ac_name')) ?: null;
            $data['pppoe_mtu']          = (int) ($this->request->getPost('pppoe_mtu') ?: 1492);
            $data['pppoe_mru']          = (int) ($this->request->getPost('pppoe_mru') ?: 1492);
            $data['pppoe_idle_timeout'] = max(0, (int) $this->request->getPost('pppoe_idle_timeout'));
            $data['pppoe_default_route']= $this->request->getPost('pppoe_default_route') ? 1 : 0;

            $password = (string) $this->request->getPost('pppoe_password');
            if ($password !== '') {
                $data['pppoe_password'] = $compiler->encrypt($password);
            }
        }

        $db->table('interfaces')->where('id', $id)->update($data);

        $this->audit('interfaces.update', $id, "Updated interface '{$name}'");

        return redirect()->to(base_url('interfaces'))
            ->with('success', "Interface '{$name}' configuration updated. Remember to Apply Changes.");
    }

    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $iface = $db->table('interfaces')->where('id', $id)->get()->getRowArray();

        if (! $iface) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Interface not found.');
        }

        $newState = ((int) ($iface['enabled'] ?? 1) === 1) ? 0 : 1;
        $db->table('interfaces')->where('id', $id)->update([
            'enabled'    => $newState,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $statusStr = $newState === 1 ? 'enabled' : 'disabled';
        $this->audit('interfaces.toggle', $id, "Interface '{$iface['name']}' {$statusStr}");

        return redirect()->to(base_url('interfaces'))
            ->with('success', "Interface '{$iface['name']}' has been {$statusStr}. Remember to Apply Changes.");
    }

    public function delete(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $iface = $db->table('interfaces')->where('id', $id)->get()->getRowArray();

        if (! $iface) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Interface not found.');
        }

        // Safety: protect default primary WAN and LAN interfaces from accidental removal
        if (in_array(strtolower($id), ['wan', 'lan'], true) && ! $this->request->getPost('confirm_core_delete')) {
            return redirect()->to(base_url('interfaces'))
                ->with('error', "Primary interface '{$iface['name']}' ({$id}) is a core system interface and cannot be deleted.");
        }

        // Check dependencies across firewall rules, NAT, DHCP, PPPoE, child VLANs
        $deps = [];

        if ($db->tableExists('firewall_rules')) {
            $cnt = $db->table('firewall_rules')->where('interface_id', $id)->countAllResults();
            if ($cnt > 0) $deps[] = "{$cnt} firewall policy rule(s)";
        }

        if ($db->tableExists('nat_nptv6')) {
            $cnt = $db->table('nat_nptv6')->where('interface_id', $id)->countAllResults();
            if ($cnt > 0) $deps[] = "{$cnt} NPTv6 prefix translation rule(s)";
        }

        if ($db->tableExists('nat_dnat')) {
            $cnt = $db->table('nat_dnat')->where('interface_id', $id)->countAllResults();
            if ($cnt > 0) $deps[] = "{$cnt} Destination NAT rule(s)";
        }

        if ($db->tableExists('nat_snat_pools')) {
            $cnt = $db->table('nat_snat_pools')->where('interface_id', $id)->countAllResults();
            if ($cnt > 0) $deps[] = "{$cnt} SNAT Pool(s)";
        }

        if ($db->tableExists('dhcp_server_settings')) {
            $cnt = $db->table('dhcp_server_settings')->where('interface_id', $id)->countAllResults();
            if ($cnt > 0) $deps[] = "{$cnt} DHCP server configuration(s)";
        }

        if ($db->tableExists('pppoe_servers')) {
            $cnt = $db->table('pppoe_servers')->where('interface', $iface['physical_device'])->countAllResults();
            if ($cnt > 0) $deps[] = "{$cnt} PPPoE Access Concentrator(s)";
        }

        // Check if other VLANs use this as parent
        $vlanChildren = $db->table('interfaces')->where('vlan_parent', $iface['physical_device'])->countAllResults();
        if ($vlanChildren > 0) {
            $deps[] = "{$vlanChildren} child 802.1Q VLAN interface(s)";
        }

        if (! empty($deps)) {
            $depStr = implode(', ', $deps);
            return redirect()->to(base_url('interfaces'))
                ->with('error', "Cannot delete interface '{$iface['name']}': It is actively referenced by {$depStr}. Please reassign or delete these references first.");
        }

        $db->table('interfaces')->where('id', $id)->delete();

        $this->audit('interfaces.delete', $id, "Deleted interface '{$iface['name']}' ({$iface['physical_device']})");

        return redirect()->to(base_url('interfaces'))
            ->with('success', "Interface '{$iface['name']}' has been removed. Remember to Apply Changes.");
    }

    public function apply(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $resp = $this->ipc->call('network.apply', ['reload_dhcp' => true]);
            $msg = $resp['message'] ?? 'Network interface configurations applied to kernel.';

            // The dynamic half of the DNS Server Override: pick up any DHCP/PPP/
            // OpenVPN-assigned resolvers the links now hold and merge (or drop)
            // them in the appliance stub resolver.
            $msg .= $this->reconcileApplianceResolver($this->ipc);

            $this->audit('interfaces.apply', 'network', 'Applied network interface changes to kernel');
            return redirect()->to(base_url('interfaces'))->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Failed to apply: ' . $e->getMessage());
        }
    }

    private function audit(string $action, string $target, string $desc): void
    {
        try {
            $db = \Config\Database::connect();
            if ($db->tableExists('audit_logs')) {
                $db->table('audit_logs')->insert([
                    // admin_user/client_ip are NOT NULL on an appliance whose
                    // database predates the migration file's later edits.
                    'admin_user'      => session('username') ?? 'admin',
                    'client_ip'       => $this->request->getIPAddress(),
                    'user_id'         => session('user_id') ?? 'system',
                    'username'        => session('username') ?? 'admin',
                    'action_type'     => 'network',
                    'action'          => $action,
                    'target_resource' => 'Interface',
                    'target'          => $target,
                    'new_state'       => $desc,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * The LLDP peer each device is patched into, keyed by device name.
     *
     * The neighbour table belongs to the lldpd plugin, so it is only consulted
     * when that plugin is installed. Anything else — the plugin absent, the
     * daemon unreachable, no neighbours answering — yields an empty map, and the
     * inventory simply carries no peer information rather than failing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lldpPeersByDevice(): array
    {
        try {
            $manager = \Config\Services::packageManager();
            if ($manager->registry()->get('lldpd') === null) {
                return [];
            }

            $db = \Config\Database::connect();
            if (! $db->tableExists('plugins') || $db->table('plugins')->where('id', 'lldpd')->countAllResults() < 1) {
                return [];
            }

            $result = $manager->lldpdNeighbours(['format' => 'json']);
            if (($result['success'] ?? false) !== true) {
                return [];
            }
        } catch (\Throwable $e) {
            // An inventory page must render even when the daemon cannot answer.
            return [];
        }

        $peers = [];
        foreach ((array) ($result['data']['ports'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $device = (string) ($row['interface'] ?? '');
            if ($device === '') {
                continue;
            }
            // One port can see several neighbours; the first names the link and
            // the rest are counted so the count is not silently lost.
            if (isset($peers[$device])) {
                $peers[$device]['extra'] = (int) ($peers[$device]['extra'] ?? 0) + 1;
                continue;
            }
            $peers[$device] = $row + ['extra' => 0];
        }

        return $peers;
    }

    /**
     * A one-line label for a neighbour: its system name (or chassis ID) and the
     * remote port it was seen on.
     *
     * @param array<string, mixed> $peer
     */
    private function lldpPeerLabel(array $peer): string
    {
        $name = trim((string) ($peer['chassis'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($peer['chassis_id'] ?? ''));
        }
        if ($name === '') {
            $name = 'unnamed neighbour';
        }

        $port  = trim((string) ($peer['port'] ?? ''));
        $label = $port !== '' ? $name . ' · ' . $port : $name;

        $extra = (int) ($peer['extra'] ?? 0);
        if ($extra > 0) {
            $label .= ' (+' . $extra . ' more)';
        }

        return $label;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Create or update a kernel bonding interface (e.g. bond0 802.3ad LACP).
     */
    public function storeBond(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $bondModel = new BondModel();

        $id       = strtolower(trim((string) $this->request->getPost('id')));
        $name     = trim((string) $this->request->getPost('name'));
        $mode     = trim((string) ($this->request->getPost('mode') ?: '802.3ad'));
        $slaves   = (array) $this->request->getPost('slaves');
        $lacpRate = trim((string) ($this->request->getPost('lacp_rate') ?: 'fast'));
        $xmitHash = trim((string) ($this->request->getPost('xmit_hash_policy') ?: 'layer2+3'));
        $miimon   = (int) ($this->request->getPost('miimon') ?: 100);
        $mtu      = (int) ($this->request->getPost('mtu') ?: 1500);

        if (! preg_match('/^bond[0-9]+$/', $id)) {
            return redirect()->to(base_url('interfaces?tab=bonds'))->withInput()->with('error', 'Bond identifier must follow bond0, bond1 naming format.');
        }

        if ($name === '') {
            $name = strtoupper($id) . ' Trunk';
        }

        // Filter and sanitize member slave interfaces
        $slaves = array_values(array_filter(array_map('trim', $slaves), static fn ($s) => $s !== ''));
        if (empty($slaves)) {
            return redirect()->to(base_url('interfaces?tab=bonds'))->withInput()->with('error', 'At least one member network interface must be selected for the bond aggregation.');
        }

        $bondData = [
            'id'               => $id,
            'name'             => $name,
            'mode'             => $mode,
            'slaves'           => json_encode($slaves),
            'lacp_rate'        => $lacpRate,
            'xmit_hash_policy' => $xmitHash,
            'miimon'           => $miimon > 0 ? $miimon : 100,
            'updelay'          => (int) ($this->request->getPost('updelay') ?: 0),
            'downdelay'        => (int) ($this->request->getPost('downdelay') ?: 0),
            'mtu'              => $mtu > 0 ? $mtu : 1500,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        $existing = $bondModel->find($id);
        if ($existing) {
            $bondModel->update($id, $bondData);
        } else {
            $bondData['created_at'] = date('Y-m-d H:i:s');
            $bondModel->insert($bondData);
        }

        // Ensure entry in interfaces table for IP assignment and routing
        $ifaceRow = $db->table('interfaces')->where('physical_device', $id)->get()->getRowArray();
        if (! $ifaceRow) {
            $db->table('interfaces')->insert([
                'id'                 => $id,
                'name'               => $name,
                'physical_device'    => $id,
                'interface_type'     => 'bond',
                'ip_assignment_type' => 'none',
                'mtu'                => $mtu,
                'enabled'            => 1,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        } else {
            $db->table('interfaces')->where('id', $ifaceRow['id'])->update([
                'mtu'        => $mtu,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Apply configuration via micrond
        $ipcData = [
            'id'               => $id,
            'mode'             => $mode,
            'slaves'           => $slaves,
            'lacp_rate'        => $lacpRate,
            'xmit_hash_policy' => $xmitHash,
            'miimon'           => $miimon,
            'mtu'              => $mtu,
        ];
        $this->ipc->bondApply($ipcData);

        return redirect()->to(base_url('interfaces?tab=bonds'))->with('success', "Bond interface {$id} ({$mode}) successfully created and applied to Micron OS kernel.");
    }

    /**
     * Delete a kernel bonding interface and release member physical interfaces.
     */
    public function deleteBond(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $bondModel = new BondModel();

        $bond = $bondModel->find($id);
        if (! $bond) {
            return redirect()->to(base_url('interfaces?tab=bonds'))->with('error', "Bond '{$id}' does not exist.");
        }

        $bondModel->delete($id);

        // Remove from interfaces table if it was created as bond type
        $db->table('interfaces')->where('physical_device', $id)->where('interface_type', 'bond')->delete();

        // Release slaves and delete link via micrond
        $this->ipc->bondDelete($id);

        return redirect()->to(base_url('interfaces?tab=bonds'))->with('success', "Bond link {$id} deleted and member physical ports released.");
    }

    /**
     * Configure Wireless LAN interface (hostapd AP or wpa_supplicant station).
     */
    public function saveWireless(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $wirelessModel = new WirelessModel();

        $id              = strtolower(trim((string) $this->request->getPost('id')));
        $iface           = strtolower(trim((string) ($this->request->getPost('interface') ?: $id)));
        $name            = trim((string) $this->request->getPost('name'));
        $mode            = trim((string) ($this->request->getPost('mode') ?: 'ap'));
        $ssid            = trim((string) $this->request->getPost('ssid'));
        $passphrase      = trim((string) $this->request->getPost('passphrase'));
        $security        = trim((string) ($this->request->getPost('security') ?: 'wpa2'));
        $hwMode          = trim((string) ($this->request->getPost('hw_mode') ?: 'g'));
        $channel         = (int) ($this->request->getPost('channel') ?: 6);
        $countryCode     = strtoupper(trim((string) ($this->request->getPost('country_code') ?: 'US')));
        $hiddenSsid      = (int) ($this->request->getPost('hidden_ssid') ? 1 : 0);
        $clientIsolation = (int) ($this->request->getPost('client_isolation') ? 1 : 0);
        $txPower         = (int) ($this->request->getPost('tx_power') ?: 20);
        $enabled         = (int) ($this->request->getPost('enabled') !== '0' ? 1 : 0);

        if ($iface === '') {
            $iface = 'wlan0';
        }
        if ($id === '') {
            $id = $iface;
        }

        if ($ssid === '') {
            return redirect()->to(base_url('interfaces?tab=wireless'))->withInput()->with('error', 'Wireless SSID network name is required.');
        }

        if ($security !== 'open' && strlen($passphrase) < 8) {
            return redirect()->to(base_url('interfaces?tab=wireless'))->withInput()->with('error', 'Wireless security passphrase must be at least 8 characters long.');
        }

        if ($name === '') {
            $name = 'Wi-Fi ' . strtoupper($mode);
        }

        $wData = [
            'id'               => $id,
            'interface'        => $iface,
            'name'             => $name,
            'mode'             => $mode,
            'ssid'             => $ssid,
            'passphrase'       => $passphrase,
            'security'         => $security,
            'hw_mode'          => $hwMode,
            'channel'          => $channel,
            'country_code'     => $countryCode,
            'hidden_ssid'      => $hiddenSsid,
            'client_isolation' => $clientIsolation,
            'tx_power'         => $txPower,
            'enabled'          => $enabled,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        $existing = $wirelessModel->find($id);
        if ($existing) {
            $wirelessModel->update($id, $wData);
        } else {
            $wData['created_at'] = date('Y-m-d H:i:s');
            $wirelessModel->insert($wData);
        }

        // Ensure entry in interfaces table for IP/DHCP/Firewall configuration
        $ifaceRow = $db->table('interfaces')->where('physical_device', $iface)->get()->getRowArray();
        if (! $ifaceRow) {
            $db->table('interfaces')->insert([
                'id'                 => $id,
                'name'               => $name,
                'physical_device'    => $iface,
                'interface_type'     => 'wireless',
                'ip_assignment_type' => 'static',
                'ipv4_address'       => '192.168.50.1',
                'ipv4_netmask'       => 24,
                'enabled'            => $enabled,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        }

        // Apply via micrond
        $this->ipc->wirelessApply($wData);

        return redirect()->to(base_url('interfaces?tab=wireless'))->with('success', "Wireless interface {$iface} ({$mode}) configuration saved and daemon updated.");
    }

    /**
     * Return live status and connected clients for a Wi-Fi interface.
     */
    public function wirelessStatus(string $interface = 'wlan0'): \CodeIgniter\HTTP\ResponseInterface
    {
        $status = $this->ipc->wirelessStatus($interface);
        return $this->response->setJSON($status);
    }
}
