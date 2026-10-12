<?php

namespace App\Models;

use CodeIgniter\Model;

class SsoProviderModel extends Model
{
    protected $table            = 'sso_providers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'slug',
        'protocol',
        'enabled',
        'discovery_url',
        'client_id',
        'client_secret',
        'auth_url',
        'token_url',
        'userinfo_url',
        'scopes',
        'idp_entity_id',
        'idp_sso_url',
        'idp_x509_cert',
        'sp_entity_id',
        'auto_provision',
        'default_role',
        'role_mapping_json',
        'allow_unmapped_users',
        'icon',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get all active and enabled SSO providers.
     *
     * @return array<array<string, mixed>>
     */
    public function getEnabledProviders(): array
    {
        return $this->where('enabled', 1)->orderBy('name', 'ASC')->findAll();
    }

    /**
     * Get a provider by slug.
     */
    public function getBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }
}
