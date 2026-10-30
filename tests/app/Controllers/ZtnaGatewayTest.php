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
        $this->assertSame('127.0.0.1', $payload['guacd_host'] ?? null);
        $this->assertSame(4822, $payload['guacd_port'] ?? null);

        // A tampered payload or signature is rejected.
        [$body, $sig] = explode('.', $token);
        $this->assertNull(GuacamoleTokenGenerator::verifyToken($body . '.' . str_repeat('0', 64)));
        $this->assertNull(GuacamoleTokenGenerator::verifyToken('x' . $token));

        // Connect viewer with valid token
        $viewer = $this->withSession($this->sessionData())->get("vpn/clientless-gateway/connect/{$token}");
        $viewer->assertOK();
        $viewer->assertSee('guacamole-common.js');
        $viewer->assertSee('vpn/clientless-gateway/tunnel');
        $viewer->assertSee('Opening HTML5 RDP session');
    }

    public function testCompilerProducesValidNginxConfig(): void
    {
        $compiler = new ZtnaCompiler();
        $output = $compiler->compile();

        $this->assertTrue($output['enabled']);
        $this->assertStringContainsString('location = /api/ztna/verify-auth', $output['nginx_config']);
        $this->assertStringContainsString('location /vpn/clientless-gateway/tunnel', $output['nginx_config']);
        // guacd is not a WebSocket server: the tunnel must go to the bridge.
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:' . ZtnaCompiler::BRIDGE_PORT . ';', $output['nginx_config']);
        $this->assertDoesNotMatchRegularExpression('#proxy_pass http://[^;]*:4822#', $output['nginx_config']);
        $this->assertStringContainsString('Upgrade $http_upgrade', $output['nginx_config']);
    }

    public function testApplyAndStatusEndpoints(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        // Test Apply endpoint
        $applyResult = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/apply', [
            $tokenName => $this->csrfToken,
        ]);
        $applyResult->assertOK();
        $applyJson = json_decode($applyResult->getJSON(), true);
        $this->assertTrue($applyJson['success'] ?? false);
        $this->assertTrue($applyJson['compiled']['enabled'] ?? false);

        // Test Status endpoint
        $statusResult = $this->withSession($this->sessionData())->get('vpn/clientless-gateway/status');
        $statusResult->assertOK();
        $statusJson = json_decode($statusResult->getJSON(), true);
        $this->assertTrue($statusJson['success'] ?? false);
        $this->assertArrayHasKey('active_sessions', $statusJson);
    }

    public function testSaveAndFetchCompanyBrandingSettings(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $brandingData = [
            $tokenName           => $this->csrfToken,
            'enabled'            => '1',
            'company_name'       => 'Acme Global Cyber Corp',
            'portal_title'       => 'Acme Zero-Trust Secure Workstation',
            'brand_color'        => '#2563eb',
            'brand_color_hover'  => '#1d4ed8',
            'brand_accent_color' => '#10b981',
            'portal_theme'       => 'gray',
            'welcome_heading'    => 'Enterprise Workstation Access',
            'welcome_subtitle'   => 'Zero-install remote terminal and desktop streaming.',
            'notice_banner'      => 'Authorized personnel only. All traffic audited.',
            'notice_banner_type' => 'warning',
            'help_link'          => 'https://support.acme.corp',
            'help_link_text'     => 'IT Operations Desk',
            'footer_text'        => '© 2026 Acme Global Cyber Corp. All rights reserved.',
            'external_domain'    => 'gateway.acme.corp',
            'external_port'      => '8443',
            'guacd_host'         => '127.0.0.1',
            'guacd_port'         => '4822',
        ];

        $saveResult = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/settings/save', $brandingData);
        $saveResult->assertOK();
        $saveJson = json_decode($saveResult->getJSON(), true);
        $this->assertTrue($saveJson['success'] ?? false);

        // Verify in database
        $this->seeInDatabase('ztna_settings', [
            'id'           => 1,
            'company_name' => 'Acme Global Cyber Corp',
            'brand_color'  => '#2563eb',
            'portal_theme' => 'gray',
        ]);

        // Verify portal renders custom branding
        $portalResult = $this->withSession($this->sessionData())->get('vpn/clientless-gateway/portal');
        $portalResult->assertOK();
        $portalResult->assertSee('Acme Global Cyber Corp');
        $portalResult->assertSee('Enterprise Workstation Access');
        $portalResult->assertSee('Authorized personnel only. All traffic audited.');
        $portalResult->assertSee('#2563eb');
    }

    public function testResetBrandingEndpoint(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $resetResult = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/branding/reset', [
            $tokenName => $this->csrfToken,
        ]);
        $resetResult->assertOK();
        $resetJson = json_decode($resetResult->getJSON(), true);
        $this->assertTrue($resetJson['success'] ?? false);

        $this->seeInDatabase('ztna_settings', [
            'id'           => 1,
            'company_name' => 'Micron Security',
        ]);
    }

    public function testGuacdSocketProbeEndpoint(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $probeResult = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/guacd/test', [
            $tokenName  => $this->csrfToken,
            'guacd_host' => '127.0.0.1',
            'guacd_port' => '4822',
        ]);
        $probeResult->assertOK();
        $probeJson = json_decode($probeResult->getJSON(), true);
        $this->assertArrayHasKey('success', $probeJson);
        $this->assertArrayHasKey('message', $probeJson);
    }

    // -------------------------------------------------------------------------
    // Gateway sign-in (separate from the appliance login)
    // -------------------------------------------------------------------------

    /** CSRF tokens rotate after each POST; a test that posts twice needs a fresh one. */
    private function freshCsrf(): void
    {
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function makeGatewayUser(string $password, int $totp = 0): string
    {
        $id = 'usr_gw_' . bin2hex(random_bytes(3));
        $this->db->table('users')->insert([
            'id' => $id, 'username' => 'gwuser_' . substr($id, -6), 'email' => 'gw@example.invalid',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT), 'auth_source' => 'local',
            'role' => 'operator', 'is_active' => 1, 'totp_enabled' => $totp,
            'totp_secret' => $totp ? base64_encode('12345678901234567890') : null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function setRequireMfa(int $on): void
    {
        $model = new \App\Models\ZtnaSettingsModel();
        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'require_mfa' => $on]));
    }

    public function testPortalSendsSignedOutVisitorsToTheGatewayLogin(): void
    {
        $result = $this->withSession([])->get('vpn/clientless-gateway/portal');

        $result->assertRedirect();
        $this->assertStringContainsString('vpn/clientless-gateway/login', $result->getRedirectUrl());
        $this->assertStringNotContainsString('auth/login', $result->getRedirectUrl());
    }

    public function testGatewayLoginPageRendersBrandedForm(): void
    {
        $result = $this->withSession([])->get('vpn/clientless-gateway/login');

        $result->assertOK();
        $result->assertSee('Sign in');
        $result->assertSee('name="username"', null);
        $result->assertSee('name="password"', null);
        $result->assertDontSee('Enterprise Management Console');
    }

    public function testGatewaySignInGrantsPortalButNotTheAdminUi(): void
    {
        $this->setRequireMfa(0);
        $userId   = $this->makeGatewayUser('Gateway-Pass-1');
        $username = 'gwuser_' . substr($userId, -6);
        $tokenName = config(SecurityConfig::class)->tokenName;

        $login = $this->withSession([])->post('vpn/clientless-gateway/login', [
            $tokenName => $this->csrfToken,
            'username' => $username,
            'password' => 'Gateway-Pass-1',
        ]);
        $login->assertRedirect();
        $this->assertStringContainsString('vpn/clientless-gateway/portal', $login->getRedirectUrl());

        $identity = session()->get(\App\Libraries\GatewayIdentity::SESSION_KEY);
        $this->assertSame($username, $identity['username'] ?? null);
        // A portal sign-in is never an appliance (admin UI) sign-in.
        $this->assertNull(session()->get('user_id'));

        $portal = $this->withSession([\App\Libraries\GatewayIdentity::SESSION_KEY => $identity])->get('vpn/clientless-gateway/portal');
        $portal->assertOK();
        $portal->assertSee('Sign out');

        $admin = $this->withSession([\App\Libraries\GatewayIdentity::SESSION_KEY => $identity])->get('dashboard');
        $admin->assertRedirect();
        $this->assertStringContainsString('auth/login', $admin->getRedirectUrl());

        $this->db->table('users')->where('id', $userId)->delete();
    }

    public function testGatewaySignInRejectsWrongPassword(): void
    {
        $userId    = $this->makeGatewayUser('Gateway-Pass-1');
        $tokenName = config(SecurityConfig::class)->tokenName;

        $login = $this->withSession([])->post('vpn/clientless-gateway/login', [
            $tokenName => $this->csrfToken,
            'username' => 'gwuser_' . substr($userId, -6),
            'password' => 'wrong-password',
        ]);
        $login->assertRedirect();
        $this->assertStringContainsString('vpn/clientless-gateway/login', $login->getRedirectUrl());
        $this->assertNull(session()->get(\App\Libraries\GatewayIdentity::SESSION_KEY));

        $this->db->table('users')->where('id', $userId)->delete();
    }

    public function testGatewayRequiresTwoFactorWhenMandated(): void
    {
        $this->setRequireMfa(1);
        $tokenName = config(SecurityConfig::class)->tokenName;

        // No authenticator app enrolled: refused.
        $plain = $this->makeGatewayUser('Gateway-Pass-1');
        $refused = $this->withSession([])->post('vpn/clientless-gateway/login', [
            $tokenName => $this->csrfToken,
            'username' => 'gwuser_' . substr($plain, -6),
            'password' => 'Gateway-Pass-1',
        ]);
        $refused->assertRedirect();
        $this->assertStringContainsString('vpn/clientless-gateway/login', $refused->getRedirectUrl());
        $this->assertNull(session()->get(\App\Libraries\GatewayIdentity::SESSION_KEY));

        // Enrolled: password leads to the code step, not straight in.
        $totpUser = $this->makeGatewayUser('Gateway-Pass-1', 1);
        $this->freshCsrf();
        $step = $this->withSession([])->post('vpn/clientless-gateway/login', [
            $tokenName => $this->csrfToken,
            'username' => 'gwuser_' . substr($totpUser, -6),
            'password' => 'Gateway-Pass-1',
        ]);
        $step->assertRedirect();
        $this->assertStringContainsString('vpn/clientless-gateway/login/verify', $step->getRedirectUrl());
        $this->assertNull(session()->get(\App\Libraries\GatewayIdentity::SESSION_KEY));

        $this->setRequireMfa(0);
        $this->db->table('users')->whereIn('id', [$plain, $totpUser])->delete();
    }

    // -------------------------------------------------------------------------
    // Gateway HTTPS certificate
    // -------------------------------------------------------------------------

    private function selfSignedPair(string $cn): array
    {
        $key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr  = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 90, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }

    public function testUploadedCertificateIsImportedAndBound(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;
        [$cert, $key] = $this->selfSignedPair('gateway.acme.corp');

        $result = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/certificate/upload', [
            $tokenName => $this->csrfToken,
            'cert_pem' => $cert,
            'key_pem'  => $key,
        ]);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false, $json['message'] ?? '');

        $settings = (new \App\Models\ZtnaSettingsModel())->getSettings();
        $this->assertGreaterThan(0, (int) $settings['cert_id']);
        $this->seeInDatabase('pki_certificates', ['id' => (int) $settings['cert_id'], 'source' => 'imported', 'common_name' => 'gateway.acme.corp']);

        // The key never comes back to the browser.
        $page = $this->withSession($this->sessionData())->get('vpn/clientless-gateway');
        $page->assertOK();
        $page->assertSee('Gateway HTTPS Certificate');
        $this->assertStringNotContainsString('PRIVATE KEY-----' . "\n" . substr(explode("\n", $key)[1] ?? '', 0, 20), (string) $page->getBody());

        // Back to self-signed.
        $this->freshCsrf();
        $back = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/certificate/select', [
            $tokenName => $this->csrfToken,
            'cert_id'  => '0',
        ]);
        $this->assertTrue(json_decode($back->getJSON(), true)['success'] ?? false);
        $this->assertSame(0, (int) (new \App\Models\ZtnaSettingsModel())->getSettings()['cert_id']);
    }

    public function testUploadRejectsMismatchedKey(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;
        [$cert] = $this->selfSignedPair('gateway.acme.corp');
        [, $otherKey] = $this->selfSignedPair('other.acme.corp');

        $result = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/certificate/upload', [
            $tokenName => $this->csrfToken,
            'cert_pem' => $cert,
            'key_pem'  => $otherKey,
        ]);
        $json = json_decode($result->getJSON(), true);
        $this->assertFalse($json['success'] ?? true);
        $this->assertStringContainsString('does not match', $json['message'] ?? '');
    }

    public function testLetsEncryptRefusesPrivateHostnames(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;
        $model = new \App\Models\ZtnaSettingsModel();
        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'external_domain' => 'gac.akolaurban.local']));

        $result = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/certificate/acme', [
            $tokenName   => $this->csrfToken,
            'acme_email' => 'admin@example.com',
        ]);
        $json = json_decode($result->getJSON(), true);
        $this->assertFalse($json['success'] ?? true);
        $this->assertStringContainsString('private name', $json['message'] ?? '');
        $this->assertSame(0, (int) $model->getSettings()['acme_enabled']);
    }

    public function testCompilerAddsAcmeListenerOnlyWhenLetsEncryptIsOn(): void
    {
        $model = new \App\Models\ZtnaSettingsModel();
        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'enabled' => 1, 'acme_enabled' => 0]));
        $off = (new ZtnaCompiler())->compile()['nginx_config'];
        $this->assertStringNotContainsString('listen ' . ZtnaCompiler::ACME_PORT, $off);
        // Admin pages are not reachable through the gateway hostname.
        $this->assertStringContainsString('location ~ ^/vpn/clientless-gateway/(login|logout|portal|launch/|connect/)', $off);
        $this->assertStringContainsString('X-Forwarded-Proto https', $off);

        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'acme_enabled' => 1]));
        $on = (new ZtnaCompiler())->compile()['nginx_config'];
        $this->assertStringContainsString('listen ' . ZtnaCompiler::ACME_PORT . ';', $on);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/', $on);

        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'acme_enabled' => 0]));
    }

    public function testSettingsSaveKeepsTheCertificateBinding(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;
        $model = new \App\Models\ZtnaSettingsModel();
        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'cert_id' => 4242, 'acme_email' => 'keep@example.com']));

        $this->withSession($this->sessionData())->post('vpn/clientless-gateway/settings/save', [
            $tokenName        => $this->csrfToken,
            'enabled'         => '1',
            'external_domain' => 'gateway.acme.corp',
        ])->assertOK();

        $settings = $model->getSettings();
        $this->assertSame(4242, (int) $settings['cert_id']);
        $this->assertSame('keep@example.com', $settings['acme_email']);
        $model->replace(array_merge($settings, ['cert_id' => 0]));
    }

    // -------------------------------------------------------------------------
    // Gateway access controlled by firewall rules
    // -------------------------------------------------------------------------

    private bool $createdWan = false;

    private function ensureWanInterface(): void
    {
        if ($this->db->table('interfaces')->where('id', 'wan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1,
                'ip_assignment_type' => 'static', 'ipv4_address' => '198.51.100.2', 'ipv4_netmask' => 24, 'mtu' => 1500,
            ]);
            $this->createdWan = true;
        }
    }

    private function clearGatewayPolicies(): void
    {
        if ($this->createdWan) {
            $this->db->table('firewall_rules')->where('interface_id', 'wan')->whereIn('dst_interface', ['local', 'self'])->delete();
        }
        $this->db->table('firewall_rules')->whereIn('dst_interface', ['local', 'self'])->delete();
        $this->db->table('firewall_services')->where('name', \App\Libraries\GatewayAccessPolicy::SERVICE_NAME)->delete();
        if ($this->createdWan) {
            $this->db->table('interfaces')->where('id', 'wan')->delete();
            $this->createdWan = false;
        }
    }

    public function testSwitchingToFirewallControlSeedsAnAllowAllPolicyOnce(): void
    {
        $this->clearGatewayPolicies();
        $this->ensureWanInterface();
        $tokenName = config(SecurityConfig::class)->tokenName;
        $model = new \App\Models\ZtnaSettingsModel();
        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'access_mode' => 'open', 'external_port' => 443]));

        $post = [
            $tokenName        => $this->csrfToken,
            'enabled'         => '1',
            'external_domain' => 'gateway.acme.corp',
            'external_port'   => '443',
            'access_mode'     => 'firewall',
        ];
        $result = $this->withSession($this->sessionData())->post('vpn/clientless-gateway/settings/save', $post);
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);
        $this->assertStringContainsString(\App\Libraries\GatewayAccessPolicy::STARTER_NAME, $json['message'] ?? '');
        $this->assertSame('firewall', $model->getSettings()['access_mode']);

        $this->seeInDatabase('firewall_services', ['name' => \App\Libraries\GatewayAccessPolicy::SERVICE_NAME, 'tcp_port' => '443']);
        $this->seeInDatabase('firewall_rules', ['name' => \App\Libraries\GatewayAccessPolicy::STARTER_NAME, 'dst_interface' => 'local', 'action' => 'accept']);

        // Saving again (or switching off and on) never adds a second one.
        (new \App\Libraries\GatewayAccessPolicy())->ensure(443);
        $this->assertSame(1, $this->db->table('firewall_rules')->where('name', \App\Libraries\GatewayAccessPolicy::STARTER_NAME)->countAllResults());

        // The branding form does not post access_mode and must not reset it.
        $this->freshCsrf();
        $this->withSession($this->sessionData())->post('vpn/clientless-gateway/settings/save', [
            $tokenName     => $this->csrfToken,
            'enabled'      => '1',
            'company_name' => 'Acme',
        ])->assertOK();
        $this->assertSame('firewall', $model->getSettings()['access_mode']);

        $model->replace(array_merge($model->getSettings(), ['access_mode' => 'open']));
        $this->clearGatewayPolicies();
    }

    public function testFirewallControlReplacesTheBlanketAcceptWithAPolicyChain(): void
    {
        $this->clearGatewayPolicies();
        $this->ensureWanInterface();
        $model = new \App\Models\ZtnaSettingsModel();
        $model->replace(array_merge($model->getSettings(), ['id' => 1, 'enabled' => 1, 'external_port' => 443, 'access_mode' => 'open']));

        $open = (new \App\Libraries\NftablesCompiler())->compile();
        $this->assertStringContainsString('tcp dport 443 accept comment "ZTNA Clientless Gateway Reverse Proxy"', $open);
        $this->assertStringNotContainsString('jump ztna_gateway', $open);

        $model->replace(array_merge($model->getSettings(), ['access_mode' => 'firewall']));
        (new \App\Libraries\GatewayAccessPolicy())->ensure(443);

        // A narrower allow above the starter policy, for one office range.
        $now = date('Y-m-d H:i:s');
        $this->db->table('firewall_rules')->insert([
            'id' => 'pol_gwtest_office', 'name' => 'ZTNA office only', 'sequence' => 0, 'interface_id' => 'wan',
            'src_interface' => 'any', 'dst_interface' => 'local',
            'sources' => json_encode(['203.0.113.0/24']), 'destinations' => json_encode(['all']),
            'services' => json_encode([\App\Libraries\GatewayAccessPolicy::SERVICE_NAME]),
            'action' => 'accept', 'direction' => 'in', 'protocol' => 'any', 'source_type' => 'any', 'source_value' => 'any',
            'dest_type' => 'any', 'dest_value' => 'any', 'schedule' => 'always', 'log_traffic' => 'none', 'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $ruleset = (new \App\Libraries\NftablesCompiler())->compile();

        $this->assertStringContainsString('tcp dport 443 jump ztna_gateway', $ruleset);
        $this->assertStringNotContainsString('ZTNA Clientless Gateway Reverse Proxy', $ruleset);
        $this->assertStringNotContainsString('ZTNA Clientless Gateway (WAN Ingress)', $ruleset);

        $chainStart = strpos($ruleset, 'chain ztna_gateway {');
        $this->assertNotFalse($chainStart);
        $chain = substr($ruleset, $chainStart, strpos($ruleset, "\n    }", $chainStart) - $chainStart);

        $office  = strpos($chain, 'ip saddr 203.0.113.0/24 tcp dport 443 accept comment "ZTNA office only"');
        $starter = strpos($chain, 'comment "' . \App\Libraries\GatewayAccessPolicy::STARTER_NAME . '"');
        $deny    = strpos($chain, 'MICRON-ZTNA-DENY');
        $this->assertNotFalse($office);
        $this->assertNotFalse($starter);
        $this->assertNotFalse($deny);
        // Policy order is kept and the default deny comes last.
        $this->assertLessThan($starter, $office);
        $this->assertLessThan($deny, $starter);

        // The jump comes before the WAN catch-all drop, so WAN sources can be allowed.
        $jump = strpos($ruleset, 'jump ztna_gateway');
        $wanDrop = strpos($ruleset, 'WAN Security: Drop unsolicited inbound WAN traffic');
        if ($wanDrop !== false) {
            $this->assertLessThan($wanDrop, $jump);
        }

        // This-Firewall policies are never turned into forward rules.
        $forward = substr($ruleset, (int) strpos($ruleset, 'chain forward {'));
        $this->assertStringNotContainsString('oifname "local"', $forward);

        $this->db->table('firewall_rules')->where('id', 'pol_gwtest_office')->delete();
        $model->replace(array_merge($model->getSettings(), ['access_mode' => 'open']));
        $this->clearGatewayPolicies();
    }
}
