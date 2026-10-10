<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

final class AppIdTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'firewall.rules.view',
        'firewall.rules.edit',
        'firewall.rules.delete',
        'firewall.rules.apply',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        AppVersion::clearCache();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function sessionData(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    public function testIndexPageLoads(): void
    {
        $result = $this->withSession($this->sessionData())->get('appid');

        $result->assertOK();
        $result->assertSee('Layer 7 Application Control Rulebase');
        $result->assertSee('Real-Time Layer 7 Flow Stream');
        $result->assertSee('Predefined Application Signatures');
    }

    public function testSavePolicyCreatesPolicy(): void
    {
        $payload = [
            'csrf_test_name'       => $this->csrfToken,
            'name'                 => 'Block Torrent Traffic',
            'sequence'             => 2,
            'src_interface'        => 'lan',
            'dst_interface'        => 'wan',
            'action'               => 'block',
            'app_ids'              => ['bittorrent'],
            'category_ids'         => ['p2p'],
            'bandwidth_limit_kbps' => '',
            'log_enabled'          => 1,
            'enabled'              => 1,
        ];

        $result = $this->withSession($this->sessionData())->post('appid/policy/save', $payload);

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success']);

        // Verify in database
        $this->seeInDatabase('dpi_policies', ['name' => 'Block Torrent Traffic', 'action' => 'block']);
    }

    public function testLiveFlowsEndpointReturnsFlows(): void
    {
        $result = $this->withSession($this->sessionData())->get('appid/live-flows');

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success']);
        $this->assertNotEmpty($json['flows']);
        $this->assertArrayHasKey('app_name', $json['flows'][0]);
    }

    public function testPreviewEndpointReturnsCompiledConfigs(): void
    {
        $result = $this->withSession($this->sessionData())->get('appid/preview');

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success']);
        $this->assertStringContainsString('ndpi_rules.conf', $json['ndpi_config']);
        $this->assertStringContainsString('dpi_forward', $json['nftables_snippet']);
    }

    public function testApplyEndpointExecutesSuccessfully(): void
    {
        $payload = [
            'csrf_test_name' => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())->post('appid/apply', $payload);

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success']);
    }
}
