<?php

namespace App\Models;

use CodeIgniter\Model;

class ZtnaResourceModel extends Model
{
    protected $table            = 'ztna_resources';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'slug',
        'type',
        'internal_host',
        'internal_port',
        'description',
        'icon',
        'require_auth',
        'allowed_roles',
        'active_sessions_count',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get accessible resources for a user given their roles.
     *
     * @param list<string> $userRoles
     * @return list<array<string, mixed>>
     */
    public function getAccessibleForRoles(array $userRoles, bool $isSuperadmin = false): array
    {
        $all = $this->orderBy('name', 'ASC')->findAll();
        if ($isSuperadmin) {
            return $all;
        }

        $accessible = [];
        foreach ($all as $res) {
            if (empty($res['require_auth'])) {
                $accessible[] = $res;
                continue;
            }
            $allowed = json_decode($res['allowed_roles'] ?? '[]', true) ?: [];
            if (empty($allowed) || !empty(array_intersect($userRoles, $allowed))) {
                $accessible[] = $res;
            }
        }

        return $accessible;
    }
}
