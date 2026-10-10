<?php

namespace App\Models;

use CodeIgniter\Model;

class HaSettingsModel extends Model
{
    protected $table            = 'ha_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'enabled',
        'node_name',
        'mode',
        'vrrp_router_id',
        'vrrp_interface',
        'vrrp_priority',
        'vrrp_auth_pass',
        'preempt',
        'conntrackd_enabled',
        'conntrackd_interface',
        'peer_sync_ip',
        'peer_sync_port',
        'config_sync_enabled',
        'config_sync_peer_ip',
        'config_sync_api_key',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getSettings(): array
    {
        $settings = $this->first();
        if (!$settings) {
            $default = [
                'enabled'              => 0,
                'node_name'            => 'node-01',
                'mode'                 => 'master',
                'vrrp_router_id'       => 51,
                'vrrp_interface'       => 'lan0',
                'vrrp_priority'        => 110,
                'vrrp_auth_pass'       => 'VRRP_S3cur3Key',
                'preempt'              => 1,
                'conntrackd_enabled'   => 1,
                'conntrackd_interface' => 'sync0',
                'peer_sync_ip'         => '10.254.254.2',
                'peer_sync_port'       => 3780,
                'config_sync_enabled'  => 1,
                'config_sync_peer_ip'  => '10.254.254.2',
                'config_sync_api_key'  => 'sync_key_' . bin2hex(random_bytes(8)),
            ];
            $id = $this->insert($default);
            return array_merge(['id' => $id], $default);
        }
        return $settings;
    }
}
