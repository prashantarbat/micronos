<?php

namespace App\Models;

use CodeIgniter\Model;

class InterfaceModel extends Model
{
    protected $table            = 'interfaces';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'physical_device',
        'enabled',
        'interface_type',
        'vlan_parent',
        'vlan_tag',
        'vlan_priority',
        'ip_assignment_type',
        'ipv4_address',
        'ipv4_netmask',
        'ipv4_gateway',
        'ipv6_assignment_type',
        'ipv6_address',
        'ipv6_prefix',
        'mtu',
        'mss_clamp',
        'mac_override',
        'speed_duplex',
        'description',
        'pppoe_username',
        'pppoe_password',
        'pppoe_service',
        'pppoe_ac_name',
        'pppoe_mtu',
        'pppoe_mru',
        'pppoe_idle_timeout',
        'pppoe_default_route',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
