<?php

namespace App\Models;

use CodeIgniter\Model;

class SdwanRuleModel extends Model
{
    protected $table            = 'sdwan_rules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'priority',
        'name',
        'source_subnet',
        'destination_subnet',
        'sdwan_profile_id',
        'strategy',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getOrderedRules(): array
    {
        return $this->orderBy('priority', 'ASC')->findAll();
    }
}
