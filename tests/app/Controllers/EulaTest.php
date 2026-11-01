<?php

namespace Tests\App\Controllers;

use App\Libraries\Eula;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * The licence agreement stands in front of every page until an administrator
 * accepts the current version, and is asked once per appliance.
 */
final class EulaTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private string $csrfToken = '';
    private array $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('MICRON_EULA_ASSUME_ACCEPTED=0');
        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        $row = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
        if ($row === null) {
            $this->db->table('system_settings')->insert(['id' => 1, 'hostname' => 'micronos']);
            $row = ['id' => 1];
        }
        $this->savedSettings = array_intersect_key($row, array_flip(['eula_version', 'eula_accepted_at', 'eula_accepted_by']));
        $this->db->table('system_settings')->where('id', 1)->update(['eula_version' => '', 'eula_accepted_at' => null, 'eula_accepted_by' => '']);
    }

    protected function tearDown(): void
    {
        if ($this->savedSettings !== []) {
            $this->db->table('system_settings')->where('id', 1)->update($this->savedSettings);
        }
        putenv('MICRON_EULA_ASSUME_ACCEPTED=1');
        parent::tearDown();
    }

    private function session(bool $admin = true): array
    {
        return [
            'user_id'          => $admin ? 'usr_superadmin' : 'usr_operator',
            'username'         => $admin ? 'ministrator' : 'operator',
            'is_superadmin'    => $admin,
            'permissions'      => ['dashboard.view'],
            'user_permissions' => ['dashboard.view'],
        ];
    }

    private function postAs(string $path, array $data = [], bool $admin = true)
    {
        $data[config(SecurityConfig::class)->tokenName] = $this->csrfToken;

        return $this->withSession($this->session($admin))->post($path, $data);
    }

    public function testEveryPageLeadsToTheAgreementUntilItIsAccepted(): void
    {
        $result = $this->withSession($this->session())->get('dashboard');

        $result->assertRedirectTo(base_url('eula'));
    }

    public function testTheAgreementPageShowsTheShippedText(): void
    {
        $result = $this->withSession($this->session())->get('eula');

        $result->assertOK();
        $result->assertSee('Micron OS Licence Agreement');
        $result->assertSee('Micron OS is open source software');
        $result->assertSee('eula/accept');
    }

    public function testAcceptingNeedsTheTickBox(): void
    {
        $this->postAs('eula/accept')->assertRedirectTo(base_url('eula'));

        $this->assertFalse(Eula::isAccepted($this->settings()));
    }

    public function testAnAdministratorAcceptsOnceForTheAppliance(): void
    {
        $this->postAs('eula/accept', ['agree' => '1'])->assertRedirect();

        $settings = $this->settings();
        $this->assertTrue(Eula::isAccepted($settings));
        $this->assertSame('ministrator', $settings['eula_accepted_by']);
        $this->assertNotEmpty($settings['eula_accepted_at']);

        // A later sign-in, by anyone, is no longer stopped.
        session()->destroy();
        $_SESSION = $this->session(false);
        $this->assertNull((new \App\Filters\AuthFilter())->before(service('request')));
        $this->assertSame(Eula::VERSION, session()->get(Eula::SESSION_FLAG));
    }

    public function testOnlyAnAdministratorCanAccept(): void
    {
        $this->withSession($this->session(false))->get('eula')->assertDontSee('eula/accept');

        $this->postAs('eula/accept', ['agree' => '1'], false)->assertRedirectTo(base_url('eula'));
        $this->assertFalse(Eula::isAccepted($this->settings()));
    }

    public function testDecliningSignsOut(): void
    {
        $this->postAs('eula/decline')->assertRedirectTo(base_url('auth/logout'));
    }

    public function testANewVersionOfTheAgreementIsAskedAgain(): void
    {
        $this->db->table('system_settings')->where('id', 1)->update(['eula_version' => '0.9']);

        $this->withSession($this->session())->get('dashboard')->assertRedirectTo(base_url('eula'));
    }

    private function settings(): array
    {
        return (array) $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
    }
}
