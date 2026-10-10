<?php

namespace App\Models;

use CodeIgniter\Model;

class DpiPolicyModel extends Model
{
    protected $table            = 'dpi_policies';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'sequence',
        'src_interface',
        'dst_interface',
        'app_ids',
        'category_ids',
        'action',
        'bandwidth_limit_kbps',
        'schedule',
        'log_enabled',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getActivePolicies(): array
    {
        return $this->where('enabled', 1)
                    ->orderBy('sequence', 'ASC')
                    ->findAll();
    }

    public function getOrderedPolicies(): array
    {
        return $this->orderBy('sequence', 'ASC')->findAll();
    }

    public function getNextSequence(): int
    {
        $max = $this->selectMax('sequence')->first();
        return ($max['sequence'] ?? 0) + 1;
    }
}
