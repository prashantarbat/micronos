<?php

use App\Models\PermissionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * SuperAdmin is a wildcard, not a snapshot.
 *
 * Role grants are written once when the role is seeded. A permission added by a
 * later migration — as the PPPoE permissions were — would therefore be denied
 * to the superuser unless the model widens the role at read time.
 *
 * @internal
 */
final class PermissionModelSuperAdminTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private string $userId;
    private string $roleId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = date('Y-m-d H:i:s');

        $this->db->table('user_roles')->where('user_id', 'u_superadmin_test')->delete();
        $this->db->table('users')->where('id', 'u_superadmin_test')->delete();

        // The application seeder does not run against the test database, so the
        // roles and the permissions this test reasons about are created here.
        $this->db->table('roles')->where('id', 'role_superadmin_test')->delete();
        $this->db->table('roles')->where('id', 'role_operator_test')->delete();
        $this->db->table('roles')->insertBatch([
            ['id' => 'role_superadmin_test', 'name' => 'SuperAdmin', 'display_name' => 'Super Administrator', 'description' => null, 'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'role_operator_test',   'name' => 'Operator',   'display_name' => 'Operator',            'description' => null, 'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $pppoeView   = $this->ensurePermission('perm_pppoe_view', 'pppoe.server.view', $now);
        $usersDelete = $this->ensurePermission('perm_users_delete', 'users.delete', $now);

        // Operator is granted one permission, and only that one.
        $this->db->table('role_permissions')->where('role_id', 'role_operator_test')->delete();
        $this->db->table('role_permissions')->insert([
            'role_id' => 'role_operator_test',
            'permission_id' => $pppoeView, 'granted' => 1, 'created_at' => $now,
        ]);

        $this->roleId = 'role_superadmin_test';
        $this->userId = 'u_superadmin_test';

        $this->db->table('users')->insert([
            'id'            => $this->userId,
            'username'      => 'superadmin_test',
            'email'         => 'sa@example.test',
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
            'auth_source'   => 'local',
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $this->db->table('user_roles')->insert([
            'user_id'     => $this->userId,
            'role_id'     => $this->roleId,
            'assigned_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();

        session()->remove('user_permissions');

        parent::tearDown();
    }

    /**
     * The id of a permission row, inserting one when the catalog lacks it.
     *
     * The test database already carries the application's permission catalog,
     * so an existing key is reused rather than duplicated.
     */
    private function ensurePermission(string $id, string $key, string $now): string
    {
        $existing = $this->db->table('permissions')->where('key', $key)->get()->getRowArray();
        if ($existing) {
            return (string) $existing['id'];
        }

        $this->db->table('permissions')->insert([
            'id' => $id, 'key' => $key, 'label' => $key, 'module' => 'Test',
            'description' => null, 'created_at' => $now,
        ]);

        return $id;
    }

    /** Removes every fixture this test class creates. */
    private function tearDownFixtures(): void
    {
        $this->db->table('user_roles')->where('user_id', 'u_superadmin_test')->delete();
        $this->db->table('user_roles')->where('id', 'ur_operator_test')->delete();
        $this->db->table('users')->where('id', 'u_superadmin_test')->delete();
        $this->db->table('permissions')->whereIn('id', ['perm_added_later', 'perm_pppoe_view', 'perm_users_delete'])->delete();
        $this->db->table('role_permissions')->whereIn('role_id', ['role_superadmin_test', 'role_operator_test'])->delete();
        $this->db->table('roles')->whereIn('id', ['role_superadmin_test', 'role_operator_test'])->delete();
    }

    public function testSuperAdminHoldsAPermissionAddedAfterTheRoleWasSeeded(): void
    {
        // A permission that no role_permissions row grants to anyone — exactly
        // the situation a migration-created permission starts in.
        $id = $this->ensurePermission('perm_added_later', 'test.added.later', date('Y-m-d H:i:s'));

        $granted = $this->db->table('role_permissions')
            ->where('role_id', $this->roleId)
            ->where('permission_id', $id)
            ->countAllResults();
        $this->assertSame(0, $granted, 'The fixture must start ungranted.');

        $model = new PermissionModel();

        $this->assertContains('test.added.later', $model->getEffectiveKeysForUser($this->userId));
        $this->assertTrue($model->userCan($this->userId, 'test.added.later'));
    }

    public function testAnExplicitDenialStillWinsOverTheWildcard(): void
    {
        $now        = date('Y-m-d H:i:s');
        $permission = $this->ensurePermission('perm_added_later', 'test.added.later', $now);

        // Deny-override must survive the wildcard: an operator can revoke even a
        // newly added capability from the superuser.
        $this->db->table('role_permissions')->insert([
            'role_id'       => $this->roleId,
            'permission_id' => $permission,
            'granted'       => 0,
            'created_at'    => $now,
        ]);

        $model = new PermissionModel();

        $this->assertNotContains('test.added.later', $model->getEffectiveKeysForUser($this->userId));

        $this->db->table('role_permissions')->where('role_id', $this->roleId)->delete();
    }

    public function testANonSuperAdminDoesNotGetTheWildcard(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('user_roles')->insert([
            'user_id'     => $this->userId,
            'role_id'     => 'role_operator_test',
            'assigned_at' => $now,
        ]);
        $this->db->table('user_roles')->where('role_id', 'role_superadmin_test')->delete();

        $model = new PermissionModel();

        // Operator holds pppoe.server.view but must not hold a permission that
        // was never granted to it.
        $this->assertTrue($model->userCan($this->userId, 'pppoe.server.view'));
        $this->assertFalse($model->userCan($this->userId, 'users.delete'));

        $this->db->table('user_roles')->where('role_id', 'role_operator_test')->delete();
    }
}
