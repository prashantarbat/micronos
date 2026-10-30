<?php

namespace Tests\App\Commands;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * The break-glass reset has no default password, refuses the published
 * factory password, never prints the password it was given, and the
 * Ministrator account has no hard-coded password that always works.
 */
final class UserResetAdminCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private ?array $savedAdmin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAdmin = $this->db->table('users')->where('username', 'Ministrator')->get()->getRowArray();
        putenv('MICRON_ADMIN_PW');
    }

    protected function tearDown(): void
    {
        putenv('MICRON_ADMIN_PW');
        $current = $this->db->table('users')->where('username', 'Ministrator')->get()->getRowArray();
        if ($this->savedAdmin === null && $current !== null) {
            $this->db->table('user_roles')->where('user_id', $current['id'])->delete();
            $this->db->table('users')->where('id', $current['id'])->delete();
        } elseif ($this->savedAdmin !== null) {
            $this->db->table('users')->where('id', $this->savedAdmin['id'])->update($this->savedAdmin);
        }
        parent::tearDown();
    }

    private function adminHash(): ?string
    {
        $row = $this->db->table('users')->where('username', 'Ministrator')->get()->getRowArray();

        return $row['password_hash'] ?? null;
    }

    public function testThereIsNoDefaultPassword(): void
    {
        $before = $this->adminHash();

        $this->resetStreamFilterBuffer();
        command('user:reset-admin');

        $this->assertStringContainsString('A new password is required', $this->getStreamFilterBuffer());
        $this->assertSame($before, $this->adminHash());
    }

    public function testTheFactoryPasswordIsRefused(): void
    {
        $before = $this->adminHash();

        command('user:reset-admin M1Cr0N');

        $this->assertSame($before, $this->adminHash());
    }

    public function testAShortPasswordIsRefused(): void
    {
        $before = $this->adminHash();

        command('user:reset-admin short');

        $this->assertSame($before, $this->adminHash());
    }

    public function testThePasswordCanComeFromTheEnvironmentAndIsNeverPrinted(): void
    {
        putenv('MICRON_ADMIN_PW=Env-Supplied-2026');

        $this->resetStreamFilterBuffer();
        command('user:reset-admin --from-env');
        $output = $this->getStreamFilterBuffer();

        $this->assertTrue(password_verify('Env-Supplied-2026', (string) $this->adminHash()));
        $this->assertStringNotContainsString('Env-Supplied-2026', $output);
    }

    public function testGenerateSetsARandomPasswordAndShowsItOnce(): void
    {
        $this->resetStreamFilterBuffer();
        command('user:reset-admin --generate');
        $output = $this->getStreamFilterBuffer();

        $this->assertMatchesRegularExpression('/shown once\): ([A-Za-z0-9]{16})/', $output);
        preg_match('/shown once\): ([A-Za-z0-9]{16})/', $output, $m);
        $this->assertTrue(password_verify($m[1], (string) $this->adminHash()));
    }

    public function testTheFactoryPasswordNoLongerOpensAChangedAdminAccount(): void
    {
        command('user:reset-admin Own-Strong-Pass-1');
        $model = new UserModel();

        foreach (['M1Cr0N', 'M1Cr0N80'] as $legacy) {
            $this->assertFalse($model->attemptLogin('Ministrator', $legacy), "{$legacy} must not open the account");
        }
        $this->db->table('users')->where('username', 'Ministrator')->update(['failed_logins' => 0, 'locked_until' => null]);
        $this->assertIsArray($model->attemptLogin('Ministrator', 'Own-Strong-Pass-1'));
    }
}
