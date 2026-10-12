<?php

namespace App\Models;

use CodeIgniter\Model;

class SsoSettingsModel extends Model
{
    protected $table            = 'sso_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'sso_enforced',
        'allow_local_fallback',
        'default_provider_id',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Retrieve the global SSO settings row (or create default if missing).
     */
    public function getSettings(): array
    {
        $settings = $this->first();
        if ($settings) {
            return $settings;
        }

        $now = date('Y-m-d H:i:s');
        $default = [
            'id'                   => 'global_sso_settings',
            'sso_enforced'         => 0,
            'allow_local_fallback' => 1,
            'default_provider_id'  => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
        $this->insert($default);

        return $default;
    }
}
