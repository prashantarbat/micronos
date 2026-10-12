<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

final class SslInspectionTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'webfilter.view',
        'webfilter.policy.edit',
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
        $result = $this->withSession($this->sessionData())->get('ssl-inspection');

        $result->assertOK();
        $result->assertSee('Full SSL/TLS Deep Packet Inspection');
        $result->assertSee('Inspection Profiles');
        $result->assertSee('Domain Bypass List');
        $result->assertSee('Synthetic Cert Simulator');
    }

    public function testSaveAndFetchInspectionProfile(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName                => $this->csrfToken,
            'name'                    => 'Unit Test High Security Profile',
            'description'             => 'Deep packet inspection for corporate LAN',
            'mode'                    => 'deep_inspection',
            'min_tls_version'         => '1.3',
            'block_untrusted_issuers' => '1',
            'allow_invalid_certs'     => '0',
            'log_inspected_sessions'  => '1',
            'is_default'              => '0',
            'exempt_categories'       => ['finance', 'healthcare'],
            'exempt_domains'          => "*.chase.com\n*.wellsfargo.com",
        ];

        $result = $this->withSession($this->sessionData())->post('ssl-inspection/profile/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        // Verify in database
        $this->seeInDatabase('ssl_inspection_profiles', [
            'name' => 'Unit Test High Security Profile',
            'mode' => 'deep_inspection',
        ]);
    }

    public function testSaveAndFetchBypassDomain(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName       => $this->csrfToken,
            'domain_pattern' => '*.bankofamerica.com',
            'category'       => 'banking',
            'reason'         => 'Financial portal TLS certificate bypass',
            'enabled'        => '1',
        ];

        $result = $this->withSession($this->sessionData())->post('ssl-inspection/bypass/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $this->seeInDatabase('ssl_bypass_domains', [
            'domain_pattern' => '*.bankofamerica.com',
            'category'       => 'banking',
        ]);
    }

    public function testSaveGlobalSettings(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName                => $this->csrfToken,
            'enabled'                 => '1',
            'active_profile_id'       => 'prof_deep_inspection',
            'dynamic_cert_cache_size' => '15000',
            'cert_validity_days'      => '365',
            'generate_missing_san'    => '1',
        ];

        $result = $this->withSession($this->sessionData())->post('ssl-inspection/settings/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $this->seeInDatabase('ssl_mitm_settings', [
            'id'                      => 1,
            'dynamic_cert_cache_size' => 15000,
        ]);
    }

    public function testSimulateSyntheticMitmCert(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName => $this->csrfToken,
            'domain'   => 'api.stripe.com',
        ];

        $result = $this->withSession($this->sessionData())->post('ssl-inspection/simulate-cert', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);
        $this->assertNotEmpty($json['certificate']['certificate_pem'] ?? '');
    }

    public function testDownloadCaCertificateEndpoints(): void
    {
        $pemResult = $this->withSession($this->sessionData())->get('ssl-inspection/download-ca/pem');
        $pemResult->assertOK();
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $pemResult->getBody());

        $derResult = $this->withSession($this->sessionData())->get('ssl-inspection/download-ca/der');
        $derResult->assertOK();
    }

    public function testApplyAndActiveSessionsEndpoints(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $applyResult = $this->withSession($this->sessionData())->post('ssl-inspection/apply', [
            $tokenName => $this->csrfToken,
        ]);
        $applyResult->assertOK();
        $json = json_decode($applyResult->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $sessionsResult = $this->withSession($this->sessionData())->get('ssl-inspection/active-sessions');
        $sessionsResult->assertOK();
        $sessionsJson = json_decode($sessionsResult->getJSON(), true);
        $this->assertTrue($sessionsJson['success'] ?? false);
    }
}
