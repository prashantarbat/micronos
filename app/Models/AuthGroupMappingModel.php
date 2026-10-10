<?php

namespace App\Models;

use CodeIgniter\Model;

class AuthGroupMappingModel extends Model
{
    protected $table            = 'auth_group_mappings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'auth_type',
        'server_id',
        'external_group',
        'role_id',
        'priority',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get all mappings joined with their target role info.
     */
    public function getAllWithRoles(?string $authType = null): array
    {
        $builder = $this->db->table('auth_group_mappings m')
            ->select('m.*, m.external_group as external_group_name, m.auth_type as source_type, r.name as role_name, r.display_name as role_display_name, r.is_system as role_is_system')
            ->join('roles r', 'r.id = m.role_id', 'left')
            ->orderBy('m.priority', 'ASC')
            ->orderBy('m.created_at', 'ASC');

        if ($authType !== null) {
            $builder->where('m.auth_type', $authType);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Maps a list of external groups/attributes (from AD or RADIUS) to a MicronOS role.
     * Evaluates mappings by priority (lowest number = highest priority).
     *
     * @param string $authType 'ldap' or 'radius'
     * @param array $externalGroups Array of group names or DNs or attributes
     * @param string|null $defaultRoleId Fallback role ID if no group matches
     * @return array|null Returns ['role_id' => ..., 'role_name' => ..., 'matched_group' => ...] or null
     */
    public function resolveRoleForGroups(string $authType, array $externalGroups, ?string $defaultRoleId = null): ?array
    {
        if (empty($externalGroups) && empty($defaultRoleId)) {
            return null;
        }

        $mappings = $this->getAllWithRoles($authType);

        // Check each mapping against candidate groups
        foreach ($mappings as $mapping) {
            $targetGroup = trim($mapping['external_group']);
            foreach ($externalGroups as $group) {
                $groupStr = trim((string)$group);
                // Exact match or contains (for full DNs like CN=Domain Admins,OU=Groups,DC=corp)
                if (strcasecmp($targetGroup, $groupStr) === 0
                    || stripos($groupStr, "CN={$targetGroup},") !== false
                    || stripos($groupStr, "CN={$targetGroup}") !== false
                    || (stripos($groupStr, $targetGroup) !== false && strlen($targetGroup) >= 4)
                ) {
                    return [
                        'role_id'           => $mapping['role_id'],
                        'role_name'         => $mapping['role_name'] ?? 'operator',
                        'role_display_name' => $mapping['role_display_name'] ?? 'Operator',
                        'matched_group'     => $targetGroup,
                    ];
                }
            }
        }

        // If fallback role specified
        if ($defaultRoleId) {
            $role = $this->db->table('roles')->where('id', $defaultRoleId)->get()->getRowArray();
            if ($role) {
                return [
                    'role_id'           => $role['id'],
                    'role_name'         => $role['name'],
                    'role_display_name' => $role['display_name'],
                    'matched_group'     => 'Default / Fallback',
                ];
            }
        }

        return null;
    }
}
