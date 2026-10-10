<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * RoleModel — manages roles and their permission assignments.
 *
 * Provides helpers for:
 *   - Listing roles with permission counts
 *   - Creating / updating custom roles
 *   - Syncing permission sets to a role
 *   - Checking whether a role has a specific permission
 */
class RoleModel extends Model
{
    protected $table            = 'roles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id', 'name', 'display_name', 'description',
        'is_system', 'dashboard_widgets', 'created_at', 'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // -------------------------------------------------------------------------
    // Retrieval
    // -------------------------------------------------------------------------

    /**
     * Get all roles with their permission count.
     */
    public function getAllWithCounts(): array
    {
        $db = \Config\Database::connect();
        $rolesTable = $db->prefixTable('roles');
        $rpTable    = $db->prefixTable('role_permissions');

        return $db->query("
            SELECT r.*, COUNT(rp.id) AS permission_count
            FROM {$rolesTable} r
            LEFT JOIN {$rpTable} rp ON rp.role_id = r.id AND rp.granted = 1
            GROUP BY r.id
            ORDER BY r.is_system DESC, r.name ASC
        ")->getResultArray();
    }

    /**
     * Get a role with its full permission list.
     */
    public function getWithPermissions(string $roleId): ?array
    {
        $role = $this->find($roleId);
        if (! $role) {
            return null;
        }

        $db = \Config\Database::connect();
        $permTable = $db->prefixTable('permissions');
        $rpTable   = $db->prefixTable('role_permissions');
        $role['permissions'] = $db->query("
            SELECT p.*, rp.granted
            FROM {$permTable} p
            INNER JOIN {$rpTable} rp ON rp.permission_id = p.id
            WHERE rp.role_id = ?
            ORDER BY p.module, p.label
        ", [$roleId])->getResultArray();

        return $role;
    }

    /**
     * Get just the permission keys granted to a role.
     */
    public function getPermissionKeys(string $roleId): array
    {
        $db = \Config\Database::connect();
        $permTable = $db->prefixTable('permissions');
        $rpTable   = $db->prefixTable('role_permissions');
        $rows = $db->query("
            SELECT p.key FROM {$permTable} p
            INNER JOIN {$rpTable} rp ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND rp.granted = 1
        ", [$roleId])->getResultArray();

        return array_column($rows, 'key');
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new custom role. Returns the new role ID.
     */
    public function createRole(string $name, string $displayName, string $description = ''): string
    {
        $id = $this->generateUuid();
        $this->insert([
            'id'           => $id,
            'name'         => $name,
            'display_name' => $displayName,
            'description'  => $description,
            'is_system'    => 0,
        ]);
        return $id;
    }

    /**
     * Sync the permissions assigned to a role.
     *
     * @param string   $roleId         The role to update
     * @param string[] $permissionIds  Array of permission UUIDs to GRANT
     */
    public function syncPermissions(string $roleId, array $permissionIds): void
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // Remove existing assignments
        $db->table('role_permissions')->where('role_id', $roleId)->delete();

        // Insert new assignments
        foreach (array_unique($permissionIds) as $permId) {
            $db->table('role_permissions')->insert([
                'role_id'       => $roleId,
                'permission_id' => $permId,
                'granted'       => 1,
                'created_at'    => $now,
            ]);
        }
    }

    /**
     * Check if a role has a specific permission key (e.g. "firewall.rules.edit").
     */
    public function hasPermission(string $roleId, string $permKey): bool
    {
        $db = \Config\Database::connect();
        $rpTable   = $db->prefixTable('role_permissions');
        $permTable = $db->prefixTable('permissions');
        $count = $db->query("
            SELECT COUNT(*) AS cnt FROM {$rpTable} rp
            INNER JOIN {$permTable} p ON p.id = rp.permission_id
            WHERE rp.role_id = ? AND p.key = ? AND rp.granted = 1
        ", [$roleId, $permKey])->getRow()->cnt ?? 0;

        return (int) $count > 0;
    }

    /**
     * Delete a custom (non-system) role.
     *
     * @throws \RuntimeException if attempting to delete a system role
     */
    public function deleteCustomRole(string $roleId): bool
    {
        $role = $this->find($roleId);

        if (! $role) {
            return false;
        }

        if ((int) $role['is_system'] === 1) {
            throw new \RuntimeException("System role '{$role['name']}' cannot be deleted.");
        }

        $db = \Config\Database::connect();

        // Remove permission assignments
        $db->table('role_permissions')->where('role_id', $roleId)->delete();

        // Remove user assignments
        $db->table('user_roles')->where('role_id', $roleId)->delete();

        return $this->delete($roleId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
