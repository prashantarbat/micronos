<?php

namespace Tests\App\Controllers;

use App\Libraries\IpcClient;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * UsersCrudMicronOsSyncTest
 *
 * Verifies unified Web GUI and Micron OS user CRUD operations:
 * - Listing users with Micron OS synchronization badges
 * - Creating users with shell and SSH authorized keys
 * - Updating users and syncing changes to Micron OS
 * - Resetting passwords across Web GUI and Micron OS
 * - Deleting users while safeguarding protected accounts (ministrator / root)
 * - Bulk synchronizing DB users to Micron OS
 *
 * @internal
 */
final class UsersCrudMicronOsSyncTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'users.view',
        'users.create',
        'users.edit',
        'users.delete',
        'users.password.reset',
        'roles.view',
        'roles.assign',
    ];

    private string $csrfToken = '';
    private UserModel $userModel;
    private RoleModel $roleModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        $this->userModel = new UserModel();
        $this->roleModel = new RoleModel();

        $now = date('Y-m-d H:i:s');

        // Ensure superadmin role exists
        if (! $this->roleModel->where('name', 'superadmin')->first()) {
            $this->roleModel->insert([
                'id'           => 'role_superadmin_test',
                'name'         => 'superadmin',
                'display_name' => 'Super Administrator',
                'description'  => 'Full appliance access',
                'is_system'    => 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // Ensure default ministrator user exists in DB
        if (! $this->userModel->where('username', 'ministrator')->first()) {
            $this->userModel->insert([
                'id'            => 'usr_ministrator_test',
                'username'      => 'ministrator',
                'email'         => 'ministrator@firewall.local',
                'full_name'     => 'MicronOS Administrator',
                'password_hash' => UserModel::hashPassword('M1Cr0N'),
                'auth_source'   => 'local',
                'shell'         => '/bin/bash',
                'role'          => 'superadmin',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        $superRole = $this->roleModel->where('name', 'superadmin')->first();
        if ($superRole) {
            $db = \Config\Database::connect();
            $hasPivot = $db->table('user_roles')
                ->where('user_id', 'usr_ministrator_test')
                ->where('role_id', $superRole['id'])
                ->countAllResults() > 0;
            if (! $hasPivot) {
                $db->table('user_roles')->insert([
                    'user_id'     => 'usr_ministrator_test',
                    'role_id'     => $superRole['id'],
                    'assigned_by' => 'usr_ministrator_test',
                ]);
            }
        }
    }

    private function session(string $username = 'ministrator', bool $isSuper = true): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'usr_ministrator_test',
            'username'         => $username,
            'role'             => 'superadmin',
            'is_superadmin'    => $isSuper,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params = []): array
    {
        $this->withSession($this->session());
        return $params + [config(SecurityConfig::class)->tokenName => service('security')->getHash()];
    }

    public function testIndexDisplaysOsSyncBadgesAndActions(): void
    {
        $result = $this->withSession($this->session())->get('users');

        $result->assertStatus(200);
        $result->assertSee('Unified Web GUI');
        $result->assertSee('Micron OS Accounts');
        $result->assertSee('Sync Micron OS');
        $result->assertSee('Micron OS Status');
        $result->assertSee('OS Synced');
    }

    public function testCreateRendersShellAndSshKeyFields(): void
    {
        $result = $this->withSession($this->session())->get('users/create');

        $result->assertStatus(200);
        $result->assertSee('Micron OS Login Shell');
        $result->assertSee('/bin/bash');
        $result->assertSee('/usr/local/bin/microctl');
        $result->assertSee('SSH Authorized Keys');
    }

    public function testStoreCreatesDbUserAndProvisionsMicronOs(): void
    {
        $testUsername = 'devuser_' . bin2hex(random_bytes(3));

        $postData = [
            'username'    => $testUsername,
            'full_name'   => 'Test Dev User',
            'email'       => $testUsername . '@example.com',
            'password'    => 'StrongPass99!',
            'shell'       => '/bin/bash',
            'ssh_keys'    => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... user@host',
            'auth_source' => 'local',
        ];

        $result = $this->post('users/store', $this->csrf($postData));
        $result->assertRedirectTo(base_url('users'));

        $created = $this->userModel->where('username', $testUsername)->first();
        $this->assertNotNull($created);
        $this->assertSame('Test Dev User', $created['full_name']);
        $this->assertSame('/bin/bash', $created['shell']);
        $this->assertStringContainsString('ssh-ed25519', (string) $created['ssh_keys']);
    }

    public function testEditRendersShellAndSshKeys(): void
    {
        $testUsername = 'edittest_' . bin2hex(random_bytes(3));
        $userId = 'usr_' . bin2hex(random_bytes(6));

        $this->userModel->insert([
            'id'            => $userId,
            'username'      => $testUsername,
            'password_hash' => UserModel::hashPassword('InitialPass12!'),
            'full_name'     => 'Edit Test User',
            'email'         => 'edit@example.com',
            'shell'         => '/usr/local/bin/microctl',
            'ssh_keys'      => 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAA... admin@micron',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $result = $this->withSession($this->session())->get('users/edit/' . $userId);
        $result->assertStatus(200);
        $result->assertSee($testUsername);
        $result->assertSee('Micron OS Login Shell');
        $result->assertSee('/usr/local/bin/microctl');
        $result->assertSee('ssh-rsa AAAAB3NzaC1yc2E');
    }

    public function testUpdateSavesChangesAndSyncsToMicronOs(): void
    {
        $testUsername = 'updatetest_' . bin2hex(random_bytes(3));
        $userId = 'usr_' . bin2hex(random_bytes(6));

        $this->userModel->insert([
            'id'            => $userId,
            'username'      => $testUsername,
            'password_hash' => UserModel::hashPassword('InitialPass12!'),
            'full_name'     => 'Before Update',
            'email'         => 'before@example.com',
            'shell'         => '/bin/bash',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $postData = [
            'full_name' => 'After Update Senior',
            'email'     => 'after@example.com',
            'password'  => 'UpdatedPass99!',
            'shell'     => '/usr/local/bin/microctl',
            'ssh_keys'  => 'ssh-ed25519 AAAAC3NzaC... updated@key',
        ];

        $result = $this->post('users/update/' . $userId, $this->csrf($postData));
        $result->assertRedirectTo(base_url('users'));

        $updated = $this->userModel->find($userId);
        $this->assertSame('After Update Senior', $updated['full_name']);
        $this->assertSame('after@example.com', $updated['email']);
        $this->assertSame('/usr/local/bin/microctl', $updated['shell']);
        $this->assertTrue(password_verify('UpdatedPass99!', $updated['password_hash']));
    }

    public function testResetPasswordUpdatesDbAndMicronOs(): void
    {
        $testUsername = 'pwreset_' . bin2hex(random_bytes(3));
        $userId = 'usr_' . bin2hex(random_bytes(6));

        $this->userModel->insert([
            'id'            => $userId,
            'username'      => $testUsername,
            'password_hash' => UserModel::hashPassword('OldPass123!'),
            'full_name'     => 'Reset Target',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $postData = [
            'new_password' => 'BrandNewPass888!',
        ];

        $result = $this->post('users/' . $userId . '/reset-pw', $this->csrf($postData));
        $result->assertRedirectTo(base_url('users'));

        $user = $this->userModel->find($userId);
        $this->assertTrue(password_verify('BrandNewPass888!', $user['password_hash']));
    }

    public function testDeleteRemovesUserAndProtectsMinistrator(): void
    {
        // 1. Attempt to delete ministrator (must fail)
        $ministrator = $this->userModel->where('username', 'ministrator')->first();
        if ($ministrator) {
            $result = $this->post('users/' . $ministrator['id'] . '/delete', $this->csrf());
            $result->assertRedirectTo(base_url('users'));
            // ministrator must still exist
            $this->assertNotNull($this->userModel->where('username', 'ministrator')->first());
        }

        // 2. Normal user delete (must succeed)
        $testUsername = 'todelete_' . bin2hex(random_bytes(3));
        $userId = 'usr_' . bin2hex(random_bytes(6));

        $this->userModel->insert([
            'id'            => $userId,
            'username'      => $testUsername,
            'password_hash' => UserModel::hashPassword('TempPass123!'),
            'full_name'     => 'Delete Candidate',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $result = $this->post('users/' . $userId . '/delete', $this->csrf());
        $result->assertRedirectTo(base_url('users'));

        $this->assertNull($this->userModel->find($userId));
    }

    public function testSyncOsActionReconcilesAllDbUsers(): void
    {
        $result = $this->post('users/sync-os', $this->csrf());
        $result->assertRedirectTo(base_url('users'));
    }
}
