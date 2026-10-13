<?php

namespace App\Models;

use CodeIgniter\Model;

class CaptiveWalledGardenModel extends Model
{
    protected $table            = 'captive_walled_garden';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'zone_id',
        'target',
        'description',
        'enabled',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Fetch all enabled walled garden targets for a zone.
     */
    public function getEnabledTargets(string $zoneId): array
    {
        return $this->where('zone_id', $zoneId)->where('enabled', 1)->findAll();
    }
}
