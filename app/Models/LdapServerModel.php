<?php

namespace App\Models;

use CodeIgniter\Model;

class LdapServerModel extends Model
{
    protected $table            = 'ldap_servers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'hostname',
        'port',
        'encryption',
        'base_dn',
        'bind_dn',
        'bind_password',
        'user_search_base',
        'user_search_filter',
        'group_search_base',
        'admin_group_mapping',
        'schema_type',
        'group_attribute',
        'default_role_id',
        'timeout',
        'fallback_local',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get active primary or first enabled LDAP server.
     */
    public function getActiveServer(): ?array
    {
        return $this->where('enabled', 1)->orderBy('created_at', 'ASC')->first();
    }
}
