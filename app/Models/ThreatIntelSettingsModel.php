<?php

namespace App\Models;

use CodeIgniter\Model;

class ThreatIntelSettingsModel extends Model
{
    protected $table            = 'threat_intel_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'enabled',
        'action',
        'safeguard_rfc1918',
        'log_blocked_packets',
        'auto_prune_days',
        'nft_set_name',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get or initialize the global threat intelligence configuration row.
     */
    public function getSettings(): array
    {
        $settings = $this->first();
        if ($settings) {
            return $settings;
        }

        $now = date('Y-m-d H:i:s');
        $default = [
            'id'                  => 'global_ti_settings',
            'enabled'             => 1,
            'action'              => 'drop',
            'safeguard_rfc1918'   => 1,
            'log_blocked_packets' => 1,
            'auto_prune_days'     => 7,
            'nft_set_name'        => 'threat_intel_ipv4',
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        $this->insert($default);

        return $default;
    }
}
