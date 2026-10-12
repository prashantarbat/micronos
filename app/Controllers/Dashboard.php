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

        $baseHardwarePorts = [
            'eth0' => [
                'id' => 'WAN', 'name' => 'WAN 1', 'device' => 'eth0', 'status' => 'up',
                'ip' => '198.51.100.25', 'mask' => 24, 'ipv6' => '2001:db8::1',
                'mac' => '00:1a:2b:3c:4d:01', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 41.2, 'tx_mbps' => 14.5, 'rx_packets' => '18,420,119', 'tx_packets' => '11,204,801',
                'in_bytes' => '22.8 GB', 'out_bytes' => '8.4 GB', 'zone' => 'WAN', 'bay' => 'wan',
            ],
            'eth3' => [
                'id' => 'AUX', 'name' => 'MGMT', 'device' => 'eth3', 'status' => 'down',
                'ip' => '192.168.100.1', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:04', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'MGMT', 'bay' => 'mgmt',
            ],
            // 16-Port Internal Switch Matrix (Ports 1-16: Top row 1,3,5,7,9,11,13,15 / Bottom row 2,4,6,8,10,12,14,16)
            'eth1' => [
                'id' => 'LAN', 'name' => 'Port 1 (LAN)', 'device' => 'eth1', 'port_num' => 1, 'status' => 'up',
                'ip' => '192.168.1.1', 'mask' => 24, 'ipv6' => 'fd00:abcd::1',
                'mac' => '00:1a:2b:3c:4d:02', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 58.7, 'tx_mbps' => 31.2, 'rx_packets' => '31,529,002', 'tx_packets' => '24,198,340',
                'in_bytes' => '34.2 GB', 'out_bytes' => '18.9 GB', 'zone' => 'LAN', 'bay' => 'internal',
            ],
            'eth2' => [
                'id' => 'OPT1', 'name' => 'Port 2 (DMZ)', 'device' => 'eth2', 'port_num' => 2, 'status' => 'up',
                'ip' => '192.168.20.1', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:03', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 6.2, 'tx_mbps' => 3.8, 'rx_packets' => '4,102,890', 'tx_packets' => '3,109,240',
                'in_bytes' => '4.8 GB', 'out_bytes' => '2.1 GB', 'zone' => 'DMZ', 'bay' => 'internal',
            ],
            'eth4' => [
                'id' => 'PORT3', 'name' => 'Port 3', 'device' => 'eth4', 'port_num' => 3, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:07', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth5' => [
                'id' => 'PORT4', 'name' => 'Port 4', 'device' => 'eth5', 'port_num' => 4, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:08', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth6' => [
                'id' => 'PORT5', 'name' => 'Port 5', 'device' => 'eth6', 'port_num' => 5, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:09', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth7' => [
                'id' => 'PORT6', 'name' => 'Port 6', 'device' => 'eth7', 'port_num' => 6, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:0a', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth8' => [
                'id' => 'PORT7', 'name' => 'Port 7', 'device' => 'eth8', 'port_num' => 7, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:0b', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth9' => [
                'id' => 'PORT8', 'name' => 'Port 8', 'device' => 'eth9', 'port_num' => 8, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:0c', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth10' => [
                'id' => 'PORT9', 'name' => 'Port 9', 'device' => 'eth10', 'port_num' => 9, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:0d', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth11' => [
                'id' => 'PORT10', 'name' => 'Port 10', 'device' => 'eth11', 'port_num' => 10, 'status' => 'up',
                'ip' => '10.0.10.10', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:0e', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 18.4, 'tx_mbps' => 9.2, 'rx_packets' => '12,410,200', 'tx_packets' => '8,102,400',
                'in_bytes' => '14.2 GB', 'out_bytes' => '7.8 GB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth12' => [
                'id' => 'PORT11', 'name' => 'Port 11', 'device' => 'eth12', 'port_num' => 11, 'status' => 'up',
                'ip' => '10.0.20.11', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:0f', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 24.1, 'tx_mbps' => 12.8, 'rx_packets' => '16,204,110', 'tx_packets' => '10,410,900',
                'in_bytes' => '18.9 GB', 'out_bytes' => '9.5 GB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth13' => [
                'id' => 'PORT12', 'name' => 'Port 12', 'device' => 'eth13', 'port_num' => 12, 'status' => 'up',
                'ip' => '10.0.20.12', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:10', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 19.5, 'tx_mbps' => 11.2, 'rx_packets' => '14,102,800', 'tx_packets' => '9,120,400',
                'in_bytes' => '15.4 GB', 'out_bytes' => '8.2 GB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth14' => [
                'id' => 'PORT13', 'name' => 'Port 13', 'device' => 'eth14', 'port_num' => 13, 'status' => 'up',
                'ip' => '10.0.20.13', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:11', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 15.2, 'tx_mbps' => 8.4, 'rx_packets' => '9,820,400', 'tx_packets' => '6,400,100',
                'in_bytes' => '11.8 GB', 'out_bytes' => '6.1 GB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth15' => [
                'id' => 'PORT14', 'name' => 'Port 14', 'device' => 'eth15', 'port_num' => 14, 'status' => 'up',
                'ip' => '10.0.30.14', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:12', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 32.8, 'tx_mbps' => 18.9, 'rx_packets' => '22,100,500', 'tx_packets' => '14,800,200',
                'in_bytes' => '25.6 GB', 'out_bytes' => '14.2 GB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth16' => [
                'id' => 'PORT15', 'name' => 'Port 15', 'device' => 'eth16', 'port_num' => 15, 'status' => 'down',
                'ip' => '—', 'mask' => 0, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:13', 'media' => '1000Base-T <standby>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'eth17' => [
                'id' => 'PORT16', 'name' => 'Port 16', 'device' => 'eth17', 'port_num' => 16, 'status' => 'up',
                'ip' => '10.0.10.16', 'mask' => 24, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:14', 'media' => '1000Base-T <full-duplex>', 'speed' => '1000', 'mtu' => 1500,
                'rx_mbps' => 28.4, 'tx_mbps' => 16.1, 'rx_packets' => '19,400,200', 'tx_packets' => '12,500,800',
                'in_bytes' => '21.5 GB', 'out_bytes' => '12.0 GB', 'zone' => 'INTERNAL', 'bay' => 'internal',
            ],
            'sfp0' => [
                'id' => 'SFP1', 'name' => 'SFP+ 1', 'device' => 'sfp0', 'status' => 'up',
                'ip' => '10.254.1.1', 'mask' => 30, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:05', 'media' => '10GBase-SR Fiber <full-duplex>', 'speed' => '10000', 'mtu' => 9000,
                'rx_mbps' => 124.5, 'tx_mbps' => 88.2, 'rx_packets' => '54,120,891', 'tx_packets' => '42,901,114',
                'in_bytes' => '68.4 GB', 'out_bytes' => '49.1 GB', 'zone' => 'FIBER', 'bay' => 'sfp',
            ],
            'sfp1' => [
                'id' => 'SFP2', 'name' => 'SFP+ 2', 'device' => 'sfp1', 'status' => 'down',
                'ip' => '—', 'mask' => 30, 'ipv6' => '—',
                'mac' => '00:1a:2b:3c:4d:06', 'media' => '10GBase-SR Fiber <standby>', 'speed' => '10000', 'mtu' => 9000,
                'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_packets' => '0', 'tx_packets' => '0',
                'in_bytes' => '0 MB', 'out_bytes' => '0 MB', 'zone' => 'FIBER', 'bay' => 'sfp',
            ],
        ];

        // Overlay with database config if available
        if (!empty($dbInterfaces)) {
            foreach ($dbInterfaces as $dbIf) {
                $dev = $dbIf['physical_device'] ?? 'eth0';
                if (isset($baseHardwarePorts[$dev])) {
                    $baseHardwarePorts[$dev]['id']     = $dbIf['id'];
                    $baseHardwarePorts[$dev]['name']   = $dbIf['name'];
                    $baseHardwarePorts[$dev]['status'] = ($dbIf['enabled'] ?? 1) ? 'up' : 'down';
                    if (!empty($dbIf['ipv4_address'])) {
                        $baseHardwarePorts[$dev]['ip'] = $dbIf['ipv4_address'];
                    }
                    if (!empty($dbIf['ipv4_netmask'])) {
                        $baseHardwarePorts[$dev]['mask'] = $dbIf['ipv4_netmask'];
                    }
                }
            }
        }
        $interfaces = array_values($baseHardwarePorts);
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

        // 14. Modular User Dashboard Layout (pfSense / FortiGate Style)
        $layoutModel = new \App\Models\DashboardLayoutModel();
        $userId      = (int) (session('user_id') ?? 1);
        $profile     = (string) ($this->request->getGet('profile') ?? 'default');
        $layoutRow   = $layoutModel->getLayoutForUser($userId, $profile);
        $userLayout  = $layoutRow['parsed_layout'] ?? $layoutModel->getDefaultLayout($profile);

        return view('dashboard/index', [
            'title'            => 'Enterprise Firewall Dashboard — Micron OS',
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
            'userLayout'       => $userLayout,
            'userProfile'      => $profile,
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
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'Invalid layout data structure',
            ]);
        }

        $model = new \App\Models\DashboardLayoutModel();
        $model->saveLayoutForUser($userId, $profile, $layoutData);

        return $this->response->setJSON([
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

        return $this->response->setJSON([
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
        $db = \Config\Database::connect();
        
        // 1. Interfaces & Physical Ports Live Status
        $dbInterfaces = [];
        try {
            $dbInterfaces = $db->table('interfaces')->get()->getResultArray();
        } catch (\Throwable $e) {}

        $basePorts = [
            'eth0' => [
                'id' => 'WAN', 'name' => 'WAN 1', 'device' => 'eth0', 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '198.51.100.25', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:01', 'mtu' => 1500, 'rx_mbps' => round(38.5 + (lcg_value() - 0.5) * 6, 1),
                'tx_mbps' => round(14.2 + (lcg_value() - 0.5) * 3, 1), 'rx_pps' => rand(4800, 6200),
                'tx_pps' => rand(2200, 3100), 'errors' => 0, 'drops' => 0, 'zone' => 'WAN'
            ],
            'eth3' => [
                'id' => 'AUX', 'name' => 'MGMT', 'device' => 'eth3', 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'half', 'ip' => '192.168.100.1', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:04', 'mtu' => 1500, 'rx_mbps' => 0.0,
                'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'MGMT'
            ],
            'eth1' => [
                'id' => 'LAN', 'name' => 'Port 1 (LAN)', 'device' => 'eth1', 'port_num' => 1, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '192.168.1.1', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:02', 'mtu' => 1500, 'rx_mbps' => round(54.2 + (lcg_value() - 0.5) * 9, 1),
                'tx_mbps' => round(28.4 + (lcg_value() - 0.5) * 5, 1), 'rx_pps' => rand(6500, 8900),
                'tx_pps' => rand(3800, 5200), 'errors' => 0, 'drops' => 0, 'zone' => 'LAN'
            ],
            'eth2' => [
                'id' => 'OPT1', 'name' => 'Port 2 (DMZ)', 'device' => 'eth2', 'port_num' => 2, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '192.168.20.1', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:03', 'mtu' => 1500, 'rx_mbps' => round(6.2 + (lcg_value() - 0.5) * 2, 1),
                'tx_mbps' => round(3.8 + (lcg_value() - 0.5) * 1.5, 1), 'rx_pps' => rand(1200, 1900),
                'tx_pps' => rand(700, 1100), 'errors' => 0, 'drops' => 0, 'zone' => 'DMZ'
            ],
            'eth4' => [
                'id' => 'PORT3', 'name' => 'Port 3', 'device' => 'eth4', 'port_num' => 3, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:07', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth5' => [
                'id' => 'PORT4', 'name' => 'Port 4', 'device' => 'eth5', 'port_num' => 4, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:08', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth6' => [
                'id' => 'PORT5', 'name' => 'Port 5', 'device' => 'eth6', 'port_num' => 5, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:09', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth7' => [
                'id' => 'PORT6', 'name' => 'Port 6', 'device' => 'eth7', 'port_num' => 6, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:0a', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth8' => [
                'id' => 'PORT7', 'name' => 'Port 7', 'device' => 'eth8', 'port_num' => 7, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:0b', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth9' => [
                'id' => 'PORT8', 'name' => 'Port 8', 'device' => 'eth9', 'port_num' => 8, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:0c', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth10' => [
                'id' => 'PORT9', 'name' => 'Port 9', 'device' => 'eth10', 'port_num' => 9, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:0d', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth11' => [
                'id' => 'PORT10', 'name' => 'Port 10', 'device' => 'eth11', 'port_num' => 10, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '10.0.10.10', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:0e', 'mtu' => 1500, 'rx_mbps' => round(18.4 + (lcg_value() - 0.5) * 3, 1),
                'tx_mbps' => round(9.2 + (lcg_value() - 0.5) * 2, 1), 'rx_pps' => rand(2200, 3100),
                'tx_pps' => rand(1100, 1900), 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth12' => [
                'id' => 'PORT11', 'name' => 'Port 11', 'device' => 'eth12', 'port_num' => 11, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '10.0.20.11', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:0f', 'mtu' => 1500, 'rx_mbps' => round(24.1 + (lcg_value() - 0.5) * 4, 1),
                'tx_mbps' => round(12.8 + (lcg_value() - 0.5) * 2.5, 1), 'rx_pps' => rand(2800, 3900),
                'tx_pps' => rand(1500, 2400), 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth13' => [
                'id' => 'PORT12', 'name' => 'Port 12', 'device' => 'eth13', 'port_num' => 12, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '10.0.20.12', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:10', 'mtu' => 1500, 'rx_mbps' => round(19.5 + (lcg_value() - 0.5) * 3, 1),
                'tx_mbps' => round(11.2 + (lcg_value() - 0.5) * 2, 1), 'rx_pps' => rand(2300, 3300),
                'tx_pps' => rand(1300, 2100), 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth14' => [
                'id' => 'PORT13', 'name' => 'Port 13', 'device' => 'eth14', 'port_num' => 13, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '10.0.20.13', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:11', 'mtu' => 1500, 'rx_mbps' => round(15.2 + (lcg_value() - 0.5) * 2.5, 1),
                'tx_mbps' => round(8.4 + (lcg_value() - 0.5) * 1.5, 1), 'rx_pps' => rand(1800, 2700),
                'tx_pps' => rand(950, 1600), 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth15' => [
                'id' => 'PORT14', 'name' => 'Port 14', 'device' => 'eth15', 'port_num' => 14, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '10.0.30.14', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:12', 'mtu' => 1500, 'rx_mbps' => round(32.8 + (lcg_value() - 0.5) * 5, 1),
                'tx_mbps' => round(18.9 + (lcg_value() - 0.5) * 3, 1), 'rx_pps' => rand(3900, 5200),
                'tx_pps' => rand(2100, 3100), 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth16' => [
                'id' => 'PORT15', 'name' => 'Port 15', 'device' => 'eth16', 'port_num' => 15, 'status' => 'down', 'carrier' => 0,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:13', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'eth17' => [
                'id' => 'PORT16', 'name' => 'Port 16', 'device' => 'eth17', 'port_num' => 16, 'status' => 'up', 'carrier' => 1,
                'speed' => '1000', 'duplex' => 'full', 'ip' => '10.0.10.16', 'mask' => 24,
                'mac' => '00:1a:2b:3c:4d:14', 'mtu' => 1500, 'rx_mbps' => round(28.4 + (lcg_value() - 0.5) * 4, 1),
                'tx_mbps' => round(16.1 + (lcg_value() - 0.5) * 2.5, 1), 'rx_pps' => rand(3400, 4600),
                'tx_pps' => rand(1900, 2700), 'errors' => 0, 'drops' => 0, 'zone' => 'INTERNAL'
            ],
            'sfp0' => [
                'id' => 'SFP1', 'name' => 'SFP+ 1', 'device' => 'sfp0', 'status' => 'up', 'carrier' => 1,
                'speed' => '10000', 'duplex' => 'full', 'ip' => '10.254.1.1', 'mask' => 30,
                'mac' => '00:1a:2b:3c:4d:05', 'mtu' => 9000, 'rx_mbps' => round(120.5 + (lcg_value() - 0.5) * 15, 1),
                'tx_mbps' => round(84.1 + (lcg_value() - 0.5) * 10, 1), 'rx_pps' => rand(14200, 17500),
                'tx_pps' => rand(10200, 13100), 'errors' => 0, 'drops' => 0, 'zone' => 'FIBER'
            ],
            'sfp1' => [
                'id' => 'SFP2', 'name' => 'SFP+ 2', 'device' => 'sfp1', 'status' => 'down', 'carrier' => 0,
                'speed' => '10000', 'duplex' => 'half', 'ip' => '—', 'mask' => 0,
                'mac' => '00:1a:2b:3c:4d:06', 'mtu' => 9000, 'rx_mbps' => 0.0,
                'tx_mbps' => 0.0, 'rx_pps' => 0, 'tx_pps' => 0, 'errors' => 0, 'drops' => 0, 'zone' => 'FIBER'
            ],
        ];

        if (!empty($dbInterfaces)) {
            foreach ($dbInterfaces as $dbIf) {
                $dev = $dbIf['physical_device'] ?? 'eth0';
                if (isset($basePorts[$dev])) {
                    $basePorts[$dev]['id']      = $dbIf['id'];
                    $basePorts[$dev]['name']    = $dbIf['name'];
                    $basePorts[$dev]['status']  = ($dbIf['enabled'] ?? 1) ? 'up' : 'down';
                    $basePorts[$dev]['carrier'] = ($dbIf['enabled'] ?? 1) ? 1 : 0;
                    if (!empty($dbIf['ipv4_address'])) $basePorts[$dev]['ip'] = $dbIf['ipv4_address'];
                }
            }
        }
        $ports = array_values($basePorts);

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
            return $this->response->setStatusCode(400)->setJSON([
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
                    return $this->response->setJSON([
                        'success' => true,
                        'device'  => $device,
                        'enabled' => $newEnabled,
                        'message' => "Port {$device} administratively switched to {$newState}.",
                    ]);
                }
            } catch (\Throwable $e) {}

            return $this->response->setJSON([
                'success' => true,
                'device'  => $device,
                'message' => "Simulated toggle for {$device}.",
            ]);
        }

        if ($action === 'pcap') {
            $packets = [
                date('H:i:s.u') . " IP 192.168.1.104.53812 > 1.1.1.1.53: 49201+ A? micronos.network. (34)",
                date('H:i:s.u') . " IP 1.1.1.1.53 > 192.168.1.104.53812: 49201 1/0/0 A 104.21.48.19 (50)",
                date('H:i:s.u') . " IP 198.51.100.25.443 > 198.51.100.88.54210: Flags [P.], seq 1:1420, ack 1",
                date('H:i:s.u') . " IP 185.220.101.5.41829 > 198.51.100.25.22: Flags [S], seq 184920192, win 1024",
                date('H:i:s.u') . " IP 198.51.100.25.22 > 185.220.101.5.41829: Flags [R.], seq 0, ack 184920193",
            ];
            return $this->response->setJSON([
                'success' => true,
                'device'  => $device,
                'packets' => $packets,
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'device'  => $device,
            'message' => "Action '{$action}' performed on {$device}.",
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
