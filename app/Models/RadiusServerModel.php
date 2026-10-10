<?php

namespace App\Models;

use CodeIgniter\Model;

class RadiusServerModel extends Model
{
    protected $table            = 'radius_servers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'hostname',
        'auth_port',
        'acct_port',
        'secret',
        'auth_type',
        'nas_identifier',
        'nas_ip',
        'timeout',
        'retries',
        'acct_enabled',
        'acct_server',
        'default_role_id',
        'group_attribute',
        'is_primary',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get active primary RADIUS server.
     */
    public function getPrimaryServer(): ?array
    {
        return $this->where('enabled', 1)->where('is_primary', 1)->first()
            ?: $this->where('enabled', 1)->first();
    }

    /**
     * Get secondary/backup RADIUS server.
     */
    public function getBackupServer(): ?array
    {
        return $this->where('enabled', 1)->where('is_primary', 0)->first();
    }
}
