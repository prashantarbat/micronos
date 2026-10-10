<?php

namespace App\Models;

use CodeIgniter\Model;

class SslMitmSettingsModel extends Model
{
    protected $table            = 'ssl_mitm_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'enabled',
        'active_profile_id',
        'dynamic_cert_cache_size',
        'cert_validity_days',
        'generate_missing_san',
        'updated_at',
    ];

    protected $useTimestamps = false;

    public function getSettings(): array
    {
        $settings = $this->find(1);
        if (!$settings) {
            $default = [
                'id'                      => 1,
                'enabled'                 => 1,
                'active_profile_id'       => 'prof_deep_inspection',
                'dynamic_cert_cache_size' => 10000,
                'cert_validity_days'      => 365,
                'generate_missing_san'    => 1,
                'updated_at'              => date('Y-m-d H:i:s'),
            ];
            $this->insert($default);
            return $default;
        }
        return $settings;
    }
}
