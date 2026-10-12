<?php

namespace App\Models;

use CodeIgniter\Model;

class ZtnaSessionModel extends Model
{
    protected $table            = 'ztna_sessions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'session_token',
        'user_id',
        'username',
        'resource_id',
        'client_ip',
        'user_agent',
        'started_at',
        'last_active_at',
        'status',
    ];

    public function getActiveSessions(): array
    {
        return $this->where('status', 'active')
                    ->orderBy('started_at', 'DESC')
                    ->findAll();
    }
}
