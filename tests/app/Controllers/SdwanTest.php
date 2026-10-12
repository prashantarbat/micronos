<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

final class SdwanTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'routing.table.view',
        'routing.static.manage',
        'routing.pbr.manage',
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
        $result = $this->withSession($this->sessionData())->get('routing/sdwan');

        $result->assertSee('Application-Aware Dynamic SD-WAN');
        $result->assertSee('WAN Health');
        $result->assertSee('Performance SLA Profiles');
        $result->assertSee('Path Steering Rules');
    }

    public function testSaveAndFetchMember(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName    => $this->csrfToken,
            'name'        => 'Test Starlink Satellite',
            'interface'   => 'wan3',
            'gateway_ip'  => '192.168.12.1',
            'cost'        => '40',
            'weight'      => '80',
            'priority'    => '2',
            'ping_target' => '8.8.4.4',
            'enabled'     => '1',
        ];

        $result = $this->withSession($this->sessionData())->post('routing/sdwan/member/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $this->seeInDatabase('sdwan_members', [
            'name'      => 'Test Starlink Satellite',
            'interface' => 'wan3',
        ]);
    }

    public function testSaveAndFetchProfile(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName              => $this->csrfToken,
            'name'                  => 'Financial Low Latency Profile',
            'description'           => 'High-frequency banking feeds',
            'sla_max_loss_pct'      => '0.2',
            'sla_max_latency_ms'    => '30',
            'sla_max_jitter_ms'     => '5',
            'preferred_member_id'   => 'mem_wan1',
            'assigned_applications' => ['Bloomberg', 'Stripe', 'Swift'],
        ];

        $result = $this->withSession($this->sessionData())->post('routing/sdwan/profile/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $this->seeInDatabase('sdwan_profiles', [
            'name' => 'Financial Low Latency Profile',
        ]);
    }

    public function testSaveAndFetchRule(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $postData = [
            $tokenName           => $this->csrfToken,
            'priority'           => '5',
            'name'               => 'Trading Desk Steering',
            'source_subnet'      => '192.168.50.0/24',
            'destination_subnet' => '0.0.0.0/0',
            'sdwan_profile_id'   => 'prof_voip_collab',
            'strategy'           => 'best_quality',
            'enabled'            => '1',
        ];

        $result = $this->withSession($this->sessionData())->post('routing/sdwan/rule/save', $postData);
        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success'] ?? false);

        $this->seeInDatabase('sdwan_rules', [
            'name'     => 'Trading Desk Steering',
            'priority' => 5,
        ]);
    }

    public function testProbeNowAndApplyEndpoints(): void
    {
        $tokenName = config(SecurityConfig::class)->tokenName;

        $probeResult = $this->withSession($this->sessionData())->get('routing/sdwan/probe-now');
        $probeResult->assertOK();
        $probeJson = json_decode($probeResult->getJSON(), true);
        $this->assertTrue($probeJson['success'] ?? false);

        $applyResult = $this->withSession($this->sessionData())->post('routing/sdwan/apply', [
            $tokenName => $this->csrfToken,
        ]);
        $applyResult->assertOK();
        $applyJson = json_decode($applyResult->getJSON(), true);
        $this->assertTrue($applyJson['success'] ?? false);
        $this->assertTrue($applyJson['compiled']['enabled'] ?? false);
    }
}
