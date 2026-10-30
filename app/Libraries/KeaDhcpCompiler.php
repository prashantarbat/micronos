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

    /** @var list<string> problems found while compiling (nothing is refused) */
    public array $warnings = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Compiles /etc/kea/kea-dhcp4.conf JSON string.
     */
    public function compile(): string
    {
        $this->warnings = [];
        $interfaces = $this->fetchInterfaces();

        $subnets = [];
        $subnetId = 1;

        foreach ($interfaces as $iface) {
            $ifId = $iface['id'] ?? $iface['name'] ?? 'lan';
            $setting = $this->db->table('dhcp_server_settings')->where('interface_id', $ifId)->get()->getRowArray();
            if ($setting && empty($setting['enabled'])) {
                continue; // Interface DHCP is disabled
            }

            // DHCP is served only from an interface with a static address; its
            // subnet is always derived from that address, never stored.
            if (($iface['ip_assignment_type'] ?? 'static') !== 'static' || empty($iface['ipv4_address'])) {
                if ($setting && ! empty($setting['enabled'])) {
                    $this->warnings[] = "DHCP on {$ifId} skipped: the interface has no static IPv4 address";
                }
                continue;
            }
            $dev = $iface['physical_device'] ?? $iface['name'] ?? 'eth1';
            $ip = (string) $iface['ipv4_address'];
            $netmask = \App\Libraries\Network\NetworkPlan::prefix($iface['ipv4_netmask'] ?? 24);

            // Compute subnet base
            $ipLong = ip2long($ip);
            $maskLong = ~((1 << (32 - $netmask)) - 1);
            $subnetBase = long2ip($ipLong & $maskLong);

            // Pool range from settings or fallback (avoid overlapping with router's own IP)
            $startOffset = ($ipLong === ($ipLong & $maskLong) + 10) ? 20 : 10;
            $poolStart = $setting['range_from'] ?? long2ip(($ipLong & $maskLong) + $startOffset);
            $poolEnd   = $setting['range_to'] ?? long2ip(($ipLong & $maskLong) + 245);
            // A pool left over from a previous subnet would make Kea refuse
            // the whole configuration: fall back to the derived range.
            if (! \App\Libraries\Network\NetworkPlan::inSubnet((string) $poolStart, $subnetBase, $netmask)
                || ! \App\Libraries\Network\NetworkPlan::inSubnet((string) $poolEnd, $subnetBase, $netmask)) {
                $this->warnings[] = "DHCP pool {$poolStart}-{$poolEnd} on {$ifId} is outside {$subnetBase}/{$netmask}; using the derived range";
                $hosts     = (1 << (32 - $netmask)) - 2;
                $poolStart = long2ip(($ipLong & $maskLong) + ($hosts >= 254 ? 100 : max(2, intdiv($hosts, 2) + 1)));
                $poolEnd   = long2ip(($ipLong & $maskLong) + ($hosts >= 254 ? 200 : $hosts));
            }

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
                if (! \App\Libraries\Network\NetworkPlan::inSubnet((string) $res['ip_address'], $subnetBase, $netmask)) {
                    $this->warnings[] = "reservation {$res['ip_address']} ({$res['mac_address']}) is outside {$subnetBase}/{$netmask} and was left out";
                    continue;
                }
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
