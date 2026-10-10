<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS strongSwan IPsec IKEv2 Configuration Compiler
 * =======================================================
 * Compiles `ipsec_tunnels` records into modern strongSwan `/etc/swanctl/swanctl.conf`
 * format, in either of the two shapes an appliance needs:
 *
 *   Policy-based (default) — the tunnel IS the traffic selectors
 *     (local_subnet ↔ remote_subnet). No interface, no address of its own.
 *
 *   Route-based / VTI (vti_enabled) — the tunnel gets a real interface with an
 *     address pair, exactly like OpenVPN's `ifconfig` or WireGuard's Address:
 *          us:   vti_local_ip        (e.g. 10.10.1.1/30)
 *          peer: vti_remote_ip       (e.g. 10.10.1.2/30)
 *     The connection is pinned to the interface with `if_id_in` / `if_id_out`
 *     equal to vti_mark, traffic selectors widen to 0.0.0.0/0, and the
 *     accompanying `compileVtiScript()` produces the `ip tunnel add` /
 *     `ip addr add` / `ip route add` commands that create and address it.
 */
class StrongswanCompiler
{
    /** Default strongSwan if_id / VTI key when a tunnel does not set one. */
    public const DEFAULT_VTI_MARK = 1;

    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // -------------------------------------------------------------------------
    // swanctl.conf
    // -------------------------------------------------------------------------

    public function compile(): string
    {
        $tunnels = $this->fetchTunnels();

        $lines   = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled strongSwan swanctl.conf';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ' . count($tunnels) . ' enabled tunnel(s)';
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';
        $lines[] = 'connections {';

        $secrets = [];

        foreach ($tunnels as $tunnel) {
            $t               = $this->normalize($tunnel);
            $ikeProposals    = $t['ike_cipher'] !== '' ? $this->toSwanctlProposal($t['ike_cipher']) : 'aes256-sha256-modp2048,aes128-sha256-modp2048';
            $espProposals    = $t['esp_cipher'] !== '' ? $this->toSwanctlProposal($t['esp_cipher'], true) : 'aes256-sha256,aes128-sha256';
            $localTs         = $t['vti_enabled'] ? '0.0.0.0/0' : ($t['local_subnet'] !== '' ? $t['local_subnet'] : '0.0.0.0/0');
            $remoteTs        = $t['vti_enabled'] ? '0.0.0.0/0' : ($t['remote_subnet'] !== '' ? $t['remote_subnet'] : '0.0.0.0/0');

            $lines[] = "    {$t['conn']} {";
            $lines[] = '        version = ' . ($t['ike_version'] === 'ikev1' ? '1' : '2');
            $lines[] = '        local_addrs = ' . $t['local_gateway'];
            $lines[] = '        remote_addrs = ' . $t['remote_gateway'];

            if ($t['vti_enabled']) {
                $lines[] = '';
                $lines[] = '        # Route-based (VTI): pin the CHILD_SA to the tunnel interface.';
                $lines[] = '        # if_id must match the VTI key used by `ip tunnel add ... key <n>`.';
                $lines[] = '        if_id_in = ' . $t['vti_mark'];
                $lines[] = '        if_id_out = ' . $t['vti_mark'];
            }

            $lines[] = "        proposals = {$ikeProposals}";
            $lines[] = '';
            $lines[] = '        local {';
            $lines[] = '            auth = ' . $t['auth_type'];
            $lines[] = '            id = ' . $t['local_id'];
            $lines[] = '        }';
            $lines[] = '';
            $lines[] = '        remote {';
            $lines[] = '            auth = ' . $t['auth_type'];
            $lines[] = '            id = ' . $t['remote_id'];
            $lines[] = '        }';
            $lines[] = '';
            $lines[] = '        children {';
            $lines[] = "            {$t['conn']}_child {";
            $lines[] = '                local_ts = ' . $localTs;
            $lines[] = '                remote_ts = ' . $remoteTs;
            $lines[] = "                esp_proposals = {$espProposals}";
            $lines[] = '                start_action = ' . ($t['mode'] === 'roadwarrior' ? 'trap' : 'start');
            $lines[] = '                dpd_action = restart';
            if ($t['mode'] === 'roadwarrior') {
                $lines[] = '                close_action = none';
            }
            $lines[] = '            }';
            $lines[] = '        }';
            $lines[] = '    }';
            $lines[] = '';

            if ($t['psk_secret'] !== '') {
                $secrets[] = [
                    'id'  => $t['remote_id'],
                    'key' => $t['psk_secret'],
                ];
            }
        }

        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'secrets {';
        $lines[] = '    ike-psk {';
        foreach ($secrets as $index => $secret) {
            $lines[] = "        secret{$index} {";
            $lines[] = "            id = \"{$secret['id']}\"";
            $lines[] = "            secret = \"{$secret['key']}\"";
            $lines[] = '        }';
        }
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // VTI interface addressing
    // -------------------------------------------------------------------------

    /**
     * The shell commands that create the route-based tunnel interface and give
     * it an IP address — the IPsec equivalent of OpenVPN's `ifconfig`.
     */
    public function compileVtiScript(array $tunnel): string
    {
        $t = $this->normalize($tunnel);

        if (! $t['vti_enabled']) {
            return "# MicronOS VTI\n# {$t['name']} is policy-based (no tunnel interface, no tunnel IP).\n"
                . "# Enable \"Route-based (VTI)\" and set the local/remote tunnel addresses to use one.\n";
        }

        $iface  = $t['vti_interface'];
        $local  = $t['local_gateway'] === '%defaultroute' ? '<local-wan-ip>' : $t['local_gateway'];
        $remote = $t['remote_gateway'] === '%any' ? '<remote-gateway-ip>' : $t['remote_gateway'];
        $ours   = $t['vti_local_ip']  !== '' ? $t['vti_local_ip']  : '10.10.1.1';
        $theirs = $t['vti_remote_ip'] !== '' ? $t['vti_remote_ip'] : '10.10.1.2';

        $lines   = [];
        $lines[] = '#!/bin/sh';
        $lines[] = '# MicronOS — create the VTI for IPsec tunnel ' . $t['name'];
        $lines[] = '# Tunnel addresses:  this appliance ' . $ours . '  <->  peer ' . $theirs;
        $lines[] = '# The VTI key must equal if_id_in/if_id_out (' . $t['vti_mark'] . ') in swanctl.conf.';
        $lines[] = 'set -e';
        $lines[] = '';
        $lines[] = 'ip tunnel add ' . $iface . ' local ' . $local . ' remote ' . $remote . ' mode vti key ' . $t['vti_mark'];
        $lines[] = 'sysctl -w net.ipv4.conf.' . $iface . '.disable_policy=1';
        $lines[] = 'ip link set ' . $iface . ' up';
        $lines[] = '# Only our own tunnel address goes on the interface — the peer keeps its own.';
        $lines[] = 'ip address add ' . $this->toCidr($ours) . ' dev ' . $iface;

        // Networks behind the peer are reached over the VTI, next hop = the peer's
        // tunnel address (that is what vti_remote_ip is for).
        $nextHop = str_contains($theirs, '/') ? explode('/', $theirs)[0] : $theirs;
        if ($t['remote_subnet'] !== '') {
            foreach (Cidr::split($t['remote_subnet']) as $network) {
                $lines[] = 'ip route replace ' . $network . ' via ' . $nextHop . ' dev ' . $iface;
            }
        } else {
            $lines[] = 'ip route replace default via ' . $nextHop . ' dev ' . $iface;
        }

        $lines[] = '';
        $lines[] = '# Then load the connection:  swanctl --load-all';

        return implode("\n", $lines) . "\n";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Maps a stored proposal string onto strongSwan's IKE/ESP proposal syntax.
     *
     * The UI offers "aes256gcm16-prfsha384-ecp384" style values (IKE form).
     * ESP proposals must not carry a PRF, so it is stripped there — strongSwan
     * rejects `aes256gcm16-prfsha384-ecp384` in an ESP proposal.
     */
    public function toSwanctlProposal(string $value, bool $esp = false): string
    {
        $parts = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $proposal) {
            $tokens = explode('-', $proposal);
            if ($esp) {
                $tokens = array_filter($tokens, static fn ($token) => ! str_starts_with($token, 'prf'));
            }
            $parts[] = implode('-', $tokens);
        }

        return implode(',', $parts) ?: $value;
    }

    public function normalize(array $tunnel): array
    {
        $name = (string) ($tunnel['name'] ?? $tunnel['id'] ?? 'tunnel');
        $conn = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?: 'tunnel';

        return [
            'id'             => (string) ($tunnel['id'] ?? ''),
            'name'           => $name,
            'conn'           => $conn,
            'ike_version'    => strtolower((string) ($tunnel['ike_version'] ?? 'ikev2')),
            'mode'           => ($tunnel['mode'] ?? 'site2site') === 'roadwarrior' ? 'roadwarrior' : 'site2site',
            'auth_type'      => (string) ($tunnel['auth_type'] ?? 'psk'),
            'psk_secret'     => (string) ($tunnel['psk_secret'] ?? $tunnel['preshared_key'] ?? ''),
            'local_gateway'  => trim((string) ($tunnel['local_gateway'] ?? '')) ?: '%defaultroute',
            'remote_gateway' => trim((string) ($tunnel['remote_gateway'] ?? '')) ?: '%any',
            'local_id'       => trim((string) ($tunnel['local_id'] ?? '')) ?: '%any',
            'remote_id'      => trim((string) ($tunnel['remote_id'] ?? '')) ?: '%any',
            // Traffic selectors: one or many networks, comma-separated.
            // strongSwan accepts `local_ts = 192.168.1.0/24, 192.168.5.0/24`.
            'local_subnet'   => Cidr::normalise((string) ($tunnel['local_subnet'] ?? '')),
            'remote_subnet'  => Cidr::normalise((string) ($tunnel['remote_subnet'] ?? '')),
            'ike_cipher'     => trim((string) ($tunnel['ike_cipher'] ?? '')),
            'esp_cipher'     => trim((string) ($tunnel['esp_cipher'] ?? '')),
            'vti_enabled'    => (int) ($tunnel['vti_enabled'] ?? 0) === 1,
            'vti_interface'  => trim((string) ($tunnel['vti_interface'] ?? '')) ?: 'vti0',
            'vti_local_ip'   => trim((string) ($tunnel['vti_local_ip'] ?? '')),
            'vti_remote_ip'  => trim((string) ($tunnel['vti_remote_ip'] ?? '')),
            'vti_mark'       => (int) ($tunnel['vti_mark'] ?? 0) ?: self::DEFAULT_VTI_MARK,
        ];
    }

    /** Appends /30 when an address was entered without a prefix. */
    protected function toCidr(string $address): string
    {
        return str_contains($address, '/') ? $address : $address . '/30';
    }

    protected function fetchTunnels(): array
    {
        try {
            return $this->db->table('ipsec_tunnels')->where('enabled', 1)->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
