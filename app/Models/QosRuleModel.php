<?php

namespace App\Models;

use CodeIgniter\Model;

class QosRuleModel extends Model
{
    protected $table            = 'qos_rules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'shaper_id',
        'name',
        'priority',
        'protocol',
        'src_ip',
        'dst_ip',
        'dst_port',
        'dscp_class',
        'bandwidth_share',
        'schedule',
        'enabled',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'       => 'required|max_length[64]',
        'priority'   => 'required|numeric|greater_than_equal_to[1]|less_than_equal_to[8]',
        'dscp_class' => 'required',
    ];
}
