<?php

namespace App\Models;

use CodeIgniter\Model;

class DhcpServerSettingsModel extends Model
{
    protected $table            = 'dhcp_server_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'interface_id',
        'enabled',
        'deny_unknown',
        'range_from',
        'range_to',
        'gateway',
        'domain_name',
        'domain_search',
        'dns_1',
        'dns_2',
        'dns_3',
        'dns_4',
        'default_lease_time',
        'max_lease_time',
        'ntp_1',
        'ntp_2',
        'tftp_server',
        'next_server',
        'bootfile_name',
        'wins_1',
        'wins_2',
        'custom_options',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get or create DHCP settings for an interface
     */
    public function getForInterface(string $interfaceId, array $iface): array
    {
        $settings = $this->where('interface_id', $interfaceId)->first();
        if (!$settings) {
            $ip = $iface['ipv4_address'] ?? '10.10.10.10';
            $netmask = (int) ($iface['ipv4_netmask'] ?? 24);

            $ipLong = ip2long($ip) ?: ip2long('10.10.10.10');
            $maskLong = ~((1 << (32 - $netmask)) - 1);
            $subnetBaseLong = $ipLong & $maskLong;

            $startOffset = ($ipLong === $subnetBaseLong + 10) ? 20 : 10;
            $rangeFrom = long2ip($subnetBaseLong + $startOffset);
            $rangeTo   = long2ip($subnetBaseLong + min(245, (1 << (32 - $netmask)) - 3));

            $isLan = (strtolower($interfaceId) === 'lan');

            $default = [
                'interface_id'       => $interfaceId,
                'enabled'            => $isLan ? 1 : 0,
                'deny_unknown'       => 0,
                'range_from'         => $rangeFrom,
                'range_to'           => $rangeTo,
                'gateway'            => $ip,
                'domain_name'        => 'micronos.internal',
                'domain_search'      => 'micronos.internal, home.arpa',
                'dns_1'              => $ip,
                'dns_2'              => '1.1.1.1',
                'dns_3'              => '8.8.8.8',
                'dns_4'              => '',
                'default_lease_time' => 7200,
                'max_lease_time'     => 86400,
                'ntp_1'              => 'pool.ntp.org',
                'ntp_2'              => 'time.google.com',
                'tftp_server'        => '',
                'next_server'        => '',
                'bootfile_name'      => '',
                'wins_1'             => '',
                'wins_2'             => '',
                'custom_options'     => json_encode([
                    ['number' => 43, 'type' => 'IP', 'value' => '192.168.1.10', 'description' => 'Vendor Specific (Controller Inform IP)'],
                    ['number' => 150, 'type' => 'IP', 'value' => '192.168.1.20', 'description' => 'VoIP TFTP Server IP'],
                ]),
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ];

            $id = $this->insert($default);
            return array_merge(['id' => $id], $default);
        }

        return $settings;
    }
}
