<?php

namespace App\Models;

use CodeIgniter\Model;

class SdwanSettingsModel extends Model
{
    protected $table            = 'sdwan_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'enabled',
        'probe_interval_seconds',
        'probe_packet_count',
        'jitter_smoothing_factor',
        'default_strategy',
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
            'id'                      => 1,
            'enabled'                 => 1,
            'probe_interval_seconds'  => 5,
            'probe_packet_count'      => 10,
            'jitter_smoothing_factor' => 0.85,
            'default_strategy'        => 'best_quality',
        ];
    }
}
