<?php

namespace App\Controllers;

use App\Libraries\IpcClient;

/**
 * Routing Controller
 *
 * Handles: Static routes, FIB inspection, BGP (FRRouting), OSPF,
 *          BFD, VRF, PBR, and Multi-WAN gateway / group management.
 */
class Routing extends BaseController
{
    private IpcClient $ipc;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
    }

    // ================================================================
    // INDEX — Static Routes + FIB + all dynamic routing data (tabbed)
    // ================================================================

    public function index()
    {
        if ($this->request->getGet('tab') === 'static') {
            return redirect()->to(base_url('routing/static'));
        }

        $db = \Config\Database::connect();

        // Read the live kernel FIB through the daemon. Only development mock
        // mode may substitute simulated rows when the daemon has no response.
        $routeResponse = $this->ipc->call('diag.routing_table');
        $routeResult   = $routeResponse['result'] ?? null;
        if (is_array($routeResult) && isset($routeResult['routes']) && is_array($routeResult['routes'])) {
            $rawKernelRoutes = $routeResult['routes'];
        } elseif ($this->ipc->isMock()) {
            $rawKernelRoutes = [
                ['destination' => '0.0.0.0/0', 'gateway' => '192.168.1.1', 'interface' => 'eth0 (WAN)', 'metric' => 100, 'protocol' => 'dhcp', 'flags' => 'UG'],
                ['destination' => '10.8.0.0/24', 'gateway' => '10.8.0.2', 'interface' => 'tun0 (VPN)', 'metric' => 50, 'protocol' => 'static', 'flags' => 'UG'],
                ['destination' => '10.100.0.0/24', 'gateway' => 'direct', 'interface' => 'wg0 (WG)', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
                ['destination' => '192.168.1.0/24', 'gateway' => 'direct', 'interface' => 'eth1 (LAN)', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
                ['destination' => '192.168.20.0/24', 'gateway' => 'direct', 'interface' => 'eth2 (DMZ)', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
                ['destination' => '127.0.0.0/8', 'gateway' => 'direct', 'interface' => 'lo', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
            ];
        } else {
            $rawKernelRoutes = [];
        }
        $kernelRoutes = array_values(array_map(static fn (array $route): array => [
            'destination' => (string) ($route['destination'] ?? $route['dst'] ?? '—'),
            'gateway'     => (string) ($route['gateway'] ?? 'direct'),
            'interface'   => (string) ($route['interface'] ?? $route['dev'] ?? '—'),
            'flags'       => (string) ($route['flags'] ?? ''),
            'metric'      => (int) ($route['metric'] ?? 0),
            'proto'       => strtolower((string) ($route['proto'] ?? $route['protocol'] ?? 'unknown')),
        ], array_filter($rawKernelRoutes, 'is_array')));

        $staticRoutes   = $db->table('static_routes')->orderBy('destination', 'ASC')->get()->getResultArray();
        $interfaces     = $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray();
        $bgpNeighbors   = $db->table('frr_bgp_neighbors')->orderBy('neighbor_ip', 'ASC')->get()->getResultArray();
        $bgpSettings    = $db->table('frr_bgp_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $ospfSettings   = $db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $ospfAreas      = $db->table('frr_ospf_areas')->orderBy('area_id', 'ASC')->get()->getResultArray();
        $ospfIfaces     = $db->table('frr_ospf_ifaces')->orderBy('interface', 'ASC')->get()->getResultArray();
        $ospfNetworks   = $db->table('frr_ospf_networks')->orderBy('area', 'ASC')->orderBy('network', 'ASC')->get()->getResultArray();
        $ospfNeighbors  = $db->table('frr_ospf_neighbors')->orderBy('neighbor_ip', 'ASC')->get()->getResultArray();
        $prefixLists    = $db->table('frr_prefix_lists')->orderBy('name', 'ASC')->get()->getResultArray();
        $routeMaps      = $db->table('frr_route_maps')->orderBy('name', 'ASC')->get()->getResultArray();
        $accessLists    = $db->table('frr_access_lists')->orderBy('name', 'ASC')->get()->getResultArray();

        // Where each prefix-list / route-map / access-list is referenced, so the Policy tab can
        // show usage and refuse to delete something still in use.
        $policyRefs     = $this->buildPolicyReferences();
        $prefixListRefs = $policyRefs['prefixLists'];
        $routeMapRefs   = $policyRefs['routeMaps'];
        $accessListRefs = $policyRefs['accessLists'] ?? [];
        $ospfDistributeLists = $db->table('frr_ospf_distribute_lists')->orderBy('prefix_list', 'ASC')->get()->getResultArray();
        $ospfAreaFilters     = $db->table('frr_ospf_area_filters')->orderBy('area_id', 'ASC')->get()->getResultArray();
        $ospfPolicyPreview   = self::compileOspfPolicy($prefixLists, $routeMaps, $ospfDistributeLists, $ospfAreaFilters, $accessLists);
        $ospfStaleRefs       = self::staleOspfReferences($prefixLists, $routeMaps, $ospfDistributeLists, $ospfAreaFilters, $ospfSettings);
        $ripSettings         = $db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $ripNetworks         = $db->table('frr_rip_networks')->orderBy('network', 'ASC')->get()->getResultArray();
        $ripNeighbors        = $db->table('frr_rip_neighbors')->orderBy('neighbor_ip', 'ASC')->get()->getResultArray();
        $ripPassiveIfaces    = $db->table('frr_rip_passive_interfaces')->orderBy('interface', 'ASC')->get()->getResultArray();
        $isisSettings        = $db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $isisInterfaces      = $db->table('frr_isis_interfaces')->orderBy('interface', 'ASC')->get()->getResultArray();
        $bfdPeers       = $db->table('frr_bfd_peers')->orderBy('peer_ip', 'ASC')->get()->getResultArray();
        $vrfs           = $db->table('routing_vrfs')->orderBy('name', 'ASC')->get()->getResultArray();
        $pbrRules       = $db->table('routing_pbr_rules')->orderBy('priority', 'ASC')->get()->getResultArray();

        // The address objects a PBR rule can match on are no longer loaded here:
        // the pickers ask `routing/objects/search` for one page at a time, so
        // this screen does not pull every object into the response.

        $wanGateways    = $db->table('wan_gateways')->orderBy('priority', 'ASC')->get()->getResultArray();
        $wanGroups      = $db->table('wan_gateway_groups')->orderBy('name', 'ASC')->get()->getResultArray();

        // Attach gateway latency telemetry
        $isMock = $this->ipc->isMock();
        foreach ($wanGateways as &$gw) {
            if ($isMock) {
                $isPri = str_contains($gw['name'], 'Fiber') || ($gw['priority'] ?? 0) === 1;
                $gw['telemetry'] = [
                    'rtt'     => $isPri ? 11.4 : 28.6,
                    'stddev'  => $isPri ? 1.2 : 3.4,
                    'loss'    => 0.0,
                    'status'  => 'online',
                ];
            } else {
                $gw['telemetry'] = [
                    'rtt'     => null,
                    'stddev'  => null,
                    'loss'    => null,
                    'status'  => 'unknown',
                ];
            }
        }
        unset($gw);

        // Live OSPF status from micrond IPC (with Linux vtysh fallback)
        $ospfStatus        = $this->getOspfStatus();
        $ospfLiveNeighbors = $this->extractLiveNeighbors($ospfStatus, $isMock);

        $frrStatus = $this->getFrrStatus();
        $frrSvc = $db->table('system_services')->where('id', 'frr')->get()->getRowArray();
        $frrBootEnabled = !empty($frrSvc['enabled_at_boot']);

        $vpnInterfaces = \App\Libraries\VpnInterfaceProvider::getVpnInterfaces($db);
        $allInterfaces = \App\Libraries\VpnInterfaceProvider::getUnifiedInterfaces($db);
        $frrConf       = (new \App\Libraries\FrrCompiler($db))->compile();

        return view('routing/index', [
            'title'             => 'Dynamic Routing & Multi-WAN — Micron OS',
            'breadcrumb'        => 'Routing',
            'kernelRoutes'      => $kernelRoutes,
            'staticRoutes'      => $staticRoutes,
            'interfaces'        => $interfaces,
            'vpnInterfaces'     => $vpnInterfaces,
            'allInterfaces'     => $allInterfaces,
            'frrConf'           => $frrConf,
            'bgpNeighbors'      => $bgpNeighbors,
            'bgpSettings'       => $bgpSettings,
            'ospfSettings'      => $ospfSettings,
            'ospfAreas'         => $ospfAreas,
            'ospfIfaces'        => $ospfIfaces,
            'ospfNetworks'      => $ospfNetworks,
            'ospfNeighbors'     => $ospfNeighbors,
            'ospfLiveNeighbors' => $ospfLiveNeighbors,
            'ospfStatus'        => $ospfStatus,
            'prefixLists'       => $prefixLists,
            'routeMaps'         => $routeMaps,
            'accessLists'       => $accessLists,
            'prefixListRefs'    => $prefixListRefs,
            'routeMapRefs'      => $routeMapRefs,
            'accessListRefs'    => $accessListRefs,
            'ospfDistributeLists' => $ospfDistributeLists,
            'ospfAreaFilters'     => $ospfAreaFilters,
            'ospfPolicyPreview'   => $ospfPolicyPreview,
            'ospfStaleRefs'       => $ospfStaleRefs,
            'ripSettings'       => $ripSettings,
            'ripNetworks'       => $ripNetworks,
            'ripNeighbors'      => $ripNeighbors,
            'ripPassiveIfaces'  => $ripPassiveIfaces,
            'isisSettings'      => $isisSettings,
            'isisInterfaces'    => $isisInterfaces,
            'bfdPeers'          => $bfdPeers,
            'vrfs'              => $vrfs,
            'pbrRules'          => $pbrRules,
            'wanGateways'       => $wanGateways,
            'wanGroups'         => $wanGroups,
            'frrStatus'         => $frrStatus,
            'frrBootEnabled'    => $frrBootEnabled,
            'isMock'            => $this->ipc->isMock(),
        ]);
    }

    // ================================================================
    // STATIC ROUTES (Dedicated Page & Operations)
    // ================================================================

    public function staticPage(): string
    {
        $db = \Config\Database::connect();
        $staticRoutes  = $db->table('static_routes')->orderBy('destination', 'ASC')->get()->getResultArray();
        $interfaces    = $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray();
        $vrfs          = $db->table('routing_vrfs')->orderBy('name', 'ASC')->get()->getResultArray();
        $allInterfaces = \App\Libraries\VpnInterfaceProvider::getUnifiedInterfaces($db);

        return view('routing/static', [
            'title'         => 'Static Routes — micronOS',
            'staticRoutes'  => $staticRoutes,
            'interfaces'    => $interfaces,
            'allInterfaces' => $allInterfaces,
            'vrfs'          => $vrfs,
        ]);
    }

    /**
     * Check reachability of static route gateways (online/offline, RTT latency).
     *
     * Probes all unique gateway IPs found in static_routes or a single requested IP.
     * Non-blocking JSON endpoint called automatically by /routing/static view.
     */
    public function checkStaticGateways(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $targetGw = trim((string) ($this->request->getGetPost('gw') ?? ''));

        if ($targetGw !== '') {
            $gateways = [$targetGw];
        } else {
            $routes = $db->table('static_routes')
                ->select('gateway, type')
                ->where('type', 'gateway')
                ->get()
                ->getResultArray();

            $gateways = [];
            foreach ($routes as $r) {
                $gw = trim((string) ($r['gateway'] ?? ''));
                if ($gw !== '' && filter_var($gw, FILTER_VALIDATE_IP)) {
                    $gateways[] = $gw;
                }
            }
            $gateways = array_values(array_unique($gateways));
        }

        $results = [];
        foreach ($gateways as $gw) {
            $results[$gw] = $this->probeGatewayHost($gw);
        }

        return $this->response->setJSON([
            'success'    => true,
            'timestamp'  => date('Y-m-d H:i:s'),
            'time_human' => date('H:i:s'),
            'count'      => count($results),
            'gateways'   => $results,
        ]);
    }

    /**
     * Probes a single gateway host via IPC diag.ping or ICMP echo / ARP table.
     */
    private function probeGatewayHost(string $ip): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return [
                'status'  => 'unknown',
                'online'  => false,
                'rtt'     => null,
                'message' => 'Invalid IP address',
            ];
        }

        // 1. If running on appliance with active IPC daemon, attempt diag.ping
        if (! $this->ipc->isMock()) {
            try {
                $response = $this->ipc->call('diag.ping', [
                    'host'  => $ip,
                    'count' => 1,
                    'wait'  => 1,
                ]);
                $result = $response['result'] ?? null;
                if (is_array($result) && isset($result['output'])) {
                    $output = (string) $result['output'];
                    $code   = (int) ($result['returncode'] ?? 0);
                    $online = ($code === 0) || str_contains($output, '1 received') || str_contains($output, 'bytes from');
                    $rtt    = $this->extractPingRtt($output);

                    return [
                        'status'  => $online ? 'online' : 'offline',
                        'online'  => $online,
                        'rtt'     => $rtt,
                        'message' => $online ? ($rtt ? "Online ({$rtt})" : 'Online') : 'Unreachable',
                    ];
                }
            } catch (\Throwable $e) {
                // Fall back to direct system execution
            }
        }

        // 2. Direct fast probe via ICMP ping
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $cmd = $isWindows
            ? 'ping -n 1 -w 400 ' . escapeshellarg($ip)
            : 'ping -c 1 -W 1 ' . escapeshellarg($ip);

        $output = [];
        $returnCode = 1;
        @exec($cmd, $output, $returnCode);
        $outputStr = implode("\n", $output);

        $online = false;
        $rtt = null;

        if ($returnCode === 0) {
            if ($isWindows) {
                if (str_contains($outputStr, 'Reply from ' . $ip) || str_contains($outputStr, 'bytes=')) {
                    $online = true;
                }
            } else {
                if (str_contains($outputStr, '1 received') || str_contains($outputStr, 'bytes from ' . $ip)) {
                    $online = true;
                }
            }
            if ($online) {
                $rtt = $this->extractPingRtt($outputStr);
            }
        }

        // 3. If ping timed out, check ARP cache as fallback (gateway might drop ICMP but be online at L2)
        if (! $online) {
            $online = $this->checkArpReachability($ip);
            if ($online) {
                $rtt = '< 1 ms (ARP)';
            }
        }

        return [
            'status'  => $online ? 'online' : 'offline',
            'online'  => $online,
            'rtt'     => $rtt,
            'message' => $online ? ($rtt ? "Online ({$rtt})" : 'Online') : 'Unreachable / Timeout',
        ];
    }

    private function extractPingRtt(string $text): ?string
    {
        // Windows: time=12ms or time<1ms
        if (preg_match('/time[=<]([0-9.]+\s*m?s)/i', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/Average\s*=\s*([0-9]+)\s*ms/i', $text, $m)) {
            return $m[1] . ' ms';
        }
        // Linux: time=0.456 ms or min/avg/max = .../1.234/...
        if (preg_match('/rtt min\/avg\/max\/mdev\s*=\s*[0-9.]+\/([0-9.]+)/i', $text, $m)) {
            return round((float) $m[1], 1) . ' ms';
        }
        if (preg_match('/time=([0-9.]+)\s*ms/i', $text, $m)) {
            return round((float) $m[1], 1) . ' ms';
        }

        return null;
    }

    private function checkArpReachability(string $ip): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $arpOut = @shell_exec('arp -a ' . escapeshellarg($ip));
            if ($arpOut && str_contains($arpOut, $ip)) {
                if (preg_match('/([0-9a-f]{2}[:-]){5}[0-9a-f]{2}/i', $arpOut)) {
                    return true;
                }
            }
        } elseif (file_exists('/proc/net/arp')) {
            $lines = @file('/proc/net/arp') ?: [];
            foreach (array_slice($lines, 1) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (isset($parts[0], $parts[3]) && $parts[0] === $ip) {
                    $mac = strtolower($parts[3]);
                    if ($mac !== '00:00:00:00:00:00' && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function policyPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'policy';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    public function storeStatic(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $dest = trim($this->request->getPost('destination') ?? '');
        $type = trim($this->request->getPost('type') ?? 'gateway');
        $gw   = trim($this->request->getPost('gateway') ?? '');
        $iface  = trim($this->request->getPost('interface') ?? '');
        $metric = (int)($this->request->getPost('metric') ?: 10);
        $tableId= ($this->request->getPost('table_id') !== null && $this->request->getPost('table_id') !== '') ? (int)$this->request->getPost('table_id') : null;
        $desc   = trim($this->request->getPost('description') ?? '');

        if (empty($dest)) {
            return redirect()->to(base_url('routing/static'))->with('error', 'Destination network CIDR is required.');
        }

        if ($type === 'gateway' && empty($gw)) {
            return redirect()->to(base_url('routing/static'))->with('error', 'Gateway IP is required for gateway routes.');
        }
        if ($type === 'interface' && empty($iface)) {
            return redirect()->to(base_url('routing/static'))->with('error', 'Interface is required for directly connected routes.');
        }
        if (in_array($type, ['blackhole', 'reject'], true)) {
            $gw = $type;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('static_routes')->insert([
            'id'          => 'rt_' . bin2hex(random_bytes(6)),
            'destination' => $dest,
            'type'        => $type,
            'gateway'     => $gw,
            'interface'   => $iface ?: '',
            'metric'      => $metric,
            'table_id'    => $tableId,
            'enabled'     => 1,
            'description' => $desc,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('network.route.add', ['destination' => $dest, 'gateway' => $gw, 'interface' => $iface, 'type' => $type]);
        return redirect()->to(base_url('routing/static'))->with('success', "Static route to {$dest} added.");
    }

    public function updateStatic(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $route = $db->table('static_routes')->where('id', $id)->get()->getRowArray();
        if (! $route) {
            return redirect()->to(base_url('routing/static'))->with('error', 'Route not found.');
        }

        $type = trim($this->request->getPost('type') ?: ($route['type'] ?? 'gateway'));
        $gw   = trim($this->request->getPost('gateway') ?: $route['gateway']);
        $iface= trim($this->request->getPost('interface') ?? ($route['interface'] ?? ''));
        if (in_array($type, ['blackhole', 'reject'], true)) {
            $gw = $type;
        }

        $db->table('static_routes')->where('id', $id)->update([
            'destination' => trim($this->request->getPost('destination') ?: $route['destination']),
            'type'        => $type,
            'gateway'     => $gw,
            'interface'   => $iface ?: '',
            'metric'      => (int)($this->request->getPost('metric') ?: 10),
            'table_id'    => ($this->request->getPost('table_id') !== null && $this->request->getPost('table_id') !== '') ? (int)$this->request->getPost('table_id') : null,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => $this->request->getPost('enabled') !== null ? (int)$this->request->getPost('enabled') : 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url('routing/static'))->with('success', 'Static route updated.');
    }

    public function toggleStatic(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $route = $db->table('static_routes')->where('id', $id)->get()->getRowArray();
        if ($route) {
            $new = ((int)$route['enabled'] === 1) ? 0 : 1;
            $db->table('static_routes')->where('id', $id)->update(['enabled' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            return redirect()->to(base_url('routing/static'))->with('success', 'Static route ' . ($new ? 'enabled' : 'disabled') . '.');
        }
        return redirect()->to(base_url('routing/static'))->with('error', 'Route not found.');
    }

    public function deleteStatic(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('static_routes')->where('id', $id)->delete();
        return redirect()->to(base_url('routing/static'))->with('success', 'Static route removed.');
    }

    // ================================================================
    // BGP Neighbors
    // ================================================================

    public function storeBgp(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $ip   = trim($this->request->getPost('neighbor_ip') ?? '');
        $as   = (int)($this->request->getPost('remote_as') ?? 0);

        if (empty($ip) || $as === 0) {
            return redirect()->back()->with('error', 'Neighbor IP and Remote AS are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_bgp_neighbors')->insert([
            'id'               => 'bgp_' . bin2hex(random_bytes(6)),
            'neighbor_ip'      => $ip,
            'remote_as'        => $as,
            'local_as'         => ($this->request->getPost('local_as') ?: null),
            'description'      => trim($this->request->getPost('description') ?? ''),
            'password'         => trim($this->request->getPost('password') ?? '') ?: null,
            'update_source'    => trim($this->request->getPost('update_source') ?? '') ?: null,
            'next_hop_self'    => (int)(bool)$this->request->getPost('next_hop_self'),
            'soft_reconfigure' => 1,
            'route_map_in'     => trim($this->request->getPost('route_map_in') ?? '') ?: null,
            'route_map_out'    => trim($this->request->getPost('route_map_out') ?? '') ?: null,
            'prefix_list_in'   => trim($this->request->getPost('prefix_list_in') ?? '') ?: null,
            'prefix_list_out'  => trim($this->request->getPost('prefix_list_out') ?? '') ?: null,
            'filter_list_in'   => trim($this->request->getPost('filter_list_in') ?? '') ?: null,
            'filter_list_out'  => trim($this->request->getPost('filter_list_out') ?? '') ?: null,
            'bfd_enabled'      => (int)(bool)$this->request->getPost('bfd_enabled'),
            'enabled'          => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('bgp'), "BGP neighbor {$ip} (AS{$as}) added.", 'Saved, but the BGP apply');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=neighbors')->with($key, $message);
    }

    public function updateBgp(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_bgp_neighbors')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'BGP neighbor not found.');
        }

        $db->table('frr_bgp_neighbors')->where('id', $id)->update([
            'neighbor_ip'     => trim($this->request->getPost('neighbor_ip') ?: $row['neighbor_ip']),
            'remote_as'       => (int)($this->request->getPost('remote_as') ?: $row['remote_as']),
            'description'     => trim($this->request->getPost('description') ?? ''),
            'password'        => trim($this->request->getPost('password') ?? '') ?: null,
            'update_source'   => trim($this->request->getPost('update_source') ?? '') ?: null,
            'next_hop_self'   => (int)(bool)$this->request->getPost('next_hop_self'),
            'route_map_in'    => trim($this->request->getPost('route_map_in') ?? '') ?: null,
            'route_map_out'   => trim($this->request->getPost('route_map_out') ?? '') ?: null,
            'prefix_list_in'  => trim($this->request->getPost('prefix_list_in') ?? '') ?: null,
            'prefix_list_out' => trim($this->request->getPost('prefix_list_out') ?? '') ?: null,
            'filter_list_in'  => trim($this->request->getPost('filter_list_in') ?? '') ?: null,
            'filter_list_out' => trim($this->request->getPost('filter_list_out') ?? '') ?: null,
            'bfd_enabled'     => (int)(bool)$this->request->getPost('bfd_enabled'),
            'enabled'         => (int)(bool)$this->request->getPost('enabled'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('bgp'), 'BGP neighbor updated.', 'Saved, but the BGP apply');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=neighbors')->with($key, $message);
    }

    public function deleteBgp(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_bgp_neighbors')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('bgp'), 'BGP neighbor removed.', 'Saved, but the BGP apply');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=neighbors')->with($key, $message);
    }

    // ================================================================
    // OSPF Networks
    // ================================================================

    public function storeOspf(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $net = trim($this->request->getPost('network') ?? '');
        if (empty($net)) {
            return redirect()->back()->with('error', 'Network CIDR is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_networks')->insert([
            'id'          => 'ospf_' . bin2hex(random_bytes(6)),
            'network'     => $net,
            'area'        => trim($this->request->getPost('area') ?: '0.0.0.0'),
            'area_type'   => $this->request->getPost('area_type') ?: 'normal',
            'cost'        => ($this->request->getPost('cost') ?: null),
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "OSPF network {$net} added.", 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf')->with($key, $message);
    }

    public function deleteOspf(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_networks')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF network removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=networks')->with($key, $message);
    }

    // ================================================================
    // OSPF Global Settings
    // ================================================================

    public function saveOspfSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $data = [
            'enabled'                 => (int)(bool)$this->request->getPost('enabled'),
            'router_id'               => trim($this->request->getPost('router_id') ?? '') ?: null,
            'log_adj_changes'         => (int)(bool)$this->request->getPost('log_adj_changes'),
            'spf_hold_time'           => (int)($this->request->getPost('spf_hold_time') ?: 1000),
            'spf_delay'               => (int)($this->request->getPost('spf_delay') ?: 200),
            'spf_min_hold'            => ($this->request->getPost('spf_min_hold') ?: null),
            'spf_max_hold'            => ($this->request->getPost('spf_max_hold') ?: null),
            'snmp_agentx'             => (int)(bool)$this->request->getPost('snmp_agentx'),
            'default_area'            => trim($this->request->getPost('default_area') ?: '0.0.0.0'),
            'passive_default'         => (int)(bool)$this->request->getPost('passive_default'),
            'redistribute_connected'  => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'     => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_bgp'        => (int)(bool)$this->request->getPost('redistribute_bgp'),
            'redistribute_rip'        => (int)(bool)$this->request->getPost('redistribute_rip'),
            'redistribute_connected_rm'=> $this->knownRouteMap($this->request->getPost('redistribute_connected_rm')),
            'redistribute_static_rm'  => $this->knownRouteMap($this->request->getPost('redistribute_static_rm')),
            'redistribute_bgp_rm'     => $this->knownRouteMap($this->request->getPost('redistribute_bgp_rm')),
            'redistribute_rip_rm'     => $this->knownRouteMap($this->request->getPost('redistribute_rip_rm')),
            'redistribute_metric'      => ($this->request->getPost('redistribute_metric') ?: null),
            'redistribute_metric_type' => (int)($this->request->getPost('redistribute_metric_type') ?: 2),
            'reference_bandwidth'      => ($this->request->getPost('reference_bandwidth') !== null && trim((string)$this->request->getPost('reference_bandwidth')) !== '') ? (int)$this->request->getPost('reference_bandwidth') : 100,
            'distance_external'        => ($this->request->getPost('distance_external') ?: null),
            'distance_inter'           => ($this->request->getPost('distance_inter') ?: null),
            'distance_intra'           => ($this->request->getPost('distance_intra') ?: null),
            'max_paths'                => ($this->request->getPost('max_paths') !== null && trim((string)$this->request->getPost('max_paths')) !== '') ? (int)$this->request->getPost('max_paths') : 4,
            'updated_at'               => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_ospf_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_ospf_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_ospf_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF general settings saved.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=general')->with($key, $message);
    }

    // ================================================================
    // OSPF Areas
    // ================================================================

    public function storeOspfArea(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $areaId = trim($this->request->getPost('area_id') ?? '');
        if (empty($areaId)) {
            return redirect()->back()->with('error', 'Area ID is required.');
        }

        $ranges = $this->buildSummaryRanges();
        $now    = date('Y-m-d H:i:s');
        $db->table('frr_ospf_areas')->insert([
            'id'           => 'oa_' . bin2hex(random_bytes(6)),
            'area_id'      => $areaId,
            'description'  => trim($this->request->getPost('description') ?? ''),
            'area_type'    => $this->request->getPost('area_type') ?: 'normal',
            'default_cost' => ($this->request->getPost('default_cost') ?: null),
            'abr_shortcut' => $this->request->getPost('abr_shortcut') ?: 'default',
            'auth_type'    => $this->request->getPost('auth_type') ?: 'none',
            'no_summary'   => (int)(bool)$this->request->getPost('no_summary'),
            'summary_ranges'=> json_encode($ranges),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "OSPF area {$areaId} added.", 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=areas')->with($key, $message);
    }

    public function updateOspfArea(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_areas')->where('id', $id)->get()->getRowArray();
        if (!$row) {
            return redirect()->back()->with('error', 'Area not found.');
        }

        $db->table('frr_ospf_areas')->where('id', $id)->update([
            'area_id'       => trim($this->request->getPost('area_id') ?: $row['area_id']),
            'description'   => trim($this->request->getPost('description') ?? ''),
            'area_type'     => $this->request->getPost('area_type') ?: 'normal',
            'default_cost'  => ($this->request->getPost('default_cost') ?: null),
            'abr_shortcut'  => $this->request->getPost('abr_shortcut') ?: 'default',
            'auth_type'     => $this->request->getPost('auth_type') ?: 'none',
            'no_summary'    => (int)(bool)$this->request->getPost('no_summary'),
            'summary_ranges'=> json_encode($this->buildSummaryRanges()),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF area updated.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=areas')->with($key, $message);
    }

    public function deleteOspfArea(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_areas')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF area removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=areas')->with($key, $message);
    }

    /** Build summary ranges array from POST sr_prefix[], sr_no_adv[], sr_cost[], sr_substitute[] */
    private function buildSummaryRanges(): array
    {
        $prefixes    = (array)($this->request->getPost('sr_prefix') ?? []);
        $noAdv       = (array)($this->request->getPost('sr_no_adv') ?? []);
        $costs       = (array)($this->request->getPost('sr_cost') ?? []);
        $substitutes = (array)($this->request->getPost('sr_substitute') ?? []);
        $ranges = [];
        foreach ($prefixes as $i => $prefix) {
            if (!empty(trim($prefix))) {
                $ranges[] = [
                    'prefix'      => trim($prefix),
                    'not_advertise'=> !empty($noAdv[$i]),
                    'cost'        => $costs[$i] ?: null,
                    'substitute'  => trim($substitutes[$i] ?? '') ?: null,
                ];
            }
        }
        return $ranges;
    }

    // ================================================================
    // OSPF Interface Configuration
    // ================================================================

    public function storeOspfIface(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $iface = trim($this->request->getPost('interface') ?? '');
        if ($iface === '__custom__' || empty($iface)) {
            $iface = trim($this->request->getPost('interface_custom') ?? '');
        }
        if (empty($iface)) {
            return redirect()->back()->with('error', 'Interface is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_ifaces')->insert([
            'id'                  => 'oif_' . bin2hex(random_bytes(6)),
            'interface'           => $iface,
            'description'         => trim($this->request->getPost('description') ?? ''),
            'network_type'        => $this->request->getPost('network_type') ?: 'broadcast',
            'area'                => trim($this->request->getPost('area') ?? '') ?: null,
            'metric'              => ($this->request->getPost('metric') ?: null),
            'passive'             => (int)(bool)$this->request->getPost('passive'),
            'ignore_mtu'          => (int)(bool)$this->request->getPost('ignore_mtu'),
            'accept_filter'       => (int)(bool)$this->request->getPost('accept_filter'),
            'auth_type'           => $this->request->getPost('auth_type') ?: 'none',
            'auth_password'       => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'router_priority'     => ($this->request->getPost('router_priority') !== null && trim((string)$this->request->getPost('router_priority')) !== '') ? (int)$this->request->getPost('router_priority') : 1,
            'retransmit_interval' => (int)($this->request->getPost('retransmit_interval') ?: 5),
            'hello_interval'      => (int)($this->request->getPost('hello_interval') ?: 10),
            'dead_interval'       => (int)($this->request->getPost('dead_interval') ?: 40),
            'minimal_hello'       => ($this->request->getPost('minimal_hello') ?: null),
            'transmit_delay'      => (int)($this->request->getPost('transmit_delay') ?: 1),
            'bfd_enabled'         => (int)(bool)$this->request->getPost('bfd_enabled'),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "OSPF interface {$iface} configured.", 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=interfaces')->with($key, $message);
    }

    public function updateOspfIface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_ifaces')->where('id', $id)->get()->getRowArray();
        if (!$row) {
            return redirect()->back()->with('error', 'Interface not found.');
        }

        $iface = trim($this->request->getPost('interface') ?: $row['interface']);
        if ($iface === '__custom__') {
            $iface = trim($this->request->getPost('interface_custom') ?: $row['interface']);
        }

        $db->table('frr_ospf_ifaces')->where('id', $id)->update([
            'interface'           => $iface,
            'description'         => trim($this->request->getPost('description') ?? ''),
            'network_type'        => $this->request->getPost('network_type') ?: 'broadcast',
            'area'                => trim($this->request->getPost('area') ?? '') ?: null,
            'metric'              => ($this->request->getPost('metric') ?: null),
            'passive'             => (int)(bool)$this->request->getPost('passive'),
            'ignore_mtu'          => (int)(bool)$this->request->getPost('ignore_mtu'),
            'accept_filter'       => (int)(bool)$this->request->getPost('accept_filter'),
            'auth_type'           => $this->request->getPost('auth_type') ?: 'none',
            'auth_password'       => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'router_priority'     => ($this->request->getPost('router_priority') !== null && trim((string)$this->request->getPost('router_priority')) !== '') ? (int)$this->request->getPost('router_priority') : 1,
            'retransmit_interval' => (int)($this->request->getPost('retransmit_interval') ?: 5),
            'hello_interval'      => (int)($this->request->getPost('hello_interval') ?: 10),
            'dead_interval'       => (int)($this->request->getPost('dead_interval') ?: 40),
            'minimal_hello'       => ($this->request->getPost('minimal_hello') ?: null),
            'transmit_delay'      => (int)($this->request->getPost('transmit_delay') ?: 1),
            'bfd_enabled'         => (int)(bool)$this->request->getPost('bfd_enabled'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF interface updated.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=interfaces')->with($key, $message);
    }

    public function deleteOspfIface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_ifaces')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF interface config removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=interfaces')->with($key, $message);
    }

    // ================================================================
    // OSPF Manual Neighbors
    // ================================================================

    public function storeOspfNeighbor(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ip = trim($this->request->getPost('neighbor_ip') ?? '');
        if (empty($ip)) {
            return redirect()->back()->with('error', 'Neighbor IP is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_neighbors')->insert([
            'id'           => 'on_' . bin2hex(random_bytes(6)),
            'neighbor_ip'  => $ip,
            'area'         => trim($this->request->getPost('area') ?? '') ?: null,
            'priority'     => ($this->request->getPost('priority') ?: null),
            'poll_interval'=> ($this->request->getPost('poll_interval') ?: null),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'enabled'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "OSPF neighbor {$ip} added.", 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=neighbors')->with($key, $message);
    }

    public function deleteOspfNeighbor(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_neighbors')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF neighbor removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=neighbors')->with($key, $message);
    }

    // ================================================================
    // OSPF Policy — Prefix Lists
    // ================================================================

    /**
     * Redirects back to the OSPF Policy sub-tab with an error flash.
     *
     * Policy forms are opened from the Policy sub-tab; redirect()->back() would
     * follow the referer and could land the user on a different OSPF sub-tab,
     * hiding the message.
     */
    private function policyError(string $message): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('error', $message);
    }

    public function storePrefixList(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Prefix-list name is required and may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildPrefixListRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        if ($db->table('frr_prefix_lists')->where('name', $name)->countAllResults()) {
            return $this->policyError("A prefix-list named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_prefix_lists')->insert([
            'id'          => 'pl_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "Prefix-list '{$name}' created.", 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function updatePrefixList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_prefix_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Prefix-list not found.');
        }

        $name = trim($this->request->getPost('name') ?: $row['name']);
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Prefix-list name may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildPrefixListRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        $dupe = $db->table('frr_prefix_lists')->where('name', $name)->where('id !=', $id)->countAllResults();
        if ($dupe) {
            return $this->policyError("A prefix-list named '{$name}' already exists.");
        }

        $db->table('frr_prefix_lists')->where('id', $id)->update([
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'Prefix-list updated.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function deletePrefixList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_prefix_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Prefix-list not found.');
        }

        $uses = $this->policyUsage($row['name'], 'prefix');
        if ($uses !== []) {
            return $this->policyError(
                "Prefix-list '{$row['name']}' is still in use and cannot be deleted: " . implode('; ', $uses) . '. Remove those references first.'
            );
        }

        $db->table('frr_prefix_lists')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'Prefix-list removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    /**
     * Builds the ordered rule array for a prefix-list from the modal arrays.
     *
     * @return array<int,array<string,mixed>>|string The rules, or an error message.
     */
    private function buildPrefixListRules()
    {
        $seqs    = (array)($this->request->getPost('pl_seq') ?? []);
        $actions = (array)($this->request->getPost('pl_action') ?? []);
        $prefixes= (array)($this->request->getPost('pl_prefix') ?? []);
        $ges     = (array)($this->request->getPost('pl_ge') ?? []);
        $les     = (array)($this->request->getPost('pl_le') ?? []);
        $descs   = (array)($this->request->getPost('pl_desc') ?? []);

        $rules = [];
        foreach ($prefixes as $i => $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix === '') {
                continue;
            }
            if (! \App\Libraries\Cidr::isValid($prefix)) {
                return "'{$prefix}' is not a valid prefix (use address/prefix-length, e.g. 192.168.1.0/24).";
            }
            $ge = (string) ($ges[$i] ?? '');
            $le = (string) ($les[$i] ?? '');
            $rules[] = [
                'seq'         => (int) ($seqs[$i] ?? 0) ?: ($i + 1) * 10,
                'action'      => (($actions[$i] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                'prefix'      => $prefix,
                'ge'          => ($ge !== '' && ctype_digit($ge)) ? (int) $ge : null,
                'le'          => ($le !== '' && ctype_digit($le)) ? (int) $le : null,
                'description' => trim((string) ($descs[$i] ?? '')),
            ];
        }

        if ($rules === []) {
            return 'Add at least one rule with a prefix.';
        }

        usort($rules, static fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return $rules;
    }

    // ================================================================
    // OSPF Policy — Route Maps
    // ================================================================

    public function storeRouteMap(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Route-map name is required and may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildRouteMapRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        if ($db->table('frr_route_maps')->where('name', $name)->countAllResults()) {
            return $this->policyError("A route-map named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_route_maps')->insert([
            'id'          => 'rm_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "Route-map '{$name}' created.", 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function updateRouteMap(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_route_maps')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Route-map not found.');
        }

        $name = trim($this->request->getPost('name') ?: $row['name']);
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Route-map name may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildRouteMapRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        $dupe = $db->table('frr_route_maps')->where('name', $name)->where('id !=', $id)->countAllResults();
        if ($dupe) {
            return $this->policyError("A route-map named '{$name}' already exists.");
        }

        $db->table('frr_route_maps')->where('id', $id)->update([
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'Route-map updated.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function deleteRouteMap(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_route_maps')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Route-map not found.');
        }

        $uses = $this->policyUsage($row['name'], 'map');
        if ($uses !== []) {
            return $this->policyError(
                "Route-map '{$row['name']}' is still in use and cannot be deleted: " . implode('; ', $uses) . '. Remove those references first.'
            );
        }

        $db->table('frr_route_maps')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'Route-map removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    /**
     * Builds the ordered rule array for a route-map from the modal arrays.
     *
     * @return array<int,array<string,mixed>>|string The rules, or an error message.
     */
    private function buildRouteMapRules()
    {
        $seqs    = (array)($this->request->getPost('rm_seq') ?? []);
        $actions = (array)($this->request->getPost('rm_action') ?? []);
        $matches = (array)($this->request->getPost('rm_match_pl') ?? []);
        $acls    = (array)($this->request->getPost('rm_match_acl') ?? []);
        $metrics = (array)($this->request->getPost('rm_set_metric') ?? []);
        $types   = (array)($this->request->getPost('rm_set_metric_type') ?? []);
        $tags    = (array)($this->request->getPost('rm_set_tag') ?? []);
        $descs   = (array)($this->request->getPost('rm_desc') ?? []);

        $rules = [];
        foreach ($seqs as $i => $seq) {
            $match = trim((string) ($matches[$i] ?? ''));
            $acl   = trim((string) ($acls[$i] ?? ''));
            $metric = (string) ($metrics[$i] ?? '');
            $type   = (string) ($types[$i] ?? '');
            $tag    = (string) ($tags[$i] ?? '');

            // A rule that neither matches nor sets anything is a no-op; skip it.
            if ($match === '' && $acl === '' && $metric === '' && $type === '' && $tag === '') {
                continue;
            }

            $rules[] = [
                'seq'              => (int) $seq ?: ($i + 1) * 10,
                'action'           => (($actions[$i] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                'match_prefix_list'=> $match ?: null,
                'match_access_list'=> $acl ?: null,
                'set_metric'       => ($metric !== '' && ctype_digit($metric)) ? (int) $metric : null,
                'set_metric_type'  => in_array($type, ['type-1', 'type-2'], true) ? $type : null,
                'set_tag'          => ($tag !== '' && ctype_digit($tag)) ? (int) $tag : null,
                'description'      => trim((string) ($descs[$i] ?? '')),
            ];
        }

        if ($rules === []) {
            return 'Add at least one route-map rule with a match or set action.';
        }

        usort($rules, static fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return $rules;
    }

    /**
     * Returns a route-map name only when it exists, otherwise null.
     *
     * Prevents a redistribution from referencing a route-map that was deleted
     * (or mistyped), which FRR would reject at load time.
     */
    private function knownRouteMap(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $exists = \Config\Database::connect()->table('frr_route_maps')
            ->where('name', $name)->countAllResults();

        return $exists ? $name : null;
    }

    // ================================================================
    // Policy — Access Lists
    // ================================================================

    public function storeAccessList(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Access-list name is required and may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildAccessListRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        if ($db->table('frr_access_lists')->where('name', $name)->countAllResults()) {
            return $this->policyError("An access-list named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_access_lists')->insert([
            'id'          => 'acl_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'type'        => trim($this->request->getPost('type') ?? 'standard'),
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), "Access-list '{$name}' created.", 'Saved, but daemon apply');
        $tab = $this->request->getPost('return_tab') === 'ospf' ? 'ospf&sub=policy' : 'policy&sub=acls';
        return redirect()->to(base_url('routing') . '?tab=' . $tab)->with($key, $message);
    }

    public function updateAccessList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_access_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Access-list not found.');
        }

        $name = trim($this->request->getPost('name') ?: $row['name']);
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Access-list name may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildAccessListRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        $dupe = $db->table('frr_access_lists')->where('name', $name)->where('id !=', $id)->countAllResults();
        if ($dupe) {
            return $this->policyError("An access-list named '{$name}' already exists.");
        }

        $db->table('frr_access_lists')->where('id', $id)->update([
            'name'        => $name,
            'type'        => trim($this->request->getPost('type') ?: ($row['type'] ?? 'standard')),
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'Access-list updated.', 'Saved, but daemon apply');
        $tab = $this->request->getPost('return_tab') === 'ospf' ? 'ospf&sub=policy' : 'policy&sub=acls';
        return redirect()->to(base_url('routing') . '?tab=' . $tab)->with($key, $message);
    }

    public function deleteAccessList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_access_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Access-list not found.');
        }

        $uses = $this->policyUsage($row['name'], 'acl');
        if ($uses !== []) {
            return $this->policyError(
                "Access-list '{$row['name']}' is still in use and cannot be deleted: " . implode('; ', $uses) . '. Remove those references first.'
            );
        }

        $db->table('frr_access_lists')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'Access-list removed.', 'Saved, but daemon apply');
        $tab = $this->request->getGet('return_tab') === 'ospf' ? 'ospf&sub=policy' : 'policy&sub=acls';
        return redirect()->to(base_url('routing') . '?tab=' . $tab)->with($key, $message);
    }

    private function buildAccessListRules()
    {
        $seqs     = (array)($this->request->getPost('acl_seq') ?? []);
        $actions  = (array)($this->request->getPost('acl_action') ?? []);
        $prefixes = (array)($this->request->getPost('acl_prefix') ?? []);
        $descs    = (array)($this->request->getPost('acl_desc') ?? []);

        $rules = [];
        foreach ($prefixes as $i => $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix === '') {
                continue;
            }
            if ($prefix !== 'any' && ! \App\Libraries\Cidr::isValid($prefix)) {
                return "'{$prefix}' is not a valid prefix or 'any' (use address/prefix-length, e.g. 192.168.1.0/24).";
            }
            $rules[] = [
                'seq'         => (int) ($seqs[$i] ?? 0) ?: ($i + 1) * 10,
                'action'      => (($actions[$i] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                'prefix'      => $prefix,
                'description' => trim((string) ($descs[$i] ?? '')),
            ];
        }

        if ($rules === []) {
            return 'Add at least one rule with a prefix.';
        }

        usort($rules, static fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return $rules;
    }

    // ================================================================
    // OSPF Policy — Distribute Lists (process-level)
    // ================================================================

    public function storeDistributeList(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $fields = $this->distributeListFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_distribute_lists')->insert($fields + [
            'id'         => 'odl_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF distribute-list added.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function updateDistributeList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_distribute_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Distribute-list entry not found.');
        }

        $fields = $this->distributeListFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $db->table('frr_ospf_distribute_lists')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF distribute-list updated.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function deleteDistributeList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_distribute_lists')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF distribute-list removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    /**
     * Validates and normalises a distribute-list form submission.
     *
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function distributeListFields()
    {
        $prefixList = trim($this->request->getPost('prefix_list') ?? '');
        if (! $this->knownPrefixList($prefixList)) {
            return 'Select an existing prefix-list.';
        }

        $direction = strtolower(trim($this->request->getPost('direction') ?? 'in'));
        if (! in_array($direction, ['in', 'out'], true)) {
            return 'Direction must be in or out.';
        }

        // FRR only permits an interface on the inbound form; the outbound form
        // has no interface argument, so drop any stray value rather than store
        // a configuration the daemon cannot render.
        $interface = trim($this->request->getPost('interface') ?? '') ?: null;
        if ($direction === 'out') {
            $interface = null;
        }

        return [
            'prefix_list' => $prefixList,
            'direction'   => $direction,
            'interface'   => $interface,
            'description' => trim($this->request->getPost('description') ?? ''),
        ];
    }

    // ================================================================
    // OSPF Policy — Area Filter Lists (ABR inter-area filtering)
    // ================================================================

    public function storeAreaFilter(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $fields = $this->areaFilterFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_area_filters')->insert($fields + [
            'id'         => 'oaf_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF area filter-list added.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function updateAreaFilter(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_area_filters')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Area filter-list entry not found.');
        }

        $fields = $this->areaFilterFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $db->table('frr_ospf_area_filters')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF area filter-list updated.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    public function deleteAreaFilter(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_area_filters')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('ospf'), 'OSPF area filter-list removed.', 'Saved, but the OSPF apply');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with($key, $message);
    }

    /**
     * Validates and normalises an area filter-list form submission.
     *
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function areaFilterFields()
    {
        $area = trim($this->request->getPost('area_id') ?? '');
        if ($area === '' || ! preg_match('/^\d+(\.\d+){0,3}$/', $area)) {
            return 'Enter a valid area ID (dotted-decimal like 0.0.0.1, or an integer).';
        }

        $prefixList = trim($this->request->getPost('prefix_list') ?? '');
        if (! $this->knownPrefixList($prefixList)) {
            return 'Select an existing prefix-list.';
        }

        $direction = strtolower(trim($this->request->getPost('direction') ?? 'in'));
        if (! in_array($direction, ['in', 'out'], true)) {
            return 'Direction must be in or out.';
        }

        return [
            'area_id'     => $area,
            'prefix_list' => $prefixList,
            'direction'   => $direction,
            'description' => trim($this->request->getPost('description') ?? ''),
        ];
    }

    /**
     * True when a prefix-list with the given name exists.
     *
     * Distribute-lists and area filter-lists reference a prefix-list by name;
     * storing one that does not exist would make ospfd reject the config.
     */
    private function knownPrefixList(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return (bool) \Config\Database::connect()->table('frr_prefix_lists')
            ->where('name', $name)->countAllResults();
    }

    /**
     * Indexes every place a prefix-list or route-map name is referenced.
     *
     * Used by the OSPF Policy tab to display usage and by the delete actions to
     * refuse removing something still in use (FRR would otherwise reject, or
     * silently drop policy, on the next config render).
     *
     * @return array{prefixLists: array<string,list<string>>, routeMaps: array<string,list<string>>}
     */
    private function buildPolicyReferences(): array
    {
        $db          = \Config\Database::connect();
        $prefixes    = [];
        $maps        = [];
        $accessLists = [];

        $add = static function (array &$bucket, ?string $name, string $label): void {
            $name = trim((string) $name);
            if ($name !== '') {
                $bucket[$name][] = $label;
            }
        };

        // Prefix-lists and Access-lists matched inside route-map rules.
        foreach ($db->table('frr_route_maps')->get()->getResultArray() as $rm) {
            foreach (json_decode($rm['rules'] ?? '[]', true) ?: [] as $rule) {
                $add($prefixes, $rule['match_prefix_list'] ?? '', 'Route-map ' . $rm['name']);
                $add($accessLists, $rule['match_access_list'] ?? '', 'Route-map ' . $rm['name']);
            }
        }

        // Prefix-lists referenced by OSPF distribute-lists and area filter-lists.
        foreach ($db->table('frr_ospf_distribute_lists')->get()->getResultArray() as $dl) {
            $scope = $dl['direction'] === 'out' ? 'outbound' : 'inbound';
            if (! empty($dl['interface'])) {
                $scope .= ' on ' . $dl['interface'];
            }
            $add($prefixes, $dl['prefix_list'] ?? '', 'OSPF distribute-list ' . $scope);
        }
        foreach ($db->table('frr_ospf_area_filters')->get()->getResultArray() as $af) {
            $add($prefixes, $af['prefix_list'] ?? '', 'OSPF area ' . $af['area_id'] . ' filter-list ' . ($af['direction'] === 'out' ? 'out' : 'in'));
        }

        // Route-maps, prefix-lists, and filter-lists applied to BGP neighbors.
        foreach ($db->table('frr_bgp_neighbors')->get()->getResultArray() as $nb) {
            $add($maps, $nb['route_map_in'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (route-map in)');
            $add($maps, $nb['route_map_out'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (route-map out)');
            $add($prefixes, $nb['prefix_list_in'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (prefix-list in)');
            $add($prefixes, $nb['prefix_list_out'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (prefix-list out)');
            $add($accessLists, $nb['filter_list_in'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (filter-list in)');
            $add($accessLists, $nb['filter_list_out'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (filter-list out)');
        }

        // Route-maps attached to redistribution, per protocol.
        $redistColumns = [
            'frr_bgp_settings' => [
                'redistribute_connected_rm' => 'BGP redistribute connected',
                'redistribute_static_rm'    => 'BGP redistribute static',
                'redistribute_ospf_rm'      => 'BGP redistribute ospf',
            ],
            'frr_ospf_settings' => [
                'redistribute_connected_rm' => 'OSPF redistribute connected',
                'redistribute_static_rm'    => 'OSPF redistribute static',
                'redistribute_bgp_rm'       => 'OSPF redistribute bgp',
                'redistribute_rip_rm'       => 'OSPF redistribute rip',
            ],
            'frr_rip_settings' => [
                'redistribute_connected_rm' => 'RIP redistribute connected',
                'redistribute_static_rm'    => 'RIP redistribute static',
                'redistribute_bgp_rm'       => 'RIP redistribute bgp',
                'redistribute_ospf_rm'      => 'RIP redistribute ospf',
            ],
            'frr_isis_settings' => [
                'redistribute_connected_rm' => 'IS-IS redistribute connected',
                'redistribute_static_rm'    => 'IS-IS redistribute static',
                'redistribute_bgp_rm'       => 'IS-IS redistribute bgp',
                'redistribute_ospf_rm'      => 'IS-IS redistribute ospf',
            ],
        ];
        foreach ($redistColumns as $table => $columns) {
            $row = $db->table($table)->where('id', 'default')->get()->getRowArray();
            if (! $row) {
                continue;
            }
            foreach ($columns as $column => $label) {
                $add($maps, $row[$column] ?? '', $label);
            }
        }

        // RIP default-information originates a default route via a route-map.
        $rip = $db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        if ($rip) {
            $add($maps, $rip['default_information_rm'] ?? '', 'RIP default-information');
        }

        return ['prefixLists' => $prefixes, 'routeMaps' => $maps, 'accessLists' => $accessLists];
    }

    /**
     * Human-readable list of the places a prefix-list / route-map / access-list is referenced.
     *
     * @return list<string>
     */
    private function policyUsage(string $name, string $kind): array
    {
        $refs = $this->buildPolicyReferences();
        $key  = match($kind) {
            'map' => 'routeMaps',
            'acl' => 'accessLists',
            default => 'prefixLists',
        };

        return array_values(array_unique($refs[$key][$name] ?? []));
    }

    /**
     * Compiles policy objects into the FRR configuration block they produce.
     *
     * Prefix-lists, access-lists, and route-maps are global objects; distribute-lists
     * and area filter-lists render inside the `router ospf` block. Disabled objects are
     * omitted and summarised in a trailing comment.
     *
     * @param array<int,array<string,mixed>> $prefixLists
     * @param array<int,array<string,mixed>> $routeMaps
     * @param array<int,array<string,mixed>> $distributeLists
     * @param array<int,array<string,mixed>> $areaFilters
     * @param array<int,array<string,mixed>> $accessLists
     */
    public static function compileOspfPolicy(array $prefixLists, array $routeMaps, array $distributeLists, array $areaFilters, array $accessLists = []): string
    {
        // Address family of each prefix-list drives the route-map match keyword.
        $family = [];
        foreach ($prefixLists as $pl) {
            $rules = json_decode($pl['rules'] ?? '[]', true) ?: [];
            $allV6 = $rules !== [];
            foreach ($rules as $rule) {
                if (\App\Libraries\Cidr::family((string) ($rule['prefix'] ?? '')) !== 'ipv6') {
                    $allV6 = false;
                    break;
                }
            }
            $family[$pl['name']] = $allV6 ? 'ipv6' : 'ip';
        }

        // Names of prefix-lists and access-lists that actually exist
        $plExists  = array_flip(array_column($prefixLists, 'name'));
        $aclExists = array_flip(array_column($accessLists, 'name'));

        $lines   = [];
        $skipped = [];

        // ── Access lists ──────────────────────────────────────────────
        $lines[] = '! ── Access lists ──────────────────────────────';
        if ($accessLists === []) {
            $lines[] = '! (none)';
        }
        foreach ($accessLists as $acl) {
            if (empty($acl['enabled'])) {
                $skipped[] = 'access-list ' . $acl['name'];
                continue;
            }
            if (! empty($acl['description'])) {
                $lines[] = '! ' . $acl['name'] . ' — ' . $acl['description'];
            }
            $rules = json_decode($acl['rules'] ?? '[]', true) ?: [];
            if ($rules === []) {
                $lines[] = '! ' . $acl['name'] . ' (no rules)';
                continue;
            }
            foreach ($rules as $rule) {
                $prefix = (string) ($rule['prefix'] ?? '');
                if ($prefix === '') {
                    continue;
                }
                $isV6 = \App\Libraries\Cidr::family($prefix) === 'ipv6';
                $line = sprintf(
                    '%s access-list %s seq %d %s %s',
                    $isV6 ? 'ipv6' : 'ip',
                    $acl['name'],
                    (int) ($rule['seq'] ?? 0),
                    (($rule['action'] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                    $prefix
                );
                $lines[] = $line;
            }
        }

        // ── Prefix lists ──────────────────────────────────────────────
        $lines[] = '!';
        $lines[] = '! ── Prefix lists ──────────────────────────────';
        if ($prefixLists === []) {
            $lines[] = '! (none)';
        }
        foreach ($prefixLists as $pl) {
            if (empty($pl['enabled'])) {
                $skipped[] = 'prefix-list ' . $pl['name'];
                continue;
            }
            if (! empty($pl['description'])) {
                $lines[] = '! ' . $pl['name'] . ' — ' . $pl['description'];
            }
            $rules = json_decode($pl['rules'] ?? '[]', true) ?: [];
            if ($rules === []) {
                $lines[] = '! ' . $pl['name'] . ' (no rules)';
                continue;
            }
            foreach ($rules as $rule) {
                $prefix = (string) ($rule['prefix'] ?? '');
                if ($prefix === '') {
                    continue;
                }
                $keyword = \App\Libraries\Cidr::family($prefix) === 'ipv6' ? 'ipv6' : 'ip';
                $line    = sprintf(
                    '%s prefix-list %s seq %d %s %s',
                    $keyword,
                    $pl['name'],
                    (int) ($rule['seq'] ?? 0),
                    (($rule['action'] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                    $prefix
                );
                if (! empty($rule['ge'])) {
                    $line .= ' ge ' . (int) $rule['ge'];
                }
                if (! empty($rule['le'])) {
                    $line .= ' le ' . (int) $rule['le'];
                }
                $lines[] = $line;
            }
        }

        // ── Route maps ────────────────────────────────────────────────
        $lines[] = '!';
        $lines[] = '! ── Route maps ────────────────────────────────';
        if ($routeMaps === []) {
            $lines[] = '! (none)';
        }
        foreach ($routeMaps as $rm) {
            if (empty($rm['enabled'])) {
                $skipped[] = 'route-map ' . $rm['name'];
                continue;
            }
            if (! empty($rm['description'])) {
                $lines[] = '! ' . $rm['name'] . ' — ' . $rm['description'];
            }
            $rules = json_decode($rm['rules'] ?? '[]', true) ?: [];
            if ($rules === []) {
                $lines[] = '! ' . $rm['name'] . ' (no rules)';
                continue;
            }
            foreach ($rules as $rule) {
                $lines[] = sprintf(
                    'route-map %s %s %d',
                    $rm['name'],
                    (($rule['action'] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                    (int) ($rule['seq'] ?? 0)
                );
                if (! empty($rule['match_prefix_list'])) {
                    $plName  = (string) $rule['match_prefix_list'];
                    $lines[] = sprintf(' match %s address prefix-list %s', $family[$plName] ?? 'ip', $plName);
                    if (! isset($plExists[$plName])) {
                        $lines[] = '! WARNING: prefix-list ' . $plName . ' does not exist';
                    }
                }
                if (! empty($rule['match_access_list'])) {
                    $aclName = (string) $rule['match_access_list'];
                    $lines[] = sprintf(' match ip address %s', $aclName);
                    if (! isset($aclExists[$aclName])) {
                        $lines[] = '! WARNING: access-list ' . $aclName . ' does not exist';
                    }
                }
                if (isset($rule['set_metric']) && $rule['set_metric'] !== null) {
                    $lines[] = ' set metric ' . (int) $rule['set_metric'];
                }
                if (! empty($rule['set_metric_type'])) {
                    $lines[] = ' set metric-type ' . $rule['set_metric_type'];
                }
                if (isset($rule['set_tag']) && $rule['set_tag'] !== null) {
                    $lines[] = ' set tag ' . (int) $rule['set_tag'];
                }
            }
        }

        // ── router ospf: distribute-lists & area filter-lists ─────────
        $lines[]     = '!';
        $lines[]     = '! ── router ospf ───────────────────────────────';
        $activeDist  = array_values(array_filter($distributeLists, static fn ($dl) => ! empty($dl['enabled'])));
        $activeArea  = array_values(array_filter($areaFilters, static fn ($af) => ! empty($af['enabled'])));
        foreach ($distributeLists as $dl) {
            if (empty($dl['enabled'])) {
                $skipped[] = 'distribute-list ' . $dl['prefix_list'];
            }
        }
        foreach ($areaFilters as $af) {
            if (empty($af['enabled'])) {
                $skipped[] = 'area ' . $af['area_id'] . ' filter-list';
            }
        }
        if ($activeDist === [] && $activeArea === []) {
            $lines[] = '! (no distribute-lists or area filter-lists)';
        } else {
            $lines[] = 'router ospf';
            foreach ($activeDist as $dl) {
                $dir  = ($dl['direction'] === 'out') ? 'out' : 'in';
                $line = ' distribute-list ' . $dl['prefix_list'] . ' ' . $dir;
                if ($dir === 'in' && ! empty($dl['interface'])) {
                    $line .= ' ' . $dl['interface'];
                }
                $lines[] = $line;
                if (! isset($plExists[$dl['prefix_list']])) {
                    $lines[] = '! WARNING: prefix-list ' . $dl['prefix_list'] . ' does not exist';
                }
            }
            foreach ($activeArea as $af) {
                $dir     = ($af['direction'] === 'out') ? 'out' : 'in';
                $lines[] = sprintf(' area %s filter-list prefix %s %s', $af['area_id'], $af['prefix_list'], $dir);
                if (! isset($plExists[$af['prefix_list']])) {
                    $lines[] = '! WARNING: prefix-list ' . $af['prefix_list'] . ' does not exist';
                }
            }
        }

        if ($skipped !== []) {
            $lines[] = '!';
            $lines[] = '! Disabled (not compiled): ' . implode(', ', $skipped);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Finds OSPF policy references pointing at a route-map or prefix-list that
     * no longer exists.
     *
     * 
     * A rename or a hand-edited row can leave an OSPF distribute-list, area
     * filter-list, route-map match or redistribution route-map pointing at a
     * deleted object. FRR would then reject the config or silently match
     * nothing, so these are surfaced instead of being ignored.
     *
     * @param array<int,array<string,mixed>> $prefixLists
     * @param array<int,array<string,mixed>> $routeMaps
     * @param array<int,array<string,mixed>> $distributeLists
     * @param array<int,array<string,mixed>> $areaFilters
     * @param array<string,mixed>            $ospfSettings
     *
     * @return list<array{type:string,name:string,referenced_by:string}>
     */
    public static function staleOspfReferences(
        array $prefixLists,
        array $routeMaps,
        array $distributeLists,
        array $areaFilters,
        array $ospfSettings = []
    ): array {
        $plExists = array_flip(array_column($prefixLists, 'name'));
        $rmExists = array_flip(array_column($routeMaps, 'name'));

        $stale = [];
        $check = static function (string $type, ?string $name, string $referencedBy) use (&$stale, $plExists, $rmExists): void {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            $exists = $type === 'route-map' ? isset($rmExists[$name]) : isset($plExists[$name]);
            if (! $exists) {
                $stale[] = ['type' => $type, 'name' => $name, 'referenced_by' => $referencedBy];
            }
        };

        // Route-map rules matching a prefix-list.
        foreach ($routeMaps as $rm) {
            foreach (json_decode($rm['rules'] ?? '[]', true) ?: [] as $rule) {
                if (! empty($rule['match_prefix_list'])) {
                    $check('prefix-list', (string) $rule['match_prefix_list'], 'Route-map ' . $rm['name']);
                }
            }
        }

        // Distribute-lists and area filter-lists referencing a prefix-list.
        foreach ($distributeLists as $dl) {
            $dir = ($dl['direction'] ?? 'in') === 'out' ? 'outbound' : 'inbound';
            $check('prefix-list', $dl['prefix_list'] ?? '', "OSPF distribute-list {$dir}");
        }
        foreach ($areaFilters as $af) {
            $dir = ($af['direction'] ?? 'in') === 'out' ? 'out' : 'in';
            $check('prefix-list', $af['prefix_list'] ?? '', 'OSPF area ' . ($af['area_id'] ?? '?') . " filter-list {$dir}");
        }

        // Redistribution route-maps.
        $redistribute = [
            'redistribute_connected_rm' => 'OSPF redistribute connected',
            'redistribute_static_rm'    => 'OSPF redistribute static',
            'redistribute_bgp_rm'       => 'OSPF redistribute bgp',
            'redistribute_rip_rm'       => 'OSPF redistribute rip',
        ];
        foreach ($redistribute as $column => $label) {
            $check('route-map', $ospfSettings[$column] ?? '', $label);
        }

        return $stale;
    }

    // ================================================================
    // RIP (ripd)
    // ================================================================

    /** Redirect back to a RIP sub-tab with an error flash. */
    private function ripError(string $message, string $sub = 'general'): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url('routing') . '?tab=rip&sub=' . $sub)->with('error', $message);
    }

    public function saveRipSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $version = (int) $this->request->getPost('version');
        if (! in_array($version, [1, 2], true)) {
            $version = 2;
        }

        $authMode = strtolower(trim($this->request->getPost('auth_mode') ?? 'none'));
        if (! in_array($authMode, ['none', 'text', 'md5'], true)) {
            return $this->ripError('Authentication mode must be none, text or md5.');
        }

        $data = [
            'enabled'                => (int)(bool)$this->request->getPost('enabled'),
            'version'                => $version,
            'redistribute_connected' => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'    => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_bgp'       => (int)(bool)$this->request->getPost('redistribute_bgp'),
            'redistribute_ospf'      => (int)(bool)$this->request->getPost('redistribute_ospf'),
            'redistribute_connected_rm' => $this->knownRouteMap($this->request->getPost('redistribute_connected_rm')),
            'redistribute_static_rm'    => $this->knownRouteMap($this->request->getPost('redistribute_static_rm')),
            'redistribute_bgp_rm'       => $this->knownRouteMap($this->request->getPost('redistribute_bgp_rm')),
            'redistribute_ospf_rm'      => $this->knownRouteMap($this->request->getPost('redistribute_ospf_rm')),
            'redistribute_metric'    => ($this->request->getPost('redistribute_metric') !== null && $this->request->getPost('redistribute_metric') !== '' ? (int)$this->request->getPost('redistribute_metric') : null),
            'default_information'    => (int)(bool)$this->request->getPost('default_information'),
            'default_information_rm' => $this->knownRouteMap($this->request->getPost('default_information_rm')),
            'distance'               => ($this->request->getPost('distance') !== null && $this->request->getPost('distance') !== '' ? (int)$this->request->getPost('distance') : null),
            'maximum_paths'          => max(1, (int)($this->request->getPost('maximum_paths') ?: 4)),
            'timers_update'          => (int)($this->request->getPost('timers_update') ?: 30),
            'timers_timeout'         => (int)($this->request->getPost('timers_timeout') ?: 180),
            'timers_garbage'         => (int)($this->request->getPost('timers_garbage') ?: 120),
            'passive_default'        => (int)(bool)$this->request->getPost('passive_default'),
            'auth_mode'              => $authMode,
            'auth_password'          => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_rip_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_rip_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_rip_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), 'RIP general settings saved.', 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=general')->with($key, $message);
    }

    public function storeRipNetwork(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db      = \Config\Database::connect();
        $network = trim($this->request->getPost('network') ?? '');
        if (\App\Libraries\Cidr::isValid($network) === false) {
            return $this->ripError('Enter a valid IPv4/IPv6 network in CIDR notation (e.g. 192.168.1.0/24).', 'networks');
        }

        if ($db->table('frr_rip_networks')->where('network', $network)->countAllResults()) {
            return $this->ripError("RIP network '{$network}' already exists.", 'networks');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_rip_networks')->insert([
            'id'          => 'rn_' . bin2hex(random_bytes(6)),
            'network'     => $network,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), "RIP network {$network} added.", 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=networks')->with($key, $message);
    }

    public function deleteRipNetwork(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_rip_networks')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), 'RIP network removed.', 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=networks')->with($key, $message);
    }

    public function storeRipNeighbor(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ip = trim($this->request->getPost('neighbor_ip') ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->ripError('Enter a valid neighbor IP address.', 'neighbors');
        }

        if ($db->table('frr_rip_neighbors')->where('neighbor_ip', $ip)->countAllResults()) {
            return $this->ripError("RIP neighbor '{$ip}' already exists.", 'neighbors');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_rip_neighbors')->insert([
            'id'          => 'rb_' . bin2hex(random_bytes(6)),
            'neighbor_ip' => $ip,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), "RIP neighbor {$ip} added.", 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=neighbors')->with($key, $message);
    }

    public function deleteRipNeighbor(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_rip_neighbors')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), 'RIP neighbor removed.', 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=neighbors')->with($key, $message);
    }

    public function storeRipPassiveInterface(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $iface = trim($this->request->getPost('interface') ?? '');
        if ($iface === '') {
            return $this->ripError('Interface is required.', 'passive');
        }

        if ($db->table('frr_rip_passive_interfaces')->where('interface', $iface)->countAllResults()) {
            return $this->ripError("Interface '{$iface}' is already marked passive.", 'passive');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_rip_passive_interfaces')->insert([
            'id'          => 'rp_' . bin2hex(random_bytes(6)),
            'interface'   => $iface,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), "Interface {$iface} marked passive for RIP.", 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=passive')->with($key, $message);
    }

    public function deleteRipPassiveInterface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_rip_passive_interfaces')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('rip'), 'RIP passive interface removed.', 'Saved, but the RIP apply');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=passive')->with($key, $message);
    }

    // ================================================================
    // IS-IS (isisd)
    // ================================================================

    /** Redirect back to an IS-IS sub-tab with an error flash. */
    private function isisError(string $message, string $sub = 'general'): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url('routing') . '?tab=isis&sub=' . $sub)->with('error', $message);
    }

    public function saveIsisSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $areaTag = trim($this->request->getPost('area_tag') ?? '') ?: 'DEFAULT';
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $areaTag)) {
            return $this->isisError('Area tag may only contain letters, digits, dot, dash and underscore.');
        }

        $isType = strtolower(trim($this->request->getPost('is_type') ?? 'level-1-2'));
        if (! in_array($isType, ['level-1', 'level-1-2', 'level-2-only'], true)) {
            return $this->isisError('IS type must be level-1, level-1-2 or level-2-only.');
        }

        $metricStyle = strtolower(trim($this->request->getPost('metric_style') ?? 'wide'));
        if (! in_array($metricStyle, ['narrow', 'transition', 'wide'], true)) {
            return $this->isisError('Metric style must be narrow, transition or wide.');
        }

        $authMode = strtolower(trim($this->request->getPost('auth_mode') ?? 'none'));
        if (! in_array($authMode, ['none', 'text', 'md5'], true)) {
            return $this->isisError('Authentication mode must be none, text or md5.');
        }

        // NET is optional while disabled, but must be well-formed when present.
        $net = trim($this->request->getPost('net') ?? '');
        if ($net !== '' && ! preg_match('/^[0-9A-Fa-f]{2}(\.[0-9A-Fa-f]{4})+\.[0-9A-Fa-f]{2}$/', $net)) {
            return $this->isisError('NET must be a valid network entity title, e.g. 49.0001.1921.6800.1001.00.');
        }

        $data = [
            'enabled'                => (int)(bool)$this->request->getPost('enabled'),
            'area_tag'               => $areaTag,
            'net'                    => $net ?: null,
            'is_type'                => $isType,
            'metric_style'           => $metricStyle,
            'log_adjacency'          => (int)(bool)$this->request->getPost('log_adjacency'),
            'lsp_gen_interval'       => ($this->request->getPost('lsp_gen_interval') !== null && $this->request->getPost('lsp_gen_interval') !== '' ? (int)$this->request->getPost('lsp_gen_interval') : null),
            'spf_interval_l1'        => ($this->request->getPost('spf_interval_l1') !== null && $this->request->getPost('spf_interval_l1') !== '' ? (int)$this->request->getPost('spf_interval_l1') : null),
            'spf_interval_l2'        => ($this->request->getPost('spf_interval_l2') !== null && $this->request->getPost('spf_interval_l2') !== '' ? (int)$this->request->getPost('spf_interval_l2') : null),
            'max_lsp_lifetime'       => ($this->request->getPost('max_lsp_lifetime') !== null && $this->request->getPost('max_lsp_lifetime') !== '' ? (int)$this->request->getPost('max_lsp_lifetime') : null),
            'redistribute_connected' => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'    => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_bgp'       => (int)(bool)$this->request->getPost('redistribute_bgp'),
            'redistribute_ospf'      => (int)(bool)$this->request->getPost('redistribute_ospf'),
            'redistribute_connected_rm' => $this->knownRouteMap($this->request->getPost('redistribute_connected_rm')),
            'redistribute_static_rm'    => $this->knownRouteMap($this->request->getPost('redistribute_static_rm')),
            'redistribute_bgp_rm'       => $this->knownRouteMap($this->request->getPost('redistribute_bgp_rm')),
            'redistribute_ospf_rm'      => $this->knownRouteMap($this->request->getPost('redistribute_ospf_rm')),
            'redistribute_metric'    => ($this->request->getPost('redistribute_metric') !== null && $this->request->getPost('redistribute_metric') !== '' ? (int)$this->request->getPost('redistribute_metric') : null),
            'auth_mode'              => $authMode,
            'auth_password'          => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'passive_default'        => (int)(bool)$this->request->getPost('passive_default'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_isis_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_isis_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_isis_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        [$key, $message] = $this->ipcFlash($this->applier()->frr('isis'), 'IS-IS general settings saved.', 'Saved, but the IS-IS apply');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=general')->with($key, $message);
    }

    public function storeIsisInterface(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $fields = $this->isisInterfaceFields();
        if (is_string($fields)) {
            return $this->isisError($fields, 'interfaces');
        }

        if ($db->table('frr_isis_interfaces')->where('interface', $fields['interface'])->countAllResults()) {
            return $this->isisError("Interface '{$fields['interface']}' is already configured for IS-IS.", 'interfaces');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_isis_interfaces')->insert($fields + [
            'id'         => 'ii_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('isis'), "IS-IS interface {$fields['interface']} configured.", 'Saved, but the IS-IS apply');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=interfaces')->with($key, $message);
    }

    public function updateIsisInterface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_isis_interfaces')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->isisError('IS-IS interface not found.', 'interfaces');
        }

        $fields = $this->isisInterfaceFields();
        if (is_string($fields)) {
            return $this->isisError($fields, 'interfaces');
        }

        if ($db->table('frr_isis_interfaces')->where('interface', $fields['interface'])->where('id !=', $id)->countAllResults()) {
            return $this->isisError("Interface '{$fields['interface']}' is already configured for IS-IS.", 'interfaces');
        }

        $db->table('frr_isis_interfaces')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('isis'), 'IS-IS interface updated.', 'Saved, but the IS-IS apply');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=interfaces')->with($key, $message);
    }

    public function deleteIsisInterface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_isis_interfaces')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('isis'), 'IS-IS interface removed.', 'Saved, but the IS-IS apply');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=interfaces')->with($key, $message);
    }

    /**
     * Validates and normalises an IS-IS interface form submission.
     *
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function isisInterfaceFields()
    {
        $iface = trim($this->request->getPost('interface') ?? '');
        if ($iface === '') {
            return 'Interface is required.';
        }

        $circuitType = strtolower(trim($this->request->getPost('circuit_type') ?? 'level-1-2'));
        if (! in_array($circuitType, ['level-1', 'level-1-2', 'level-2-only'], true)) {
            return 'Circuit type must be level-1, level-1-2 or level-2-only.';
        }

        $networkType = strtolower(trim($this->request->getPost('network_type') ?? 'broadcast'));
        if (! in_array($networkType, ['broadcast', 'point-to-point'], true)) {
            return 'Network type must be broadcast or point-to-point.';
        }

        $intOrNull = static function ($value): ?int {
            return ($value !== null && $value !== '') ? (int) $value : null;
        };

        return [
            'interface'        => $iface,
            'description'      => trim($this->request->getPost('description') ?? ''),
            'circuit_type'     => $circuitType,
            'network_type'     => $networkType,
            'metric_l1'        => $intOrNull($this->request->getPost('metric_l1')),
            'metric_l2'        => $intOrNull($this->request->getPost('metric_l2')),
            'hello_interval'   => max(1, (int)($this->request->getPost('hello_interval') ?: 10)),
            'hello_multiplier' => max(1, (int)($this->request->getPost('hello_multiplier') ?: 3)),
            'csnp_interval'    => $intOrNull($this->request->getPost('csnp_interval')),
            'psnp_interval'    => $intOrNull($this->request->getPost('psnp_interval')),
            'password'         => trim($this->request->getPost('password') ?? '') ?: null,
            'bfd_enabled'      => (int)(bool)$this->request->getPost('bfd_enabled'),
        ];
    }

    // ================================================================
    // BGP Global Settings
    // ================================================================

    public function saveBgpSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $networks = array_values(array_filter(
            array_map('trim', explode("\n", $this->request->getPost('networks') ?? ''))
        ));

        $data = [
            'enabled'                  => (int)(bool)$this->request->getPost('enabled'),
            'local_as'                 => (int)($this->request->getPost('local_as') ?: 65001),
            'router_id'                => trim($this->request->getPost('router_id') ?? '') ?: null,
            'keepalive_interval'       => (int)($this->request->getPost('keepalive_interval') ?: 60),
            'hold_time'                => (int)($this->request->getPost('hold_time') ?: 180),
            'connect_retry'            => (int)($this->request->getPost('connect_retry') ?: 120),
            'log_neighbor_changes'     => (int)(bool)$this->request->getPost('log_neighbor_changes'),
            'graceful_restart'         => (int)(bool)$this->request->getPost('graceful_restart'),
            'deterministic_med'        => (int)(bool)$this->request->getPost('deterministic_med'),
            'always_compare_med'       => (int)(bool)$this->request->getPost('always_compare_med'),
            'bestpath_as_path_ignore'  => (int)(bool)$this->request->getPost('bestpath_as_path_ignore'),
            'redistribute_connected'    => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_connected_rm' => trim($this->request->getPost('redistribute_connected_rm') ?? '') ?: null,
            'redistribute_static'       => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_static_rm'    => trim($this->request->getPost('redistribute_static_rm') ?? '') ?: null,
            'redistribute_ospf'         => (int)(bool)$this->request->getPost('redistribute_ospf'),
            'redistribute_ospf_rm'      => trim($this->request->getPost('redistribute_ospf_rm') ?? '') ?: null,
            'networks'                  => json_encode($networks),
            'max_paths_ebgp'            => (int)($this->request->getPost('max_paths_ebgp') ?: 1),
            'max_paths_ibgp'            => (int)($this->request->getPost('max_paths_ibgp') ?: 1),
            'updated_at'                => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_bgp_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_bgp_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_bgp_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        [$key, $message] = $this->ipcFlash($this->applier()->frr('bgp'), 'BGP global settings saved.', 'Saved, but the BGP apply');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=general')->with($key, $message);
    }


    // ================================================================

    public function storeBfd(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ip = trim($this->request->getPost('peer_ip') ?? '');
        if (empty($ip)) {
            return redirect()->back()->with('error', 'Peer IP is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_bfd_peers')->insert([
            'id'                => 'bfd_' . bin2hex(random_bytes(6)),
            'peer_ip'           => $ip,
            'local_ip'          => trim($this->request->getPost('local_ip') ?? '') ?: null,
            'interface'         => trim($this->request->getPost('interface') ?? '') ?: null,
            'detect_multiplier' => (int)($this->request->getPost('detect_multiplier') ?: 3),
            'receive_interval'  => (int)($this->request->getPost('receive_interval') ?: 300),
            'transmit_interval' => (int)($this->request->getPost('transmit_interval') ?: 300),
            'echo_mode'         => (int)(bool)$this->request->getPost('echo_mode'),
            'description'       => trim($this->request->getPost('description') ?? ''),
            'enabled'           => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->frr('bfd'), "BFD peer {$ip} added.", 'Saved, but the BFD apply');
        return redirect()->to(base_url('routing') . '?tab=bfd')->with($key, $message);
    }

    public function deleteBfd(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_bfd_peers')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->frr('bfd'), 'BFD peer removed.', 'Saved, but the BFD apply');
        return redirect()->to(base_url('routing') . '?tab=bfd')->with($key, $message);
    }

    // ================================================================
    // VRF
    // ================================================================

    public function storeVrf(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if (empty($name)) {
            return redirect()->back()->with('error', 'VRF name is required.');
        }

        $rawIfaces = $this->request->getPost('interfaces');
        if (is_string($rawIfaces)) {
            $ifaces = array_values(array_filter(array_map('trim', explode(',', $rawIfaces))));
        } else {
            $ifaces = (array)($rawIfaces ?? []);
        }
        $now = date('Y-m-d H:i:s');
        $db->table('routing_vrfs')->insert([
            'id'          => 'vrf_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'table_id'    => ($this->request->getPost('table_id') ?: null),
            'interfaces'  => json_encode(array_values($ifaces)),
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->vrfs(), "VRF '{$name}' created.", 'Saved, but the VRF sync');
        return redirect()->to(base_url('routing') . '?tab=vrf')->with($key, $message);
    }

    public function deleteVrf(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('routing_vrfs')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->vrfs(), 'VRF removed.', 'Saved, but the VRF sync');
        return redirect()->to(base_url('routing') . '?tab=vrf')->with($key, $message);
    }

    // ================================================================
    // PBR Rules
    // ================================================================

    public function storePbr(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $fields = $this->pbrFields();
        if (is_string($fields)) {
            return redirect()->to(base_url('routing') . '?tab=pbr')->with('error', $fields);
        }

        $db->table('routing_pbr_rules')->insert($fields + [
            'id'         => 'pbr_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.pbr.sync'), 'PBR rule added.', 'Saved, but the policy-routing apply');
        return redirect()->to(base_url('routing') . '?tab=pbr')->with($key, $message);
    }

    public function updatePbr(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('routing_pbr_rules')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PBR rule not found.');
        }

        $fields = $this->pbrFields($row);
        if (is_string($fields)) {
            return redirect()->to(base_url('routing') . '?tab=pbr')->with('error', $fields);
        }

        // The edit form carries an explicit enabled flag; when it is absent
        // (e.g. a partial update) the existing value is preserved rather than
        // silently cleared.
        $fields['enabled'] = $this->request->getPost('enabled') !== null
            ? (int) (bool) $this->request->getPost('enabled')
            : (int) $row['enabled'];

        $db->table('routing_pbr_rules')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.pbr.sync'), 'PBR rule updated.', 'Saved, but the policy-routing apply');
        return redirect()->to(base_url('routing') . '?tab=pbr')->with($key, $message);
    }

    /**
     * Validates and normalises the PBR form fields.
     *
     * @param  array<string,mixed>|null $existing
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function pbrFields(?array $existing = null)
    {
        $src = trim($this->request->getPost('match_src') ?? '');
        $dst = trim($this->request->getPost('match_dst') ?? '');

        foreach (['source' => $src, 'destination' => $dst] as $label => $value) {
            if ($value !== '' && $value !== 'any' && ! \App\Libraries\Cidr::isValid($value)) {
                return "The {$label} match must be an IP address or CIDR (e.g. 10.0.0.0/24).";
            }
        }

        $nexthop = trim($this->request->getPost('set_nexthop') ?? '');
        if ($nexthop !== '' && ! filter_var($nexthop, FILTER_VALIDATE_IP)) {
            return 'The next-hop must be a valid IP address.';
        }

        // A selected object must exist, otherwise the rule silently matches
        // nothing. Catch the typo at save time instead.
        $unknown = $this->unknownPbrObjects();
        if ($unknown !== []) {
            return 'Unknown address object(s): ' . implode(', ', $unknown) . '.';
        }

        $table = $this->request->getPost('set_table');
        $table = ($table === null || $table === '') ? null : (int) $table;
        if ($table !== null && ($table < 1 || $table > 252)) {
            return 'The routing table must be between 1 and 252.';
        }

        $action = strtolower(trim((string) ($this->request->getPost('action') ?? 'permit')));

        return [
            'priority'          => (int) ($this->request->getPost('priority') ?: ($existing['priority'] ?? 100)),
            'description'       => trim($this->request->getPost('description') ?? ''),
            'match_src'         => $src ?: null,
            'match_dst'         => $dst ?: null,
            'match_src_objects' => $this->pbrObjectList('match_src_objects'),
            'match_dst_objects' => $this->pbrObjectList('match_dst_objects'),
            'match_protocol'    => trim($this->request->getPost('match_protocol') ?? '') ?: null,
            'match_dscp'        => trim($this->request->getPost('match_dscp') ?? '') ?: null,
            'match_iif'         => trim($this->request->getPost('match_iif') ?? '') ?: null,
            'set_nexthop'       => $nexthop ?: null,
            'set_table'         => $table,
            'set_vrf'           => trim($this->request->getPost('set_vrf') ?? '') ?: null,
            'action'            => $action === 'deny' ? 'deny' : 'permit',
        ];
    }

    /**
     * Object names in the submitted selection that are neither an address
     * object nor an address group.
     *
     * @return list<string>
     */
    private function unknownPbrObjects(): array
    {
        $names = [];
        foreach (['match_src_objects', 'match_dst_objects'] as $field) {
            $raw = $this->request->getPost($field);
            $items = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string) $raw);
            foreach ($items ?: [] as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $names[$item] = true;
                }
            }
        }

        if ($names === []) {
            return [];
        }

        $db = \Config\Database::connect();
        $known = array_merge(
            array_column($db->table('firewall_addresses')->select('name')->get()->getResultArray(), 'name'),
            array_column($db->table('firewall_address_groups')->select('name')->get()->getResultArray(), 'name'),
        );
        $known = array_flip($known);

        return array_values(array_filter(array_keys($names), static fn ($name) => ! isset($known[$name])));
    }

    /**
     * Reads a PBR object-match selection (a list of names) into a JSON list.
     *
     * The value is stored as JSON so a name containing a comma survives; an
     * empty selection becomes null rather than an empty array.
     */
    private function pbrObjectList(string $field): ?string
    {
        $raw = $this->request->getPost($field);
        if ($raw === null) {
            return null;
        }

        $items = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string) $raw);
        $items = array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $items ?: [],
        ), 'strlen')));

        return $items === [] ? null : json_encode($items);
    }

    public function deletePbr(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('routing_pbr_rules')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.pbr.sync'), 'PBR rule removed.', 'Saved, but the policy-routing apply');
        return redirect()->to(base_url('routing') . '?tab=pbr')->with($key, $message);
    }

    public function togglePbr(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('routing_pbr_rules')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PBR rule not found.');
        }

        $newVal = empty($row['enabled']) ? 1 : 0;
        $db->table('routing_pbr_rules')->where('id', $id)->update([
            'enabled'    => $newVal,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->routingPolicy('network.pbr.sync'),
            "PBR rule '{$row['description']}' is now " . ($newVal ? 'enabled' : 'disabled') . '.',
            'Saved, but the policy-routing apply'
        );

        return redirect()->to(base_url('routing') . '?tab=pbr')->with($key, $message);
    }

    public function reorderPbr(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db    = \Config\Database::connect();
        $order = array_values(array_filter(array_map('strval', (array) ($this->request->getPost('order') ?? [])), 'strlen'));

        if ($order === []) {
            return $this->response->setJSON(['ok' => false, 'error' => 'No rule order was supplied.']);
        }

        foreach ($order as $index => $id) {
            $db->table('routing_pbr_rules')->where('id', $id)->update(['priority' => $index + 1]);
        }

        $outcome = $this->ipcOutcome($this->applier()->routingPolicy('network.pbr.sync'));

        return $this->response->setJSON($outcome['ok']
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Order saved, but the policy-routing apply failed: ' . $outcome['reason']]);
    }

    /**
     * Compiles the PBR rules and pushes the mark/route policy to the dataplane.
     *
     * The compiled text is returned for inspection (the ruleset plus its
     * companion `ip rule` / `ip route` section) and applied through the daemon.
     */
    public function applyPbr(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $compiler = new \App\Libraries\NftablesCompiler();

            // The PBR marks live in the nftables ruleset; the `ip rule` /
            // `ip route` policy is separate kernel state. Both are sent so the
            // daemon can load the ruleset and (re)build the routing policy
            // atomically in one call.
            $ruleset = $compiler->compile();
            $policy  = $compiler->compileRoutingPolicy();

            $resp = $this->ipc->call('routing.apply', [
                'ruleset'   => $ruleset,
                'rules'     => $policy['rules'],
                'routes'    => $policy['routes'],
                'safe_mode' => false,
            ]);
            $this->ipc->call('firewall.confirm');

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('routing') . '?tab=pbr')
                    ->with('error', 'Failed to apply policy routing: ' . ($resp['error']['message'] ?? 'daemon error'));
            }

            $result = $resp['result'] ?? [];
            $status = $result['status'] ?? 'applied';

            if (in_array($status, ['syntax_error', 'apply_failed', 'error'], true)) {
                return redirect()->to(base_url('routing') . '?tab=pbr')
                    ->with('error', 'Policy routing rejected: ' . ($result['error'] ?? $result['message'] ?? $status));
            }

            $count = count($policy['rules']);
            $msg   = $result['message'] ?? sprintf(
                'Policy routing applied: %d rule(s) and %d table route(s)%s.',
                $count,
                count($policy['routes']),
                $this->ipc->isMock() ? ' (simulated)' : '',
            );

            return redirect()->to(base_url('routing') . '?tab=pbr')->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('routing') . '?tab=pbr')
                ->with('error', 'Failed to apply policy routing: ' . $e->getMessage());
        }
    }

    // ================================================================
    // Multi-WAN — Gateways
    // ================================================================

    public function storeGateway(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        $gw   = trim($this->request->getPost('gateway_ip') ?? '');
        if (empty($name) || empty($gw)) {
            return redirect()->back()->with('error', 'Gateway name and IP are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('wan_gateways')->insert([
            'id'           => 'gw_' . bin2hex(random_bytes(6)),
            'name'         => $name,
            'interface'    => trim($this->request->getPost('interface') ?: 'eth0'),
            'gateway_ip'   => $gw,
            'monitor_ip'   => trim($this->request->getPost('monitor_ip') ?? '') ?: null,
            'weight'       => (int)($this->request->getPost('weight') ?: 1),
            'priority'     => (int)($this->request->getPost('priority') ?: 1),
            'latency_warn' => (int)($this->request->getPost('latency_warn') ?: 200),
            'latency_down' => (int)($this->request->getPost('latency_down') ?: 500),
            'loss_warn'    => (int)($this->request->getPost('loss_warn') ?: 10),
            'loss_down'    => (int)($this->request->getPost('loss_down') ?: 20),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'enabled'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.multiwan.sync'), "Gateway '{$name}' added.", 'Saved, but the gateway policy apply');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with($key, $message);
    }

    public function updateGateway(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('wan_gateways')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Gateway not found.');
        }

        $db->table('wan_gateways')->where('id', $id)->update([
            'name'         => trim($this->request->getPost('name') ?: $row['name']),
            'interface'    => trim($this->request->getPost('interface') ?: $row['interface']),
            'gateway_ip'   => trim($this->request->getPost('gateway_ip') ?: $row['gateway_ip']),
            'monitor_ip'   => trim($this->request->getPost('monitor_ip') ?? '') ?: null,
            'weight'       => (int)($this->request->getPost('weight') ?: 1),
            'priority'     => (int)($this->request->getPost('priority') ?: 1),
            'latency_warn' => (int)($this->request->getPost('latency_warn') ?: 200),
            'latency_down' => (int)($this->request->getPost('latency_down') ?: 500),
            'loss_warn'    => (int)($this->request->getPost('loss_warn') ?: 10),
            'loss_down'    => (int)($this->request->getPost('loss_down') ?: 20),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'enabled'      => (int)(bool)$this->request->getPost('enabled'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.multiwan.sync'), 'Gateway updated.', 'Saved, but the gateway policy apply');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with($key, $message);
    }

    public function deleteGateway(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('wan_gateways')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.multiwan.sync'), 'Gateway removed.', 'Saved, but the gateway policy apply');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with($key, $message);
    }

    // ================================================================
    // Multi-WAN — Gateway Groups
    // ================================================================

    public function storeGatewayGroup(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if (empty($name)) {
            return redirect()->back()->with('error', 'Group name is required.');
        }

        // Build members array [{id, tier, weight}]
        $memberIds   = (array)($this->request->getPost('member_ids') ?? $this->request->getPost('members') ?? []);
        $memberTiers = (array)($this->request->getPost('member_tiers') ?? []);
        $members = [];
        foreach ($memberIds as $i => $mid) {
            if (!empty($mid)) {
                $tier = isset($memberTiers[$mid]) ? (int)$memberTiers[$mid] : (int)($memberTiers[$i] ?? 1);
                $members[] = ['id' => $mid, 'tier' => $tier];
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->table('wan_gateway_groups')->insert([
            'id'             => 'gwg_' . bin2hex(random_bytes(6)),
            'name'           => $name,
            'strategy'       => $this->request->getPost('strategy') ?: 'failover',
            'sticky_enabled' => (int)(bool)$this->request->getPost('sticky_enabled'),
            'sticky_timeout' => max(0, (int)($this->request->getPost('sticky_timeout') ?? 0)),
            'members'        => json_encode($members),
            'trigger'        => $this->request->getPost('trigger') ?: 'down',
            'description'    => trim($this->request->getPost('description') ?? ''),
            'enabled'        => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.multiwan.sync'), "Gateway group '{$name}' created.", 'Saved, but the gateway policy apply');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with($key, $message);
    }

    public function updateGatewayGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('wan_gateway_groups')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Gateway group not found.');
        }

        $memberIds   = (array)($this->request->getPost('member_ids') ?? $this->request->getPost('members') ?? []);
        $memberTiers = (array)($this->request->getPost('member_tiers') ?? []);
        $members = [];
        foreach ($memberIds as $i => $mid) {
            if (!empty($mid)) {
                $tier = isset($memberTiers[$mid]) ? (int)$memberTiers[$mid] : (int)($memberTiers[$i] ?? 1);
                $members[] = ['id' => $mid, 'tier' => $tier];
            }
        }

        $db->table('wan_gateway_groups')->where('id', $id)->update([
            'name'           => trim($this->request->getPost('name') ?: $row['name']),
            'strategy'       => $this->request->getPost('strategy') ?: $row['strategy'],
            'sticky_enabled' => (int)(bool)$this->request->getPost('sticky_enabled'),
            'sticky_timeout' => max(0, (int)($this->request->getPost('sticky_timeout') ?? 0)),
            'members'        => json_encode($members),
            'trigger'        => $this->request->getPost('trigger') ?: $row['trigger'],
            'description'    => trim($this->request->getPost('description') ?? ''),
            'enabled'        => (int)(bool)$this->request->getPost('enabled'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.multiwan.sync'), 'Gateway group updated.', 'Saved, but the gateway policy apply');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with($key, $message);
    }

    public function deleteGatewayGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('wan_gateway_groups')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash($this->applier()->routingPolicy('network.multiwan.sync'), 'Gateway group removed.', 'Saved, but the gateway policy apply');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with($key, $message);
    }

    /**
     * Applies Multi-WAN load balancing: the nftables ruleset that tags each
     * flow with its gateway, plus the routing policy that turns those tags into
     * per-gateway default routes.
     */
    public function applyMultiWan(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $compiler = new \App\Libraries\NftablesCompiler();
            $multiWan = $compiler->compileMultiWan();

            if ($multiWan['balancers'] === []) {
                return redirect()->to(base_url('routing') . '?tab=multiwan')
                    ->with('error', 'No enabled load-balanced gateway group with at least two members.');
            }

            // The routing half is shared with Policy-Based Routing, so both are
            // sent in one call and the daemon rebuilds the whole policy.
            $policy = $compiler->compileRoutingPolicy();

            $resp = $this->ipc->call('routing.apply', [
                'ruleset'   => $compiler->compile(),
                'rules'     => $policy['rules'],
                'routes'    => $policy['routes'],
                'safe_mode' => false,
            ]);
            $this->ipc->call('firewall.confirm');

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('routing') . '?tab=multiwan')
                    ->with('error', 'Failed to apply Multi-WAN: ' . ($resp['error']['message'] ?? 'daemon error'));
            }

            $result = $resp['result'] ?? [];
            if (in_array($result['status'] ?? 'applied', ['syntax_error', 'apply_failed', 'error'], true)) {
                return redirect()->to(base_url('routing') . '?tab=multiwan')
                    ->with('error', 'Multi-WAN rejected: ' . ($result['error'] ?? $result['message'] ?? 'apply failed'));
            }

            $sticky = 0;
            foreach ($multiWan['balancers'] as $balancer) {
                if ($balancer['sticky']) {
                    $sticky++;
                }
            }

            $msg = sprintf(
                'Multi-WAN applied: %d balanced group(s), %d with sticky connections%s.',
                count($multiWan['balancers']),
                $sticky,
                $this->ipc->isMock() ? ' (simulated)' : '',
            );

            return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('routing') . '?tab=multiwan')
                ->with('error', 'Failed to apply Multi-WAN: ' . $e->getMessage());
        }
    }

    /**
     * Full Routing Table - Combined view of all routing sources.
     * Shows static routes, kernel FIB, BGP, OSPF, RIP, IS-IS, BFD, VRF, PBR, and Multi-WAN.
     */
    public function fullRoutingTable(): string
    {
        // Fetch kernel FIB routes
        $routeResponse = $this->ipc->call('diag.routing_table');
        $routeResult   = $routeResponse['result'] ?? null;
        if (is_array($routeResult) && isset($routeResult['routes']) && is_array($routeResult['routes'])) {
            $rawKernelRoutes = $routeResult['routes'];
        } elseif ($this->ipc->isMock()) {
            $rawKernelRoutes = [
                ['destination' => '0.0.0.0/0', 'gateway' => '192.168.1.1', 'interface' => 'eth0 (WAN)', 'metric' => 100, 'protocol' => 'dhcp', 'flags' => 'UG'],
                ['destination' => '10.8.0.0/24', 'gateway' => '10.8.0.2', 'interface' => 'tun0 (VPN)', 'metric' => 50, 'protocol' => 'static', 'flags' => 'UG'],
                ['destination' => '10.100.0.0/24', 'gateway' => 'direct', 'interface' => 'wg0 (WG)', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
                ['destination' => '192.168.1.0/24', 'gateway' => 'direct', 'interface' => 'eth1 (LAN)', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
                ['destination' => '192.168.20.0/24', 'gateway' => 'direct', 'interface' => 'eth2 (DMZ)', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
                ['destination' => '127.0.0.0/8', 'gateway' => 'direct', 'interface' => 'lo', 'metric' => 0, 'protocol' => 'kernel', 'flags' => 'U'],
            ];
        } else {
            $rawKernelRoutes = [];
        }
        $kernelRoutes = array_values(array_map(static fn (array $route): array => [
            'destination' => (string) ($route['destination'] ?? $route['dst'] ?? '—'),
            'gateway'     => (string) ($route['gateway'] ?? 'direct'),
            'interface'   => (string) ($route['interface'] ?? $route['dev'] ?? '—'),
            'flags'       => (string) ($route['flags'] ?? ''),
            'metric'      => (int) ($route['metric'] ?? 0),
            'proto'       => strtolower((string) ($route['proto'] ?? $route['protocol'] ?? 'unknown')),
        ], array_filter($rawKernelRoutes, 'is_array')));

        $db = \Config\Database::connect();

        // Fetch configured static routes
        $staticRoutes = $db->table('static_routes')->where('enabled', 1)->get()->getResultArray();

        // Fetch BGP neighbors
        $bgpNeighbors = $db->table('frr_bgp_neighbors')->get()->getResultArray();

        // Fetch OSPF data
        $ospfSettings = $db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray();
        $ospfAreas    = $db->table('frr_ospf_areas')->get()->getResultArray();
        $ospfIfaces   = $db->table('frr_ospf_ifaces')->get()->getResultArray();
        $ospfNetworks = $db->table('frr_ospf_networks')->get()->getResultArray();

        // Fetch RIP data
        $ripNetworks  = $db->table('frr_rip_networks')->get()->getResultArray();
        $ripNeighbors = $db->table('frr_rip_neighbors')->get()->getResultArray();

        // Fetch IS-IS data
        $isisInterfaces = $db->table('frr_isis_interfaces')->get()->getResultArray();

        // Fetch BFD peers
        $bfdPeers = $db->table('frr_bfd_peers')->get()->getResultArray();

        // Fetch VRFs
        $vrfs = $db->table('routing_vrfs')->get()->getResultArray();

        // Fetch PBR rules
        $pbrRules = $db->table('routing_pbr_rules')->orderBy('priority', 'ASC')->get()->getResultArray();

        // Fetch Multi-WAN gateways
        $wanGateways = $db->table('wan_gateway_groups')->get()->getResultArray();

        return view('routing/full_table', [
            'kernelRoutes'  => $kernelRoutes,
            'staticRoutes'  => $staticRoutes,
            'bgpNeighbors'  => $bgpNeighbors,
            'ospfSettings'  => $ospfSettings,
            'ospfAreas'     => $ospfAreas,
            'ospfIfaces'    => $ospfIfaces,
            'ospfNetworks'  => $ospfNetworks,
            'ripNetworks'   => $ripNetworks,
            'ripNeighbors'  => $ripNeighbors,
            'isisInterfaces' => $isisInterfaces,
            'bfdPeers'      => $bfdPeers,
            'vrfs'          => $vrfs,
            'pbrRules'      => $pbrRules,
            'wanGateways'   => $wanGateways,
            'frrStatus'     => $this->getFrrStatus(),
            'isMock'        => $this->ipc->isMock(),
        ]);
    }

    /**
     * Get FRR status helper.
     */
    private function getFrrStatus(): array
    {
        $db = \Config\Database::connect();
        $frrSvc = $db->table('system_services')->where('id', 'frr')->get()->getRowArray();
        $dbRunning = ($frrSvc['status'] ?? 'stopped') === 'running';
        $bootEnabled = !empty($frrSvc['enabled_at_boot']);

        $fallback = [
            'running'   => $this->ipc->isMock() ? true : $dbRunning,
            'enabled'   => $bootEnabled,
            'version'   => $this->ipc->isMock() ? 'FRRouting 9.1' : ($dbRunning ? 'FRRouting' : '—'),
            'uptime'    => $this->ipc->isMock() ? '3d 14h 22m' : ($dbRunning ? 'Active' : '—'),
            'bgp_as'    => '',
            'router_id' => '',
        ];

        try {
            $res = $this->ipc->call('frr.status');
            $result = $res['result'] ?? null;
            if (is_array($result) && empty($result['_mock'])) {
                return array_merge($fallback, $result, ['enabled' => $result['enabled'] ?? $bootEnabled]);
            }
            return $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Get live OSPF status via micrond IPC with direct vtysh fallback on Linux.
     *
     * @return array{
     *     success: bool,
     *     status: string,
     *     sections: array<string, array{command: string, available: bool, output: string}>,
     *     resources: array<string, mixed>
     * }
     */
    private function getOspfStatus(): array
    {
        try {
            $resp = $this->ipc->call('ospf.status');
            $result = $resp['result'] ?? null;
            if (is_array($result) && !empty($result['sections'])) {
                return $result;
            }
            if (!empty($resp['error'])) {
                log_message('error', 'OSPF status IPC call failed: ' . ($resp['error']['message'] ?? 'unknown error'));
            }
        } catch (\Throwable $e) {
            log_message('error', 'OSPF status IPC call failed: ' . $e->getMessage());
        }

        // Direct vtysh fallback on Linux appliance if IPC daemon is unavailable or returned empty
        if (DIRECTORY_SEPARATOR === '/' && (is_executable('/usr/bin/vtysh') || is_executable('/bin/vtysh') || trim((string)@shell_exec('which vtysh 2>/dev/null')) !== '')) {
            $commands = [
                'general'         => 'show ip ospf',
                'neighbors'       => 'show ip ospf neighbor detail',
                'routes'          => 'show ip ospf route',
                'database'        => 'show ip ospf database',
                'router_database' => 'show ip ospf database router',
                'interfaces'      => 'show ip ospf interface',
            ];
            $sections = [];
            foreach ($commands as $key => $cmd) {
                $out = @shell_exec('vtysh -c ' . escapeshellarg($cmd) . ' 2>&1');
                $output = trim((string)$out);
                // The web server user is not in the frrvty group, so vtysh run from
                // here cannot read /etc/frr or reach the daemons. Show why instead
                // of vtysh's raw complaint; micrond (root) is the supported path.
                if (str_contains($output, 'Permission denied') || str_contains($output, 'failed to connect to any daemons')) {
                    $sections[$key] = [
                        'command'   => $cmd,
                        'available' => false,
                        'output'    => 'OSPF state could not be read: the micrond service did not answer, '
                                     . 'and the web server is not allowed to query FRR directly. '
                                     . 'Check that micrond is running (Services page or "systemctl status micrond").',
                    ];
                    continue;
                }
                $sections[$key] = [
                    'command'   => $cmd,
                    'available' => $output !== '' && !str_contains($output, 'command not found'),
                    'output'    => $output !== '' ? $output : 'No OSPF data returned.',
                ];
            }
            $cpu = trim((string)@shell_exec("ps -C ospfd -o %cpu=,%mem=,rss=,pid= 2>/dev/null"));
            $cpuOutput = 'ospfd is not running; CPU usage is unavailable.';
            $memOutput = 'ospfd is not running; memory usage is unavailable.';
            $processAvailable = false;
            if ($cpu !== '') {
                $lines = explode("\n", $cpu);
                $cpuTotal = 0.0;
                $memTotal = 0.0;
                $rssTotal = 0;
                $pids = 0;
                foreach ($lines as $line) {
                    $f = preg_split('/\s+/', trim($line));
                    if (count($f) >= 4) {
                        $cpuTotal += (float)$f[0];
                        $memTotal += (float)$f[1];
                        $rssTotal += (int)$f[2];
                        $pids++;
                    }
                }
                if ($pids > 0) {
                    $cpuOutput = sprintf("ospfd CPU average: %.1f%%\nProcesses: %d", $cpuTotal, $pids);
                    $memOutput = sprintf("Resident memory: %.1f MiB\nProcess memory: %.1f%%", $rssTotal / 1024, $memTotal);
                    $processAvailable = true;
                }
            }
            return [
                'success'   => true,
                'status'    => 'ok',
                'sections'  => $sections,
                'resources' => [
                    'available'     => $processAvailable,
                    'cpu_output'    => $cpuOutput,
                    'memory_output' => $memOutput,
                ],
            ];
        }

        return [
            'success'   => false,
            'status'    => 'unavailable',
            'sections'  => [],
            'resources' => [],
        ];
    }

    /**
     * Extract live OSPF neighbors list from live status or mock during dev.
     */
    private function extractLiveNeighbors(array $ospfStatus, bool $isMock): array
    {
        if ($isMock && empty($ospfStatus['sections'])) {
            return [
                ['neighbor_id' => '10.10.10.10', 'priority' => 1, 'state' => 'Full/DR',     'dead_time' => '34.90s', 'address' => '172.16.100.1', 'interface' => 're0:172.16.100.4', 'rxmtl' => 0, 'rqstl' => 0, 'dbsml' => 0],
                ['neighbor_id' => '9.9.9.9',     'priority' => 1, 'state' => 'Full/Backup', 'dead_time' => '32.76s', 'address' => '172.16.100.3', 'interface' => 're0:172.16.100.4', 'rxmtl' => 0, 'rqstl' => 0, 'dbsml' => 0],
            ];
        }

        $output = (string) ($ospfStatus['sections']['neighbors']['output'] ?? '');
        if ($output === '' || str_contains($output, 'No OSPF data') || str_contains($output, 'unavailable')) {
            return [];
        }

        $neighbors = [];
        // Pattern 1: Table format "192.168.1.2 1 Full/DR ..."
        if (preg_match_all('/^\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})\s+(\d+)\s+([A-Za-z0-9\/_-]+)\s+(\S+)\s+([0-9]{1,3}(?:\.[0-9]{1,3}){3})\s+(\S+)/m', $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $neighbors[] = [
                    'neighbor_id' => $m[1],
                    'priority'    => (int)$m[2],
                    'state'       => $m[3],
                    'dead_time'   => $m[4],
                    'address'     => $m[5],
                    'interface'   => $m[6],
                ];
            }
        } elseif (preg_match_all('/Neighbor\s+([0-9]{1,3}(?:\.[0-9]{1,3}){3})/i', $output, $matches)) {
            // Pattern 2: Detail format "Neighbor 192.168.1.2, interface address ..."
            foreach ($matches[1] as $nId) {
                $neighbors[] = [
                    'neighbor_id' => $nId,
                    'priority'    => 1,
                    'state'       => 'Full',
                    'dead_time'   => '—',
                    'address'     => $nId,
                    'interface'   => '—',
                ];
            }
        }

        return $neighbors;
    }

    /**
     * AJAX endpoint: return live OSPF status JSON.
     */
    public function ospfStatusJson(): \CodeIgniter\HTTP\ResponseInterface
    {
        $status = $this->getOspfStatus();
        $liveNeighbors = $this->extractLiveNeighbors($status, $this->ipc->isMock());
        $status['live_neighbors'] = $liveNeighbors;
        return $this->response->setJSON($status);
    }

    /**
     * BGP standalone page -> renders routing index with tab=bgp.
     */
    public function bgpPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'bgp';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    /**
     * OSPF standalone page -> renders routing index with tab=ospf.
     */
    public function ospfPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'ospf';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    /**
     * RIP standalone page -> renders routing index with tab=rip.
     */
    public function ripPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'rip';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    /**
     * IS-IS standalone page -> renders routing index with tab=isis.
     */
    public function isisPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'isis';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    /**
     * BFD standalone page -> renders routing index with tab=bfd.
     */
    public function bfdPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'bfd';
        return $this->index();
    }

    /**
     * VRF/PBR standalone page -> renders routing index with tab=vrf.
     */
    public function vrfPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'vrf';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    /**
     * Multi-WAN standalone page -> renders routing index with tab=multiwan.
     */
    public function multiwanPage(): string|\CodeIgniter\HTTP\ResponseInterface
    {
        $_GET['tab'] = 'multiwan';
        if ($sub = $this->request->getGet('sub')) {
            $_GET['sub'] = $sub;
        }
        return $this->index();
    }

    /**
     * Download compiled FRR configuration as frr.conf attachment.
     */
    public function downloadFrrConf(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $conf = (new \App\Libraries\FrrCompiler($db))->compile();

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="frr.conf"')
            ->setBody($conf);
    }

    /**
     * Reload FRR routing service and flash status.
     */
    public function reloadFrr(): \CodeIgniter\HTTP\RedirectResponse
    {
        [$key, $message] = $this->ipcFlash($this->applier()->frr('all'), 'FRRouting configuration applied and daemon reloaded.', 'FRR reload');
        return redirect()->to(base_url('routing?tab=frr'))->with($key, $message);
    }
}
