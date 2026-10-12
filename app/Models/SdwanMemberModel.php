<?php

namespace App\Models;

use CodeIgniter\Model;

class SdwanMemberModel extends Model
{
    protected $table            = 'sdwan_members';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'interface',
        'gateway_ip',
        'cost',
        'weight',
        'priority',
        'ping_target',
        'current_loss',
        'current_latency',
        'current_jitter',
        'status',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get all active members ordered by priority.
     */
    public function getActiveMembers(): array
    {
        return $this->where('enabled', 1)->orderBy('priority', 'ASC')->findAll();
    }
}
