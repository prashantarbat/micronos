<?php

namespace Tests\App\Controllers;

use App\Models\SystemSettingsModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * System settings page after the clean-up: English only (no language picker),
 * no System Information section, no serial console / baud rate controls
 * (fixed 115200), IPv6 off by default, Solar Amber + Top nav by default.
 *
 * @internal
 */
final class SystemSettingsCleanupTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
        $this->db->table('system_settings')->where('id', 1)->delete();
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
        Services::injectMock('ipc', new FakeIpcClient());
    }

    protected function tearDown(): void
    {
        $this->db->table('system_settings')->where('id', 1)->delete();
        if ($this->saved !== null) {
            $this->db->table('system_settings')->insert($this->saved);
        }
        parent::tearDown();
    }

    private function page(): string
    {
        $perm = ['system.info.view', 'system.settings.edit', 'dashboard.view'];

        return $this->withSession(['logged_in' => true, 'user_id' => 'u1', 'username' => 'admin', 'is_superadmin' => 1,
            'permissions' => $perm, 'user_permissions' => $perm])->get('system')->getBody();
    }

    public function testFactoryDefaultsWhenNothingWasSaved(): void
    {
        $s = (new SystemSettingsModel())->getSettings();
        $this->assertSame('micronos', $s['theme']);
        $this->assertSame('topnav', $s['layout']);
        $this->assertSame(0, (int) $s['ipv6_enabled']);
        $this->assertSame('micronos', config('Appearance')->defaultTheme);
        $this->assertSame('topnav', config('Appearance')->defaultLayout);
    }

    public function testTheFirstPaintUsesMicronOsAndTopNav(): void
    {
        $body = $this->page();
        $this->assertMatchesRegularExpression('/<html[^>]*data-theme="micronos"/', $body);
        $this->assertMatchesRegularExpression('/<html[^>]*data-layout="topnav"/', $body);
    }

    public function testASavedPreferenceIsKept(): void
    {
        $this->db->table('system_settings')->insert(['id' => 1, 'theme' => 'dark', 'layout' => 'sidebar', 'updated_at' => date('Y-m-d H:i:s')]);
        $body = $this->page();
        $this->assertMatchesRegularExpression('/<html[^>]*data-theme="dark"/', $body);
    }

    public function testNoLanguageSystemInformationOrSerialConsoleControls(): void
    {
        $body = $this->page();
        $this->assertStringNotContainsString('name="language"', $body);
        $this->assertDoesNotMatchRegularExpression('/>\s*System Information\s*</', $body);
        $this->assertStringNotContainsString('name="console_speed"', $body);
        $this->assertDoesNotMatchRegularExpression('/Console Baud Rate/i', $body);
        $this->assertStringContainsString('115200', $body, 'the fixed speed is stated');
    }

    public function testIpv6IsShownOffByDefault(): void
    {
        $body = $this->page();
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="ipv6_enabled" id="ipv6_enabled" value="1"\s+onchange/', $body,
            'the IPv6 checkbox is not checked');
    }

    public function testPostedLanguageAndBaudRateAreIgnored(): void
    {
        $this->db->table('system_settings')->insert(['id' => 1, 'hostname' => 'micron', 'updated_at' => date('Y-m-d H:i:s')]);
        $perm = ['system.info.view', 'system.settings.edit', 'dashboard.view'];
        $this->withSession(['logged_in' => true, 'user_id' => 'u1', 'username' => 'admin', 'is_superadmin' => 1,
            'permissions' => $perm, 'user_permissions' => $perm])->post('system/save', [
            config(SecurityConfig::class)->tokenName => service('security')->getHash(),
            'hostname' => 'micron', 'language' => 'de', 'console_speed' => '9600',
        ])->assertRedirect();

        $row = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
        $this->assertNotSame('de', $row['language'] ?? null);
        $this->assertNotSame('9600', (string) ($row['console_speed'] ?? ''));
    }

    public function testBootPathsUse115200(): void
    {
        $grub = file_get_contents(ROOTPATH . 'installer/grub.d/09_micron_slots');
        $this->assertStringContainsString('console=ttyS0,115200n8', $grub);
        $install = file_get_contents(ROOTPATH . 'installer/install.sh');
        $this->assertStringContainsString('--speed=115200', $install);
        $this->assertDoesNotMatchRegularExpression('/ttyS0,(?!115200)\d+/', $grub . $install);
    }
}
