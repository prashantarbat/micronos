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

        // The front panel is always drawn from the ports the kernel reports
        // (/sys/class/net via `system.health`): enp7s0, eno1, wlp2s0 … exactly
        // as the machine the ISO was installed on has them. In development the
        // client answers with a simulated machine of the same shape.
        $interfaces = [];
        $__ipcPorts = \Config\Services::ipc();
        if ($__ipcPorts->isMock()) {
            $interfaces = (new \App\Libraries\LiveTelemetry($__ipcPorts))->snapshot()['interfaces'] ?? [];
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

        // 13b. Real telemetry. Everything above is the development sample set;
        // on an appliance the daemon's /proc + /sys readings replace it, and when
        // the daemon does not answer the page says so instead of showing samples.
        $ipc           = \Config\Services::ipc();
        $telemetryMode = $ipc->isMock() ? 'simulated' : 'live';
        $blockedRecent = null;
        if (! $ipc->isMock()) {
            $live = new \App\Libraries\LiveTelemetry($ipc);
            $snap = $live->snapshot();
            if ($snap === null) {
                $telemetryMode = 'unavailable';
                $uptimeSec = 0; $uptimeStr = 'Unknown';
                $cpuLoad1 = $cpuLoad5 = $cpuLoad15 = 0.0; $cpuPct = 0; $cpuModel = '—';
                $memTotal = $memUsed = $memPct = $memCache = 0;
                $diskTotal = $diskUsed = $diskPct = 0;
                $statesActive = 0; $statesMax = 0; $statesPct = 0;
                $temperature = null; $interfaces = []; $services = []; $recentLogs = []; $gateways = [];
            } else {
                $uptimeSec = $snap['uptime_sec'];
                $uptimeStr = $this->formatUptime($uptimeSec);
                [$cpuLoad1, $cpuLoad5, $cpuLoad15] = $snap['load'];
                $cpuCores  = $snap['cpu_cores'];
                $cpuPct    = $snap['cpu_pct'];
                $cpuModel  = $snap['cpu_model'] ?? '—';
                $memTotal  = $snap['mem_total'];
                $memUsed   = $snap['mem_used'];
                $memPct    = $snap['mem_pct'];
                $memCache  = 0;
                $swapTotal = $snap['swap_total'];
                $swapUsed  = $snap['swap_used'];
                $swapPct   = $swapTotal > 0 ? (int) round($swapUsed / $swapTotal * 100) : 0;
                $diskTotal = $snap['disk_total'];
                $diskUsed  = $snap['disk_used'];
                $diskPct   = $snap['disk_pct'];
                $statesActive = (int) ($snap['states'] ?? 0);
                $statesMax    = (int) ($snap['states_max'] ?? 0);
                $statesPct    = $statesMax > 0 ? round($statesActive / $statesMax * 100, 2) : 0;
                $temperature  = $snap['temperature'];
                $cryptoEngine = '—';
                $mbufActive   = 0; $mbufMax = 0; $mbufPct = 0;
                // Counts that had development defaults: on an appliance they
                // are what the database and the daemon actually hold.
                $dnsblFeedsCount = 0;
                $dnsblSignatures = 0;
                try {
                    $feeds           = $db->table('dns_feeds')->where('enabled', 1)->get()->getResultArray();
                    $dnsblFeedsCount = count($feeds);
                    $dnsblSignatures = (int) array_sum(array_column($feeds, 'entries_count'));
                } catch (\Throwable) {
                }
                $leaseList        = $ipc->call('dhcp.leases')['result']['leases'] ?? null;
                $dhcpActiveLeases = is_array($leaseList) ? count($leaseList) : 0;
                $interfaces   = $snap['interfaces'];
                $services     = $live->services() ?? [];
                $recentLogs   = $live->firewallLog() ?? [];
                $blockedRecent = count(array_filter($recentLogs, static fn ($l) => $l['action'] === 'block'));
                // Gateway quality comes from the SD-WAN probe when it runs; otherwise none is claimed.
                $gateways = [];
                $sla = \Config\Services::ipc()->call('sdwan.probe_status')['result']['data'] ?? [];
                foreach ((array) ($sla['members'] ?? $sla) as $name => $m) {
                    if (is_array($m) && isset($m['rtt_ms'])) {
                        $gateways[] = ['name' => (string) $name, 'iface' => (string) ($m['interface'] ?? $name),
                            'ip' => (string) ($m['gateway'] ?? '—'), 'monitor' => (string) ($m['target'] ?? '—'),
                            'rtt' => $m['rtt_ms'], 'rttsd' => $m['jitter_ms'] ?? null, 'loss' => $m['loss_pct'] ?? null,
                            'status' => ($m['loss_pct'] ?? 0) >= 50 ? 'offline' : 'online', 'tier' => '', 'subtext' => ''];
                    }
                }
            }
            $ifUp = count(array_filter($interfaces, fn ($i) => ($i['status'] ?? '') === 'up'));
        }

        // 14. Modular User Dashboard Layout (pfSense / FortiGate Style)
        $layoutModel = new \App\Models\DashboardLayoutModel();
        $userId      = (int) (session('user_id') ?? 1);
        $profile     = (string) ($this->request->getGet('profile') ?? 'default');
        $layoutRow   = $layoutModel->getLayoutForUser($userId, $profile);
        $userLayout  = $layoutRow['parsed_layout'] ?? $layoutModel->getDefaultLayout($profile);

        // Data for the widget cards (DHCP leases, connection states, plugins).
        $widgetData = $this->widgetData($ipc, $dhcpActiveLeases);

        return view('dashboard/index', [
            'widgetData'       => $widgetData,
            'title'            => 'Enterprise Firewall Dashboard — Micron OS',
            'systemInfo'       => $systemInfo,
            'uptimeStr'        => $uptimeStr,
            'bootSlot'         => (new \App\Libraries\BootSlot())->detect(),
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
            'checkinOn'        => $this->safeCall(fn () => (new \App\Libraries\InstallCheckin())->enabled(), false),
            'haSettings'       => $haSettings,
            'haVips'           => $haVips,
            'haRole'           => $haRole,
            'dnsblFeedsCount'  => $dnsblFeedsCount,
            'dnsblSignatures'  => $dnsblSignatures,
            'dhcpActiveLeases' => $dhcpActiveLeases,
            'dhcpPoolSize'     => $dhcpPoolSize,
            'lastRollback'     => $lastRollback,
            'userLayout'       => $userLayout,
            'userProfile'      => $profile,
            'telemetryMode'    => $telemetryMode,
            'blockedRecent'    => $blockedRecent,
        ]);
    }

    /**
     * Real data for the add-on widget cards. On an appliance every value comes
     * from the daemon or the database; the development client gets a small,
     * clearly simulated set so the cards can be laid out offline.
     *
     * @return array<string, mixed>
     */
    private function widgetData(\App\Libraries\IpcClient $ipc, int $dhcpActiveLeases): array
    {
        $mock = $ipc->isMock();

        // DHCP leases (Kea lease file).
        $leases = [];
        if (! $mock) {
            $leases = (array) ($ipc->call('dhcp.leases', ['limit' => 200])['result']['leases'] ?? []);
        } else {
            $leases = [
                ['ip' => '192.168.1.101', 'mac' => '52:54:00:5a:01:01', 'hostname' => 'workstation-01', 'expires' => date('Y-m-d H:i', time() + 3600)],
                ['ip' => '192.168.1.102', 'mac' => '52:54:00:5a:01:02', 'hostname' => 'nas', 'expires' => date('Y-m-d H:i', time() + 7200)],
                ['ip' => '192.168.1.105', 'mac' => '52:54:00:5a:01:05', 'hostname' => '', 'expires' => date('Y-m-d H:i', time() + 1800)],
            ];
        }
        usort($leases, static fn ($a, $b) => strcmp((string) ($b['expires'] ?? ''), (string) ($a['expires'] ?? '')));

        // Connection-tracking states, summarised by protocol and TCP state.
        $states = null;
        $resp   = $ipc->call('diag.conntrack_states', ['limit' => 2000]);
        if (is_array($resp['result']['states'] ?? null)) {
            $states = $resp['result']['states'];
        } elseif ($mock) {
            $states = [
                ['proto' => 'tcp', 'state' => 'ESTABLISHED'], ['proto' => 'tcp', 'state' => 'ESTABLISHED'],
                ['proto' => 'tcp', 'state' => 'TIME_WAIT'], ['proto' => 'udp', 'state' => 'ASSURED'],
                ['proto' => 'udp', 'state' => ''], ['proto' => 'icmp', 'state' => ''],
            ];
        }
        $byProto = [];
        $byState = [];
        foreach ((array) $states as $st) {
            $proto = strtoupper((string) ($st['proto'] ?? '?'));
            $byProto[$proto] = ($byProto[$proto] ?? 0) + 1;
            if ($proto === 'TCP' && ! empty($st['state'])) {
                $k           = strtoupper((string) $st['state']);
                $byState[$k] = ($byState[$k] ?? 0) + 1;
            }
        }
        arsort($byProto);
        arsort($byState);

        // Installed plugins.
        $plugins = [];
        try {
            $db = \Config\Database::connect();
            if ($db->tableExists('plugins')) {
                foreach ($db->table('plugins')->orderBy('name', 'ASC')->get()->getResultArray() as $row) {
                    $plugins[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'],
                        'version' => (string) ($row['version'] ?? ''), 'enabled' => (int) ($row['enabled'] ?? 0) === 1];
                }
            }
        } catch (\Throwable) {
        }

        return [
            'leases'       => array_slice($leases, 0, 8),
            'lease_count'  => $mock ? count($leases) : max($dhcpActiveLeases, count($leases)),
            'states_known' => $states !== null,
            'states_total' => $states === null ? null : count($states),
            'by_proto'     => $byProto,
            'by_state'     => array_slice($byState, 0, 6, true),
            'plugins'      => $plugins,
        ];
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
        $iface = (string) ($this->request->getGet('iface') ?? 'WAN');

        // On an appliance: the real rate of the requested port (by device or
        // configured name), measured from /sys counters by LiveTelemetry.
        $ipc = \Config\Services::ipc();
        if (! $ipc->isMock()) {
            $snap = (new \App\Libraries\LiveTelemetry($ipc))->snapshot();
            if ($snap === null) {
                return $this->response->setStatusCode(503)->setJSON(['iface' => $iface, 'mode' => 'unavailable',
                    'error' => 'The appliance daemon did not answer; telemetry is unavailable.']);
            }
            $port = null;
            foreach ($snap['interfaces'] as $p) {
                if (strcasecmp((string) $p['device'], $iface) === 0 || strcasecmp((string) $p['id'], $iface) === 0
                    || strcasecmp((string) $p['zone'], $iface) === 0) {
                    $port = $p;
                    break;
                }
            }

            return $this->response->setJSON([
                'iface'     => $iface,
                'mode'      => 'live',
                'timestamp' => date('H:i:s'),
                'in_mbps'   => $port['rx_mbps'] ?? null,
                'out_mbps'  => $port['tx_mbps'] ?? null,
                'in_pps'    => null,
                'out_pps'   => null,
                'cpu_pct'   => $snap['cpu_pct'],
                'states'    => $snap['states'],
            ]);
        }

        // Development (mock) client only: jitter simulation for live graphs
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

        // Dashboard card id → managed service id understood by the daemon.
        $map = ['nftables' => 'nftables', 'kea-dhcp4' => 'kea-dhcp4', 'unbound' => 'unbound', 'wireguard' => 'wireguard',
            'openvpn' => 'openvpn', 'frr-ospfd' => 'frr', 'frr' => 'frr', 'gateway-monitor' => 'gateway-monitor',
            'squid' => 'squid', 'keepalived' => 'keepalived', 'chrony' => 'chrony', 'suricata' => 'suricata',
            'strongswan' => 'strongswan', 'snmpd' => 'snmpd', 'rsyslog' => 'rsyslog'];
        if (! isset($map[$service]) || ! in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->response->setStatusCode(400)->setJSON(['csrf' => csrf_hash(), 'success' => false, 'message' => 'Invalid service or action']);
        }

        $response = \Config\Services::ipc()->call('system.service', ['service' => $map[$service], 'action' => $action]);
        $outcome  = $this->ipcOutcome($response);

        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                // See Cron::auditLog() for why every column is supplied.
                'admin_user'      => session('username') ?? 'admin',
                'client_ip'       => $this->request->getIPAddress(),
                'user_id'         => session('user_id') ?? 1,
                'username'        => session('username') ?? 'admin',
                'action_type'     => 'service',
                'target_resource' => 'System Services',
                'action'          => "service.{$action}",
                'target'          => $service,
                'new_state'       => "Dashboard {$action} of {$service}: " . ($outcome['ok'] ? 'ok' : 'failed — ' . $outcome['reason']),
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }

        if (! $outcome['ok']) {
            return $this->response->setStatusCode(502)->setJSON(['csrf' => csrf_hash(), 
                'success' => false, 'service' => $service, 'action' => $action,
                'message' => "Could not {$action} {$service}: " . $outcome['reason'],
            ]);
        }
        $state = (string) ($response['result']['state'] ?? '');

        return $this->response->setJSON(['csrf' => csrf_hash(), 
            'success' => true,
            'service' => $service,
            'action'  => $action,
            'state'   => $state,
            'message' => "{$service}: {$action} completed" . ($state !== '' ? " — now {$state}" : '') . '.',
        ]);
    }

    /**
     * Get current dashboard layout JSON for authenticated user.
     */
    public function layout(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId  = (int) (session('user_id') ?? 1);
        $profile = (string) ($this->request->getGet('profile') ?? 'default');
        $model   = new \App\Models\DashboardLayoutModel();
        $record  = $model->getLayoutForUser($userId, $profile);

        return $this->response->setJSON([
            'success' => true,
            'layout'  => $record['parsed_layout'] ?? $model->getDefaultLayout($profile),
        ]);
    }

    /**
     * Save updated dashboard layout for authenticated user.
     */
    public function saveLayout(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId  = (int) (session('user_id') ?? 1);
        $profile = (string) ($this->request->getPost('profile') ?? 'default');
        $jsonRaw = $this->request->getPost('layout');
        
        $layoutData = null;
        if (is_string($jsonRaw)) {
            $layoutData = json_decode($jsonRaw, true);
        } elseif (is_array($jsonRaw)) {
            $layoutData = $jsonRaw;
        }

        if (!$layoutData) {
            $rawBody = $this->request->getBody();
            if ($rawBody) {
                $decoded = json_decode($rawBody, true);
                $layoutData = $decoded['layout'] ?? $decoded;
                $profile = $decoded['profile'] ?? $profile;
            }
        }

        if (!is_array($layoutData)) {
            return $this->response->setStatusCode(400)->setJSON(['csrf' => csrf_hash(), 
                'success' => false,
                'message' => 'Invalid layout data structure',
            ]);
        }

        $model = new \App\Models\DashboardLayoutModel();
        $model->saveLayoutForUser($userId, $profile, $layoutData);

        return $this->response->setJSON(['csrf' => csrf_hash(), 
            'success' => true,
            'message' => 'Dashboard layout saved successfully',
            'layout'  => $layoutData,
        ]);
    }

    /**
     * Reset dashboard layout to factory default.
     */
    public function resetLayout(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId  = (int) (session('user_id') ?? 1);
        $profile = (string) ($this->request->getPost('profile') ?? 'default');
        $model   = new \App\Models\DashboardLayoutModel();
        $default = $model->getDefaultLayout($profile);
        $model->saveLayoutForUser($userId, $profile, $default);

        return $this->response->setJSON(['csrf' => csrf_hash(), 
            'success' => true,
            'message' => 'Dashboard layout reset to factory default',
            'layout'  => $default,
        ]);
    }

    /**
     * Unified real-time telemetry endpoint for live cards & interactive faceplate.
     */
    public function telemetry(): \CodeIgniter\HTTP\ResponseInterface
    {
        $ipc = \Config\Services::ipc();
        if (! $ipc->isMock()) {
            $snap = (new \App\Libraries\LiveTelemetry($ipc))->snapshot();
            if ($snap === null) {
                return $this->response->setStatusCode(503)->setJSON([
                    'timestamp' => date('H:i:s'), 'mode' => 'unavailable',
                    'error' => 'The appliance daemon did not answer; telemetry is unavailable.',
                ]);
            }

            return $this->response->setJSON([
                'timestamp' => date('H:i:s'),
                'mode'      => 'live',
                'gauges'    => [
                    'cpu_pct'     => $snap['cpu_pct'],
                    'mem_pct'     => $snap['mem_pct'],
                    'states'      => $snap['states'],
                    'mbuf_pct'    => null,
                    'disk_pct'    => $snap['disk_pct'],
                    'temperature' => $snap['temperature'],
                ],
                'ports'     => $snap['interfaces'],
                'traffic'   => [
                    'total_rx_mbps' => round(array_sum(array_column($snap['interfaces'], 'rx_mbps')), 2),
                    'total_tx_mbps' => round(array_sum(array_column($snap['interfaces'], 'tx_mbps')), 2),
                ],
            ]);
        }

        // Development (mock) client only: animated sample telemetry.
        $db = \Config\Database::connect();
        
        // 1. Ports: the simulated machine's real-shaped port list, with a
        //    little jitter on the counters of linked ports so the page animates.
        $ports = (new \App\Libraries\LiveTelemetry($ipc))->snapshot()['interfaces'] ?? [];
        foreach ($ports as &$p) {
            if (($p['status'] ?? '') === 'up' && empty($p['virtual'])) {
                $p['rx_mbps'] = round(20 + lcg_value() * 40, 1);
                $p['tx_mbps'] = round(8 + lcg_value() * 18, 1);
            }
        }
        unset($p);

        // 2. Resource gauges
        $cpuPct    = rand(12, 28);
        $memPct    = rand(38, 44);
        $states    = 1480 + rand(-25, 45);
        $mbufPct   = 0.48;
        $diskPct   = 15;
        $temp      = 37.5 + (lcg_value() - 0.5) * 1.5;

        // 3. Traffic throughput
        $totalRx = array_sum(array_column($ports, 'rx_mbps'));
        $totalTx = array_sum(array_column($ports, 'tx_mbps'));

        return $this->response->setJSON([
            'timestamp'  => date('H:i:s'),
            'mode'       => 'simulated',
            'gauges'     => [
                'cpu_pct'     => $cpuPct,
                'mem_pct'     => $memPct,
                'states'      => $states,
                'mbuf_pct'    => $mbufPct,
                'disk_pct'    => $diskPct,
                'temperature' => round($temp, 1),
            ],
            'ports'      => $ports,
            'traffic'    => [
                'total_rx_mbps' => round($totalRx, 1),
                'total_tx_mbps' => round($totalTx, 1),
            ],
        ]);
    }

    /**
     * Physical port action (administrative Up/Down toggle, port capture, diagnostic ping).
     */
    public function portAction(): \CodeIgniter\HTTP\ResponseInterface
    {
        $device = (string) $this->request->getPost('device');
        $action = (string) $this->request->getPost('action'); // toggle, ping, pcap

        if (empty($device)) {
            return $this->response->setStatusCode(400)->setJSON(['csrf' => csrf_hash(), 
                'success' => false,
                'message' => 'Device parameter required',
            ]);
        }

        $db = \Config\Database::connect();

        if ($action === 'toggle') {
            try {
                $iface = $db->table('interfaces')->where('physical_device', $device)->orWhere('id', $device)->get()->getRowArray();
                if ($iface) {
                    $newEnabled = ($iface['enabled'] ?? 1) ? 0 : 1;
                    $db->table('interfaces')->where('id', $iface['id'])->update(['enabled' => $newEnabled]);
                    $newState = $newEnabled ? 'UP' : 'DOWN';
                    return $this->response->setJSON(['csrf' => csrf_hash(), 
                        'success' => true,
                        'device'  => $device,
                        'enabled' => $newEnabled,
                        // Only the stored state changes here; the data plane follows on the next
                        // interface apply, and the message says exactly that.
                        'message' => "Port {$device} marked administratively {$newState}. Apply the change under Network → Interfaces to bring the link " . ($newEnabled ? 'up' : 'down') . '.',
                        'pending_apply' => true,
                    ]);
                }
            } catch (\Throwable $e) {}

            return $this->response->setStatusCode(404)->setJSON(['csrf' => csrf_hash(), 
                'success' => false,
                'device'  => $device,
                'message' => "{$device} is not a configured interface, so it cannot be toggled here. Configure it under Network → Interfaces.",
            ]);
        }

        if ($action === 'pcap') {
            if (! \Config\Services::ipc()->isMock()) {
                return $this->response->setStatusCode(400)->setJSON(['csrf' => csrf_hash(), 
                    'success' => false,
                    'device'  => $device,
                    'message' => 'Use Diagnostics → Packet Capture for a live capture on this port.',
                    'redirect'=> base_url('diag') . '?tab=pcap&interface=' . rawurlencode($device),
                ]);
            }
            $packets = [
                '[SIMULATED — development mode] ' . date('H:i:s') . ' IP 192.168.1.104.53812 > 1.1.1.1.53: A? example.com. (29)',
                '[SIMULATED — development mode] ' . date('H:i:s') . ' IP 1.1.1.1.53 > 192.168.1.104.53812: 1/0/0 A 93.184.216.34 (45)',
            ];

            return $this->response->setJSON(['csrf' => csrf_hash(), 'success' => true, 'device' => $device, 'packets' => $packets, 'mock' => true]);
        }

        return $this->response->setStatusCode(400)->setJSON(['csrf' => csrf_hash(), 
            'success' => false,
            'device'  => $device,
            'message' => "Unsupported port action '{$action}'.",
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
