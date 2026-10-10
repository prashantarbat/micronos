<?php

namespace App\Models;

use CodeIgniter\Model;

class HaVirtualIpModel extends Model
{
    protected $table            = 'ha_virtual_ips';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'interface',
        'vip_type',
        'ip_address',
        'subnet_mask',
        'vrrp_router_id',
        'vrrp_priority',
        'description',
        'status',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'interface'      => 'required|max_length[32]',
        'ip_address'     => 'required|valid_ip',
        'vrrp_router_id' => 'required|numeric|greater_than_equal_to[1]|less_than_equal_to[255]',
    ];
}
