<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * PermissionModel — manages the permission catalog and user effective permissions.
 *
 * Effective permissions for a user = UNION of all granted permissions across
 * all roles the user holds, with explicit denied entries overriding grants.
 */
class PermissionModel extends Model
{
    protected $table            = 'permissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id', 'key', 'label', 'module', 'description', 'created_at',
    ];

    /**
     * Cached size of the permission catalog for the current request.
     */
    private static ?int $catalogCount = null;

    // -------------------------------------------------------------------------
    // Retrieval
    // -------------------------------------------------------------------------

    /**
     * Get all permissions grouped by module — for the role editor UI.
     *
     * @return array<string, array> ['Module' => [permission, ...]]
     */
    public function getAllGrouped(): array
    {
        $rows = $this->orderBy('module', 'ASC')->orderBy('label', 'ASC')->findAll();
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['module']][] = $row;
        }

        return $grouped;
    }

    /**
     * Compute the effective permission key set for a user (all their roles combined).
     *
     * This result should be cached in the session after login to avoid repeated queries.
     *
     * @param  string   $userId
     * @return string[] Array of permission keys the user is effectively granted
     */
    public function getEffectiveKeysForUser(string $userId): array
    {
        $db = \Config\Database::connect();

        // Raw SQL has to carry the database prefix itself: the test database
        // group uses one (db_), so an unprefixed table name would not resolve.
        $rows = $db->query("
            SELECT p.key, rp.granted
            FROM " . $db->prefixTable('user_roles') . " ur
            INNER JOIN " . $db->prefixTable('role_permissions') . " rp ON rp.role_id = ur.role_id
            INNER JOIN " . $db->prefixTable('permissions') . " p ON p.id = rp.permission_id
            WHERE ur.user_id = ?
        ", [$userId])->getResultArray();

        $granted = [];
        $denied  = [];

        foreach ($rows as $row) {
            if ((int) $row['granted'] === 1) {
                $granted[$row['key']] = true;
            } else {
                $denied[$row['key']] = true;
            }
        }

        // SuperAdmin is a wildcard, not a snapshot. Its grants are written once
        // when the role is seeded, so a permission introduced by a later
        // migration would otherwise be denied to the superuser — silently
        // locking the administrator out of the newest feature.
        //
        // This runs *before* the deny pass below so that an explicit denial can
        // still revoke a capability, even from the superuser.
        if ($this->userIsSuperAdmin($userId)) {
            $all = array_column($this->select('key')->findAll(), 'key');
            foreach ($all as $key) {
                $granted[$key] = true;
            }
        }

        // Explicit denials always win over grants (deny-override model)
        foreach (array_keys($denied) as $key) {
            unset($granted[$key]);
        }

        return array_keys($granted);
    }

    /**
     * Whether a user holds the built-in SuperAdmin role.
     *
     * Matched by role name rather than id, because the id is generated per
     * installation and a custom role must not be able to impersonate it.
     */
    public function userIsSuperAdmin(string $userId): bool
    {
        $db = \Config\Database::connect();

        return (bool) $db->query("
            SELECT 1
            FROM " . $db->prefixTable('user_roles') . " ur
            INNER JOIN " . $db->prefixTable('roles') . " r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.name = 'SuperAdmin'
            LIMIT 1
        ", [$userId])->getRowArray();
    }

    /**
     * Check whether a user has a specific permission key.
     *
     * The session cache is only trusted while the permission catalog is
     * unchanged. A permission introduced by a later migration/seeder must not
     * stay invisible for the rest of a session that logged in before it
     * existed, so a changed catalog forces one recompute.
     */
    public function userCan(string $userId, string $permKey): bool
    {
        $session = session();
        $cached  = $session->get('user_permissions');

        if (is_array($cached)) {
            // A cached grant is always honoured.
            if (in_array($permKey, $cached, true)) {
                return true;
            }

            // The key is absent from the cache. Either it is genuinely not
            // granted, or the cache predates a catalog change (a permission
            // added by a later migration/seeder). Only re-check the database
            // when the catalog actually differs, so a normal denial costs
            // nothing and a newly added permission is picked up automatically.
            if (! $this->permissionCatalogChanged($session)) {
                return false;
            }

            $effective = $this->cacheEffectiveKeysForUser($userId);

            return in_array($permKey, $effective, true);
        }

        // No cache at all — live DB check (also seeds the session cache).
        $effective = $this->cacheEffectiveKeysForUser($userId);

        return in_array($permKey, $effective, true);
    }

    /**
     * Recomputes a user's effective permissions and stores them in the session
     * together with the catalog marker used to detect later catalog changes.
     *
     * @return string[]
     */
    public function cacheEffectiveKeysForUser(string $userId): array
    {
        $effective = $this->getEffectiveKeysForUser($userId);

        session()->set([
            'user_permissions'   => $effective,
            'perm_catalog_count' => $this->permissionCatalogCount(),
        ]);

        return $effective;
    }

    /**
     * Number of permission keys currently in the catalog (for the request).
     */
    public function permissionCatalogCount(): int
    {
        if (self::$catalogCount === null) {
            $db = \Config\Database::connect();
            self::$catalogCount = $db->tableExists('permissions')
                ? (int) $db->table('permissions')->countAllResults()
                : 0;
        }

        return self::$catalogCount;
    }

    private function permissionCatalogChanged($session): bool
    {
        $stored = $session->get('perm_catalog_count');

        return ! is_int($stored) || $stored !== $this->permissionCatalogCount();
    }

    /**
     * Clear the permission cache from session (call after role changes).
     */
    public static function clearSessionCache(): void
    {
        $session = session();
        $session->remove('user_permissions');
        $session->remove('perm_catalog_count');
    }
}
