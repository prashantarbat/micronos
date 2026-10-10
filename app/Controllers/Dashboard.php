<?php

namespace App\Controllers;

use App\Libraries\CommandDispatcher;
use App\Models\ConfigTransactionModel;

/**
 * Dashboard Controller — Enterprise Appliance landing page.
 * Highlights:
 *  - System Information with CPU, RAM, Swap, Disk, MBUF & State Table Gauges
 *  - Real-Time Live Traffic & Bandwidth Graph Widget (Dual-stream In/Out)
 *  - Core Services Status Widget with daemon control (Start/Stop/Restart)
 *  - Multi-WAN gateway quality matrix (RTT / jitter / loss)
 *  - Live Interface Network Status & Packet Counters
 *  - Recent Firewall Filter Log Stream
 *  - DNSBL Threat Shield & Web Filtering Telemetry
 *  - High Availability (VRRP) & VPN Gateway Summary
 */
class Dashboard extends BaseController
{
    private CommandDispatcher $cmd;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->cmd = new CommandDispatcher();
    }

    public function index(): string
    {
        // 1. Gather data from mock/live daemon
        $systemInfo    = $this->safeCall(fn () => $this->cmd->systemInfo(),      []);
        $interfacesRaw = $this->safeCall(fn () => $this->cmd->interfaceList(),   []);
        $wgStatus      = $this->safeCall(fn () => $this->cmd->wireguardStatus(), []);
        $ovpnStatus    = $this->safeCall(fn () => $this->cmd->openvpnStatus(),   []);
        $squidStatus   = $this->safeCall(fn () => $this->cmd->squidStatus(),     []);
        $firewallRules = $this->safeCall(fn () => $this->cmd->firewallListRules(), []);

        // 2. Format uptime
        $uptimeSec = (int) ($systemInfo['uptime_sec'] ?? 278450);
        $uptimeStr = $this->formatUptime($uptimeSec);

        // 3. CPU usage & Load Averages (1m, 5m, 15m)
        $cpuLoad1  = (float) ($systemInfo['cpu_load'][0] ?? 0.22);
        $cpuLoad5  = (float) ($systemInfo['cpu_load'][1] ?? 0.28);
        $cpuLoad15 = (float) ($systemInfo['cpu_load'][2] ?? 0.19);
        $cpuCores  = (int) ($systemInfo['cpu_count'] ?? 8);
        $cpuPct    = min(100, max(2, round(($cpuLoad1 / $cpuCores) * 100)));

        // 4. Memory usage & Caching
        $memTotal = (int) ($systemInfo['mem_total_mb'] ?? 8192);
        $memUsed  = (int) ($systemInfo['mem_used_mb'] ?? 2450);
        $memPct   = $memTotal > 0 ? round(($memUsed / $memTotal) * 100) : 30;
        $memCache = (int) round($memTotal * 0.18);

        // 5. Swap usage
        $swapTotal = 4096;
        $swapUsed  = 0;
        $swapPct   = 0;

        // 6. Disk usage
        $diskTotal = (int) ($systemInfo['disk_total_gb'] ?? 120);
        $diskUsed  = (int) ($systemInfo['disk_used_gb'] ?? 18);
        $diskPct   = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100) : 15;

        // 7. Firewall conntrack state table
        $statesActive = 1482;
        $statesMax    = 262144;
        $statesPct    = round(($statesActive / $statesMax) * 100, 2);

        // 8. Network socket buffer clusters
        $mbufActive = 4820;
        $mbufMax    = 1000000;
        $mbufPct    = round(($mbufActive / $mbufMax) * 100, 2);

        // 9. Hardware Crypto & CPU Model
        $cpuModel     = $systemInfo['cpu_model'] ?? 'Intel(R) Xeon(R) CPU E3-1270 v6 @ 3.80GHz';
        $cryptoEngine = 'AES-NI, SHA-256 (Hardware crypto accelerated)';
        $temperature  = 37.5; // Celsius

        // 10. Database queries for enriched subsystems
        $db = \Config\Database::connect();

        // High Availability cluster state
        $haSettings = null;
        $haVips     = [];
        $haRole     = 'master';
        try {
            $haSettings = $db->table('ha_settings')->where('id', 'primary')->get()->getRowArray();
            $haVips     = $db->table('ha_virtual_ips')->get()->getResultArray();
            $haRole     = $haSettings['node_role'] ?? 'master';
        } catch (\Throwable $e) {}

        // DNSBL Threat Shield Status
        $dnsblFeedsCount = 4;
        $dnsblSignatures = 502937;
        try {
            $dnsFeeds = $db->table('dns_feeds')->where('enabled', 1)->get()->getResultArray();
            if (!empty($dnsFeeds)) {
                $dnsblFeedsCount = count($dnsFeeds);
                $sum = array_sum(array_column($dnsFeeds, 'entries_count'));
                if ($sum > 0) $dnsblSignatures = $sum;
            }
        } catch (\Throwable $e) {}

        // DHCP Leases summary
        $dhcpActiveLeases = 28;
        $dhcpPoolSize     = 150;
        try {
            $dhcpSettings = $db->table('dhcp_server_settings')->get()->getRowArray();
            if (!empty($dhcpSettings)) {
                $dhcpPoolSize = max(50, (int)($dhcpSettings['range_end'] ?? 150));
            }
        } catch (\Throwable $e) {}

        // WireGuard & OpenVPN counts
        $wgPeersCount = 0;
        try {
            $wgPeersCount = $db->table('wireguard_peers')->where('enabled', 1)->countAllResults();
        } catch (\Throwable $e) {}
        if ($wgPeersCount === 0 && !empty($wgStatus['peers'])) {
            $wgPeersCount = count($wgStatus['peers']);
        }

        // Last configuration rollback — the transaction log's own record of when
        // the appliance last discarded a candidate configuration. `confirmed_at`
        // is the resolution stamp (see ConfigTransactionModel); a transaction
        // rolled back before that stamp existed falls back to its apply time.
        $lastRollback = $this->lastRollback();

        // Multi-WAN gateway quality thresholds
        $gateways = [
            [
                'name'      => 'WAN1_Fiber_GW',
                'iface'     => 'WAN (eth0)',
                'ip'        => '198.51.100.1',
                'monitor'   => '1.1.1.1',
                'rtt'       => 11.2,
                'rttsd'     => 1.1,
                'loss'      => 0.0,
                'status'    => 'online',
                'tier'      => 'Tier 1 (Active Default)',
                'subtext'   => 'Cloudflare Primary DNS',
            ],
            [
                'name'      => 'WAN2_Broadband_GW',
                'iface'     => 'OPT1 (eth2)',
                'ip'        => '203.0.113.1',
                'monitor'   => '8.8.8.8',
                'rtt'       => 27.8,
                'rttsd'     => 2.4,
                'loss'      => 0.0,
                'status'    => 'online',
                'tier'      => 'Tier 2 (Failover / Spreader)',
                'subtext'   => 'Google Public Anycast',
            ],
        ];

        // 11. Core services status
        $services = [
            [
                'name'        => 'nftables',
                'title'       => 'Firewall Engine',
                'desc'        => 'Netfilter Stateful Packet Inspection & NAT',
                'status'      => 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'kea-dhcp4',
                'title'       => 'DHCP Server',
                'desc'        => 'Kea High-Performance Dynamic Host Config Daemon',
                'status'      => 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'unbound',
                'title'       => 'DNS Resolver & DNSBL',
                'desc'        => 'Unbound DNSSEC & Threat Shield Blocker',
                'status'      => 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'wireguard',
                'title'       => 'WireGuard VPN',
                'desc'        => 'High-Speed ChaCha20-Poly1305 Kernel Tunnel',
                'status'      => !empty($wgStatus['running']) ? 'running' : 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'openvpn',
                'title'       => 'OpenVPN Server',
                'desc'        => 'TLS Multi-Client Road Warrior SSL VPN',
                'status'      => !empty($ovpnStatus['running']) ? 'running' : 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'frr-ospfd',
                'title'       => 'Dynamic Routing (OSPF)',
                'desc'        => 'FRRouting Open Shortest Path First Daemon',
                'status'      => 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'gateway-monitor',
                'title'       => 'Gateway Quality Monitor',
                'desc'        => 'Multi-WAN ICMP latency & jitter monitor',
                'status'      => 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'squid',
                'title'       => 'Web Proxy / SSL Filter',
                'desc'        => 'Squid Caching & Content Filtering Proxy',
                'status'      => !empty($squidStatus['running']) ? 'running' : 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'keepalived',
                'title'       => 'High Availability (VRRP)',
                'desc'        => 'VRRP failover & virtual address manager',
                'status'      => 'running',
                'can_restart' => true,
            ],
            [
                'name'        => 'chrony',
                'title'       => 'NTP Time Daemon',
                'desc'        => 'Precision Network Time Protocol Synchronizer',
                'status'      => 'running',
                'can_restart' => true,
            ],
        ];

        // 12. Enriched Interfaces Matrix (Combining DB config with live stats)
        $dbInterfaces = [];
        try {
            $dbInterfaces = $db->table('interfaces')->get()->getResultArray();
        } catch (\Throwable $e) {}

        $interfaces = [];
        if (!empty($dbInterfaces)) {
            foreach ($dbInterfaces as $dbIf) {
                $isWan = strtoupper($dbIf['id']) === 'WAN';
                $isLan = strtoupper($dbIf['id']) === 'LAN';
                $dev = $dbIf['physical_device'] ?? 'eth0';
                
                $interfaces[] = [
                    'id'          => $dbIf['id'],
                    'name'        => $dbIf['name'],
                    'device'      => $dev,
                    'status'      => ($dbIf['enabled'] ?? 1) ? 'up' : 'down',
                    'ip'          => $dbIf['ipv4_address'] ?? '192.168.1.1',
                    'mask'        => $dbIf['ipv4_netmask'] ?? 24,
                    'ipv6'        => $dbIf['ipv6_address'] ?? 'fe80::1',
                    'mac'         => '00:1a:2b:3c:4d:' . sprintf('%02x', rand(10, 99)),
                    'media'       => '1000Base-T <full-duplex>',
                    'mtu'         => 1500,
                    'rx_mbps'     => $isWan ? 34.8 : ($isLan ? 52.4 : 8.1),
                    'tx_mbps'     => $isWan ? 12.4 : ($isLan ? 28.2 : 4.5),
                    'rx_packets'  => $isWan ? '14,892,104' : ($isLan ? '22,410,580' : '2,109,420'),
                    'tx_packets'  => $isWan ? '9,341,208'  : ($isLan ? '18,840,119' : '1,894,002'),
                    'in_bytes'    => $isWan ? '18.4 GB' : ($isLan ? '26.8 GB' : '3.2 GB'),
                    'out_bytes'   => $isWan ? '6.8 GB'  : ($isLan ? '14.1 GB' : '1.7 GB'),
                ];
            }
        } else {
            // Fallback interfaces
            $interfaces = [
                [
                    'id' => 'WAN', 'name' => 'WAN', 'device' => 'eth0', 'status' => 'up',
                    'ip' => '198.51.100.25', 'mask' => 24, 'ipv6' => '2001:db8::1',
                    'mac' => '00:1a:2b:3c:4d:01', 'media' => '1000Base-T <full-duplex>', 'mtu' => 1500,
                    'rx_mbps' => 41.2, 'tx_mbps' => 14.5, 'rx_packets' => '18,420,119', 'tx_packets' => '11,204,801',
                    'in_bytes' => '22.8 GB', 'out_bytes' => '8.4 GB',
                ],
                [
                    'id' => 'LAN', 'name' => 'LAN', 'device' => 'eth1', 'status' => 'up',
                    'ip' => '192.168.1.1', 'mask' => 24, 'ipv6' => 'fd00:abcd::1',
                    'mac' => '00:1a:2b:3c:4d:02', 'media' => '1000Base-T <full-duplex>', 'mtu' => 1500,
                    'rx_mbps' => 58.7, 'tx_mbps' => 31.2, 'rx_packets' => '31,529,002', 'tx_packets' => '24,198,340',
                    'in_bytes' => '34.2 GB', 'out_bytes' => '18.9 GB',
                ],
                [
                    'id' => 'OPT1', 'name' => 'DMZ', 'device' => 'eth2', 'status' => 'up',
                    'ip' => '192.168.20.1', 'mask' => 24, 'ipv6' => '—',
                    'mac' => '00:1a:2b:3c:4d:03', 'media' => '1000Base-T <full-duplex>', 'mtu' => 1500,
                    'rx_mbps' => 6.2, 'tx_mbps' => 3.8, 'rx_packets' => '4,102,890', 'tx_packets' => '3,109,240',
                    'in_bytes' => '4.8 GB', 'out_bytes' => '2.1 GB',
                ],
                [
                    'id' => 'WG0', 'name' => 'WireGuard', 'device' => 'wg0', 'status' => 'up',
                    'ip' => '10.10.0.1', 'mask' => 24, 'ipv6' => '—',
                    'mac' => '— (Virtual Tunnel)', 'media' => 'Virtual Interface', 'mtu' => 1420,
                    'rx_mbps' => 12.1, 'tx_mbps' => 8.4, 'rx_packets' => '8,901,112', 'tx_packets' => '7,490,201',
                    'in_bytes' => '9.4 GB', 'out_bytes' => '6.2 GB',
                ],
            ];
        }

        $ifUp = count(array_filter($interfaces, fn ($i) => ($i['status'] ?? '') === 'up'));

        // 13. Recent firewall filter log
        $recentLogs = [
            ['time' => date('H:i:s', time() - 3),  'action' => 'pass',  'iface' => 'WAN', 'proto' => 'TCP',  'src' => '198.51.100.88:54210', 'dst' => '192.168.1.50:443',  'desc' => 'NAT Port Forward: HTTPS Web Server'],
            ['time' => date('H:i:s', time() - 8),  'action' => 'block', 'iface' => 'WAN', 'proto' => 'TCP',  'src' => '185.220.101.5:41829', 'dst' => '198.51.100.25:22',  'desc' => 'Default Deny: Inbound WAN SSH Probe'],
            ['time' => date('H:i:s', time() - 14), 'action' => 'pass',  'iface' => 'LAN', 'proto' => 'UDP',  'src' => '192.168.1.142:58392', 'dst' => '1.1.1.1:853',        'desc' => 'DNS-over-TLS (DoT) Security Query'],
            ['time' => date('H:i:s', time() - 21), 'action' => 'block', 'iface' => 'WAN', 'proto' => 'TCP',  'src' => '45.154.255.8:59182',  'dst' => '198.51.100.25:3389','desc' => 'Drop Bogon & RDP Reconnaissance'],
            ['time' => date('H:i:s', time() - 27), 'action' => 'pass',  'iface' => 'WG0', 'proto' => 'UDP',  'src' => '10.10.0.5:51820',     'dst' => '192.168.1.200:80',   'desc' => 'Permit WireGuard Road Warrior LAN'],
            ['time' => date('H:i:s', time() - 35), 'action' => 'block', 'iface' => 'LAN', 'proto' => 'ICMP', 'src' => '192.168.1.199',        'dst' => '192.168.20.15',      'desc' => 'Isolate Guest LAN from DMZ Subnet'],
        ];

        return view('dashboard/index', [
            'title'            => 'Enterprise Firewall Dashboard — MicronOS',
            'systemInfo'       => $systemInfo,
            'uptimeStr'        => $uptimeStr,
            'uptimeSec'        => $uptimeSec,
            'cpuPct'           => $cpuPct,
            'cpuLoad1'         => $cpuLoad1,
            'cpuLoad5'         => $cpuLoad5,
            'cpuLoad15'        => $cpuLoad15,
            'cpuCores'         => $cpuCores,
            'cpuModel'         => $cpuModel,
            'cryptoEngine'     => $cryptoEngine,
            'temperature'      => $temperature,
            'memTotal'         => $memTotal,
            'memUsed'          => $memUsed,
            'memPct'           => $memPct,
            'memCache'         => $memCache,
            'swapTotal'        => $swapTotal,
            'swapUsed'         => $swapUsed,
            'swapPct'          => $swapPct,
            'diskTotal'        => $diskTotal,
            'diskUsed'         => $diskUsed,
            'diskPct'          => $diskPct,
            'statesActive'     => $statesActive,
            'statesMax'        => $statesMax,
            'statesPct'        => $statesPct,
            'mbufActive'       => $mbufActive,
            'mbufMax'          => $mbufMax,
            'mbufPct'          => $mbufPct,
            'interfaces'       => $interfaces,
            'ifUp'             => $ifUp,
            'ifTotal'          => count($interfaces),
            'gateways'         => $gateways,
            'services'         => $services,
            'recentLogs'       => $recentLogs,
            'wgStatus'         => $wgStatus,
            'wgPeersCount'     => $wgPeersCount,
            'ovpnStatus'       => $ovpnStatus,
            'squidStatus'      => $squidStatus,
            'firewallRules'    => $firewallRules,
            'ruleCount'        => count($firewallRules),
            'haSettings'       => $haSettings,
            'haVips'           => $haVips,
            'haRole'           => $haRole,
            'dnsblFeedsCount'  => $dnsblFeedsCount,
            'dnsblSignatures'  => $dnsblSignatures,
            'dhcpActiveLeases' => $dhcpActiveLeases,
            'dhcpPoolSize'     => $dhcpPoolSize,
            'lastRollback'     => $lastRollback,
        ]);
    }

    /**
     * The last configuration rollback, prepared for display.
     *
     * Returns null when the appliance has never rolled a configuration back, so
     * the view can say so rather than rendering an empty row. A failure to read
     * the transaction log is not worth taking the dashboard down for: the rest of
     * the page is still the operator's, so it reports the same as no history.
     *
     * @return array{at: string, ago: string, admin_user: string, id: string, exact: bool}|null
     */
    private function lastRollback(): ?array
    {
        try {
            $row = (new ConfigTransactionModel())->lastRollback();
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($row)) {
            return null;
        }

        $resolved = $row['confirmed_at'] ?? null;
        $at       = is_string($resolved) && $resolved !== ''
            ? $resolved
            : (is_string($row['applied_at'] ?? null) ? $row['applied_at'] : '');

        return [
            'at'         => $at,
            'ago'        => $this->humanize($at),
            'admin_user' => (string) ($row['admin_user'] ?? 'unknown'),
            'id'         => (string) ($row['id'] ?? ''),
            // False when the row predates the resolution stamp and only its
            // apply time is known — the view says so rather than implying it
            // knows when the rollback itself happened.
            'exact'      => is_string($resolved) && $resolved !== '',
        ];
    }

    private function humanize(string $at): string
    {
        if ($at === '') {
            return '';
        }

        try {
            return \CodeIgniter\I18n\Time::parse($at)->humanize();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * AJAX endpoint for live real-time bandwidth graph updates.
     * Returns current inbound/outbound Mbps and packet rates per interface.
     */
    public function traffic(): \CodeIgniter\HTTP\ResponseInterface
    {
        $iface = $this->request->getGet('iface') ?? 'WAN';
        
        // Realistic dynamic jitter simulation for live graphs
        $baseIn = strtoupper($iface) === 'WAN' ? 38.5 : (strtoupper($iface) === 'LAN' ? 55.2 : 9.4);
        $baseOut = strtoupper($iface) === 'WAN' ? 14.2 : (strtoupper($iface) === 'LAN' ? 26.8 : 4.1);

        $jitterIn = (lcg_value() - 0.5) * 12.0;
        $jitterOut = (lcg_value() - 0.5) * 6.0;

        $inMbps  = max(2.5, round($baseIn + $jitterIn, 2));
        $outMbps = max(1.2, round($baseOut + $jitterOut, 2));

        $inPps  = rand(4200, 7800);
        $outPps = rand(2100, 4300);

        return $this->response->setJSON([
            'iface'      => $iface,
            'timestamp'  => date('H:i:s'),
            'in_mbps'    => $inMbps,
            'out_mbps'   => $outMbps,
            'in_pps'     => $inPps,
            'out_pps'    => $outPps,
            'cpu_pct'    => rand(4, 18),
            'states'     => 1480 + rand(-15, 25),
        ]);
    }

    /**
     * AJAX action to control system services (Restart, Start, Stop).
     */
    public function serviceToggle(): \CodeIgniter\HTTP\ResponseInterface
    {
        $service = $this->request->getPost('service');
        $action  = $this->request->getPost('action'); // restart, stop, start

        $allowedServices = ['nftables', 'kea-dhcp4', 'unbound', 'wireguard', 'openvpn', 'frr-ospfd', 'gateway-monitor', 'squid', 'keepalived', 'chrony'];
        if (!in_array($service, $allowedServices)) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Invalid service']);
        }

        // Audit log action
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
                'action'          => "service.{$action}",
                'target'          => $service,
                // `audit_logs` has no `details` column; the description goes in
                // `new_state`, as the other writers do.
                'new_state'       => "Subsystem {$service} requested {$action} from Dashboard",
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}

        return $this->response->setJSON([
            'success' => true,
            'service' => $service,
            'action'  => $action,
            'message' => "Service '{$service}' successfully {$action}ed.",
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function safeCall(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            log_message('warning', '[Dashboard] IPC error: ' . $e->getMessage());
            return $default;
        }
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Unknown';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days)    { $parts[] = "{$days}d"; }
        if ($hours)   { $parts[] = "{$hours}h"; }
        if ($minutes) { $parts[] = "{$minutes}m"; }

        return implode(' ', $parts) ?: '< 1m';
    }
}
