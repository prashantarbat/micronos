<?php

namespace Tests\App\Controllers;

use App\Libraries\DefaultCredentials;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * An account still on the factory password (seeder, installer default,
 * kickstart, console reset) must replace it before using the appliance, and
 * can never use it on the internet-facing gateway.
 */
final class FactoryPasswordTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const FACTORY = 'M1Cr0N';

    private string $csrfToken = '';

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshCsrf();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $this->db->table('users')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    private function freshCsrf(): void
    {
        $this->csrfToken = $this->csrfToken ?: bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        // Security reads the cookie through the shared request; rebuild it so
        // it sees the cookie set above.
        Services::resetSingle('superglobals');
        Services::resetSingle('request');
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function makeUser(string $password): array
    {
        $id = 'usr_fp_' . bin2hex(random_bytes(3));
        $username = 'fpuser_' . substr($id, -6);
        $this->db->table('users')->insert([
            'id' => $id, 'username' => $username, 'email' => 'fp@example.invalid',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT), 'auth_source' => 'local',
            'role' => 'operator', 'is_active' => 1, 'totp_enabled' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->created[] = $id;

        return [$id, $username];
    }

    private function signIn(string $username, string $password)
    {
        // Open the sign-in page first, as a browser does.
        $this->withSession([])->get('auth/login');
        $this->freshCsrf();

        return $this->withSession([])->post('auth/login', [
            config(SecurityConfig::class)->tokenName => $this->csrfToken,
            'username' => $username,
            'password' => $password,
        ]);
    }

    private function forcedSession(string $userId): array
    {
        return [
            'user_id' => $userId, 'username' => 'fpuser', 'is_superadmin' => 1,
            'permissions' => ['dashboard.view'], 'user_permissions' => ['dashboard.view'],
            DefaultCredentials::SESSION_FLAG => true,
        ];
    }

    public function testSigningInWithTheFactoryPasswordForcesAChange(): void
    {
        [, $username] = $this->makeUser(self::FACTORY);

        $result = $this->signIn($username, self::FACTORY);

        $result->assertRedirect();
        $this->assertStringEndsWith('/profile', $result->getRedirectUrl());
        $this->assertTrue((bool) session()->get(DefaultCredentials::SESSION_FLAG));
    }

    public function testAnOwnPasswordSignsInNormally(): void
    {
        [, $username] = $this->makeUser('Branch-Office-2026');

        $result = $this->signIn($username, 'Branch-Office-2026');

        $result->assertRedirect();
        $this->assertStringNotContainsString('/profile', $result->getRedirectUrl());
        $this->assertNull(session()->get(DefaultCredentials::SESSION_FLAG));
    }

    public function testEveryOtherPageIsClosedUntilThePasswordChanges(): void
    {
        [$id] = $this->makeUser(self::FACTORY);

        $result = $this->withSession($this->forcedSession($id))->get('dashboard');

        $result->assertRedirect();
        $this->assertStringEndsWith('/profile', $result->getRedirectUrl());
    }

    public function testTheProfilePageStaysOpen(): void
    {
        [$id] = $this->makeUser(self::FACTORY);

        $this->withSession($this->forcedSession($id))->get('profile')->assertOK();
    }

    public function testTheFactoryPasswordCannotBeChosenAgain(): void
    {
        [$id] = $this->makeUser('Old-Password-1');

        $this->withSession($this->forcedSession($id))->post('profile/password', [
            config(SecurityConfig::class)->tokenName => $this->csrfToken,
            'current_password' => 'Old-Password-1',
            'new_password'     => self::FACTORY . 'xx',
            'confirm_password' => self::FACTORY . 'xx',
        ]);
        $this->freshCsrf();

        $result = $this->withSession($this->forcedSession($id))->post('profile/password', [
            config(SecurityConfig::class)->tokenName => $this->csrfToken,
            'current_password' => self::FACTORY . 'xx',
            'new_password'     => self::FACTORY,
            'confirm_password' => self::FACTORY,
        ]);

        $result->assertSessionHas('error');
        $hash = $this->db->table('users')->where('id', $id)->get()->getRow()->password_hash;
        $this->assertFalse(password_verify(self::FACTORY, $hash));
    }

    public function testChangingThePasswordReopensTheAppliance(): void
    {
        [$id] = $this->makeUser(self::FACTORY);

        $result = $this->withSession($this->forcedSession($id))->post('profile/password', [
            config(SecurityConfig::class)->tokenName => $this->csrfToken,
            'current_password' => self::FACTORY,
            'new_password'     => 'Branch-Office-2026',
            'confirm_password' => 'Branch-Office-2026',
        ]);

        $result->assertRedirect();
        $this->assertStringEndsWith('/dashboard', $result->getRedirectUrl());
        $this->assertNull(session()->get(DefaultCredentials::SESSION_FLAG));
        $hash = $this->db->table('users')->where('id', $id)->get()->getRow()->password_hash;
        $this->assertTrue(password_verify('Branch-Office-2026', $hash));
    }

    public function testShortPasswordsAreRefused(): void
    {
        [$id] = $this->makeUser(self::FACTORY);

        $result = $this->withSession($this->forcedSession($id))->post('profile/password', [
            config(SecurityConfig::class)->tokenName => $this->csrfToken,
            'current_password' => self::FACTORY,
            'new_password'     => 'short1',
            'confirm_password' => 'short1',
        ]);

        $result->assertSessionHas('error');
    }

    public function testTheGatewayNeverAcceptsTheFactoryPassword(): void
    {
        [, $username] = $this->makeUser(self::FACTORY);

        $result = $this->withSession([])->post('vpn/clientless-gateway/login', [
            config(SecurityConfig::class)->tokenName => $this->csrfToken,
            'username' => $username,
            'password' => self::FACTORY,
        ]);

        $result->assertRedirect();
        $this->assertStringContainsString('vpn/clientless-gateway/login', $result->getRedirectUrl());
        $this->assertNull(session()->get(\App\Libraries\GatewayIdentity::SESSION_KEY));
    }
}
