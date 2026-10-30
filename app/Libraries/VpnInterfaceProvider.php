<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * VpnInterfaceProvider
 *
 * Centralizes discovery and aggregation of VPN tunnel interfaces
 * (WireGuard tunnels, OpenVPN servers/clients, and IPsec VTI tunnels)
 * alongside physical system network interfaces.
 *
 * Used by:
 *  - Firewall policy rules and DoS mitigation (interfaces & device resolution)
 *  - Dynamic Routing (FRRouting OSPF, RIP, BGP, IS-IS interface binding)
 *  - NAT rules and PBR (Policy-Based Routing)
 *  - Packet diagnostics and interface monitoring
 */
class VpnInterfaceProvider
{
    /**
     * Get categorized VPN interfaces grouped by protocol/type.
     *
     * @return array{
     *   wireguard: list<array<string, mixed>>,
     *   openvpn: list<array<string, mixed>>,
     *   ipsec: list<array<string, mixed>>
     * }
     */
    public static function getVpnInterfaces(?BaseConnection $db = null): array
    {
        $db = $db ?? \Config\Database::connect();

        $wireguard = [];
        if ($db->tableExists('wireguard_tunnels')) {
            try {
                $rows = $db->table('wireguard_tunnels')
                    ->orderBy('interface_name', 'ASC')
                    ->get()
                    ->getResultArray();

                foreach ($rows as $row) {
                    $ifname = trim((string) ($row['interface_name'] ?? ''));
                    if ($ifname === '') {
                        continue;
                    }
                    $name = trim((string) ($row['name'] ?? ''));
                    $desc = trim((string) ($row['description'] ?? ''));
                    $addr = trim((string) ($row['addresses'] ?? ($row['ipv4_address'] ?? '')));
                    if ($addr !== '' && str_contains($addr, ',')) {
                        $addr = explode(',', $addr)[0];
                    }

                    $wireguard[] = [
                        'id'             => $ifname,
                        'name'           => $name !== '' ? $name : $ifname,
                        'interface'      => $ifname,
                        'interface_name' => $ifname,
                        'physical_device'=> $ifname,
                        'description'    => $desc !== '' ? $desc : 'WireGuard Tunnel',
                        'addresses'      => $addr,
                        'ipv4_address'   => $addr,
                        'listen_port'    => (int) ($row['listen_port'] ?? 51820),
                        'enabled'        => (int) ($row['enabled'] ?? 1),
                        'type'           => 'wireguard',
                        'is_vpn'         => true,
                    ];
                }
            } catch (\Throwable) {
                // Table unreadable or migrating
            }
        }

        $openvpn = [];
        if ($db->tableExists('openvpn_servers')) {
            try {
                $rows = $db->table('openvpn_servers')
                    ->orderBy('interface_name', 'ASC')
                    ->get()
                    ->getResultArray();

                foreach ($rows as $row) {
                    $ifname = trim((string) ($row['interface_name'] ?? ''));
                    if ($ifname === '') {
                        $ifname = 'tun0';
                    }
                    $name = trim((string) ($row['name'] ?? ''));
                    $mode = strtolower(trim((string) ($row['mode'] ?? 'server')));
                    $subnet = trim((string) ($row['tunnel_local_ip'] ?? ($row['tunnel_subnet'] ?? '')));

                    $openvpn[] = [
                        'id'             => $ifname,
                        'name'           => $name !== '' ? $name : 'OpenVPN ' . $ifname,
                        'interface'      => $ifname,
                        'interface_name' => $ifname,
                        'physical_device'=> $ifname,
                        'description'    => $mode === 'client' ? 'OpenVPN Client Link' : 'OpenVPN Server Tunnel',
                        'mode'           => $mode,
                        'addresses'      => $subnet,
                        'ipv4_address'   => $subnet,
                        'listen_port'    => (int) ($row['listen_port'] ?? 1194),
                        'enabled'        => (int) ($row['enabled'] ?? 1),
                        'type'           => 'openvpn',
                        'is_vpn'         => true,
                    ];
                }
            } catch (\Throwable) {
                // Table unreadable or migrating
            }
        }

        $ipsec = [];
        if ($db->tableExists('ipsec_tunnels')) {
            try {
                $rows = $db->table('ipsec_tunnels')
                    ->orderBy('id', 'ASC')
                    ->get()
                    ->getResultArray();

                foreach ($rows as $row) {
                    $vtiDev = trim((string) ($row['vti_interface'] ?? ''));
                    $vtiEnabled = !empty($row['vti_enabled']);
                    if (!$vtiEnabled && $vtiDev === '') {
                        continue;
                    }
                    if ($vtiDev === '') {
                        $vtiDev = 'vti' . ((int) ($row['id'] ?? 0));
                    }
                    $name = trim((string) ($row['name'] ?? ''));
                    $addr = trim((string) ($row['vti_local_ip'] ?? ''));

                    $ipsec[] = [
                        'id'             => $vtiDev,
                        'name'           => $name !== '' ? $name : 'IPsec ' . $vtiDev,
                        'interface'      => $vtiDev,
                        'interface_name' => $vtiDev,
                        'vti_interface'  => $vtiDev,
                        'physical_device'=> $vtiDev,
                        'description'    => 'IPsec Route-Based VTI Tunnel',
                        'addresses'      => $addr,
                        'ipv4_address'   => $addr,
                        'enabled'        => (int) ($row['enabled'] ?? 1),
                        'type'           => 'ipsec',
                        'is_vpn'         => true,
                    ];
                }
            } catch (\Throwable) {
                // Table unreadable or migrating
            }
        }

        return [
            'wireguard' => $wireguard,
            'openvpn'   => $openvpn,
            'ipsec'     => $ipsec,
        ];
    }

    /**
     * Get a unified list of all interfaces: physical, virtual, and VPN tunnels.
     * Compatible with the schema of the `interfaces` table.
     *
     * @return list<array<string, mixed>>
     */
    public static function getUnifiedInterfaces(?BaseConnection $db = null, bool $includePhysical = true, bool $enabledOnly = false): array
    {
        $db = $db ?? \Config\Database::connect();
        $result = [];
        $seenDevices = [];

        if ($includePhysical && $db->tableExists('interfaces')) {
            try {
                $builder = $db->table('interfaces')->orderBy('name', 'ASC');
                if ($enabledOnly) {
                    $builder->where('enabled', 1);
                }
                $physRows = $builder->get()->getResultArray();
                foreach ($physRows as $p) {
                    $dev = strtolower(trim((string) ($p['physical_device'] ?? '')));
                    if ($dev !== '') {
                        $seenDevices[$dev] = true;
                    }
                    $p['is_vpn'] = false;
                    $result[] = $p;
                }
            } catch (\Throwable) {
                // Table unreadable
            }
        }

        $vpn = self::getVpnInterfaces($db);
        foreach (['wireguard', 'openvpn', 'ipsec'] as $groupKey) {
            foreach ($vpn[$groupKey] as $v) {
                $dev = strtolower($v['physical_device']);
                if (isset($seenDevices[$dev])) {
                    continue;
                }
                $seenDevices[$dev] = true;

                if ($enabledOnly && empty($v['enabled'])) {
                    continue;
                }

                $result[] = [
                    'id'                  => $v['id'],
                    'name'                => $v['name'],
                    'interface'           => $v['physical_device'],
                    'physical_device'     => $v['physical_device'],
                    'interface_type'      => $v['type'],
                    'ip_assignment_type'  => 'static',
                    'ipv4_address'        => $v['ipv4_address'],
                    'ipv4_netmask'        => 30,
                    'enabled'             => $v['enabled'],
                    'description'         => $v['description'],
                    'is_vpn'              => true,
                    'vpn_type'            => $v['type'],
                    'admin_access'        => json_encode(['ping']),
                ];
            }
        }

        return $result;
    }
}
