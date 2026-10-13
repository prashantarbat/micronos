<?php

namespace App\Models;

use CodeIgniter\Model;

class CaptiveZoneModel extends Model
{
    protected $table            = 'captive_zones';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'slug',
        'interface_id',
        'auth_mode',
        'portal_port',
        'portal_ssl_port',
        'session_timeout',
        'idle_timeout',
        'default_redirect_url',
        'enabled',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Fetch active zone by slug.
     */
    public function getBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    /**
     * Fetch active zone by bound interface.
     */
    public function getByInterface(string $interfaceId): ?array
    {
        return $this->where('interface_id', $interfaceId)->first();
    }
}
