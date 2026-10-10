<?php

namespace App\Models;

use CodeIgniter\Model;

class DpiSettingsModel extends Model
{
    protected $table            = 'dpi_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'enabled',
        'engine_mode',
        'inspection_depth_packets',
        'log_level',
        'flow_table_max',
        'updated_at',
    ];

    protected $useTimestamps = false;

    public function getSettings(): array
    {
        $settings = $this->find(1);
        if (!$settings) {
            $default = [
                'id'                       => 1,
                'enabled'                  => 1,
                'engine_mode'              => 'inline_block',
                'inspection_depth_packets' => 10,
                'log_level'                => 'matched',
                'flow_table_max'           => 262144,
                'updated_at'               => date('Y-m-d H:i:s'),
            ];
            $this->insert($default);
            return $default;
        }
        return $settings;
    }
}
