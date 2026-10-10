<?php

namespace App\Models;

use CodeIgniter\Model;

class DosPolicyModel extends Model
{
    protected $table            = 'dos_policies';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'name',
        'interface_id',
        'schedule',
        'syn_flood_enabled',
        'syn_flood_rate',
        'syn_flood_burst',
        'conn_limit_enabled',
        'conn_limit_max',
        'conn_rate_enabled',
        'conn_rate_max',
        'udp_flood_enabled',
        'udp_flood_rate',
        'icmp_flood_enabled',
        'icmp_flood_rate',
        'port_scan_enabled',
        'action',
        'ban_duration',
        'exempt_addresses',
        'log_enabled',
        'enabled',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get active DoS policies, optionally filtered by interface.
     *
     * @return list<array<string, mixed>>
     */
    public function getActivePolicies(?string $interface = null): array
    {
        $builder = $this->where('enabled', 1);
        if ($interface !== null && $interface !== 'all') {
            $builder->groupStart()
                    ->where('interface_id', $interface)
                    ->orWhere('interface_id', 'all')
                    ->groupEnd();
        }
        return $builder->findAll();
    }

    /**
     * Predefined thresholds for quick UI application.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getPresets(): array
    {
        return [
            'relaxed' => [
                'name'               => 'Relaxed (High-Throughput)',
                'syn_flood_rate'     => 300,
                'syn_flood_burst'    => 600,
                'conn_limit_max'     => 150,
                'conn_rate_max'      => 120,
                'udp_flood_rate'     => 1500,
                'icmp_flood_rate'    => 50,
                'ban_duration'       => 300,
            ],
            'balanced' => [
                'name'               => 'Balanced (Enterprise Standard)',
                'syn_flood_rate'     => 100,
                'syn_flood_burst'    => 200,
                'conn_limit_max'     => 50,
                'conn_rate_max'      => 60,
                'udp_flood_rate'     => 500,
                'icmp_flood_rate'    => 20,
                'ban_duration'       => 600,
            ],
            'aggressive' => [
                'name'               => 'Aggressive (Under Active Attack)',
                'syn_flood_rate'     => 40,
                'syn_flood_burst'    => 80,
                'conn_limit_max'     => 25,
                'conn_rate_max'      => 30,
                'udp_flood_rate'     => 200,
                'icmp_flood_rate'    => 10,
                'ban_duration'       => 1800,
            ],
        ];
    }
}
