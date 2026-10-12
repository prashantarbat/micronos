<?php

namespace App\Models;

use CodeIgniter\Model;

class SdwanProfileModel extends Model
{
    protected $table            = 'sdwan_profiles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'description',
        'sla_max_loss_pct',
        'sla_max_latency_ms',
        'sla_max_jitter_ms',
        'preferred_member_id',
        'backup_member_id',
        'assigned_applications',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
