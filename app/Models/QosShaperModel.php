<?php

namespace App\Models;

use CodeIgniter\Model;

class QosShaperModel extends Model
{
    protected $table            = 'qos_shapers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'interface',
        'enabled',
        'bandwidth_down_mbps',
        'bandwidth_up_mbps',
        'scheduler',
        'diffserv',
        'flow_isolation',
        'ack_filter',
        'rtt_ms',
        'description',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'interface'           => 'required|max_length[32]',
        'bandwidth_down_mbps' => 'required|numeric|greater_than[0]',
        'bandwidth_up_mbps'   => 'required|numeric|greater_than[0]',
        'scheduler'           => 'required|in_list[cake,fq_codel,htb]',
    ];
}
