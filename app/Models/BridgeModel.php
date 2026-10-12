<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * BridgeModel — Manages Linux Layer 2 Network Bridge (br0) configurations.
 * Allows bundling multiple physical ports into a single switch/LAN bridge interface.
 */
class BridgeModel extends Model
{
    protected $table            = 'interface_bridges';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'name',
        'slaves',
        'stp',
        'forward_delay',
        'hello_time',
        'max_age',
        'priority',
        'mtu',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get all bridges with decoded slave arrays and port counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBridgesWithDetails(): array
    {
        $bridges = $this->orderBy('id', 'ASC')->findAll();
        foreach ($bridges as &$bridge) {
            $slaves = json_decode((string) ($bridge['slaves'] ?? '[]'), true);
            $bridge['slave_list']  = is_array($slaves) ? $slaves : [];
            $bridge['slave_count'] = count($bridge['slave_list']);
        }
        unset($bridge);

        return $bridges;
    }
}
