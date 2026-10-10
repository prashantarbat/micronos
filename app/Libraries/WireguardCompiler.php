<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS WireGuard Configuration Compiler
 * =========================================
 * Renders, per tunnel:
 *
 *   /etc/wireguard/<iface>.conf  — interface + peer definitions, consumed by
 *                                  `wg setconf` (compileServerConfig)
 *   apply script                 — the `ip` commands that give the interface its
 *                                  ADDRESS and bring it up (compileApplyScript)
 *   client .conf                 — road-warrior profile for a peer
 *                                  (compileClientProfile)
 *
 * Addressing model (the part that matters):
 *   wireguard_tunnels.ipv4_address/ipv4_subnet → the IP of the tunnel interface
 *        ON THIS APPLIANCE, e.g. 10.9.0.1/24. This is the interface IP.
 *   wireguard_peers.peer_address              → the tunnel interface IP of the
 *        OTHER end, e.g. 10.9.0.2/32. WireGuard has no per-peer address field,
 *        so this is compiled as the first AllowedIPs entry (the "cryptokey
 *        routing" entry) exactly as `wg` expects.
 *   wireguard_peers.allowed_ips               → extra networks routed through
 *        that peer (its LANs), appended to AllowedIPs.
 */
class WireguardCompiler
{
    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // -------------------------------------------------------------------------
    // Interface addressing helpers
    // -------------------------------------------------------------------------

    /**
     * Every address the tunnel interface carries, as a list of CIDRs.
     *
     * A WireGuard interface can hold several addresses (dual-stack tunnels,
     * extra ULA or link subnets). These come from the `addresses` list when set,
     * otherwise from the legacy single ipv4_address/ipv4_subnet pair.
     *
     * @return list<string>
     */
    public function tunnelCidrs(array $tunnel): array
    {
        $list = Cidr::split((string) ($tunnel['addresses'] ?? ''));
        if ($list !== []) {
            $valid = array_values(array_filter($list, static fn ($entry) => Cidr::isValid($entry)));

            // IPv6 disabled system-wide (System > Networking): no v6 interface address.
            if (! $this->ipv6Enabled()) {
                $valid = array_values(array_filter($valid, static fn ($entry) => Cidr::family($entry) !== 'ipv6'));
            }

            return $valid;
        }

        $legacy = $this->legacyCidr($tunnel);

        return $legacy === '' ? [] : [$legacy];
    }

    /**
     * Whether IPv6 is enabled appliance-wide. Defaults to true so compiling
     * still works when the settings table is not reachable.
     */
    protected function ipv6Enabled(): bool
    {
        try {
            $row = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
            return (int) ($row['ipv6_enabled'] ?? 1) === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * The tunnel interface's primary address as CIDR, e.g. "10.9.0.1/24".
     *
     * Used for display and for the DNS fallback; the first entry wins.
     */
    public function tunnelCidr(array $tunnel): string
    {
        $cidrs = $this->tunnelCidrs($tunnel);

        return $cidrs === [] ? '' : $cidrs[0];
    }

    /**
     * Reads the pre-`addresses` column pair and returns it as CIDR.
     */
    protected function legacyCidr(array $tunnel): string
    {
        $address = trim((string) ($tunnel['ipv4_address'] ?? ''));
        if ($address === '') {
            return '';
        }

        // Already a CIDR (10.9.0.1/24 or fd00::1/64)
        if (str_contains($address, '/')) {
            return $address;
        }

        $prefix = $tunnel['ipv4_subnet'] ?? null;
        if ($prefix === null || $prefix === '' || (int) $prefix <= 0) {
            return $address;
        }

        return $address . '/' . (int) $prefix;
    }

    /**
     * The interface's own IP, without the prefix length (used for DNS pushes).
     */
    public function tunnelAddress(array $tunnel): string
    {
        $cidr = $this->tunnelCidr($tunnel);

        return $cidr === '' ? '' : (string) explode('/', $cidr)[0];
    }

    /**
     * The tunnel interface's own network, e.g. "10.9.0.0/24".
     *
     * Used both for the interface's connected route and as the default
     * AllowedIPs handed to a client profile (split tunnel).
     */
    public function tunnelNetwork(array $tunnel): string
    {
        $cidr = $this->tunnelCidr($tunnel);
        if ($cidr === '' || ! str_contains($cidr, '/')) {
            return '';
        }

        [$address, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        if (str_contains($address, ':')) {
            $packed  = @inet_pton($address);
            if ($packed === false) {
                return '';
            }
            $bytes = $prefix >> 3;
            for ($i = $bytes + 1; $i < 16; $i++) {
                $packed[$i] = chr(0);
            }
            if (($rem = $prefix % 8) !== 0 && $bytes < 16) {
                $packed[$bytes] = chr(ord($packed[$bytes]) & (0xFF << (8 - $rem)));
            }

            return inet_ntop($packed) . '/' . $prefix;
        }

        $long = ip2long($address);
        if ($long === false) {
            return '';
        }
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));

        return long2ip($long & $mask) . '/' . $prefix;
    }

    /**
     * Builds a peer's complete AllowedIPs list: its tunnel address(es) first,
     * then any networks routed through it.
     *
     * Several entries are normal and expected — a dual-stack peer has a v4 and a
     * v6 tunnel address, and a site-to-site peer carries its whole LAN list.
     */
    public function peerAllowedIps(array $peer): string
    {
        $entries = [];
        $ipv6    = $this->ipv6Enabled();

        // Peer tunnel address(es): 10.9.0.2/32, fd00::2/128
        foreach (Cidr::split((string) ($peer['peer_address'] ?? '')) as $address) {
            if (Cidr::isValid($address) && ($ipv6 || Cidr::family($address) !== 'ipv6')) {
                $entries[] = Cidr::withHostPrefix($address);
            }
        }

        // Networks routed through the peer
        foreach (Cidr::split((string) ($peer['allowed_ips'] ?? '')) as $network) {
            if (Cidr::isValid($network) && ($ipv6 || Cidr::family($network) !== 'ipv6')) {
                $entries[] = $network;
            }
        }

        return $entries === [] ? '' : implode(', ', array_values(array_unique($entries)));
    }

    // -------------------------------------------------------------------------
    // Interface configuration — /etc/wireguard/<iface>.conf
    // -------------------------------------------------------------------------

    /**
     * Compiles the WireGuard interface configuration for one tunnel.
     */
    public function compileServerConfig(string $tunnelId = ''): string
    {
        $tunnel = $this->fetchTunnel($tunnelId);
        if ($tunnel === null) {
            return "# MicronOS WireGuard\n# No tunnel record found (requested: {$tunnelId})\n";
        }

        $iface   = $this->interfaceName($tunnel);
        $peers   = $this->fetchPeers((string) $tunnel['id']);
        $cidrs   = $this->tunnelCidrs($tunnel);

        $lines   = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled WireGuard Interface';
        $lines[] = '# Tunnel:       ' . ($tunnel['name'] ?? $iface);
        $lines[] = '# Description:  ' . (($tunnel['description'] ?? '') !== '' ? $tunnel['description'] : '—');
        $lines[] = '# Deploy to:    /etc/wireguard/' . $iface . '.conf';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';
        $lines[] = '[Interface]';
        $lines[] = '# Tunnel interface address(es) for THIS appliance (set with `ip address add`)';
        $lines[] = 'Address = ' . ($cidrs !== [] ? implode(', ', $cidrs) : '10.9.0.1/24');
        $lines[] = 'ListenPort = ' . (int) ($tunnel['listen_port'] ?? 51820);
        $lines[] = 'PrivateKey = ' . (($tunnel['private_key'] ?? '') !== '' ? $tunnel['private_key'] : 'SERVER_PRIVATE_KEY_PLACEHOLDER');
        if (! empty($tunnel['mtu'])) {
            $lines[] = 'MTU = ' . (int) $tunnel['mtu'];
        }
        $lines[] = 'SaveConfig = false';
        $lines[] = '';

        if ($peers === []) {
            $lines[] = '# No peers defined on this tunnel yet.';
            $lines[] = '';
        }

        foreach ($peers as $peer) {
            $allowed = $this->peerAllowedIps($peer);

            $lines[] = '# Peer: ' . ($peer['name'] ?? 'Unnamed peer');
            if (! empty($peer['peer_address'])) {
                $lines[] = '#   tunnel address: ' . $peer['peer_address'];
            }
            $lines[] = '[Peer]';
            $lines[] = 'PublicKey = ' . ($peer['public_key'] ?? '');
            if (! empty($peer['preshared_key'])) {
                $lines[] = 'PresharedKey = ' . $peer['preshared_key'];
            }
            $lines[] = 'AllowedIPs = ' . ($allowed !== '' ? $allowed : '10.9.0.2/32');
            if (! empty($peer['endpoint'])) {
                $lines[] = 'Endpoint = ' . $peer['endpoint'];
            }
            if ((int) ($peer['persistent_keepalive'] ?? 0) > 0) {
                $lines[] = 'PersistentKeepalive = ' . (int) $peer['persistent_keepalive'];
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * The `ip` commands that actually create the tunnel interface and give it
     * its address — the appliance's equivalent of OpenVPN's `ifconfig` line.
     */
    public function compileApplyScript(string $tunnelId = ''): string
    {
        $tunnel = $this->fetchTunnel($tunnelId);
        if ($tunnel === null) {
            return "# MicronOS WireGuard apply script\n# No tunnel record found (requested: {$tunnelId})\n";
        }

        $iface = $this->interfaceName($tunnel);
        $cidrs = $this->tunnelCidrs($tunnel) ?: ['10.9.0.1/24'];
        $port  = (int) ($tunnel['listen_port'] ?? 51820);

        $lines   = [];
        $lines[] = '#!/bin/sh';
        $lines[] = '# MicronOS — bring up WireGuard tunnel ' . ($tunnel['name'] ?? $iface);
        $lines[] = '# Gives the tunnel interface its IP address(es), then loads the peers.';
        $lines[] = 'set -e';
        $lines[] = '';
        $lines[] = 'ip link add dev ' . $iface . ' type wireguard || true';
        $lines[] = '# ' . count($cidrs) . ' address(es) on this interface';
        foreach ($cidrs as $cidr) {
            $lines[] = 'ip address add ' . $cidr . ' dev ' . $iface;
        }
        $lines[] = 'wg set ' . $iface . ' listen-port ' . $port;
        $lines[] = 'wg setconf ' . $iface . ' /etc/wireguard/' . $iface . '.conf';
        $lines[] = 'ip link set ' . $iface . ' mtu ' . (int) ($tunnel['mtu'] ?? 1420) . ' up';

        foreach ($this->fetchPeers((string) $tunnel['id']) as $peer) {
            foreach (Cidr::split((string) ($peer['allowed_ips'] ?? '')) as $network) {
                // A host address is already covered by AllowedIPs; wider networks
                // need an explicit route in the kernel routing table.
                if (Cidr::isNetwork($network)) {
                    $lines[] = 'ip route replace ' . $network . ' dev ' . $iface;
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    // -------------------------------------------------------------------------
    // Peer profiles
    // -------------------------------------------------------------------------

    /**
     * Generates a downloadable client configuration profile.
     *
     * @param array $peer        wireguard_peers row
     * @param array $tunnel      wireguard_tunnels row (the interface we hand out)
     * @param string $endpointHost public host/IP peers dial
     * @param array $routes      networks the client should send through us
     */
    public function generateClientProfile(array $peer, array $tunnel, string $endpointHost, array $routes = []): string
    {
        $peerAddress = trim((string) ($peer['peer_address'] ?? ''));
        if ($peerAddress === '') {
            $peerAddress = '10.9.0.2/32';
        } elseif (! str_contains($peerAddress, '/')) {
            $peerAddress .= '/32';
        }

        $dns = $this->csvList($tunnel['dns_servers'] ?? '');
        if ($dns === [] && $this->tunnelAddress($tunnel) !== '') {
            $dns = [$this->tunnelAddress($tunnel)];
        }

        $allowed = $routes === [] ? ['0.0.0.0/0'] : array_values(array_filter($routes, static fn ($r) => Cidr::isValid($r)));
        if ($allowed === []) {
            $allowed = ['0.0.0.0/0'];
        }

        $lines   = [];
        $lines[] = '# MicronOS WireGuard Client Profile';
        $lines[] = '# Client: ' . ($peer['name'] ?? 'Client');
        $lines[] = '# Tunnel: ' . ($tunnel['name'] ?? $this->interfaceName($tunnel));
        $lines[] = '';
        $lines[] = '[Interface]';
        $lines[] = '# This client\'s tunnel interface address (the peer address configured on the appliance)';
        $lines[] = 'Address = ' . $peerAddress;
        $lines[] = 'PrivateKey = ' . (($peer['private_key'] ?? '') !== '' ? $peer['private_key'] : 'INSERT_CLIENT_PRIVATE_KEY_HERE');
        if ($dns !== []) {
            $lines[] = 'DNS = ' . implode(', ', $dns);
        }
        $lines[] = 'MTU = ' . (int) ($tunnel['mtu'] ?? 1420);
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = '# The appliance\'s tunnel interface public key';
        $lines[] = 'PublicKey = ' . ($tunnel['public_key'] ?? 'SERVER_PUBLIC_KEY');
        if (! empty($peer['preshared_key'])) {
            $lines[] = 'PresharedKey = ' . $peer['preshared_key'];
        }
        $lines[] = 'Endpoint = ' . $endpointHost . ':' . (int) ($tunnel['listen_port'] ?? 51820);
        $lines[] = 'AllowedIPs = ' . implode(', ', $allowed);
        $lines[] = 'PersistentKeepalive = ' . (int) ($peer['persistent_keepalive'] ?? 25);
        $lines[] = '';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Data access
    // -------------------------------------------------------------------------

    public function interfaceName(array $tunnel): string
    {
        $name = trim((string) ($tunnel['interface_name'] ?? ''));

        return $name !== '' ? $name : 'wg0';
    }

    protected function fetchTunnel(string $tunnelId): ?array
    {
        try {
            $builder = $this->db->table('wireguard_tunnels');
            if ($tunnelId !== '') {
                $builder->groupStart()
                    ->where('id', $tunnelId)
                    ->orWhere('interface_name', $tunnelId)
                    ->orWhere('name', $tunnelId)
                    ->groupEnd();
            } else {
                $builder->orderBy('created_at', 'ASC');
            }

            return $builder->get()->getRowArray() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Peers belonging to one tunnel (previously this returned every peer).
     */
    public function fetchPeers(string $tunnelId): array
    {
        try {
            return $this->db->table('wireguard_peers')
                ->where('tunnel_id', $tunnelId)
                ->orderBy('created_at', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
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
}
