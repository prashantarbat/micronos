<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Controller wiring for the connector backup policy (§7.6): the create path
 * enforces a credential-grade password and records whether the archive carries
 * connector credentials.
 *
 * @internal
 */
final class BackupPolicyTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['backup.view', 'backup.create', 'backup.restore'];
    private const TEST_USER   = 'usr_backup_policy_test';
    private const STRONG_PASS = 'uncopyable-14-character';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('users')->where('id', self::TEST_USER)->delete();
        $this->db->table('users')->insert([
            'id'            => self::TEST_USER,
            'username'      => 'backup_policy_test',
            'email'         => 'backup-policy-test@example.com',
            'password_hash' => password_hash(self::STRONG_PASS, PASSWORD_BCRYPT),
            'auth_source'   => 'local',
            'role'          => 'Operator',
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('users')->where('id', self::TEST_USER)->delete();
        parent::tearDown();
    }

    public function testCreateRejectsAShortBackupPassword(): void
    {
        $before = $this->db->table('system_backups')->countAllResults();

        $result = $this->post('backup/create', $this->csrf(['encryption_password' => 'too-short']));

        $result->assertStatus(302);
        $this->assertSame($before, $this->db->table('system_backups')->countAllResults(), 'a refused password must not create a backup');
    }

    public function testCreateRejectsReusingTheLoginPassword(): void
    {
        $before = $this->db->table('system_backups')->countAllResults();

        $result = $this->post('backup/create', $this->csrf([
            'encryption_password' => self::STRONG_PASS,
        ], self::TEST_USER));

        $result->assertStatus(302);
        $this->assertSame($before, $this->db->table('system_backups')->countAllResults());
    }

    public function testCreateWithoutPasswordRecordsCredentialExclusion(): void
    {
        $result = $this->post('backup/create', $this->csrf([
            'description'         => 'policy-test snapshot',
            'encryption_password' => '',
        ]));

        $result->assertStatus(302);

        $row = $this->db->table('system_backups')
            ->where('description', 'policy-test snapshot')
            ->orderBy('created_at', 'DESC')
            ->get()->getRowArray();

        $this->assertNotNull($row, 'plaintext backup should have been recorded');
        $this->assertSame('0', (string) $row['is_encrypted']);
        $this->assertSame('0', (string) $row['credentials_included']);

        // Clean up the archive this test wrote to disk.
        if (! empty($row['file_path']) && is_file($row['file_path'])) {
            @unlink($row['file_path']);
        }
        $this->db->table('system_backups')->where('id', $row['id'])->delete();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function csrf(array $params, string $userId = 'usr_root_ministrator'): array
    {
        $this->withSession([
            'user_id'          => $userId,
            'username'         => 'backup_policy_test',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ]);

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }
}
