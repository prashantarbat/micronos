<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use App\Libraries\GuacamoleTokenGenerator;
use App\Libraries\ZtnaCompiler;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

final class ZtnaGatewayTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'vpn.openvpn.view',
        'vpn.openvpn.manage',
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
            'email'            => 'ministrator@micronos.local',
            'is_superadmin'    => 1,
            'roles'            => ['SuperAdmin'],
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    public function testAdminIndexPageLoads(): void
    {
        $result = $this->withSession($this->sessionData())->get('vpn/clientless-gateway');

        $result->assertOK();
        $result->assertSee('In-Browser Clientless Gateway');
        $result->assertSee('Published Resource Catalogue');
        $result->assertSee('Active User Sessions');
    }

    public function testPortalPageLoads(): void
    {
        $result = $this->withSession($this->sessionData())->get('vpn/clientless-gateway/portal');

        $result->assertOK();
        $result->assertSee('Clientless Gateway');
        $result->assertSee('Authorized Corporate Applications');
    }

    public function testSaveAndFetchResource(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName      => $this->csrfToken,
            'name'          => 'GitLab Internal Codebase',
            'slug'          => 'gitlab',
            'type'          => 'web',
            'internal_host' => '192.168.10.88',
            'internal_port' => '8080',
            'description'   => 'Internal developer Git server with SSO header injection',
            'allowed_roles' => ['SuperAdmin', 'Developer'],
            'require_auth'  => '1',
            'status'        => 'online',
        ];

        $result = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/resource/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $this->seeInDatabase('ztna_resources', [
            'slug' => 'gitlab',
            'type' => 'web',
        ]);
    }

    public function testVerifyAuthSubrequest(): void
    {
        // 1. Unauthenticated request returns 401
        $unauth = $this->withSession([])->get('vpn/clientless-gateway/verify-auth');
        $unauth->assertStatus(401);

        // 2. Authenticated request returns 200 OK + identity headers
        $auth = $this->withSession($this->sessionData())->get('vpn/clientless-gateway/verify-auth');
        $auth->assertOK();
        $auth->assertHeader('X-Remote-User', 'ministrator');
        $auth->assertHeader('X-Remote-Email', 'ministrator@micronos.local');
    }

    public function testTokenGeneratorAndViewerPage(): void
    {
        $res = [
            'name'          => 'Windows Bastion Server',
            'type'          => 'rdp',
            'internal_host' => '192.168.10.15',
            'internal_port' => 3389,
        ];

        $token = GuacamoleTokenGenerator::generateToken($res, 'ministrator');
        $this->assertNotEmpty($token);

        $payload = GuacamoleTokenGenerator::verifyToken($token);
        $this->assertNotNull($payload);
        $this->assertSame('192.168.10.15', $payload['host'] ?? null);

        // Connect viewer with valid token
        $viewer = $this->withSession($this->sessionData())->get("vpn/clientless-gateway/connect/{$token}");
        $viewer->assertOK();
        $viewer->assertSee('HTML5 RDP Canvas Stream Active');
    }

    public function testCompilerProducesValidNginxConfig(): void
    {
        $compiler = new ZtnaCompiler();
        $output = $compiler->compile();

        $this->assertTrue($output['enabled']);
        $this->assertStringContainsString('location = /api/ztna/verify-auth', $output['nginx_config']);
        $this->assertStringContainsString('location /tunnel', $output['nginx_config']);
        $this->assertStringContainsString('Upgrade $http_upgrade', $output['nginx_config']);
    }
}
