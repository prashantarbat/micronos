<?php

namespace Tests\App\Controllers;

use App\Libraries\LdapAuthService;
use App\Libraries\RadiusAuthService;
use App\Models\AuthGroupMappingModel;
use App\Models\LdapServerModel;
use App\Models\RadiusServerModel;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Enterprise Authentication Tests:
 *  - System Settings tabs (LDAP/Active Directory & RADIUS)
 *  - Save, Test & Directory Sync endpoints
 *  - External Group-to-Role Mappings (AD & RADIUS attributes)
 *  - Users & Identities management integration
 *  - Roles & Permissions external mappings integration
 *  - Dynamic login delegation and role resolution
 *
 * @internal
 */
final class RadiusAndLdapAuthTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'system.info.view',
        'system.settings.edit',
        'users.view',
        'users.create',
        'users.edit',
        'users.delete',
        'roles.view',
        'roles.create',
        'roles.edit',
        'roles.delete',
        'roles.assign',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        $now = date('Y-m-d H:i:s');
        if ($this->db->table('roles')->countAllResults() === 0) {
            $this->db->table('roles')->insertBatch([
                ['id' => 'role_superadmin_01', 'name' => 'superadmin', 'display_name' => 'Super Administrator', 'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 'role_admin_01',      'name' => 'admin',      'display_name' => 'Administrator',       'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 'role_operator_01',   'name' => 'operator',   'display_name' => 'Operator',            'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 'role_readonly_01',   'name' => 'readonly',   'display_name' => 'Read Only',           'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
        if ($this->db->table('users')->where('username', 'ministrator')->countAllResults() === 0) {
            $this->db->table('users')->insert([
                'id'            => 'usr_superadmin_test',
                'username'      => 'ministrator',
                'email'         => 'ministrator@firewall.local',
                'password_hash' => UserModel::hashPassword('micronos'),
                'auth_source'   => 'local',
                'role'          => 'superadmin',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'usr_superadmin_test',
            'username'         => 'ministrator',
            'role'             => 'superadmin',
            'is_superadmin'    => true,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params = []): array
    {
        $this->withSession($this->session());
        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    /**
     * Verify System Settings renders both LDAP/AD and RADIUS tabs and panels.
     */
    public function testSystemSettingsPageLoadsLdapAndRadiusTabs(): void
    {
        $result = $this->withSession($this->session())->get('system');
        $result->assertStatus(200);

        $body = $result->getBody();
        $this->assertStringContainsString('tab-btn-ldap', $body);
        $this->assertStringContainsString('tab-btn-radius', $body);
        $this->assertStringContainsString('panel-ldap', $body);
        $this->assertStringContainsString('panel-radius', $body);
        $this->assertStringContainsString('Active Directory', $body);
        $this->assertStringContainsString('RADIUS Authentication', $body);
    }

    /**
     * Verify saving LDAP / Active Directory configuration.
     */
    public function testSaveLdapConfiguration(): void
    {
        $roleModel = new RoleModel();
        $operatorRole = $roleModel->where('name', 'operator')->first();

        $params = [
            'name'                   => 'HQ Primary Active Directory',
            'schema_type'            => 'ad',
            'hostname'               => 'ad01.hq.micron.local',
            'port'                   => 636,
            'encryption'             => 'ldaps',
            'timeout'                => 6,
            'bind_dn'                => 'CN=svc_auth,OU=ServiceAccounts,DC=hq,DC=micron,DC=local',
            'bind_password'          => 'V@ultSecr3t!2026',
            'base_dn'                => 'DC=hq,DC=micron,DC=local',
            'user_search_base'       => 'OU=Staff,DC=hq,DC=micron,DC=local',
            'user_search_filter'     => '(&(objectClass=user)(sAMAccountName={username}))',
            'username_attribute'     => 'sAMAccountName',
            'display_name_attribute' => 'displayName',
            'group_attribute'        => 'memberOf',
            'group_search_base'      => 'OU=Security Groups,DC=hq,DC=micron,DC=local',
            'default_role_id'        => $operatorRole['id'] ?? null,
            'enabled'                => 1,
            'fallback_local'         => 1,
        ];

        $result = $this->post('system/ldap/save', $this->csrf($params));
        $result->assertRedirect();

        $ldapModel = new LdapServerModel();
        $saved = $ldapModel->getActiveServer();
        $this->assertNotNull($saved);
        $this->assertSame('HQ Primary Active Directory', $saved['name']);
        $this->assertSame('ad01.hq.micron.local', $saved['hostname']);
        $this->assertSame(636, (int)$saved['port']);
        $this->assertSame('ldaps', $saved['encryption']);
        $this->assertSame(1, (int)$saved['fallback_local']);
        $this->assertSame('V@ultSecr3t!2026', $saved['bind_password']);
    }

    /**
     * Verify LDAP authentication test AJAX endpoint.
     */
    public function testLdapAuthenticationTestEndpoint(): void
    {
        $params = [
            'username'      => 'testadmin',
            'password'      => 'anypass',
            'hostname'      => '127.0.0.1',
            'port'          => 389,
            'schema_type'   => 'ad',
            'encryption'    => 'none',
            'base_dn'       => 'DC=corp,DC=local',
            'bind_dn'       => '',
            'bind_password' => '',
        ];

        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                       ->post('system/ldap/test', $this->csrf($params));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON() ?: $result->response()->getBody(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('success', $json);
    }

    /**
     * Verify directory user synchronization.
     */
    public function testLdapUserSynchronization(): void
    {
        $result = $this->post('system/ldap/sync', $this->csrf());
        $result->assertRedirect();

        // Verify directory users were seeded / synced
        $userModel = new UserModel();
        $users = $userModel->where('auth_source', 'ldap')->findAll();
        $this->assertNotEmpty($users);
    }

    /**
     * Verify saving RADIUS server configuration (primary & backup).
     */
    public function testSaveRadiusConfiguration(): void
    {
        $roleModel = new RoleModel();
        $readonlyRole = $roleModel->where('name', 'readonly')->first();

        $params = [
            'name'            => 'Enterprise RADIUS Server',
            'hostname'        => '10.50.0.15',
            'auth_port'       => 1812,
            'acct_port'       => 1813,
            'auth_type'       => 'pap',
            'nas_identifier'  => 'micronos-edge-01',
            'nas_ip'          => '10.50.0.1',
            'timeout'         => 4,
            'retries'         => 3,
            'secret'          => 'radius_shared_secret_999',
            'group_attribute' => 'Filter-Id',
            'default_role_id' => $readonlyRole['id'] ?? null,
            'enabled'         => 1,
            'acct_enabled'    => 1,
            // Backup server
            'backup_name'     => 'Secondary RADIUS HA',
            'backup_hostname' => '10.50.0.16',
            'backup_auth_port'=> 1812,
            'backup_acct_port'=> 1813,
            'backup_secret'   => 'radius_backup_secret_888',
            'backup_enabled'  => 1,
        ];

        $result = $this->post('system/radius/save', $this->csrf($params));
        $result->assertRedirect();

        $radiusModel = new RadiusServerModel();
        $primary = $radiusModel->getPrimaryServer();
        $backup  = $radiusModel->getBackupServer();

        $this->assertNotNull($primary);
        $this->assertSame('10.50.0.15', $primary['hostname']);
        $this->assertSame(1812, (int)$primary['auth_port']);
        $this->assertSame('radius_shared_secret_999', $primary['secret']);
        $this->assertSame('micronos-edge-01', $primary['nas_identifier']);

        $this->assertNotNull($backup);
        $this->assertSame('10.50.0.16', $backup['hostname']);
        $this->assertSame('radius_backup_secret_888', $backup['secret']);
    }

    /**
     * Verify RADIUS authentication test AJAX endpoint.
     */
    public function testRadiusAuthenticationTestEndpoint(): void
    {
        $params = [
            'username'       => 'rad_operator',
            'password'       => 'rad_secret_pass',
            'hostname'       => '127.0.0.1',
            'auth_port'      => 1812,
            'secret'         => 'rad_secret',
            'auth_type'      => 'pap',
            'nas_identifier' => 'micronos-test',
            'nas_ip'         => '127.0.0.1',
        ];

        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                       ->post('system/radius/test', $this->csrf($params));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON() ?: $result->response()->getBody(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('success', $json);
        $this->assertTrue($json['success']);
        $this->assertNotNull($json['user']);
    }

    /**
     * Verify Group Mapping creation and deletion from System Settings.
     */
    public function testGroupMappingCreationAndDeletionFromSystem(): void
    {
        $roleModel = new RoleModel();
        $adminRole = $roleModel->where('name', 'admin')->first();
        $this->assertNotNull($adminRole);

        $params = [
            'source_type'         => 'ldap',
            'external_group_name' => 'CN=SecOpsAdmins,OU=Groups,DC=corp,DC=local',
            'role_id'             => $adminRole['id'],
            'priority'            => 5,
        ];

        $result = $this->post('system/group-mappings/store', $this->csrf($params));
        $result->assertRedirect();

        $mappingModel = new AuthGroupMappingModel();
        $mapping = $mappingModel->where('external_group', 'CN=SecOpsAdmins,OU=Groups,DC=corp,DC=local')->first();
        $this->assertNotNull($mapping);
        $this->assertSame('ldap', $mapping['auth_type']);
        $this->assertSame($adminRole['id'], $mapping['role_id']);
        $this->assertSame(5, (int)$mapping['priority']);

        // Delete mapping
        $delResult = $this->get("system/group-mappings/{$mapping['id']}/delete");
        $delResult->assertRedirect();
        $this->assertNull($mappingModel->find($mapping['id']));
    }

    /**
     * Verify Group Mapping creation and deletion from Roles & Permissions controller.
     */
    public function testGroupMappingCreationAndDeletionFromRoles(): void
    {
        $roleModel = new RoleModel();
        $superRole = $roleModel->where('name', 'superadmin')->first();

        $params = [
            'source_type'         => 'radius',
            'external_group_name' => 'radius_full_admins',
            'role_id'             => $superRole['id'],
            'priority'            => 1,
        ];

        $result = $this->post('roles/group-mappings/store', $this->csrf($params));
        $result->assertRedirect();

        $mappingModel = new AuthGroupMappingModel();
        $mapping = $mappingModel->where('external_group', 'radius_full_admins')->first();
        $this->assertNotNull($mapping);
        $this->assertSame('radius', $mapping['auth_type']);

        // Check /roles index renders the mapped group
        $rolesPage = $this->withSession($this->session())->get('roles');
        $rolesPage->assertStatus(200);
        $this->assertStringContainsString('radius_full_admins', $rolesPage->getBody());

        // Delete mapping
        $delResult = $this->post("roles/group-mappings/{$mapping['id']}/delete", $this->csrf());
        $delResult->assertRedirect();
        $this->assertNull($mappingModel->find($mapping['id']));
    }

    /**
     * Verify Users & Identities page displays authentication source metrics and badges.
     */
    public function testUsersIndexDisplaysAuthSourceAndBadges(): void
    {
        $result = $this->withSession($this->session())->get('users');
        $result->assertStatus(200);

        $body = $result->getBody();
        $this->assertStringContainsString('Total Accounts', $body);
        $this->assertStringContainsString('LDAP / AD Directory', $body);
        $this->assertStringContainsString('RADIUS Accounts', $body);
        $this->assertStringContainsString('Import LDAP Users', $body);
        $this->assertStringContainsString('Authentication Source', $body);
    }

    /**
     * Verify creating user with LDAP or RADIUS authentication source.
     */
    public function testUserCreationWithAuthSource(): void
    {
        $userModel = new UserModel();
        $testUsername = 'rad_user_' . bin2hex(random_bytes(4));

        $params = [
            'username'       => $testUsername,
            'full_name'      => 'RADIUS Test Operator',
            'email'          => 'operator@radius.corp',
            'auth_source'    => 'radius',
            'external_group' => 'network_operators',
            'roles'          => [],
        ];

        $result = $this->post('users/store', $this->csrf($params));
        $result->assertRedirect();

        $created = $userModel->where('username', $testUsername)->first();
        $this->assertNotNull($created);
        $this->assertSame('radius', $created['auth_source']);
        $this->assertSame('network_operators', $created['external_group']);

        // Cleanup
        $userModel->delete($created['id']);
    }

    /**
     * Verify RadiusAuthService role resolution and credential verification.
     */
    public function testRadiusAuthServiceAuthentication(): void
    {
        $service = new RadiusAuthService($this->db);
        $server = [
            'hostname'       => '127.0.0.1',
            'auth_port'      => 1812,
            'secret'         => 'testing123',
            'auth_type'      => 'pap',
            'nas_identifier' => 'micronos-unit-test',
            'nas_ip'         => '127.0.0.1',
            'timeout'        => 3,
        ];

        $res = $service->authenticate('radius_operator', 'secretpassword', $server);
        $this->assertTrue($res['success']);
        $this->assertNotNull($res['user']);
        $this->assertSame('radius_operator', $res['user']['username']);
        $this->assertNotEmpty($res['user']['role']);

        // Invalid credentials rejection test
        $resFailed = $service->authenticate('radius_operator', 'wrongpass', $server);
        $this->assertFalse($resFailed['success']);
    }

    /**
     * Verify dynamic login delegation in UserModel::attemptLogin.
     */
    public function testUserModelAttemptLoginWithDelegation(): void
    {
        $userModel = new UserModel();

        // 1. Verify local auth
        $localUser = $userModel->where('username', 'ministrator')->first();
        $this->assertNotNull($localUser);

        // Valid local password
        $authLocal = $userModel->attemptLogin('ministrator', 'micronos');
        $this->assertNotFalse($authLocal);
        $this->assertSame('ministrator', $authLocal['username']);

        // Invalid local password
        $authFailed = $userModel->attemptLogin('ministrator', 'badpassword');
        $this->assertFalse($authFailed);

        // 2. Create test user with RADIUS auth_source
        $radUser = [
            'id'             => 'usr_rad_test_' . bin2hex(random_bytes(4)),
            'username'       => 'rad_agent_' . bin2hex(random_bytes(3)),
            'password_hash'  => UserModel::hashPassword('temp_local_hash'),
            'email'          => 'agent@radius.local',
            'full_name'      => 'Radius Field Agent',
            'role'           => 'operator',
            'auth_source'    => 'radius',
            'external_group' => 'network_operators',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        $userModel->insert($radUser);

        // Authenticate delegated RADIUS user with mock server enabled
        $radiusModel = new RadiusServerModel();
        $primary = $radiusModel->getPrimaryServer();
        if ($primary) {
            $radiusModel->update($primary['id'], ['enabled' => 1, 'hostname' => '127.0.0.1', 'secret' => 'testsecret']);
        }

        $authRad = $userModel->attemptLogin($radUser['username'], 'goodpassword');
        $this->assertNotFalse($authRad);
        $this->assertSame($radUser['username'], $authRad['username']);

        // Clean up
        $userModel->delete($radUser['id']);
    }
}
