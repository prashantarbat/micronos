<?php

namespace App\Libraries;

/**
 * IpcClient — MicronOS Daemon Bridge
 *
 * On Linux appliances this class communicates with `micrond` via a Unix Domain
 * Socket (UDS) at /run/micron/micrond.sock using JSON-RPC 2.0 framing.
 *
 * On Windows (development) it falls back to a mock mode: commands are logged to
 * the writable/logs/ directory and plausible stub responses are returned so the
 * web UI can be developed and tested without a running daemon.
 */
class IpcClient
{
    /** Path to the daemon socket on Linux. */
    private const SOCK_PATH = '/run/micron/micrond.sock';

    /** Socket connect/read timeout in seconds. */
    private const TIMEOUT = 5;

    /** Max response bytes to read from the socket. */
    private const MAX_READ = 65536;

    /** Whether we are running in mock (Windows / no-socket) mode. */
    private bool $mockMode = false;

    /** Internal JSON-RPC ID counter. */
    private int $rpcId = 1;

    public function __construct()
    {
        $this->mockMode = ($this->isWindows() || ! file_exists(self::SOCK_PATH));

        if ($this->mockMode) {
            $this->log('debug', '[IpcClient] Running in MOCK mode (no daemon socket).');
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Send a JSON-RPC 2.0 call to the daemon and return the decoded response.
     *
     * @param string $method  Daemon method, e.g. "interface.list"
     * @param array  $params  Method parameters
     * @return array{result: mixed, error: mixed|null}
     */
    public function call(string $method, array $params = []): array
    {
        $payload = $this->buildRpcPayload($method, $params);

        if ($this->mockMode) {
            return $this->mockDispatch($method, $params, $payload);
        }

        return $this->socketDispatch($payload);
    }

    /**
     * Convenience wrapper — throws on daemon error.
     *
     * @throws \RuntimeException  on RPC error or socket failure
     */
    public function callOrFail(string $method, array $params = []): mixed
    {
        $response = $this->call($method, $params);

        if (! empty($response['error'])) {
            throw new \RuntimeException(
                '[micrond] ' . ($response['error']['message'] ?? 'Unknown daemon error'),
                (int) ($response['error']['code'] ?? 0)
            );
        }

        return $response['result'] ?? null;
    }

    /**
     * Returns true if client is running in simulation mock mode.
     */
    public function isMock(): bool
    {
        return $this->mockMode;
    }

    /**
     * Get live interface state.
     */
    public function getInterfaces(): array
    {
        return $this->call('interface.list')['result'] ?? [];
    }

    /**
     * Get live firewall rules state.
     */
    public function getFirewallRules(): array
    {
        return $this->call('firewall.list_rules')['result'] ?? [];
    }

    /**
     * Get WireGuard tunnels and peers state.
     */
    public function getWireguardPeers(): array
    {
        return $this->call('vpn.wireguard.status')['result'] ?? [];
    }

    /**
     * Get strongSwan IPsec IKE SA and Child SA state.
     */
    public function getIpsecStatus(): array
    {
        return $this->call('vpn.ipsec.status')['result'] ?? [];
    }

    /**
     * Get system telemetry info.
     */
    public function getSystemInfo(): array
    {
        return $this->call('system.info')['result'] ?? [];
    }

    /**
     * Apply network interfaces configuration to daemon.
     */
    public function applyNetworkConfig(): array
    {
        return $this->call('interface.set')['result'] ?? [];
    }

    /**
     * Apply firewall packet filtering rules to daemon.
     */
    public function applyFirewallRules(): array
    {
        return $this->call('firewall.apply_rules')['result'] ?? [];
    }

    /**
     * Get live Micron OS accounts from daemon (UID >= 1000 + protected accounts).
     */
    public function userListOs(): array
    {
        return $this->call('user.list_os')['result'] ?? [];
    }

    /**
     * Create a Micron OS user via micrond.
     */
    public function userCreate(array $data): array
    {
        return $this->call('user.create', $data)['result'] ?? [];
    }

    /**
     * Update an existing Micron OS user via micrond.
     */
    public function userUpdate(array $data): array
    {
        return $this->call('user.update', $data)['result'] ?? [];
    }

    /**
     * Delete a Micron OS user via micrond.
     */
    public function userDelete(string $username): array
    {
        return $this->call('user.delete', ['username' => $username])['result'] ?? [];
    }

    /**
     * Bulk synchronize local DB users to Micron OS user accounts.
     */
    public function userSync(array $users): array
    {
        return $this->call('user.sync', ['users' => $users])['result'] ?? [];
    }

    /**
     * List active kernel bonding interfaces.
     */
    public function bondList(): array
    {
        return $this->call('bond.list')['result'] ?? [];
    }

    /**
     * Create or configure a kernel bonding interface (e.g. bond0 802.3ad LACP).
     */
    public function bondApply(array $data): array
    {
        return $this->call('bond.apply', $data)['result'] ?? [];
    }

    /**
     * Delete a kernel bonding interface.
     */
    public function bondDelete(string $id): array
    {
        return $this->call('bond.delete', ['id' => $id])['result'] ?? [];
    }

    /**
     * Get live Wi-Fi telemetry and connected stations.
     */
    public function wirelessStatus(string $interface = 'wlan0'): array
    {
        return $this->call('wireless.status', ['interface' => $interface])['result'] ?? [];
    }

    /**
     * Apply Wi-Fi Access Point (hostapd) or Client (wpa_supplicant) configuration.
     */
    public function wirelessApply(array $data): array
    {
        return $this->call('wireless.apply', $data)['result'] ?? [];
    }

    // -------------------------------------------------------------------------
    // JSON-RPC helpers
    // -------------------------------------------------------------------------

    private function buildRpcPayload(string $method, array $params): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id'      => $this->rpcId++,
            'method'  => $method,
            'params'  => $params,
        ]);
    }

    // -------------------------------------------------------------------------
    // Real socket dispatch (Linux)
    // -------------------------------------------------------------------------

    private function socketDispatch(string $payload): array
    {
        $sock = @socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($sock === false) {
            return $this->errorResponse(-32000, 'Failed to create UNIX socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => self::TIMEOUT, 'usec' => 0]);
        socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => self::TIMEOUT, 'usec' => 0]);

        if (! @socket_connect($sock, self::SOCK_PATH)) {
            socket_close($sock);
            return $this->errorResponse(-32001, 'Cannot connect to micrond socket: ' . socket_strerror(socket_last_error()));
        }

        // Send length-prefixed frame: 4-byte big-endian uint32 + JSON payload
        $frame = pack('N', strlen($payload)) . $payload;
        socket_write($sock, $frame, strlen($frame));

        // Read 4-byte length header
        $header = '';
        while (strlen($header) < 4) {
            $chunk = socket_read($sock, 4 - strlen($header));
            if ($chunk === false || $chunk === '') {
                socket_close($sock);
                return $this->errorResponse(-32002, 'Daemon closed connection while reading header.');
            }
            $header .= $chunk;
        }

        $bodyLen = unpack('N', $header)[1];

        if ($bodyLen > self::MAX_READ) {
            socket_close($sock);
            return $this->errorResponse(-32003, 'Daemon response too large: ' . $bodyLen . ' bytes.');
        }

        $body = '';
        while (strlen($body) < $bodyLen) {
            $chunk = socket_read($sock, $bodyLen - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        socket_close($sock);

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse(-32004, 'Invalid JSON from daemon: ' . json_last_error_msg());
        }

        return [
            'result' => $decoded['result'] ?? null,
            'error'  => $decoded['error'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Mock dispatch (Windows / dev)
    // -------------------------------------------------------------------------

    private function mockDispatch(string $method, array $params, string $rawPayload): array
    {
        $this->log('info', "[IpcClient:mock] → {$method} | params: " . json_encode($this->redact($params)));

        $result = match (true) {
            // ---------- Interface methods ----------
            $method === 'interface.list'    => $this->mockInterfaceList(),
            $method === 'interface.set' || $method === 'network.apply' || $method === 'interface.apply' => ['success' => true, 'applied' => true, 'status' => 'applied'],
            $method === 'interface.confirm' || $method === 'network.confirm' => ['success' => true, 'status' => 'confirmed'],
            $method === 'interface.status'  => $this->mockInterfaceStatus($params),
            $method === 'interface.dynamic_dns' => $this->mockDynamicDns(),

            // ---------- Firewall methods ----------
            $method === 'firewall.list_rules'  => $this->mockFirewallList(),
            $method === 'firewall.apply_rules' || $method === 'firewall.apply' => ['success' => true, 'rules_loaded' => 12, 'status' => 'applied'],
            $method === 'firewall.confirm'     => ['success' => true, 'status' => 'confirmed'],
            $method === 'firewall.flush'       => ['success' => true, 'status' => 'flushed'],
            $method === 'firewall.status'      => ['status' => 'active', 'ruleset' => '# Active nftables ruleset (mock)'],

            // ---------- NAT methods ----------
            $method === 'nat.list'   => ['rules' => []],
            $method === 'nat.apply'  => ['success' => true, 'status' => 'applied'],

            // ---------- Routing methods ----------
            $method === 'route.table'  => $this->mockRouteTable(),
            $method === 'route.add' || $method === 'network.route.add' => ['success' => true, 'status' => 'added'],
            $method === 'route.del' || $method === 'network.route.del' => ['success' => true, 'status' => 'deleted'],

            // ---------- Policy-Based Routing ----------
            $method === 'network.pbr.sync' => ['success' => true, 'status' => 'synced'],
            $method === 'routing.apply'    => ['success' => true, 'status' => 'applied', 'rules_applied' => 0, 'tables' => 0],

            // ---------- Multi-WAN ----------
            $method === 'network.multiwan.sync' => ['success' => true, 'status' => 'synced'],

            // ---------- PPPoE ----------
            $method === 'pppoe.apply'  => ['success' => true, 'status' => 'applied', 'files_written' => 0, 'servers_started' => 0],
            // A simulated applied RADIUS configuration, so the accounting status
            // has both states to render off-appliance.
            $method === 'pppoe.radius.status' => [
                'status'     => 'ok',
                'applied'    => true,
                'path'       => '/etc/radiusclient/radiusclient.conf',
                'authserver' => ['10.20.30.40:1812'],
                'acctserver' => ['10.20.30.40:1813'],
                'auth'       => true,
                'accounting' => true,
                'mock'       => true,
            ],
            $method === 'pppoe.radius.test' => [
                'status'  => 'accept',
                'message' => 'Received Access-Accept for the test user (simulated)',
                'output'  => "Received response ID 1, code 2, length = 20\n",
            ],
            $method === 'pppoe.status' => [
                'status'          => 'ok',
                'sessions'        => $this->mockPppoeSessions(),
                'links'           => [],
                'client_links'    => 1,
                'server_sessions' => 1,
                'concentrators'   => 1,
            ],
            $method === 'pppoe.redial' => $this->mockPppoeRedial($params),

            // ---------- VPN methods ----------
            $method === 'vpn.wireguard.sync'   => ['success' => true, 'status' => 'synced'],
            $method === 'vpn.wireguard.status' => $this->mockWgStatus(),
            $method === 'vpn.wireguard.peers'  => ['peers' => []],
            $method === 'vpn.wireguard.genkey' => $this->mockWgKeypair(),
            $method === 'vpn.wireguard.pubkey' => ['public_key' => $this->mockWgKeypair()['public_key'], 'mock' => true],
            $method === 'vpn.wireguard.pskgen' => $this->mockWgPresharedKey(),
            $method === 'vpn.openvpn.status'   => ['running' => false, 'clients' => 0],
            $method === 'vpn.openvpn.sync' || $method === 'vpn.openvpn.reload' => ['success' => true, 'status' => 'reloaded'],
            $method === 'vpn.ipsec.sync' || $method === 'vpn.ipsec.reload' => ['success' => true, 'status' => 'reloaded'],
            $method === 'vpn.ipsec.status'     => ['status' => 'ok', 'sas' => 'IKE_SA: active (mock)'],

            // ---------- Generic Service Manager ----------
            $method === 'service.restart' => ['success' => true, 'service' => $params['service'] ?? 'unknown', 'restarted' => true],
            $method === 'system.service'  => ['success' => true, 'service' => $params['service'] ?? 'unknown', 'action' => $params['action'] ?? 'start', 'status' => 'applied'],
            $method === 'connector.service' => ['success' => true, 'service' => $params['service'] ?? 'unknown', 'action' => $params['action'] ?? 'start', 'status' => 'applied'],

            // ---------- System methods ----------
            $method === 'system.ping'    => ['status' => 'pong', 'timestamp' => time(), 'version' => '1.0.0'],
            $method === 'system.info'    => $this->mockSystemInfo(),
            $method === 'system.reboot'  => ['scheduled' => true, 'delay_sec' => 3],
            $method === 'system.halt'    => ['scheduled' => true, 'delay_sec' => 3],
            $method === 'system.ipv6.set' => $this->mockIpv6Set($params),
            $method === 'system.ipv6.status' => ['enabled' => true, 'sysctl' => $this->ipv6Sysctl(true), 'mock' => true],
            $method === 'system.updates_check' => $this->mockUpdatesCheck(),
            $method === 'system.upgrade' => [
                'success' => true,
                'status'  => 'applied',
                'version' => '1.0.0',
                'steps'   => ['(mock) apt-get update', '(mock) apt-get -y upgrade'],
                'warnings' => [],
            ],

            // ---------- DHCP methods ----------
            $method === 'dhcp.leases'   => ['leases' => []],
            $method === 'dhcp.restart'  => ['success' => true, 'status' => 'restarted', 'service' => 'running'],
            $method === 'dhcp.apply'    => $this->mockDhcpApply($params),
            $method === 'dhcp.status'   => ['success' => true, 'status' => 'ok', 'service' => 'running', 'config' => true],
            $method === 'dhcp.relay_apply' => [
                'success'   => true,
                'status'    => 'applied',
                'instances' => array_map(static fn ($i) => [
                    'name'  => (string) ($i['name'] ?? ''),
                    'unit'  => 'dhcp-helper@' . ($i['name'] ?? '') . '.service',
                    'state' => 'running',
                ], (array) ($params['instances'] ?? [])),
                'steps'     => ['(mock) wrote dhcp-helper@*.service', '(mock) systemctl daemon-reload'],
                'warnings'  => [],
            ],
            $method === 'dhcp.relay_status' => ['success' => true, 'status' => 'ok', 'instances' => []],
            $method === 'dhcp.relay_service' => [
                'success' => true,
                'status'  => 'ok',
                'action'  => (string) ($params['action'] ?? ''),
                'unit'    => 'dhcp-helper@' . ($params['slug'] ?? '') . '.service',
                'state'   => ($params['action'] ?? '') === 'stop' ? 'stopped' : 'running',
            ],
            $method === 'dhcp.relay_logs' => [
                'success' => true,
                'status'  => 'ok',
                'unit'    => 'dhcp-helper@' . ($params['slug'] ?? '') . '.service',
                'logs'    => "(mock) dhcp-helper@" . ($params['slug'] ?? '') . ".service\n"
                    . "dhcp-helper: listening on relay interface(s)\n"
                    . "dhcp-helper: forwarding DHCP/BOOTP to configured server(s)",
            ],

            // ---------- DNS methods ----------
            $method === 'dns.status'                    => ['running' => true, 'resolver' => 'unbound'],
            $method === 'dns.flush' || $method === 'dns.cache.flush' => ['success' => true, 'status' => 'flushed'],
            $method === 'dns.config.reload'             => ['success' => true, 'status' => 'reloaded'],
            // The daemon re-validates and writes the compiled stub resolver
            // file; the mock echoes back what the control plane compiled so the
            // settings page can report the servers actually in effect.
            $method === 'dns.resolver.apply' => [
                'success'     => true,
                'status'      => 'applied',
                'file'        => \App\Libraries\SystemResolverCompiler::FILE,
                'behavior'    => (string) ($params['behavior'] ?? ''),
                'override'    => (bool) ($params['override'] ?? false),
                'nameservers' => array_values(array_filter(array_map(
                    static fn ($line) => str_starts_with(trim((string) $line), 'nameserver ')
                        ? trim(substr(trim((string) $line), 11))
                        : null,
                    preg_split('/\r\n|\r|\n/', (string) ($params['config'] ?? '')) ?: [],
                ))),
            ],
            $method === 'dnsbl.update' || $method === 'dnsbl.apply' => [
                'success'  => true,
                'status'   => 'applied',
                'entries'  => 318744,
                'reloaded' => true,
                'file'     => '/etc/unbound/micron-blocklist.conf',
            ],

            // ---------- Backup methods ----------
            $method === 'backup.create'  => ['success' => true, 'file' => 'backup_' . date('YmdHis') . '.tar.gz.enc'],
            $method === 'backup.restore' => ['success' => true, 'restored' => true],

            // ---------- Web Filter / Proxy ----------
            $method === 'squid.status' || $method === 'webfilter.status' => [
                'running'        => true,
                'cache_manager'  => false, // simulated figures, not a live mgr:info scrape
                'uptime'         => '6 days, 14:22:10',
                'requests_today' => 18492,
                'blocked_today'  => 243,
                'cache_hit_rate' => '42.8%',
                'mem_usage'      => '184 MB / 256 MB',
                'disk_usage'     => '1.2 GB / 4.0 GB',
            ],
            $method === 'squid.reload' || $method === 'webfilter.reload' || $method === 'webfilter.apply' => ['success' => true, 'status' => 'reconfigured'],
            $method === 'webfilter.cache.clear' => ['success' => true, 'status' => 'purged', 'bytes_freed' => 1284092000],
            $method === 'webfilter.access_log' || $method === 'squid.access_log' => [
                'success' => true,
                'status'  => 'ok',
                'entries' => $this->mockAccessLogEntries(),
            ],

            // ---------- Multicast & IGMP ----------
            $method === 'multicast.apply' => [
                'success'  => true,
                'status'   => !empty($params['enabled']) ? 'applied' : 'disabled',
                'steps'    => ['igmpproxy restart rc=0', 'vtysh -f rc=0'],
                'warnings' => $params['warnings'] ?? [],
                // Mirrors the real daemon report so the service widget reflects
                // the applied state in dev too.
                'services' => [
                    'igmpproxy'     => (!empty($params['enabled']) && !empty($params['igmp_proxy']['enabled'])) ? 'running' : 'stopped',
                    'mdns-repeater' => (!empty($params['enabled']) && !empty($params['mdns']['enabled'])) ? 'running' : 'stopped',
                ],
            ],
            $method === 'multicast.status' => [
                'available' => true,
                'igmpproxy' => 'running',
                'pim'       => 'running',
                'mdns'      => 'stopped',
            ],

            // ---------- C-ICAP / ClamAV Antivirus Inspection ----------
            $method === 'webfilter.av.status' || $method === 'clamav.status' => [
                'running'          => true,
                'engine'           => 'ClamAV 1.4.2',
                'icap_running'     => true,
                'icap_service'     => 'squidclamav',
                'signatures'       => 8796412,
                'defs_version'     => date('Ymd'),
                'last_update'      => date('Y-m-d H:i:s', time() - 3600),
                'requests_scanned' => 28417,
                'threats_blocked'  => 37,
                'mock'             => true,
            ],
            $method === 'webfilter.clamav.update' || $method === 'clamav.update' => [
                'success'      => true,
                'status'       => 'updated',
                'defs_version' => date('Ymd'),
                'signatures'   => 8796412 + random_int(0, 400),
                'download_kb'  => 214,
                'updated_at'   => date('Y-m-d H:i:s'),
            ],

            // ---------- IDS/IPS ----------
            $method === 'ids.reload' || $method === 'ids.apply' => ['success' => true, 'status' => 'applied', 'engine' => 'suricata'],
            $method === 'ids.status'     => [
                'running'       => true,
                'mode'          => 'ips',
                'engine'        => 'Suricata 7.0.4-RELEASE',
                'active_rules'  => 38412,
                'threads'       => 8,
                'uptime'        => '4 days, 12:44:19',
                'packets_seen'  => 8923410,
                'drops_total'   => 1289,
            ],
            $method === 'suricata.rules.update' => ['success' => true, 'status' => 'rules_updated', 'rules_count' => 38412],

            // ---------- High Availability (Keepalived & conntrackd) ----------
            $method === 'ha.status'      => [
                'state' => 'MASTER',
                'vrrp_instances' => [
                    ['name' => 'VI_LAN', 'state' => 'MASTER', 'interface' => 'lan0', 'vip' => '192.168.1.254', 'priority' => 110],
                    ['name' => 'VI_WAN', 'state' => 'MASTER', 'interface' => 'wan0', 'vip' => '203.0.113.10', 'priority' => 110]
                ],
                'conntrackd' => ['status' => 'running', 'synced_flows' => 482, 'sync_interface' => 'sync0', 'peer_alive' => true],
                'config_sync' => ['last_sync' => date('Y-m-d H:i:s'), 'status' => 'synchronized']
            ],
            $method === 'ha.sync' || $method === 'ha.apply'       => ['success' => true, 'status' => 'synchronized', 'nodes_updated' => 1],
            $method === 'ha.failover'    => ['success' => true, 'transition' => 'BACKUP', 'status' => 'demoted_successfully'],

            // ---------- Traffic Shaping & QoS (CAKE / FQ-CoDel) ----------
            $method === 'qos.apply'      => ['success' => true, 'status' => 'qdisc_installed', 'interfaces_configured' => 2],
            $method === 'qos.status'     => [
                'qdiscs' => [
                    ['interface' => 'wan0', 'scheduler' => 'cake', 'bandwidth' => '100Mbit', 'diffserv' => 'diffserv4', 'dropped' => 14, 'backlog' => '0b 0p'],
                    ['interface' => 'lan0', 'scheduler' => 'cake', 'bandwidth' => '1000Mbit', 'diffserv' => 'besteffort', 'dropped' => 0, 'backlog' => '0b 0p']
                ]
            ],

            // ---------- Certificates & PKI ----------
            $method === 'cert.install' || $method === 'cert.reload' => ['success' => true, 'services_reloaded' => ['squid', 'openvpn', 'nginx']],

            // ---------- Debian OS User Management (user.*) ----------
            $method === 'user.list_os' => [
                'success' => true,
                'status'  => 'ok',
                'users'   => $this->mockOsUsers(),
            ],
            $method === 'user.create' => [
                'success'  => true,
                'status'   => 'created',
                'username' => (string) ($params['username'] ?? ''),
                'shell'    => (string) ($params['shell'] ?? '/bin/bash'),
                'is_admin' => (bool) ($params['is_admin'] ?? false),
            ],
            $method === 'user.update' => [
                'success'  => true,
                'status'   => 'updated',
                'username' => (string) ($params['username'] ?? ''),
            ],
            $method === 'user.delete' => in_array(strtolower((string) ($params['username'] ?? '')), ['root', 'ministrator'], true)
                ? [
                    'success' => false,
                    'status'  => 'protected',
                    'message' => "Protected account '" . ($params['username'] ?? '') . "' cannot be deleted",
                ]
                : [
                    'success'  => true,
                    'status'   => 'deleted',
                    'username' => (string) ($params['username'] ?? ''),
                ],
            $method === 'user.sync' => [
                'success' => true,
                'status'  => 'synced',
                'count'   => count((array) ($params['users'] ?? [])),
                'synced'  => array_map(static fn ($u) => (string) ($u['username'] ?? ''), (array) ($params['users'] ?? [])),
            ],

            // ---------- Link Aggregation / Bonding (bond.*) ----------
            $method === 'bond.list' => [
                'success' => true,
                'status'  => 'ok',
                'bonds'   => $this->mockBondList(),
            ],
            $method === 'bond.apply' => [
                'success' => true,
                'status'  => 'applied',
                'id'      => (string) ($params['id'] ?? 'bond0'),
                'mode'    => (string) ($params['mode'] ?? '802.3ad'),
                'slaves'  => (array) ($params['slaves'] ?? []),
            ],
            $method === 'bond.delete' => [
                'success' => true,
                'status'  => 'deleted',
                'id'      => (string) ($params['id'] ?? 'bond0'),
            ],

            // ---------- Wireless LAN (wireless.*) ----------
            $method === 'wireless.status' => [
                'success'   => true,
                'status'    => 'ok',
                'interface' => (string) ($params['interface'] ?? 'wlan0'),
                'mode'      => 'AP',
                'ssid'      => 'MicronOS-Corp',
                'channel'   => 36,
                'clients'   => [
                    ['mac' => 'a4:83:e7:22:11:4a', 'ip' => '192.168.1.105', 'signal' => '-54 dBm', 'rx_rate' => '866.7 Mbps', 'tx_rate' => '866.7 Mbps'],
                ],
            ],
            $method === 'wireless.apply' => [
                'success'   => true,
                'status'    => 'applied',
                'interface' => (string) ($params['interface'] ?? 'wlan0'),
                'mode'      => (string) ($params['mode'] ?? 'ap'),
                'ssid'      => (string) ($params['ssid'] ?? 'MicronOS-WiFi'),
            ],

            // ---------- Transactional commit engine (auto-revert watchdog) ----------
            $method === 'system.commit'           => ['success' => true, 'transaction_id' => 'tx_' . bin2hex(random_bytes(4)), 'status' => 'committed'],
            $method === 'system.commit_watchdog' => ['success' => true, 'transaction_id' => 'tx_' . bin2hex(random_bytes(4)), 'watchdog_sec' => 60, 'status' => 'pending_confirm'],
            $method === 'system.rollback'         => ['success' => true, 'status' => 'rolled_back', 'previous_state' => 'restored'],
            $method === 'system.confirm'          => ['success' => true, 'status' => 'confirmed_permanently'],

            // ---------- Plugin & Package Manager (plugin.*) ----------
            $method === 'plugin.catalog' => [
                'success' => true,
                'status'  => 'ok',
                'plugins' => array_map(static fn ($p) => [
                    'id'           => $p['id'],
                    'packages'     => $p['packages'],
                    'units'        => $p['units'],
                    'capabilities' => $p['capabilities'],
                ], (new \App\Libraries\PackageManager())->catalogue()),
            ],
            $method === 'plugin.install' || $method === 'plugin.upgrade' => [
                'success'  => true,
                'status'   => 'applied',
                'id'       => (string) ($params['id'] ?? ''),
                'steps'    => ['(mock) apt-get resolved and installed', '(mock) systemctl enabled the plugin unit'],
                'warnings' => [],
            ],
            $method === 'plugin.remove'   => ['success' => true, 'status' => 'applied', 'id' => (string) ($params['id'] ?? ''), 'purge' => (bool) ($params['purge'] ?? false)],
            $method === 'plugin.service'  => ['success' => true, 'status' => 'applied', 'id' => (string) ($params['id'] ?? ''), 'unit' => (string) ($params['id'] ?? ''), 'action' => (string) ($params['action'] ?? ''), 'state' => ($params['action'] ?? '') === 'stop' ? 'stopped' : 'running'],
            $method === 'plugin.status'   => ['success' => true, 'status' => 'ok', 'id' => (string) ($params['id'] ?? ''), 'state' => 'running'],
            $method === 'plugin.logs'     => [
                'success' => true,
                'status'  => 'ok',
                'id'      => (string) ($params['id'] ?? ''),
                'unit'    => (string) ($params['id'] ?? '') . '.service',
                'logs'    => "(mock) journal for plugin '" . ($params['id'] ?? '') . "'\n"
                    . "systemd[1]: Started plugin daemon\n"
                    . "plugin: initialised and serving",
            ],
            $method === 'plugin.repo.refresh' => ['success' => true, 'status' => 'applied', 'steps' => ['(mock) apt-get update']],
            $method === 'plugin.repo.apply' => $this->mockRepoApply($params),
            $method === 'plugin.repo_test'  => $this->mockRepoTest($params),
            // Nothing is applied in mock mode, and the daemon only writes the
            // protected-core pin as part of an apply — so the honest answer is
            // "no pin yet", not an empty repository set with a pin present.
            $method === 'plugin.repo_status' => [
                'success'     => true,
                'status'      => 'ok',
                'repos'       => [],
                'count'       => 0,
                'core_pin'    => '',
                'core_pinned' => false,
            ],
            $method === 'plugin.iperf3.run' => $this->mockIperf3Run($params),
            $method === 'plugin.netbird.status' => $this->mockNetbirdStatus(),
            $method === 'plugin.netbird.up'     => $this->mockNetbirdStatus(true),
            $method === 'plugin.netbird.down'   => $this->mockNetbirdStatus(false),
            $method === 'plugin.netbird.enroll' => $this->mockNetbirdStatus(true, true),
            $method === 'plugin.zerotier.status' => $this->mockZerotierStatus(),
            $method === 'plugin.zerotier.join'   => $this->mockZerotierStatus(true, (string) ($params['nwid'] ?? '')),
            $method === 'plugin.zerotier.leave'  => $this->mockZerotierStatus(false, (string) ($params['nwid'] ?? '')),
            // NAT64/DNS64 and the 6in4 tunnel: nothing is written or started in
            // mock mode, so the honest answer is "not applied yet".
            $method === 'plugin.nat64.apply' => [
                'success'  => true,
                'status'   => 'applied',
                'id'       => 'nat64',
                'message'  => '(mock) NAT64 configuration applied',
                'steps'    => ['(mock) wrote /etc/tayga.conf', '(mock) unbound-checkconf rc=0'],
                'warnings' => [],
            ],
            $method === 'plugin.nat64.status' => [
                'success' => true,
                'status'  => 'ok',
                'id'      => 'nat64',
                'state'   => 'stopped',
                'data'    => [
                    'enabled'                 => false,
                    'dns64_fragment_present'  => false,
                    'tayga_conf_present'      => false,
                    'tayga_state'             => 'stopped',
                    'host_network_state'      => 'stopped',
                ],
                'steps'    => [],
                'warnings' => ['tayga.conf is absent: NAT64 has not been applied on this appliance'],
            ],
            $method === 'plugin.tunnel.apply' => [
                'success'  => true,
                'status'   => 'applied',
                'id'       => 'ipv6-tunnel',
                'message'  => '(mock) 6in4 tunnel configuration applied',
                'steps'    => ['(mock) wrote /etc/systemd/system/micron-6in4.service'],
                'warnings' => [],
            ],
            $method === 'plugin.tunnel.status' => [
                'success' => true,
                'status'  => 'ok',
                'id'      => 'ipv6-tunnel',
                'state'   => 'stopped',
                'data'    => [
                    'enabled'            => false,
                    'unit_file_present'  => false,
                    'update_key_stored'  => false,
                ],
                'steps'    => [],
                'warnings' => ['the tunnel unit has not been generated: the tunnel has not been applied'],
            ],
            $method === 'plugin.tunnel.update' => [
                'success'  => false,
                'status'   => 'error',
                'id'       => 'ipv6-tunnel',
                'message'  => 'no update key is stored — set it on the plugin page first',
                'steps'    => [],
                'warnings' => [],
            ],

            // ---------- Layer 7 Application Identification & DPI (dpi.*) ----------
            $method === 'dpi.status' => [
                'running'         => true,
                'engine'          => 'nDPI 4.10-RELEASE (Micron OS Kernel Interceptor)',
                'mode'            => 'inline_block',
                'uptime'          => '3 days, 08:14:22',
                'active_flows'    => 1428,
                'total_analyzed'  => 984521,
                'blocked_flows'   => 412,
                'shaped_flows'    => 189,
                'throughput_mbps' => 48.6,
                'memory_usage'    => '46.2 MB / 256 MB',
            ],
            $method === 'dpi.apply' => [
                'success'        => true,
                'status'         => 'applied',
                'rules_compiled' => 24,
                'reloaded'       => true,
                'config_file'    => '/etc/ndpi/ndpi_rules.conf',
            ],
            $method === 'dpi.live_flows' => [
                'success' => true,
                'status'  => 'ok',
                'flows'   => $this->mockDpiLiveFlows(),
            ],
            $method === 'dpi.stats' => [
                'success' => true,
                'status'  => 'ok',
                'stats'   => $this->mockDpiStats(),
            ],

            // ---------- Full SSL/TLS Deep Packet Inspection & MITM CA (ssl.*) ----------
            $method === 'ssl.ca.status' => [
                'success'       => true,
                'status'        => 'active',
                'common_name'   => 'Micron OS Proxy Inspection CA',
                'key_type'      => 'RSA 4096-bit',
                'valid_until'   => date('Y-m-d H:i:s', strtotime('+3650 days')),
                'is_installed'  => true,
                'cert_path'     => '/etc/squid/certs/micron_ca.pem',
                'cached_certs'  => 1420,
            ],
            $method === 'ssl.sessions.active' => [
                'success'  => true,
                'status'   => 'ok',
                'sessions' => $this->mockSslSessions(),
            ],
            $method === 'ssl.apply' => [
                'success'          => true,
                'status'           => 'applied',
                'profiles_applied' => 2,
                'reloaded'         => true,
                'worker'           => 'ssl_crtd',
            ],

            default => ['_mock' => true, 'method' => $method, 'params' => $params],
        };

        $this->log('info', "[IpcClient:mock] ← {$method} | result: " . json_encode($result));

        return ['result' => $result, 'error' => null];
    }

    // -------------------------------------------------------------------------
    // Mock data generators
    // -------------------------------------------------------------------------

    /**
     * Mock: the daemon's DHCP apply path — reject a missing or non-Kea payload,
     * otherwise report the validate/write/restart steps it would have run.
     *
     * @param array{config?: mixed} $params
     */
    private function mockDhcpApply(array $params): array
    {
        $config = $params['config'] ?? null;
        if (! is_string($config) || trim($config) === '') {
            return ['success' => false, 'status' => 'error', 'message' => 'config is required'];
        }

        $decoded = json_decode($config, true);
        if (! is_array($decoded) || ! isset($decoded['Dhcp4']) || ! is_array($decoded['Dhcp4'])) {
            return ['success' => false, 'status' => 'error', 'message' => 'config is not valid Kea Dhcp4 JSON'];
        }

        $subnets = $decoded['Dhcp4']['subnet4'] ?? [];

        return [
            'success'  => true,
            'status'   => 'applied',
            'steps'    => ['(mock) kea-dhcp4 -t passed', '(mock) wrote /etc/kea/kea-dhcp4.conf', '(mock) systemctl restart kea-dhcp4-server'],
            'warnings' => [],
            'service'  => 'running',
            'subnets'  => is_array($subnets) ? count($subnets) : 0,
        ];
    }

    /**
     * Mock: the daemon's curated-repository reconcile.
     *
     * Shaped like the real `plugin.repo.apply`: a repository with no inline key
     * material is refused (the daemon never fetches keys itself), otherwise the
     * generated source paths are echoed back.
     *
     * @param array{repos?: mixed} $params
     */
    private function mockRepoApply(array $params): array
    {
        $repos = $params['repos'] ?? [];
        if (! is_array($repos)) {
            return ['success' => false, 'status' => 'error', 'message' => 'repos must be a list'];
        }

        $written  = [];
        $warnings = [];

        foreach ($repos as $repo) {
            if (! is_array($repo) || ! isset($repo['id'])) {
                continue;
            }
            if (trim((string) ($repo['key_armor'] ?? '')) === '') {
                $warnings[] = (string) $repo['id'] . ': no inline signing key';

                continue;
            }

            $written[] = [
                'id'           => (string) $repo['id'],
                'url'          => (string) ($repo['url'] ?? ''),
                'fingerprint'  => (string) ($repo['fingerprint'] ?? ''),
                'sources_path' => '/etc/apt/sources.list.d/micron-' . $repo['id'] . '.sources',
            ];
        }

        if ($warnings !== []) {
            return ['success' => false, 'status' => 'error', 'message' => implode('; ', $warnings), 'warnings' => $warnings];
        }

        return [
            'success'  => true,
            'status'   => 'applied',
            'repos'    => $written,
            'steps'    => ['(mock) verified signing keys and wrote micron-*.sources', '(mock) apt-get update'],
            'warnings' => [],
        ];
    }

    /**
     * Mock: the daemon's read-only repository verification.
     *
     * Shaped like the real `plugin.repo_test`: the declared fingerprint is what
     * the (private) keyring reported, the release is signed by it, and the
     * release fields echo what the repository declared.
     *
     * @param array{repo?: mixed, release?: mixed} $params
     */
    private function mockRepoTest(array $params): array
    {
        $repo = $params['repo'] ?? null;
        if (! is_array($repo)) {
            return ['success' => false, 'status' => 'error', 'message' => 'repo is required'];
        }

        $release = $params['release'] ?? '';
        if (! is_string($release) || trim($release) === '') {
            return ['success' => false, 'status' => 'error', 'message' => 'no release metadata was supplied to verify'];
        }

        $fingerprint = strtoupper((string) ($repo['fingerprint'] ?? ''));
        $suite       = (string) ($repo['suite'] ?? '');

        return [
            'success'   => true,
            'status'    => 'ok',
            'id'        => (string) ($repo['id'] ?? ''),
            'url'       => (string) ($repo['url'] ?? ''),
            'suite'     => $suite,
            'key'       => ['declared' => $fingerprint, 'found' => [$fingerprint], 'matched' => true],
            'signature' => ['verified' => true, 'signer' => $fingerprint, 'mode' => 'inrelease'],
            'release'   => [
                'origin'        => '(mock) Vendor Repository',
                'label'         => '(mock) Vendor Repository',
                'suite'         => $suite === './' ? '' : $suite,
                'codename'      => '(mock codename)',
                'architectures' => array_values((array) ($repo['architectures'] ?? [])),
                'components'    => array_values((array) ($repo['components'] ?? [])),
            ],
            'warnings'  => [],
            'steps'     => [
                '(mock) imported the signing key into a private keyring (1 key(s))',
                '(mock) inrelease signature verified against ' . $fingerprint,
            ],
        ];
    }

    /**
     * Mock: a read-only appliance update check.
     *
     * Shaped exactly like the daemon's `system.updates_check` so the System
     * Updates page renders identically off-appliance; a couple of plausible
     * upgradable packages give the table something to show.
     */
    private function mockUpdatesCheck(): array
    {
        return [
            'success' => true,
            'status'  => 'ok',
            'os'      => 'MicronOS 1.0 (Debian 13 Trixie Kernel)',
            'version' => '1.0.0',
            'kernel'  => '6.6.30-micronos',
            'count'   => 3,
            'packages' => [
                ['name' => 'libc6', 'current' => '2.41-3', 'candidate' => '2.41-4'],
                ['name' => 'openssl', 'current' => '3.5.0-2', 'candidate' => '3.5.1-1'],
                ['name' => 'ca-certificates', 'current' => '20250419', 'candidate' => '20250826'],
            ],
            'steps'   => ['(mock) apt-get update', '(mock) apt-get -s upgrade'],
            'checked_at' => date('Y-m-d\TH:i:s'),
            'warnings' => [],
            'mock'    => true,
        ];
    }

    /**
     * Mock: an iperf3 throughput run (client measurement or time-bounded server).
     *
     * @param array<string, mixed> $params
     */
    private function mockIperf3Run(array $params): array
    {
        $mode = strtolower((string) ($params['mode'] ?? 'client'));
        $port = (int) ($params['port'] ?? 5201);

        if ($mode === 'server') {
            return [
                'success' => true,
                'status'  => 'applied',
                'mode'    => 'server',
                'port'    => $port,
                'duration' => (int) ($params['duration'] ?? 10),
                'steps'   => ["(mock) iperf3 -s -p {$port} -1"],
                'warnings' => [],
                'message' => "Listening server started on TCP {$port} for a single client (mock).",
                'mock'    => true,
            ];
        }

        $protocol  = strtolower((string) ($params['protocol'] ?? 'tcp'));
        $direction = strtolower((string) ($params['direction'] ?? 'upload'));
        $mbps      = round(940.0 + (random_int(0, 4000) / 100.0), 2);
        $udp       = $protocol === 'udp';

        return [
            'success'  => true,
            'status'   => 'ok',
            'mode'     => 'client',
            'target'   => (string) ($params['target'] ?? ''),
            'port'     => $port,
            'protocol' => $protocol,
            'direction' => $direction,
            'duration' => (int) ($params['duration'] ?? 10),
            'streams'  => (int) ($params['streams'] ?? 1),
            'bits_per_second' => $mbps * 1_000_000,
            'mbps'     => $mbps,
            'jitter_ms' => $udp ? round(random_int(5, 90) / 1000, 3) : null,
            'lost_percent' => $udp ? round(random_int(0, 10) / 100, 2) : null,
            'retransmits' => $direction === 'download' ? null : random_int(0, 12),
            'steps'    => [sprintf('(mock) iperf3 -c %s -p %d -t %d', (string) ($params['target'] ?? ''), $port, (int) ($params['duration'] ?? 10))],
            'warnings' => [],
            'mock'     => true,
        ];
    }

    /**
     * Mock: the NetBird overlay's status, as the agent's `status --json` reports
     * it once the peer is (or is not) connected.
     *
     * The mock never returns the setup key it was handed: the daemon stages it
     * and forgets it, so a response that echoed it back would be the one thing
     * the real path cannot do.
     */
    private function mockNetbirdStatus(bool $connected = false, bool $enrolling = false): array
    {
        $steps = $enrolling
            ? ['(mock) netbird up --setup-key-file <staged>', '(mock) netbird up (setup key) rc=0']
            : ['(mock) netbird status --json rc=0'];

        $result = [
            'success' => true,
            'status'  => 'ok',
            'id'      => 'netbird',
            'state'   => $connected ? 'running' : 'running',
            'data'    => [
                'fqdn'                 => 'micron-appliance.netbird.cloud',
                'ip'                   => $connected ? '100.64.0.7' : '',
                'interface'            => $connected ? 'wt0' : '',
                'public_key'           => 'xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=',
                'daemon_version'       => '0.75.0',
                'management_url'       => 'https://api.netbird.io:443',
                'management_connected' => $connected,
                'signal_connected'     => $connected,
                'peers_total'          => $connected ? 4 : 0,
                'peers_connected'      => $connected ? 3 : 0,
            ],
            'steps'    => $steps,
            'warnings' => [],
            'mock'     => true,
        ];

        if ($enrolling) {
            $result['message'] = '(mock) setup key submitted; the peer is enrolling';
        }

        return $result;
    }

    /**
     * Mock: the ZeroTier overlay's status, as `zerotier-cli info` and
     * `zerotier-cli -j listnetworks` report it once the node has (or has not)
     * joined a network.
     *
     * Nothing is written and no agent runs in mock mode, so the mock reports the
     * *node's* own identity plus the memberships the caller just asked for — and
     * reports an empty membership list rather than inventing networks, so a page
     * built on it cannot look healthier than a real appliance would.
     */
    private function mockZerotierStatus(bool $joined = false, string $nwid = ''): array
    {
        $nwid = strtolower(trim($nwid));
        $networks = [];
        if ($joined && $nwid !== '') {
            $networks[] = [
                'nwid'     => $nwid,
                'name'     => 'micron-' . substr($nwid, 0, 6),
                'status'   => 'OK',
                'type'     => 'PRIVATE',
                'device'   => 'zt' . substr(md5($nwid), 0, 8),
                'assigned' => '10.147.17.' . (hexdec(substr($nwid, -2)) % 250 + 2) . '/24',
            ];
        }

        return [
            'success' => true,
            'status'  => 'ok',
            'id'      => 'zerotier',
            'state'   => 'running',
            'data'    => [
                'address'         => 'a0c8f1e2b3',
                'version'         => '1.14.0',
                'online'          => true,
                'status_text'     => 'ONLINE',
                'networks'        => $networks,
                'networks_joined' => count($networks),
            ],
            'steps'    => $joined
                ? [sprintf('(mock) zerotier-cli join %s rc=0', $nwid)]
                : ['(mock) zerotier-cli info rc=0', '(mock) zerotier-cli -j listnetworks rc=0'],
            'warnings' => [],
            'mock'     => true,
        ];
    }

    /**
     * Replace the values of secret-looking parameters before anything is
     * written to a log.
     *
     * A plugin setup key must never reach a log file (Plugin Spec PL7). The real
     * path keeps it out of argv and out of the response; the mock's request log
     * is the remaining place a secret could leak, so it is redacted centrally
     * rather than the plugin being trusted to keep quiet. Only values under a
     * secret-sounding key change; the shape of the call is still logged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function redact(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->redact($value);

                continue;
            }

            if (is_string($key)
                && preg_match('/pass|secret|token|api[_-]?key|setup[_-]?key|private[_-]?key/i', $key) === 1) {
                $params[$key] = '***redacted***';
            }
        }

        return $params;
    }

    /** Mock: the sysctl / service actions a real micrond would perform. */
    private function mockIpv6Set(array $params): array
    {
        $enabled = (bool) ($params['enabled'] ?? true);

        return [
            'success'          => true,
            'enabled'          => $enabled,
            'sysctl'           => $this->ipv6Sysctl($enabled),
            'addresses_flushed' => $enabled ? 0 : 3,
            'routes_flushed'   => $enabled ? 0 : 2,
            'services_reloaded' => ['unbound', 'kea-dhcp4', 'strongswan', 'openvpn', 'nginx'],
            'mock'             => true,
        ];
    }

    /** The kernel switch set that stands in for "IPv6 completely off". */
    private function ipv6Sysctl(bool $enabled): array
    {
        $value = $enabled ? 0 : 1;

        return [
            'net.ipv6.conf.all.disable_ipv6'     => $value,
            'net.ipv6.conf.default.disable_ipv6' => $value,
            "net.ipv6.conf.lo.disable_ipv6"      => $enabled ? 0 : 1,
            'net.ipv6.conf.all.forwarding'       => $enabled ? 1 : 0,
            'net.ipv6.conf.all.accept_ra'        => $enabled ? 1 : 0,
        ];
    }

    private function mockInterfaceList(): array
    {
        $db = null;
        if (class_exists(\Config\Database::class)) {
            try {
                $db = \Config\Database::connect();
            } catch (\Throwable $e) {}
        }

        $dbIfaces = [];
        if ($db && $db->tableExists('interfaces')) {
            $dbIfaces = $db->table('interfaces')->get()->getResultArray();
        }

        $list = [];
        $knownDevices = [];
        $hardwareNics = ['eth0', 'eth1', 'eth2', 'eth3', 'eth4'];

        foreach ($dbIfaces as $row) {
            $dev = $row['physical_device'] ?: $row['id'];
            $knownDevices[$dev] = true;
            $ip = $row['ipv4_address'] ?: ($row['ip_assignment_type'] === 'dhcp' ? '192.168.100.15' : ($row['ip_assignment_type'] === 'pppoe' ? '100.64.0.10' : ''));
            $mask = !empty($row['ipv4_netmask']) ? '255.255.255.0' : '';
            $status = ((int)($row['enabled'] ?? 1) === 1) ? 'up' : 'down';
            $hash = md5($dev);
            $mac = !empty($row['mac_override']) ? $row['mac_override'] : sprintf('52:54:00:%02s:%02s:%02s', substr($hash, 0, 2), substr($hash, 2, 2), substr($hash, 4, 2));

            $list[] = [
                'name'     => $row['name'],
                'device'   => $dev,
                'ip'       => $ip,
                'mask'     => $mask,
                'status'   => $status,
                'rx_mbps'  => $status === 'up' ? 14.5 : 0.0,
                'tx_mbps'  => $status === 'up' ? 8.2 : 0.0,
                'rx_bytes' => $status === 'up' ? 154857600 : 0,
                'tx_bytes' => $status === 'up' ? 82428800 : 0,
                'speed'    => !empty($row['speed_duplex']) && $row['speed_duplex'] !== 'auto' ? $row['speed_duplex'] : '1000 Mbps Full Duplex',
                'mac'      => $mac,
            ];
        }

        // Add any unassigned hardware ports for physical port inventory
        foreach ($hardwareNics as $hw) {
            if (!isset($knownDevices[$hw])) {
                $hash = md5($hw);
                $list[] = [
                    'name'     => strtoupper($hw),
                    'device'   => $hw,
                    'ip'       => '',
                    'mask'     => '',
                    'status'   => 'down',
                    'rx_mbps'  => 0.0,
                    'tx_mbps'  => 0.0,
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                    'speed'    => '1000 Mbps Full Duplex',
                    'mac'      => sprintf('52:54:00:%02s:%02s:%02s', substr($hash, 0, 2), substr($hash, 2, 2), substr($hash, 4, 2)),
                ];
            }
        }

        return ['interfaces' => $list];
    }

    private function mockInterfaceStatus(array $params): array
    {
        return [
            'device'    => $params['device'] ?? 'eth0',
            'status'    => 'up',
            'speed'     => '1000baseT',
            'duplex'    => 'full',
            'mtu'       => 1500,
            'rx_bytes'  => 1_048_576_000,
            'tx_bytes'  => 204_800_000,
            'rx_errors' => 0,
            'tx_errors' => 0,
        ];
    }

    /**
     * Simulated report of the DNS servers the dynamic links would have assigned.
     *
     * Derived from the same `interfaces` rows the app renders, so an environment
     * with a DHCP or PPPoE WAN behaves like the appliance would: the interface
     * path records these and merges them into the stub resolver while the
     * override is enabled. A static-only configuration reports nothing.
     */
    private function mockDynamicDns(): array
    {
        $sources = [];

        try {
            $db = \Config\Database::connect();
            if ($db->tableExists('interfaces')) {
                foreach ($db->table('interfaces')->where('enabled', 1)->get()->getResultArray() as $row) {
                    $type = strtolower((string) ($row['interface_type'] ?? 'physical'));
                    $assignment = strtolower((string) ($row['ip_assignment_type'] ?? ''));
                    $name = (string) ($row['name'] ?? $row['id'] ?? '');

                    if ($type === 'openvpn') {
                        $sources[] = ['source' => 'openvpn', 'interface' => $name, 'interface_id' => (string) ($row['id'] ?? ''), 'servers' => ['10.8.0.1']];
                    } elseif ($assignment === 'pppoe' || $type === 'pppoe') {
                        $sources[] = ['source' => 'ppp', 'interface' => $name, 'interface_id' => (string) ($row['id'] ?? ''), 'servers' => ['100.64.0.53', '100.64.0.54']];
                    } elseif ($assignment === 'dhcp') {
                        $sources[] = ['source' => 'dhcp', 'interface' => $name, 'interface_id' => (string) ($row['id'] ?? ''), 'servers' => ['10.0.0.138', '10.0.0.139']];
                    }
                }
            }
        } catch (\Throwable $e) {
            $sources = [];
        }

        return [
            'success' => true,
            'status'  => 'ok',
            'sources' => $sources,
            'servers' => array_values(array_unique(array_merge([], ...array_map(
                static fn (array $entry) => $entry['servers'],
                $sources,
            )))),
            'mock'    => true,
        ];
    }

    private function mockFirewallList(): array
    {
        return [
            'rules' => [
                ['id' => 1, 'action' => 'ACCEPT', 'proto' => 'tcp', 'src' => 'any', 'dst' => 'LAN', 'dport' => '80,443', 'description' => 'Allow HTTP/S inbound'],
                ['id' => 2, 'action' => 'ACCEPT', 'proto' => 'udp', 'src' => 'any', 'dst' => 'WAN', 'dport' => '53',     'description' => 'DNS outbound'],
                ['id' => 3, 'action' => 'DROP',   'proto' => 'any', 'src' => 'any', 'dst' => 'any', 'dport' => 'any',    'description' => 'Default deny'],
            ],
        ];
    }

    private function mockRouteTable(): array
    {
        return [
            'routes' => [
                ['destination' => '0.0.0.0/0',       'gateway' => '203.0.113.254', 'iface' => 'eth0', 'metric' => 10],
                ['destination' => '192.168.1.0/24',   'gateway' => 'local',         'iface' => 'eth1', 'metric' => 0],
                ['destination' => '172.16.0.0/16',    'gateway' => 'local',         'iface' => 'eth2', 'metric' => 0],
            ],
        ];
    }

    /**
     * Placeholder keypair for mock mode.
     *
     * On the appliance this comes from `wg genkey` / `wg pubkey`. These values
     * are random base64 of the right shape but are NOT a valid Curve25519 pair —
     * mock mode only exists so the UI can be exercised on Windows.
     */
    private function mockWgKeypair(): array
    {
        return [
            'private_key' => base64_encode(random_bytes(32)),
            'public_key'  => base64_encode(random_bytes(32)),
            'mock'        => true,
        ];
    }

    /**
     * A WireGuard pre-shared key is just 32 random bytes, base64-encoded — the
     * same shape the appliance gets from `wg genpsk`.
     */
    private function mockWgPresharedKey(): array
    {
        return [
            'preshared_key' => base64_encode(random_bytes(32)),
            'mock'          => true,
        ];
    }

    /**
     * A representative PPPoE session table for the mock data plane.
     *
     * One dialled client link and one subscriber session on the appliance's
     * own concentrator, so the monitor has both roles to render.
     */
    /**
     * Simulated redial result.
     *
     * Mirrors the daemon's health-aware behaviour: a link whose mock session is
     * already UP is skipped rather than cycled, and the concentrator is only
     * reported as restarted when something was actually redialed. Without this
     * the mock would claim to redial a healthy link, which is the exact
     * behaviour the real daemon no longer has.
     *
     * @param array<string,mixed> $params
     */
    private function mockPppoeRedial(array $params): array
    {
        $downOnly = (bool) ($params['down_only'] ?? false);
        $force    = (bool) ($params['force'] ?? false);

        $upDevices = [];
        foreach ($this->mockPppoeSessions() as $session) {
            if (($session['role'] ?? '') === 'client' && strtoupper((string) ($session['state'] ?? '')) === 'UP') {
                $upDevices[(string) ($session['device'] ?? '')] = true;
            }
        }

        $links    = [];
        $repaired = 0;
        $skipped  = 0;
        $up       = 0;

        foreach ($params['interfaces'] ?? [] as $iface) {
            $iface = (string) $iface;
            if (! $force && ! $downOnly && isset($upDevices[$iface])) {
                $skipped++;
                $up++;
                $links[] = ['interface' => $iface, 'status' => 'up', 'state' => 'up', 'action' => 'skipped'];
                continue;
            }

            if ($downOnly) {
                $links[] = ['interface' => $iface, 'status' => 'down', 'state' => 'down', 'action' => 'down'];
                continue;
            }

            $repaired++;
            $up++;
            $links[] = ['interface' => $iface, 'status' => 'up', 'state' => 'down', 'action' => 'redialed'];
        }

        return [
            'status'       => 'applied',
            'up'           => $up,
            'failed'       => 0,
            'repaired'     => $repaired,
            'skipped'      => $skipped,
            'links'        => $links,
            'concentrator' => $repaired > 0 ? 'restarted' : null,
        ];
    }

    private function mockPppoeSessions(): array
    {
        return [
            [
                'interface'      => 'ppp0',
                'pid'            => 2141,
                'role'           => 'client',
                'device'         => 'eth0',
                'state'          => 'UP',
                'local_ip'       => '100.72.14.6',
                'peer_ip'        => '100.72.0.1',
                'uptime_seconds' => 7432,
                'rx_bytes'       => 918273645,
                'tx_bytes'       => 142536789,
                // A client link authenticates to its ISP from chap-secrets, so
                // there is no RADIUS reply to record.
                'radius_attributes' => [],
            ],
            [
                'interface'      => 'ppp1',
                'pid'            => 3388,
                'role'           => 'server',
                'device'         => 'eth1',
                'state'          => 'UP',
                'local_ip'       => '10.0.0.1',
                'peer_ip'        => '10.0.0.42',
                'uptime_seconds' => 1860,
                'rx_bytes'       => 4210944,
                'tx_bytes'       => 88920117,
                // What a subscriber's Access-Accept carried, as radattr records it.
                'radius_attributes' => [
                    'Framed-IP-Address'   => '10.0.0.42',
                    'Session-Timeout'     => '3600',
                    'Idle-Timeout'        => '300',
                    'Acct-Interim-Interval' => '300',
                    'Filter-Id'           => '2048/1024',
                    'Class'               => '0x4d6963726f6e',
                ],
            ],
        ];
    }

    private function mockWgStatus(): array
    {
        return [
            'running'    => true,
            'interface'  => 'wg0',
            'listen_port'=> 51820,
            'public_key' => 'MOCK_PUBLIC_KEY_BASE64==',
            'peers'      => [
                ['peer' => 'PEER_KEY_1==', 'endpoint' => '198.51.100.10:51820', 'allowed_ips' => '10.100.0.2/32', 'last_handshake' => '2 minutes ago', 'transfer' => '1.2 MiB rx / 0.8 MiB tx'],
            ],
        ];
    }

    private function mockSystemInfo(): array
    {
        return [
            'hostname'    => 'micronos-fw01',
            'kernel'      => '6.6.30-micronos',
            'uptime_sec'  => 86400 * 3 + 3600 * 5,
            'cpu_count'   => 4,
            'cpu_load'    => [0.42, 0.38, 0.35],
            'mem_total_mb'=> 4096,
            'mem_used_mb' => 1024,
            'disk_total_gb'=> 32,
            'disk_used_gb' => 8,
            'version'     => \App\Libraries\AppVersion::getVersion(),
        ];
    }

    /**
     * Simulated Squid access-log tail for the Real-Time monitor (dev only).
     *
     * Shaped exactly like the micrond `webfilter.access_log` result so the
     * dashboard renders identically against the mock and a live appliance.
     */
    private function mockAccessLogEntries(): array
    {
        $domains = [
            ['download.windowsupdate.com', 'ALLOW', 'TCP_HIT', true, 4096, 200],
            ['update.microsoft.com', 'ALLOW', 'TCP_MISS', false, 12800, 200],
            ['swcdn.apple.com', 'ALLOW', 'TCP_HIT', true, 8192, 200],
            ['ads.doubleclick.net', 'BLOCK', 'TCP_DENIED', false, 0, 403],
            ['archive.ubuntu.com', 'ALLOW', 'TCP_MISS', false, 65536, 200],
            ['cdn.jsdelivr.net', 'ALLOW', 'TCP_MEM_HIT', true, 2048, 200],
            ['malware-c2.example', 'BLOCK', 'TCP_DENIED', false, 0, 403],
            ['github.com', 'ALLOW', 'TCP_REFRESH_UNMODIFIED', true, 1024, 304],
        ];

        $entries = [];
        $count   = count($domains);
        for ($i = 0; $i < 12; $i++) {
            [$domain, $action, $tag, $cached, $bytes, $status] = $domains[random_int(0, $count - 1)];
            $entries[] = [
                'time'         => date('H:i:s', time() - $i),
                'timestamp'    => time() - $i,
                'client'       => ($action === 'BLOCK' ? '192.168.1.' : '10.0.0.') . random_int(20, 220),
                'result'       => $tag . '/' . $status,
                'cache_status' => $tag,
                'cached'       => $cached,
                'action'       => $action,
                'status'       => $status,
                'bytes'        => $bytes,
                'duration_ms'  => random_int(3, 480),
                'method'       => 'GET',
                'url'          => 'http://' . $domain . '/' . bin2hex(random_bytes(4)),
                'domain'       => $domain,
                'user'         => '',
            ];
        }

        return $entries;
    }

    /**
     * Mock: Debian OS accounts for development without a running Linux daemon.
     */
    private function mockOsUsers(): array
    {
        return [
            [
                'username'       => 'ministrator',
                'uid'            => 1000,
                'gid'            => 1000,
                'gecos'          => 'MicronOS Administrator',
                'home'           => '/home/ministrator',
                'shell'          => '/bin/bash',
                'is_admin'       => true,
                'has_ssh_keys'   => false,
                'ssh_keys_count' => 0,
            ],
            [
                'username'       => 'root',
                'uid'            => 0,
                'gid'            => 0,
                'gecos'          => 'root',
                'home'           => '/root',
                'shell'          => '/bin/bash',
                'is_admin'       => true,
                'has_ssh_keys'   => false,
                'ssh_keys_count' => 0,
            ],
        ];
    }

    private function mockBondList(): array
    {
        return [
            [
                'name'         => 'bond0',
                'mode'         => 'IEEE 802.3ad Dynamic link aggregation',
                'slaves'       => ['enp1s0', 'enp2s0'],
                'active_slave' => 'enp1s0',
                'raw_info'     => 'Bonding Mode: IEEE 802.3ad Dynamic link aggregation',
            ],
        ];
    }

    /**
     * Mock: DPI live active flows
     */
    private function mockDpiLiveFlows(): array
    {
        return [
            [
                'id'          => 'flw_' . bin2hex(random_bytes(4)),
                'src_ip'      => '192.168.1.105',
                'src_port'    => 54210,
                'dst_ip'      => '142.250.190.46',
                'dst_port'    => 443,
                'proto'       => 'TCP',
                'app_id'      => 'youtube',
                'app_name'    => 'YouTube',
                'category'    => 'streaming',
                'risk_level'  => 2,
                'throughput'  => '4.2 Mbps',
                'packets'     => 14208,
                'bytes'       => 15284192,
                'action'      => 'allow',
                'action_label'=> 'Allowed',
            ],
            [
                'id'          => 'flw_' . bin2hex(random_bytes(4)),
                'src_ip'      => '192.168.1.112',
                'src_port'    => 49182,
                'dst_ip'      => '172.67.142.18',
                'dst_port'    => 3333,
                'proto'       => 'TCP',
                'app_id'      => 'stratum',
                'app_name'    => 'Stratum (Mining)',
                'category'    => 'crypto',
                'risk_level'  => 5,
                'throughput'  => '0.0 Kbps',
                'packets'     => 4,
                'bytes'       => 288,
                'action'      => 'block',
                'action_label'=> 'Blocked by Policy',
            ],
            [
                'id'          => 'flw_' . bin2hex(random_bytes(4)),
                'src_ip'      => '192.168.1.88',
                'src_port'    => 51432,
                'dst_ip'      => '52.113.194.132',
                'dst_port'    => 443,
                'proto'       => 'TCP',
                'app_id'      => 'ms_teams',
                'app_name'    => 'Microsoft Teams',
                'category'    => 'collaboration',
                'risk_level'  => 1,
                'throughput'  => '850.4 Kbps',
                'packets'     => 8920,
                'bytes'       => 4892104,
                'action'      => 'allow',
                'action_label'=> 'Allowed',
            ],
            [
                'id'          => 'flw_' . bin2hex(random_bytes(4)),
                'src_ip'      => '192.168.1.140',
                'src_port'    => 6881,
                'dst_ip'      => '185.190.141.22',
                'dst_port'    => 51413,
                'proto'       => 'UDP',
                'app_id'      => 'bittorrent',
                'app_name'    => 'BitTorrent',
                'category'    => 'p2p',
                'risk_level'  => 4,
                'throughput'  => '12.4 Mbps',
                'packets'     => 45290,
                'bytes'       => 48190240,
                'action'      => 'shape',
                'action_label'=> 'Shaped (2 Mbps Limit)',
            ],
            [
                'id'          => 'flw_' . bin2hex(random_bytes(4)),
                'src_ip'      => '192.168.1.101',
                'src_port'    => 58210,
                'dst_ip'      => '104.18.32.7',
                'dst_port'    => 443,
                'proto'       => 'TCP',
                'app_id'      => 'claude_ai',
                'app_name'    => 'Anthropic Claude',
                'category'    => 'ai',
                'risk_level'  => 2,
                'throughput'  => '124.8 Kbps',
                'packets'     => 1420,
                'bytes'       => 821940,
                'action'      => 'allow',
                'action_label'=> 'Allowed',
            ],
        ];
    }

    /**
     * Mock: DPI aggregated telemetry stats
     */
    private function mockDpiStats(): array
    {
        return [
            'top_applications' => [
                ['name' => 'YouTube',          'category' => 'Streaming',     'bytes' => 14820942000, 'formatted' => '13.8 GB', 'percent' => 38],
                ['name' => 'Microsoft Teams',  'category' => 'Collaboration', 'bytes' => 8419200000,  'formatted' => '7.8 GB',  'percent' => 22],
                ['name' => 'Netflix',          'category' => 'Streaming',     'bytes' => 6102900000,  'formatted' => '5.7 GB',  'percent' => 16],
                ['name' => 'BitTorrent',       'category' => 'P2P',           'bytes' => 4819024000,  'formatted' => '4.5 GB',  'percent' => 12],
                ['name' => 'OpenAI ChatGPT',   'category' => 'AI',            'bytes' => 2419200000,  'formatted' => '2.3 GB',  'percent' => 6],
                ['name' => 'Anthropic Claude', 'category' => 'AI',            'bytes' => 1892000000,  'formatted' => '1.8 GB',  'percent' => 4],
                ['name' => 'Other Protocols',  'category' => 'General',       'bytes' => 782000000,   'formatted' => '745 MB',  'percent' => 2],
            ],
            'category_breakdown' => [
                ['category' => 'Streaming & Media',      'percent' => 54, 'color' => '#f43f5e'],
                ['category' => 'Collaboration & VoIP',   'percent' => 22, 'color' => '#10b981'],
                ['category' => 'P2P & File Sharing',     'percent' => 12, 'color' => '#f59e0b'],
                ['category' => 'Artificial Intelligence','percent' => 10, 'color' => '#06b6d4'],
                ['category' => 'Other',                  'percent' => 2,  'color' => '#64748b'],
            ],
            'threats_blocked_count' => 412,
            'active_flows_count'    => 1428,
            'dpi_engine_state'      => 'online',
        ];
    }

    /**
     * Mock: SSL/TLS active intercepted sessions
     */
    private function mockSslSessions(): array
    {
        return [
            [
                'id'            => 'ssl_' . bin2hex(random_bytes(4)),
                'client_ip'     => '192.168.1.105',
                'client_port'   => 52140,
                'server_host'   => 'developer.mozilla.org',
                'server_port'   => 443,
                'tls_version'   => 'TLSv1.3',
                'cipher_suite'  => 'TLS_AES_256_GCM_SHA384',
                'mitm_action'   => 'decrypted',
                'mitm_label'    => 'Deep Inspected & Scanned',
                'synthetic_cert'=> 'CN=developer.mozilla.org (0x' . bin2hex(random_bytes(3)) . ')',
                'duration_sec'  => 14,
            ],
            [
                'id'            => 'ssl_' . bin2hex(random_bytes(4)),
                'client_ip'     => '192.168.1.112',
                'client_port'   => 54980,
                'server_host'   => 'login.microsoft.com',
                'server_port'   => 443,
                'tls_version'   => 'TLSv1.3',
                'cipher_suite'  => 'TLS_AES_128_GCM_SHA256',
                'mitm_action'   => 'spliced_bypass',
                'mitm_label'    => 'Spliced (Bypass List: Microsoft Identity)',
                'synthetic_cert'=> 'Original Remote Certificate (Bypassed)',
                'duration_sec'  => 8,
            ],
            [
                'id'            => 'ssl_' . bin2hex(random_bytes(4)),
                'client_ip'     => '192.168.1.88',
                'client_port'   => 56122,
                'server_host'   => 'github.com',
                'server_port'   => 443,
                'tls_version'   => 'TLSv1.3',
                'cipher_suite'  => 'TLS_CHACHA20_POLY1305_SHA256',
                'mitm_action'   => 'decrypted',
                'mitm_label'    => 'Deep Inspected & Scanned',
                'synthetic_cert'=> 'CN=github.com (0x' . bin2hex(random_bytes(3)) . ')',
                'duration_sec'  => 32,
            ],
            [
                'id'            => 'ssl_' . bin2hex(random_bytes(4)),
                'client_ip'     => '192.168.1.140',
                'client_port'   => 51230,
                'server_host'   => 'chase.com',
                'server_port'   => 443,
                'tls_version'   => 'TLSv1.3',
                'cipher_suite'  => 'TLS_AES_256_GCM_SHA384',
                'mitm_action'   => 'spliced_bypass',
                'mitm_label'    => 'Spliced (Category Bypass: Banking & Finance)',
                'synthetic_cert'=> 'Original Remote Certificate (Bypassed)',
                'duration_sec'  => 4,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function log(string $level, string $message): void
    {
        if (function_exists('log_message')) {
            log_message($level, $message);
        }
    }

    private function errorResponse(int $code, string $message): array
    {
        $this->log('error', "[IpcClient] RPC error {$code}: {$message}");
        return [
            'result' => null,
            'error'  => ['code' => $code, 'message' => $message],
        ];
    }

    private function isWindows(): bool
    {
        return str_starts_with(strtoupper(PHP_OS), 'WIN');
    }
}
