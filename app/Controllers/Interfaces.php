<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\PppoeCompiler;
use App\Models\BondModel;
use App\Models\BridgeModel;
use App\Models\InterfaceModel;
use App\Models\WirelessModel;
use App\Libraries\NetworkHardware;
use App\Libraries\Network\NetworkTransaction;

/**
 * Interfaces Controller — Network Interface configuration, 802.1Q VLAN virtual interfaces,
 * physical NIC assignment, link addressing (IPv4/IPv6/PPPoE), traffic parameters, and telemetry.
 */
class Interfaces extends BaseController
{
    private const ADMIN_ACCESS_OPTIONS = ['http', 'https', 'ping', 'ssh', 'snmp'];

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

        // A confirm-window change the daemon rolled back (timeout, reboot)
        // leaves the database ahead of the OS; bring it back in line first.
        $reconciled = (new NetworkTransaction($this->ipc))->reconcile();
        if ($reconciled !== null) {
            session()->setFlashdata('warning', $reconciled);
        }
        $dbIfaces = $db->table('interfaces')->orderBy('interface_type', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        // Get live runtime state from micrond (or mock on Windows)
        $liveState = $this->ipc->getInterfaces();
        $liveByDev = [];
        $detectedHardware = [];

        // Live runtime state. On an appliance `interface.list` is `ip -j addr`
        // (ifname / operstate / address / addr_info); the development client
        // answers in the page's own shape. Both are normalised here.
        foreach ((array) ($liveState['interfaces'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $dev = (string) ($item['device'] ?? $item['ifname'] ?? $item['name'] ?? '');
            if ($dev === '') {
                continue;
            }
            if (isset($item['ifname'])) {
                $ip = '';
                foreach ((array) ($item['addr_info'] ?? []) as $a) {
                    if (($a['family'] ?? '') === 'inet') {
                        $ip = (string) ($a['local'] ?? '');
                        break;
                    }
                }
                $item = [
                    'device' => $dev,
                    'name'   => $dev,
                    'status' => strtoupper((string) ($item['operstate'] ?? '')) === 'UP'
                        || in_array('LOWER_UP', (array) ($item['flags'] ?? []), true) ? 'up' : 'down',
                    'ip'     => $ip,
                    'mac'    => (string) ($item['address'] ?? ''),
                ];
            }
            $liveByDev[$dev] = $item;
        }

        // The physical ports this machine has (Ethernet + Wi-Fi), with their
        // real kernel names. Nothing is invented when the daemon is silent.
        $hardware = new NetworkHardware($this->ipc);
        foreach ($hardware->nics() ?? [] as $nic) {
            $detectedHardware[$nic['name']] = $nic;
        }
        $wirelessRadios = $hardware->wirelessNames();

        // Live counters, MAC and speed for every kernel device (appliance only;
        // the development client already answers with them).
        $kernelDevices = null;
        if (! $this->ipc->isMock()) {
            $health = $this->ipc->call('system.health')['result'] ?? null;
            if (is_array($health) && is_array($health['interfaces'] ?? null)) {
                $kernelDevices = [];
                foreach ($health['interfaces'] as $k) {
                    $name = (string) ($k['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $kernelDevices[$name] = true;
                    $liveByDev[$name] = array_merge($liveByDev[$name] ?? ['device' => $name, 'name' => $name], [
                        'rx_bytes' => (int) ($k['rx_bytes'] ?? 0),
                        'tx_bytes' => (int) ($k['tx_bytes'] ?? 0),
                        'mac'      => (string) ($k['mac'] ?? ($liveByDev[$name]['mac'] ?? '')),
                        'speed'    => ! empty($k['speed_mbps']) ? $k['speed_mbps'] . ' Mb/s' : '',
                        'status'   => ($k['operstate'] ?? '') === 'up' || (! empty($k['carrier']) && ($k['operstate'] ?? '') === 'unknown') ? 'up' : 'down',
                    ]);
                }
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

            // On an appliance a device the kernel does not have is "not present",
            // never "up" because its row happens to be enabled.
            $present = $kernelDevices === null ? true : isset($kernelDevices[$dev]);
            $isUp    = $live ? ($live['status'] === 'up') : ($present && $kernelDevices === null && (bool) ($iface['enabled'] ?? 1));
            $iface['present'] = $present;
            $iface['is_up']        = $isUp;
            $iface['rx_bytes']     = $live['rx_bytes'] ?? 0;
            $iface['tx_bytes']     = $live['tx_bytes'] ?? 0;
            $iface['rx_human']     = $this->formatBytes((int) $iface['rx_bytes']);
            $iface['tx_human']     = $this->formatBytes((int) $iface['tx_bytes']);
            $iface['current_ip']   = ! empty($live['ip']) ? $live['ip'] : ($iface['ipv4_address'] ?? ($iface['ip_assignment_type'] === 'dhcp' ? 'DHCP Pending' : 'None'));
            $iface['mac']          = ! empty($iface['mac_override']) ? $iface['mac_override'] : (($live['mac'] ?? '') !== '' ? $live['mac'] : '—');
            $iface['speed']        = ! empty($iface['speed_duplex']) && $iface['speed_duplex'] !== 'auto' ? $iface['speed_duplex'] : (($live['speed'] ?? '') !== '' ? $live['speed'] : '—');
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

        // Bridge Interfaces (br0 Layer 2 Switch)
        $bridgeModel = new BridgeModel();
        $bridges = $bridgeModel->getBridgesWithDetails();
        $liveBridgeRes = $this->ipc->bridgeList();
        $liveBridges = $liveBridgeRes['bridges'] ?? [];
        $liveBridgeMap = [];
        foreach ($liveBridges as $lb) {
            $liveBridgeMap[$lb['id'] ?? ''] = $lb;
        }
        foreach ($bridges as &$bridge) {
            $bridge['live'] = $liveBridgeMap[$bridge['id']] ?? null;
            $parentDevices[$bridge['id']] = $bridge['name'] . " ({$bridge['id']} Bridge)";
        }
        unset($bridge);

        // Wireless LAN interfaces (wlan0 AP / Client)
        $wirelessModel = new WirelessModel();
        $wirelessList = $wirelessModel->orderBy('interface', 'ASC')->findAll();
        $wirelessWithStatus = [];
        foreach ($wirelessList as $w) {
            $wStatus = in_array($w['interface'], $wirelessRadios, true)
                ? $this->ipc->wirelessStatus($w['interface'])
                : ['success' => false, 'status' => 'absent', 'message' => 'No wireless adapter named ' . $w['interface'] . ' is installed.'];
            $w['live'] = $wStatus;
            $wirelessWithStatus[] = $w;
        }

        // A VLAN can also ride on any detected port that is not assigned yet.
        foreach (array_keys($detectedHardware) as $hDev) {
            if (! isset($parentDevices[$hDev]) && empty($detectedHardware[$hDev]['wireless'])) {
                $parentDevices[$hDev] = $hDev . ' (unassigned port)';
            }
        }

        $summary = [
            'total'    => count($dbIfaces),
            'up'       => $upCount,
            'down'     => $downCount,
            'physical' => $physicalCount,
            'vlan'     => $vlanCount,
            'bonds'    => count($bonds),
            'bridges'  => count($bridges),
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
            'bridges'          => $bridges,
            'wireless'         => $wirelessWithStatus,
            'wirelessRadios'   => $wirelessRadios,
            'hasWireless'      => $wirelessRadios !== [],
            'hardwareNics'     => $detectedHardware,
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
            'admin_access'         => $this->normaliseAdminAccess(),
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

        $this->audit('interfaces.create', $id, "Created interface '{$name}' ({$physicalDevice}, type: {$interfaceType})");

        return $this->commitNetwork(static function ($db) use ($data) {
            $db->table('interfaces')->insert($data);

            return null;
        }, "Interface '{$name}' created", false);
    }

    public function edit(string $id): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $iface = $db->table('interfaces')->where('id', $id)->get()->getRowArray();

        if (! $iface) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Interface not found.');
        }

        if (empty($iface['admin_access'])) {
            $iface['admin_access'] = json_encode($this->defaultAdminAccess($iface), JSON_THROW_ON_ERROR);
        }

        // Hardware NICs detected
        $hardware = new NetworkHardware($this->ipc);
        $hardwareNics = [];
        foreach ($hardware->nics() ?? [] as $nic) {
            $hardwareNics[$nic['name']] = $nic;
        }

        // Candidate parent devices for VLANs
        $parentDevices = [];
        $dbPhysical = $db->table('interfaces')->where('interface_type', 'physical')->get()->getResultArray();
        foreach ($dbPhysical as $p) {
            $dev = $p['physical_device'];
            $parentDevices[$dev] = $p['name'] . " ({$dev})";
        }
        foreach ((new BondModel())->getBondsWithDetails() as $bond) {
            $parentDevices[$bond['id']] = $bond['name'] . ' (' . $bond['id'] . ' LACP Trunk)';
        }
        foreach ((new BridgeModel())->getBridgesWithDetails() as $bridge) {
            $parentDevices[$bridge['id']] = $bridge['name'] . ' (' . $bridge['id'] . ' Bridge)';
        }
        if (empty($parentDevices)) {
            $parentDevices['eth0'] = 'WAN (eth0)';
            $parentDevices['eth1'] = 'LAN (eth1)';
        }

        // Live telemetry for this interface
        $liveState = $this->ipc->getInterfaces();
        $liveByDev = [];
        foreach ((array) ($liveState['interfaces'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $dev = (string) ($item['device'] ?? $item['ifname'] ?? $item['name'] ?? '');
            if ($dev === '') {
                continue;
            }
            if (isset($item['ifname'])) {
                $ip = '';
                foreach ((array) ($item['addr_info'] ?? []) as $a) {
                    if (($a['family'] ?? '') === 'inet') {
                        $ip = (string) ($a['local'] ?? '');
                        break;
                    }
                }
                $item = [
                    'device' => $dev,
                    'name'   => $dev,
                    'status' => strtoupper((string) ($item['operstate'] ?? '')) === 'UP'
                        || in_array('LOWER_UP', (array) ($item['flags'] ?? []), true) ? 'up' : 'down',
                    'ip'     => $ip,
                    'mac'    => (string) ($item['address'] ?? ''),
                ];
            }
            $liveByDev[$dev] = $item;
        }

        $dev = $iface['physical_device'] ?? '';
        $live = $liveByDev[$dev] ?? null;
        $lldpPeers = $this->lldpPeersByDevice();
        $peer = $lldpPeers[$dev] ?? null;

        $iface['live_status'] = $live['status'] ?? ((bool) ($iface['enabled'] ?? 1) ? 'up' : 'down');
        $iface['live_ip']     = ! empty($live['ip']) ? $live['ip'] : ($iface['ipv4_address'] ?? 'None');
        $iface['live_mac']    = ! empty($iface['mac_override']) ? $iface['mac_override'] : (($live['mac'] ?? '') !== '' ? $live['mac'] : '—');
        $iface['live_speed']  = ! empty($iface['speed_duplex']) && $iface['speed_duplex'] !== 'auto' ? $iface['speed_duplex'] : (($live['speed'] ?? '') !== '' ? $live['speed'] : '—');
        $iface['rx_human']    = $this->formatBytes((int) ($live['rx_bytes'] ?? 0));
        $iface['tx_human']    = $this->formatBytes((int) ($live['tx_bytes'] ?? 0));
        $iface['lldp_peer_label'] = $peer !== null ? $this->lldpPeerLabel($peer) : '';

        $assignedDevicesMap = [];
        foreach ($dbPhysical as $p) {
            $pDev = trim((string) ($p['physical_device'] ?? ''));
            if ($pDev !== '') {
                $assignedDevicesMap[$pDev] = [
                    'id'   => $p['id'],
                    'name' => $p['name'],
                ];
            }
        }
        $availableDevices = array_values(array_filter(
            array_keys($hardwareNics),
            static fn (string $device): bool => ! isset($assignedDevicesMap[$device]) || ($assignedDevicesMap[$device]['id'] === $id)
        ));

        $systemSettings = (new \App\Models\SystemSettingsModel())->getSettings();

        return view('interfaces/edit', [
            'title'              => "Configure Interface: {$iface['name']} — MicronOS",
            'breadcrumb'         => 'Edit Interface',
            'iface'              => $iface,
            'isNew'              => false,
            'parentDevices'      => $parentDevices,
            'hardwareNics'       => $hardwareNics,
            'availableDevices'   => $availableDevices,
            'assignedDevicesMap' => $assignedDevicesMap,
            'allPhysicalDevs'    => array_keys($hardwareNics),
            'systemSettings'     => $systemSettings,
            'isMock'             => $this->ipc->isMock(),
        ]);
    }

    public function create(): string
    {
        $db = \Config\Database::connect();
        $hardwareNics = [];
        foreach ((new NetworkHardware($this->ipc))->nics() ?? [] as $nic) {
            $hardwareNics[$nic['name']] = $nic;
        }

        $assignedDevices = array_column(
            $db->table('interfaces')->where('interface_type', 'physical')->get()->getResultArray(),
            'physical_device'
        );
        $availableDevices = array_values(array_filter(
            array_keys($hardwareNics),
            static fn (string $device): bool => ! in_array($device, $assignedDevices, true)
        ));

        $parentDevices = [];
        foreach ($db->table('interfaces')->where('interface_type', 'physical')->get()->getResultArray() as $physical) {
            $device = (string) ($physical['physical_device'] ?? '');
            if ($device !== '') {
                $parentDevices[$device] = $physical['name'] . " ({$device})";
            }
        }
        foreach ((new BondModel())->getBondsWithDetails() as $bond) {
            $parentDevices[$bond['id']] = $bond['name'] . ' (' . $bond['id'] . ' LACP Trunk)';
        }
        foreach ((new BridgeModel())->getBridgesWithDetails() as $bridge) {
            $parentDevices[$bridge['id']] = $bridge['name'] . ' (' . $bridge['id'] . ' Bridge)';
        }

        $now = date('Y-m-d H:i:s');
        $iface = [
            'id' => '', 'name' => '', 'physical_device' => '', 'enabled' => 1,
            'admin_access' => json_encode(['https', 'ping'], JSON_THROW_ON_ERROR),
            'interface_type' => 'physical', 'vlan_parent' => '', 'vlan_tag' => 100, 'vlan_priority' => 0,
            'ip_assignment_type' => 'dhcp', 'ipv4_address' => '', 'ipv4_netmask' => 24, 'ipv4_gateway' => '',
            'ipv6_assignment_type' => 'disabled', 'ipv6_address' => '', 'ipv6_prefix' => 64,
            'mtu' => 1500, 'mss_clamp' => '', 'mac_override' => '', 'speed_duplex' => 'auto',
            'description' => '', 'pppoe_username' => '', 'pppoe_password' => '', 'pppoe_service' => '',
            'pppoe_ac_name' => '', 'pppoe_mtu' => 1492, 'pppoe_mru' => 1492, 'pppoe_idle_timeout' => 0,
            'pppoe_default_route' => 1, 'created_at' => $now, 'updated_at' => $now,
        ];

        return view('interfaces/edit', [
            'title'            => 'Add Interface — MicronOS',
            'breadcrumb'       => 'Add Interface',
            'iface'            => $iface,
            'isNew'            => true,
            'parentDevices'    => $parentDevices,
            'hardwareNics'     => $hardwareNics,
            'availableDevices' => $availableDevices,
            'allPhysicalDevs'  => array_keys($hardwareNics),
            'systemSettings'   => (new \App\Models\SystemSettingsModel())->getSettings(),
            'isMock'           => $this->ipc->isMock(),
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
            'admin_access'         => $this->normaliseAdminAccess(),
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

        $isVlan = ($iface['interface_type'] ?? '') === 'vlan';
        $physicalDevice = trim((string) $this->request->getPost('physical_device'));
        if ($physicalDevice === '__custom__') {
            $physicalDevice = trim((string) $this->request->getPost('physical_device_custom'));
        }
        if (! $isVlan && $physicalDevice !== '') {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $physicalDevice)) {
                return redirect()->back()->withInput()->with('error', "Invalid physical hardware NIC name '{$physicalDevice}'.");
            }
            $data['physical_device'] = $physicalDevice;
        }

        // Handle VLAN updates if applicable
        if ($isVlan) {
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

        $this->audit('interfaces.update', $id, "Updated interface '{$name}'");

        // Only an address / mode / state change can cut off the browser: those
        // on the interface this session reaches the appliance through are
        // applied with a confirmation window and roll back on their own.
        $addressing = ['enabled', 'ip_assignment_type', 'ipv4_address', 'ipv4_netmask', 'ipv4_gateway', 'mtu'];
        $changesAddressing = false;
        foreach ($addressing as $col) {
            if ((string) ($iface[$col] ?? '') !== (string) ($data[$col] ?? '')) {
                $changesAddressing = true;
            }
        }
        $changesDevice = ! $isVlan && $physicalDevice !== '' && $physicalDevice !== ($iface['physical_device'] ?? '');
        $confirm = ($changesAddressing || $changesDevice) && $this->isManagementInterface($iface);

        return $this->commitNetwork(static function ($db) use ($id, $data, $isVlan, $physicalDevice) {
            $before = $db->table('interfaces')->where('id', $id)->get()->getRowArray();
            $oldDev = (string) ($before['physical_device'] ?? '');

            // Conflict resolution & clean swap for physical devices
            if (! $isVlan && $physicalDevice !== '' && $physicalDevice !== $oldDev) {
                $conflict = $db->table('interfaces')->where('physical_device', $physicalDevice)->where('id !=', $id)->get()->getRowArray();
                if ($conflict) {
                    $db->table('interfaces')->where('id', $conflict['id'])->update([
                        'physical_device' => $oldDev !== '' ? $oldDev : '',
                        'updated_at'      => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $db->table('interfaces')->where('id', $id)->update($data);

            if ($id === 'wan' || $id === 'lan' || (isset($conflict) && in_array($conflict['id'], ['wan', 'lan'], true))) {
                \App\Commands\NetworkCommand::syncNetworkInitJson();
            }

            return ['before' => $before, 'after' => $db->table('interfaces')->where('id', $id)->get()->getRowArray()];
        }, "Interface '{$name}' updated", $confirm, (bool) $this->request->getPost('keep_references'));
    }

    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $iface = $db->table('interfaces')->where('id', $id)->get()->getRowArray();

        if (! $iface) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Interface not found.');
        }

        $newState = ((int) ($iface['enabled'] ?? 1) === 1) ? 0 : 1;
        $statusStr = $newState === 1 ? 'enabled' : 'disabled';
        $this->audit('interfaces.toggle', $id, "Interface '{$iface['name']}' {$statusStr}");

        return $this->commitNetwork(static function ($db) use ($id, $newState) {
            $db->table('interfaces')->where('id', $id)->update([
                'enabled'    => $newState,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return null;
        }, "Interface '{$iface['name']}' {$statusStr}", $newState === 0 && $this->isManagementInterface($iface));
    }

    private function normaliseAdminAccess(): string
    {
        $requested = $this->request->getPost('admin_access');
        if (! is_array($requested)) {
            $requested = [];
        }

        $access = array_values(array_unique(array_intersect(self::ADMIN_ACCESS_OPTIONS, array_map('strval', $requested))));

        return json_encode($access, JSON_THROW_ON_ERROR);
    }

    private function defaultAdminAccess(array $iface): array
    {
        $id = strtolower((string) ($iface['id'] ?? ''));
        $name = strtolower((string) ($iface['name'] ?? ''));
        if (str_starts_with($id, 'lan') || str_starts_with($name, 'lan')) {
            return ['http', 'https', 'ping', 'ssh'];
        }
        if (str_starts_with($id, 'opt') || str_starts_with($name, 'opt')) {
            return ['http', 'https', 'ping'];
        }
        if (str_starts_with($id, 'wan') || str_starts_with($name, 'wan')) {
            return ['ping'];
        }

        return [];
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

        $this->audit('interfaces.delete', $id, "Deleted interface '{$iface['name']}' ({$iface['physical_device']})");

        return $this->commitNetwork(static function ($db) use ($id) {
            $db->table('interfaces')->where('id', $id)->delete();

            return null;
        }, "Interface '{$iface['name']}' removed", $this->isManagementInterface($iface));
    }

    public function apply(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->audit('interfaces.apply', 'network', 'Re-applied the committed network configuration');

        return $this->commitNetwork(null, 'Network configuration re-applied', false);
    }

    /**
     * Confirm a pending (confirm-window) network change from the new address.
     */
    public function confirmNetwork(): \CodeIgniter\HTTP\RedirectResponse
    {
        $r = (new NetworkTransaction($this->ipc))->confirm((string) $this->request->getPost('tx'));
        $this->audit('interfaces.confirm', 'network', $r['message']);

        return redirect()->to(base_url('interfaces'))->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    /** Roll a pending network change back now. */
    public function rollbackNetwork(): \CodeIgniter\HTTP\RedirectResponse
    {
        $r = (new NetworkTransaction($this->ipc))->rollback();
        $this->audit('interfaces.rollback', 'network', $r['message']);

        return redirect()->to(base_url('interfaces'))->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    /** JSON: committed plan vs kernel, pending change, last result (for the page and the CLI). */
    public function networkStatus(): \CodeIgniter\HTTP\ResponseInterface
    {
        $tx = new NetworkTransaction($this->ipc);
        $tx->reconcile();
        $st = $this->ipc->call('network.status')['result'] ?? null;

        return $this->response->setJSON([
            'ok'      => is_array($st),
            'plan'    => (new \App\Libraries\Network\NetworkPlan())->build(),
            'status'  => $st,
        ]);
    }

    /**
     * Apply a network change through the one shared transaction (database +
     * dependent settings + plan + firewall + DHCP → micrond → verify → commit
     * or roll everything back) and report exactly what happened.
     *
     * @param callable|null $mutate see NetworkTransaction::apply()
     */
    private function commitNetwork(?callable $mutate, string $what, bool $confirm, bool $keepReferences = false): \CodeIgniter\HTTP\RedirectResponse
    {
        $r = (new NetworkTransaction($this->ipc))->apply($mutate, [
            'confirm'           => $confirm,
            'update_references' => ! $keepReferences,
            'actor'             => (string) (session('username') ?? 'admin'),
            'reason'            => $what,
        ]);

        $detail = [
            'status'   => $r['status'],
            'tx'       => $r['tx'] ?? null,
            'updated'  => $r['updated'],
            'review'   => $r['review'],
            'warnings' => $r['warnings'],
            'errors'   => $r['errors'],
        ];
        if (! $r['ok']) {
            // Nothing was committed: the dependent updates were rolled back too.
            $detail['updated'] = [];
            $detail['review']  = [];
        }
        $redirect = redirect()->to(base_url('interfaces'))->with('net_result', $detail);
        if (! $r['ok']) {
            return $redirect->with('error', $what . ' — not applied. ' . $r['message']);
        }
        if (($r['status'] ?? '') === 'pending_confirm') {
            return $redirect->with('warning', $what . '. ' . $r['message']);
        }

        // DHCP/PPP-assigned resolvers on the links feed the appliance's own
        // stub resolver (DNS Server Override); refresh it after a change.
        $dns = '';
        try {
            $dns = $this->reconcileApplianceResolver($this->ipc);
        } catch (\Throwable) {
        }

        return $redirect->with('success', $what . ' and applied. ' . $r['message'] . $dns);
    }

    /**
     * Whether this browser session reaches the appliance through $iface: its
     * current address is the one the request arrived on.
     */
    private function isManagementInterface(array $iface): bool
    {
        $addr = trim((string) ($iface['ipv4_address'] ?? ''));
        if ($addr === '') {
            return false;
        }
        $server = (string) ($this->request->getServer('SERVER_ADDR') ?? '');
        $host   = strtolower((string) parse_url('http://' . ($this->request->getServer('HTTP_HOST') ?? ''), PHP_URL_HOST));

        return $server === $addr || $host === $addr;
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
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', 'Bond identifier must follow bond0, bond1 naming format.');
        }

        if ($name === '') {
            $name = strtoupper($id) . ' Trunk';
        }

        // Filter and sanitize member slave interfaces
        $slaves = array_values(array_filter(array_map('trim', $slaves), static fn ($s) => $s !== ''));
        if (empty($slaves)) {
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', 'At least one member network interface must be selected for the bond aggregation.');
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

        return redirect()->to(base_url('interfaces'))->with('success', "Bond interface {$id} ({$mode}) successfully created and applied to Micron OS kernel.");
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
            return redirect()->to(base_url('interfaces'))->with('error', "Bond '{$id}' does not exist.");
        }

        $bondModel->delete($id);

        // Remove from interfaces table if it was created as bond type
        $db->table('interfaces')->where('physical_device', $id)->where('interface_type', 'bond')->delete();

        // Release slaves and delete link via micrond
        $this->ipc->bondDelete($id);

        return redirect()->to(base_url('interfaces'))->with('success', "Bond link {$id} deleted and member physical ports released.");
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

        // Wi-Fi can only be configured on a radio this machine actually has.
        $radios = (new NetworkHardware($this->ipc))->wirelessNames();
        if ($radios === []) {
            return redirect()->to(base_url('interfaces'))->with('error', 'No wireless network adapter is installed on this appliance, so Wi-Fi cannot be configured.');
        }
        if ($iface === '') {
            $iface = $radios[0];
        }
        if (! in_array($iface, $radios, true)) {
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', "'{$iface}' is not a wireless adapter on this appliance. Installed radios: " . implode(', ', $radios) . '.');
        }
        if ($id === '') {
            $id = $iface;
        }

        if ($ssid === '') {
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', 'Wireless SSID network name is required.');
        }

        // The edit dialog never receives the stored passphrase; blank keeps it.
        if ($passphrase === '') {
            $passphrase = (string) (($wirelessModel->find($id) ?? [])['passphrase'] ?? '');
        }

        if ($security !== 'open' && strlen($passphrase) < 8) {
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', 'Wireless security passphrase must be at least 8 characters long.');
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

        return redirect()->to(base_url('interfaces'))->with('success', "Wireless interface {$iface} ({$mode}) configuration saved and daemon updated.");
    }

    /**
     * Return live status and connected clients for a Wi-Fi interface.
     */
    public function wirelessStatus(string $interface = ''): \CodeIgniter\HTTP\ResponseInterface
    {
        if (! in_array($interface, (new NetworkHardware($this->ipc))->wirelessNames(), true)) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'status'  => 'absent',
                'message' => 'No wireless adapter with that name is installed on this appliance.',
            ]);
        }
        $status = $this->ipc->wirelessStatus($interface);
        return $this->response->setJSON($status);
    }

    /**
     * Create or update a Layer 2 network bridge (e.g. br0).
     */
    public function storeBridge(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $bridgeModel = new BridgeModel();

        $id           = strtolower(trim((string) $this->request->getPost('id')));
        $name         = trim((string) $this->request->getPost('name'));
        $slaves       = (array) $this->request->getPost('slaves');
        $stp          = (int) ($this->request->getPost('stp') ? 1 : 0);
        $forwardDelay = (int) ($this->request->getPost('forward_delay') ?: 2);
        $mtu          = (int) ($this->request->getPost('mtu') ?: 1500);
        $description  = trim((string) $this->request->getPost('description'));

        if (! preg_match('/^[a-zA-Z0-9_-]{2,15}$/', $id)) {
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', 'Bridge identifier must be alphanumeric (e.g. br0, br-lan).');
        }

        if ($name === '') {
            $name = strtoupper($id) . ' Bridge';
        }

        $slaves = array_values(array_filter(array_map('trim', $slaves), static fn ($s) => $s !== ''));
        if (empty($slaves)) {
            return redirect()->to(base_url('interfaces'))->withInput()->with('error', 'At least one member port must be selected for the bridge.');
        }

        $bridgeData = [
            'id'            => $id,
            'name'          => $name,
            'slaves'        => json_encode($slaves),
            'stp'           => $stp,
            'forward_delay' => $forwardDelay,
            'mtu'           => $mtu > 0 ? $mtu : 1500,
            'description'   => $description,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        $existing = $bridgeModel->find($id);
        if ($existing) {
            $bridgeModel->update($id, $bridgeData);
        } else {
            $bridgeData['created_at'] = date('Y-m-d H:i:s');
            $bridgeModel->insert($bridgeData);
        }

        // Ensure entry in interfaces table for IP assignment and firewall rules
        $ifaceRow = $db->table('interfaces')->where('physical_device', $id)->get()->getRowArray();
        if (! $ifaceRow) {
            $db->table('interfaces')->insert([
                'id'                 => $id,
                'name'               => $name,
                'physical_device'    => $id,
                'interface_type'     => 'bridge',
                'ip_assignment_type' => 'static',
                'ipv4_address'       => '192.168.1.1',
                'ipv4_netmask'       => 24,
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

        // Apply via micrond
        $ipcData = [
            'id'            => $id,
            'slaves'        => $slaves,
            'stp'           => $stp,
            'forward_delay' => $forwardDelay,
            'mtu'           => $mtu,
        ];
        $this->ipc->bridgeApply($ipcData);

        return redirect()->to(base_url('interfaces'))->with('success', "Bridge interface {$id} successfully created and applied to Micron OS kernel.");
    }

    /**
     * Delete a Layer 2 network bridge and release member ports.
     */
    public function deleteBridge(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $bridgeModel = new BridgeModel();

        $bridge = $bridgeModel->find($id);
        if (! $bridge) {
            return redirect()->to(base_url('interfaces'))->with('error', "Bridge '{$id}' does not exist.");
        }

        $bridgeModel->delete($id);

        // Remove from interfaces table if it was created as bridge type
        $db->table('interfaces')->where('physical_device', $id)->where('interface_type', 'bridge')->delete();

        // Release member ports and delete in kernel via micrond
        $this->ipc->bridgeDelete($id);

        return redirect()->to(base_url('interfaces'))->with('success', "Bridge interface {$id} deleted and member ports released.");
    }

    /**
     * Reassign physical network devices to WAN, LAN and other interfaces.
     */
    public function assign(): \CodeIgniter\HTTP\RedirectResponse
    {
        $wanDev = trim((string) $this->request->getPost('wan_device'));
        $lanDev = trim((string) $this->request->getPost('lan_device'));
        $optInput = (array) ($this->request->getPost('opt') ?? []);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $wanDev)
            || ($lanDev !== '' && strtolower($lanDev) !== 'none' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $lanDev))) {
            return redirect()->to(base_url('interfaces'))->with('error', 'Valid physical network devices must be selected for WAN and LAN.');
        }

        $lanDev = strtolower($lanDev) === 'none' ? '' : $lanDev;
        if ($lanDev !== '' && $lanDev === $wanDev) {
            return redirect()->to(base_url('interfaces'))->with('error', 'WAN and LAN must use different physical network devices.');
        }

        $optDevices = [];
        foreach ($optInput as $optId => $dev) {
            $dev = trim((string) $dev);
            if ($dev !== '') {
                if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $dev)) {
                    return redirect()->to(base_url('interfaces'))->with('error', "Invalid device name '{$dev}' for interface {$optId}.");
                }
                if ($dev === $wanDev || $dev === $lanDev || in_array($dev, $optDevices, true)) {
                    return redirect()->to(base_url('interfaces'))->with('error', "Device '{$dev}' is assigned to multiple interfaces.");
                }
                $optDevices[$optId] = $dev;
            }
        }

        $now = date('Y-m-d H:i:s');
        $actor = 'web:' . (session()->get('username') ?: 'admin');

        $result = (new NetworkTransaction($this->ipc))->apply(static function ($db) use ($wanDev, $lanDev, $optDevices, $now) {
            $wanBefore = $db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
            $lanBefore = $db->table('interfaces')->where('id', 'lan')->get()->getRowArray();

            if (! $wanBefore || ! $lanBefore) {
                throw new \RuntimeException('WAN and LAN interface rows must exist in database.');
            }

            // 1. Clear physical_device across ALL interfaces that currently hold wanDev, lanDev,
            // or any of the target opt devices, preventing any duplicate-device collision during assignment or swap.
            $claimed = array_unique(array_filter(array_merge([$wanDev, $lanDev], array_values($optDevices))));
            $db->table('interfaces')
                ->where('interface_type', 'physical')
                ->whereIn('physical_device', $claimed)
                ->update([
                    'physical_device' => '',
                    'updated_at'      => $now,
                ]);

            // 2. Set WAN physical device while preserving addressing
            $db->table('interfaces')->where('id', 'wan')->update([
                'physical_device' => $wanDev,
                'enabled'         => 1,
                'updated_at'      => $now,
            ]);

            // 3. Set LAN physical device while preserving addressing
            if ($lanDev === '') {
                $db->table('interfaces')->where('id', 'lan')->update([
                    'physical_device' => null,
                    'enabled'         => 0,
                    'updated_at'      => $now,
                ]);
            } else {
                $db->table('interfaces')->where('id', 'lan')->update([
                    'physical_device' => $lanDev,
                    'enabled'         => 1,
                    'updated_at'      => $now,
                ]);
            }

            // 4. Set OPT physical devices if provided
            foreach ($optDevices as $optId => $dev) {
                $db->table('interfaces')->where('id', $optId)->update([
                    'physical_device' => $dev,
                    'updated_at'      => $now,
                ]);
            }

            \App\Commands\NetworkCommand::syncNetworkInitJson($wanDev, $lanDev !== '' ? $lanDev : null);

            return ['before' => $lanBefore, 'after' => $db->table('interfaces')->where('id', 'lan')->get()->getRowArray()];
        }, [
            'actor' => $actor,
            'reason' => "WebUI assign WAN={$wanDev} LAN=" . ($lanDev !== '' ? $lanDev : 'none'),
            'confirm' => false,
        ]);

        if (! empty($result['ok'])) {
            $this->audit('interfaces.assign', 'wan,lan', "Reassigned physical interfaces: WAN -> {$wanDev}, LAN -> " . ($lanDev ?: 'none'));

            return redirect()->to(base_url('interfaces'))->with('success', "Interfaces assigned successfully: WAN &rarr; {$wanDev}, LAN &rarr; " . ($lanDev ?: 'none'));
        }

        return redirect()->to(base_url('interfaces'))->with('error', $result['message'] ?? 'Could not apply interface assignment.');
    }
}
