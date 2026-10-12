<?php

namespace App\Models;

use CodeIgniter\Model;

class ZtnaSettingsModel extends Model
{
    protected $table            = 'ztna_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'enabled',
        'portal_title',
        'external_domain',
        'guacd_host',
        'guacd_port',
        'session_timeout_mins',
        'require_mfa',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getSettings(): array
    {
        $row = $this->find(1);
        return $row ?: [
            'id'                   => 1,
            'enabled'              => 1,
            'portal_title'         => 'Micron OS Clientless Gateway',
            'external_domain'      => 'gateway.micronos.local',
            'guacd_host'           => '127.0.0.1',
            'guacd_port'           => 4822,
            'session_timeout_mins' => 60,
            'require_mfa'          => 1,
        ];
    }
}
