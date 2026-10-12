<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Kea DHCPv4 Configuration Compiler
 * ==========================================
 * Compiles interface subnet pools, lease times, gateway options, and static MAC
 * reservations into Kea DHCPv4 JSON configuration (/etc/kea/kea-dhcp4.conf).
 */
class KeaDhcpCompiler
{
    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Compiles /etc/kea/kea-dhcp4.conf JSON string.
     */
    public function compile(): string
    {
        $interfaces = $this->fetchInterfaces();

        $subnets = [];
        $subnetId = 1;

        foreach ($interfaces as $iface) {
            $ifId = $iface['id'] ?? $iface['name'] ?? 'lan';
            $setting = $this->db->table('dhcp_server_settings')->where('interface_id', $ifId)->get()->getRowArray();
            if ($setting && empty($setting['enabled'])) {
                continue; // Interface DHCP is disabled
            }

            $dev = $iface['physical_device'] ?? $iface['name'] ?? 'eth1';
            $ip = $iface['ipv4_address'] ?? '10.10.10.10';
            $netmask = (int) ($iface['ipv4_netmask'] ?? 24);

            // Compute subnet base
            $ipLong = ip2long($ip);
            $maskLong = ~((1 << (32 - $netmask)) - 1);
            $subnetBase = long2ip($ipLong & $maskLong);

            // Pool range from settings or fallback (avoid overlapping with router's own IP)
            $startOffset = ($ipLong === ($ipLong & $maskLong) + 10) ? 20 : 10;
            $poolStart = $setting['range_from'] ?? long2ip(($ipLong & $maskLong) + $startOffset);
            $poolEnd   = $setting['range_to'] ?? long2ip(($ipLong & $maskLong) + 245);

            $gateway = !empty($setting['gateway']) ? $setting['gateway'] : $ip;
            $dnsServers = array_filter([
                $setting['dns_1'] ?? $ip,
                $setting['dns_2'] ?? null,
                $setting['dns_3'] ?? null,
                $setting['dns_4'] ?? null,
            ]);

            $options = [
                ['name' => 'routers', 'data' => $gateway],
                ['name' => 'domain-name-servers', 'data' => implode(', ', $dnsServers)],
            ];

            if (!empty($setting['domain_name'])) {
                $options[] = ['name' => 'domain-name', 'data' => $setting['domain_name']];
            }
            if (!empty($setting['ntp_1'])) {
                $options[] = ['name' => 'ntp-servers', 'data' => $setting['ntp_1']];
            }
            if (!empty($setting['tftp_server'])) {
                $options[] = ['name' => 'tftp-server-name', 'data' => $setting['tftp_server']];
            }
            if (!empty($setting['bootfile_name'])) {
                $options[] = ['name' => 'boot-file-name', 'data' => $setting['bootfile_name']];
            }

            // Static reservations for this interface
            $resQuery = $this->db->table('dhcp_reservations')
                                 ->where('interface_id', $ifId)
                                 ->orWhere('interface_id', null)
                                 ->get()
                                 ->getResultArray();
            $reservations = [];
            foreach ($resQuery as $res) {
                $reservations[] = [
                    'hw-address' => $res['mac_address'],
                    'ip-address' => $res['ip_address'],
                    'hostname'   => $res['hostname'] ?? null,
                ];
            }

            $subnets[] = [
                'id'          => $subnetId++,
                'subnet'      => "{$subnetBase}/{$netmask}",
                'interface'   => $dev,
                'pools'       => [
                    ['pool' => "{$poolStart} - {$poolEnd}"]
                ],
                'option-data' => $options,
                'reservations'=> $reservations,
            ];
        }

        $config = [
            'Dhcp4' => [
                'interfaces-config' => [
                    'interfaces' => array_column($subnets, 'interface')
                ],
                'lease-database' => [
                    'type' => 'memfile',
                    'persist' => true,
                    'name' => '/var/lib/kea/kea-leases4.csv'
                ],
                'valid-lifetime' => 86400,
                'renew-timer' => 43200,
                'rebind-timer' => 75600,
                'subnet4' => $subnets
            ]
        ];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function fetchInterfaces(): array
    {
        try {
            return $this->db->table('interfaces')->where('enabled', 1)->like('id', 'LAN')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [
                ['physical_device' => 'eth1', 'ipv4_address' => '10.10.10.10', 'ipv4_netmask' => 24]
            ];
        }
    }
}
