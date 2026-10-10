<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS OpenVPN Server & Client Profile Compiler
 * ==================================================
 * Renders:
 *   - road-warrior server daemons       /etc/openvpn/server/<name>.conf
 *   - site-to-site client tunnels       /etc/openvpn/client/<name>.conf
 *   - server-side client-config-dir     /etc/openvpn/server/ccd/<name>
 *   - all-in-one .ovpn bundles with embedded certificates and keys
 *
 * The same `openvpn_servers` row is used for both roles; `mode` decides which
 * compiler method applies ('server' | 'client').
 */
class OpenvpnCompiler
{
    /** Directory holding this appliance's own certificates per role. */
    public const SERVER_CERT_DIR = '/etc/openvpn/server';
    public const CLIENT_CERT_DIR = '/etc/openvpn/client';

    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // -------------------------------------------------------------------------
    // Server (road warrior + site-to-site head end)
    // -------------------------------------------------------------------------

    /**
     * Compiles the road-warrior server daemon configuration.
     */
    public function compileServerConfig(array $server): string
    {
        $s = $this->normalize($server);

        $parts    = explode('/', $s['tunnel_network']);
        $netIp    = $parts[0];
        $netmask  = $this->cidrToNetmask($parts[1] ?? '24');
        $gateway  = long2ip((int) ip2long($netIp) + 1);         // server takes .1
        // Pushed resolvers: explicit dns_servers, else the tunnel gateway
        $dnsList  = $s['dns'] !== [] ? $s['dns'] : [$gateway];

        $lines   = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled OpenVPN Server';
        $lines[] = '# Instance: ' . $s['name'];
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = "port {$s['port']}";
        $lines[] = "proto {$s['protocol']}";
        $lines[] = 'dev ' . $s['interface_name'];
        $lines[] = 'topology subnet';
        $lines[] = 'ca ' . self::SERVER_CERT_DIR . '/ca.crt';
        $lines[] = 'cert ' . self::SERVER_CERT_DIR . '/server.crt';
        $lines[] = 'key ' . self::SERVER_CERT_DIR . '/server.key';
        $lines[] = 'dh ' . self::SERVER_CERT_DIR . '/dh2048.pem';
        if ($s['tls_auth']) {
            $lines[] = 'tls-auth ' . self::SERVER_CERT_DIR . '/ta.key 0';
        }
        $lines[] = "server {$netIp} {$netmask}";
        $lines[] = 'ifconfig-pool-persist /var/log/openvpn/ipp.txt';
        // Site-to-site peers need a per-client directory for ifconfig-push/iroute
        $lines[] = 'client-config-dir ' . self::SERVER_CERT_DIR . '/ccd';

        if ($s['redirect_gateway']) {
            $lines[] = 'push "redirect-gateway def1 bypass-dhcp"';
        }
        foreach ($dnsList as $dns) {
            $lines[] = 'push "dhcp-option DNS ' . $dns . '"';
        }
        foreach ($s['routes_pushed'] as $route) {
            $lines[] = 'push "route ' . $this->routeToOpenVpn($route) . '"';
        }

        // Site-to-site peers: every LAN sitting behind one of our client tunnels
        // needs a route on this head end (so the kernel can reach it) and must be
        // pushed to the other clients (so they can too).
        // from each Client Specific Override.
        foreach ($this->peerNetworks() as $network) {
            $lines[] = 'route ' . $this->routeToOpenVpn($network);
            $lines[] = 'push "route ' . $this->routeToOpenVpn($network) . '"';
        }

        $lines[] = 'keepalive 10 120';
        $lines[] = 'cipher ' . $s['cipher'];
        $lines[] = 'auth ' . $s['auth_digest'];
        $lines[] = 'user nobody';
        $lines[] = 'group nogroup';
        $lines[] = 'persist-key';
        $lines[] = 'persist-tun';
        $lines[] = 'status /var/log/openvpn/openvpn-status.log';
        $lines[] = 'verb 3';
        $lines[] = 'explicit-exit-notify 1';

        return implode("\n", $lines);
    }

    /**
     * Server-side client-config-dir entry for a site-to-site peer.
     *
     * Deploy this on the REMOTE server, which is what terminates the tunnel at
     * the other end. `ifconfig-push <peer-address> <server-address>` mirrors the
     * peer's own `ifconfig <peer-address> <server-address>` line, and every
     * network behind the peer (`local_subnets`) is registered with `iroute` so
     * the remote server routes it into the tunnel.
     *
     * Only `iroute` belongs here. `push "route ..."` is deliberately NOT emitted
     * per-peer: pushes inside a ccd would apply to that one client. The remote
     * server learns these networks from the generated server config instead
     * (compileServerConfig pushes every peer network to all clients).
     */
    public function compileCcd(array $client): string
    {
        $c = $this->normalize($client);

        $lines   = [];
        $lines[] = '# MicronOS OpenVPN client-config-dir entry (server side)';
        $lines[] = '# Peer: ' . $c['name'];
        $lines[] = '# Deploy to: ' . self::SERVER_CERT_DIR . '/ccd/' . $this->ccdName($c);

        if ($c['tunnel_local_ip'] !== '' && $c['tunnel_remote_ip'] !== '') {
            $lines[] = '# Tunnel interface addresses: the peer receives the first address,';
            $lines[] = '# this server keeps the second (its own tunnel IP).';
            $lines[] = "ifconfig-push {$c['tunnel_local_ip']} {$c['tunnel_remote_ip']}";
        }

        $networks = $c['local_subnets'] !== [] ? $c['local_subnets'] : [];

        if ($networks === []) {
            $lines[] = '';
            $lines[] = '# No networks behind the peer were declared, so nothing is irouted.';
            $lines[] = '# Add them under "IPv4 Local Network(s)" on the Site-to-Site Clients tab.';
        }

        foreach ($networks as $subnet) {
            $lines[] = '# Networks behind the peer — routed into the tunnel by the remote server';
            $lines[] = 'iroute ' . $this->routeToOpenVpn($subnet);
        }

        return implode("\n", $lines);
    }

    /**
     * All networks declared behind our site-to-site peers.
     *
     * @return list<string>
     */
    protected function peerNetworks(): array
    {
        try {
            $rows = $this->db->table('openvpn_servers')
                ->select('local_subnets')
                ->where('mode', 'client')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }

        $networks = [];
        foreach ($rows as $row) {
            foreach ($this->csvList($row['local_subnets'] ?? '') as $network) {
                $networks[$network] = true;
            }
        }

        return array_keys($networks);
    }

    // -------------------------------------------------------------------------
    // Client (site-to-site / tunnel client)
    // -------------------------------------------------------------------------

    /**
     * Compiles an OpenVPN client tunnel configuration.
     *
     * When $certs is supplied (ca/cert/key/tls-auth bodies) the material is
     * embedded inline, otherwise relative file paths from /etc/openvpn/client
     * are referenced — that is the form the appliance daemon consumes.
     *
     * @param array $client  openvpn_servers row with mode = 'client'
     * @param array $certs   optional ['ca' => .., 'cert' => .., 'key' => .., 'tls_auth' => ..]
     */
    public function compileClientConfig(array $client, array $certs = []): string
    {
        $c = $this->normalize($client);

        $remoteHost = $c['remote_host'] !== '' ? $c['remote_host'] : 'vpn.example.com';
        $remotePort = $c['remote_port'] ?: $c['port'];

        $lines   = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled OpenVPN Client (Site-to-Site)';
        $lines[] = '# Instance: ' . $c['name'];
        $lines[] = '# Remote peer: ' . $remoteHost . ':' . $remotePort;
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = 'client';
        $lines[] = 'dev ' . $c['interface_name'];
        $lines[] = "proto {$c['protocol']}";
        $lines[] = "remote {$remoteHost} {$remotePort}";
        $lines[] = 'resolv-retry infinite';
        $lines[] = 'nobind';
        $lines[] = 'persist-key';
        $lines[] = 'persist-tun';
        $lines[] = 'remote-cert-tls server';

        if ($c['verify_x509_name'] !== '') {
            $lines[] = 'verify-x509-name "' . $c['verify_x509_name'] . '" name';
        }

        $lines[] = 'cipher ' . $c['cipher'];
        $lines[] = 'auth ' . $c['auth_digest'];

        if ($c['tls_auth']) {
            $lines[] = 'key-direction 1';
        }

        // Point-to-point tunnel addresses: ifconfig <local> <remote>
        if ($c['tunnel_local_ip'] !== '' && $c['tunnel_remote_ip'] !== '') {
            $lines[] = 'topology p2p';
            $lines[] = "ifconfig {$c['tunnel_local_ip']} {$c['tunnel_remote_ip']}";
        } elseif ($c['tunnel_network'] !== '') {
            $lines[] = 'topology subnet';
        }

        // IPv4 Remote Network(s) — LANs reachable through the tunnel
        foreach ($c['remote_subnets'] as $subnet) {
            $lines[] = 'route ' . $this->routeToOpenVpn($subnet);
        }

        // IPv4 Local Network(s) — networks behind this appliance. They are not
        // routed locally; they are exported in this instance's ccd file so the
        // remote server can iroute them back down the tunnel.
        if ($c['local_subnets'] !== []) {
            $lines[] = '# Networks behind this appliance (deploy the matching ccd on the remote server): ' . implode(', ', $c['local_subnets']);
        }

        if ($c['redirect_gateway']) {
            $lines[] = 'redirect-gateway def1';
        }

        if ($c['compression'] !== '') {
            $lines[] = 'compress ' . $c['compression'];
        }

        $lines[] = 'keepalive 10 60';
        $lines[] = 'verb 3';
        $lines[] = 'mute 20';

        // Certificates: inline when provided, otherwise reference the files
        if (! empty($certs)) {
            foreach (['ca' => 'ca', 'cert' => 'cert', 'key' => 'key'] as $tag => $file) {
                if (! empty($certs[$tag])) {
                    $lines[] = '';
                    $lines[] = "<{$tag}>\n" . trim($certs[$tag]) . "\n</{$tag}>";
                }
            }
            if ($c['tls_auth'] && ! empty($certs['tls_auth'])) {
                $lines[] = '';
                $lines[] = "<tls-auth>\n" . trim($certs['tls_auth']) . "\n</tls-auth>";
            }
        } else {
            $lines[] = 'ca ' . self::CLIENT_CERT_DIR . '/ca.crt';
            $lines[] = 'cert ' . self::CLIENT_CERT_DIR . '/client.crt';
            $lines[] = 'key ' . self::CLIENT_CERT_DIR . '/client.key';
            if ($c['tls_auth']) {
                $lines[] = 'tls-auth ' . self::CLIENT_CERT_DIR . '/ta.key 1';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Compiles an all-in-one .ovpn client configuration bundle.
     */
    public function generateClientBundle(array $server, string $clientName, string $caCert, string $clientCert, string $clientKey, string $tlsAuthKey): string
    {
        $s      = $this->normalize($server);
        $endpoint = $s['public_endpoint'] !== '' ? $s['public_endpoint'] : 'vpn.micronos.test';

        $lines   = [];
        $lines[] = '# MicronOS OpenVPN Client Bundle';
        $lines[] = '# Profile: ' . $clientName;
        $lines[] = 'client';
        $lines[] = 'dev ' . $s['interface_name'];
        $lines[] = "proto {$s['protocol']}";
        $lines[] = "remote {$endpoint} {$s['port']}";
        $lines[] = 'resolv-retry infinite';
        $lines[] = 'nobind';
        $lines[] = 'persist-key';
        $lines[] = 'persist-tun';
        $lines[] = 'remote-cert-tls server';
        $lines[] = 'cipher ' . $s['cipher'];
        $lines[] = 'auth ' . $s['auth_digest'];
        if ($s['tls_auth']) {
            $lines[] = 'key-direction 1';
        }
        $lines[] = 'verb 3';
        $lines[] = '';
        $lines[] = "<ca>\n" . trim($caCert) . "\n</ca>";
        $lines[] = "<cert>\n" . trim($clientCert) . "\n</cert>";
        $lines[] = "<key>\n" . trim($clientKey) . "\n</key>";
        if ($s['tls_auth']) {
            $lines[] = "<tls-auth>\n" . trim($tlsAuthKey) . "\n</tls-auth>";
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalises a row so both legacy/alternative column names work.
     *
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $name = (string) ($row['name'] ?? $row['id'] ?? 'openvpn');

        return [
            'id'               => (string) ($row['id'] ?? ''),
            'name'             => $name,
            'mode'             => ($row['mode'] ?? 'server') === 'client' ? 'client' : 'server',
            'protocol'         => strtolower((string) ($row['protocol'] ?? $row['proto'] ?? 'udp')),
            'port'             => (int) ($row['listen_port'] ?? $row['port'] ?? 1194),
            'interface_name'   => (string) ($row['interface_name'] ?? 'tun0'),
            'public_endpoint'  => trim((string) ($row['public_endpoint'] ?? '')),
            'tunnel_network'   => (string) ($row['tunnel_subnet'] ?? $row['tunnel_network'] ?? '10.8.0.0/24'),
            'remote_host'      => trim((string) ($row['remote_host'] ?? '')),
            'remote_port'      => (int) ($row['remote_port'] ?? 0),
            'tunnel_local_ip'  => trim((string) ($row['tunnel_local_ip'] ?? '')),
            'tunnel_remote_ip' => trim((string) ($row['tunnel_remote_ip'] ?? '')),
            'remote_subnets'   => $this->csvList($row['remote_subnets'] ?? ''),
            'local_subnets'    => $this->csvList($row['local_subnets'] ?? ''),
            'routes_pushed'    => $this->csvList($row['routes_pushed'] ?? ''),
            'dns'              => $this->csvList($row['dns_servers'] ?? ''),
            'verify_x509_name' => trim((string) ($row['verify_x509_name'] ?? '')),
            'cipher'           => (string) ($row['cipher'] ?? 'AES-256-GCM'),
            'auth_digest'      => (string) ($row['auth_digest'] ?? 'SHA256'),
            'tls_auth'         => (int) ($row['tls_auth'] ?? 1) === 1,
            'redirect_gateway' => (int) ($row['redirect_gateway'] ?? 1) === 1,
            'compression'      => (string) ($row['compression'] ?? ''),
            'enabled'          => (int) ($row['enabled'] ?? 1),
        ];
    }

    /** Safe filename for the client-config-dir entry. */
    public function ccdName(array $client): string
    {
        $n = $this->normalize($client)['name'];

        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $n) ?: 'peer';
    }

    /**
     * Converts a CIDR or "network netmask" value into OpenVPN's
     * `<network> <netmask>` form.
     */
    protected function routeToOpenVpn(string $cidr): string
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return '';
        }

        // Already "10.0.0.0 255.255.255.0"
        if (str_contains($cidr, ' ') && ! str_contains($cidr, '/')) {
            return $cidr;
        }

        [$net, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');

        return $net . ' ' . $this->cidrToNetmask($prefix);
    }

    /**
     * @return list<string>
     */
    protected function csvList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), static fn ($v) => $v !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn ($v) => $v !== ''));
    }

    protected function cidrToNetmask(string $cidr): string
    {
        $cidr = (int) $cidr;
        $mask = ~((1 << (32 - $cidr)) - 1);

        return long2ip($mask);
    }

    /**
     * Reads a certificate/key from disk, falling back to a demo body on dev hosts.
     */
    public function loadCert(string $path, string $fallback): string
    {
        return (PHP_OS_FAMILY !== 'Windows' && is_file($path))
            ? (string) file_get_contents($path)
            : $fallback;
    }
}
