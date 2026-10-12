<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

final class ThreatIntelTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'ids.view',
        'ids.edit',
        'firewall.rules.edit',
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
        $result = $this->withSession($this->sessionData())->get('security/threat-intel');

        $result->assertStatus(200);
        $result->assertSee('Dynamic Threat Intelligence Feeds');
        $result->assertSee('Configured Threat Feeds');
        $result->assertSee('Active Blocklist Indicators');
        $result->assertSee('Spamhaus DROP');
        $result->assertSee('Emerging Threats');
    }

    public function testSaveFeedCreatesAndUpdates(): void
    {
        $slug = 'custom-threats-' . bin2hex(random_bytes(4));
        $postData = [
            'name'                  => 'Custom C2 Blacklist',
            'slug'                  => $slug,
            'feed_type'             => 'custom_url',
            'url'                   => 'https://threats.internal.example/blocklist.txt',
            'min_confidence'        => '90',
            'update_interval_hours' => '4',
            'enabled'               => '1',
            'csrf_test_name'        => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('security/threat-intel/save-feed', $postData);

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('security/threat-intel'));

        $this->seeInDatabase('threat_feeds', [
            'slug' => $slug,
            'name' => 'Custom C2 Blacklist',
        ]);
    }

    public function testToggleFeed(): void
    {
        $db = \Config\Database::connect();
        $feed = $db->table('threat_feeds')->where('slug', 'spamhaus-drop')->get()->getRowArray();
        $this->assertNotNull($feed);

        $targetState = $feed['enabled'] ? 0 : 1;

        $result = $this->withSession($this->sessionData())
                       ->post('security/threat-intel/toggle/' . $feed['id'], [
                           'csrf_test_name' => $this->csrfToken,
                       ]);

        $result->assertStatus(302);
        $this->seeInDatabase('threat_feeds', [
            'id'      => $feed['id'],
            'enabled' => $targetState,
        ]);
    }

    public function testSaveSettings(): void
    {
        $postData = [
            'enabled'             => '1',
            'action'              => 'reject',
            'safeguard_rfc1918'   => '1',
            'log_blocked_packets' => '1',
            'auto_prune_days'     => '14',
            'csrf_test_name'      => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('security/threat-intel/save-settings', $postData);

        $result->assertStatus(302);
        $this->seeInDatabase('threat_intel_settings', [
            'action'            => 'reject',
            'safeguard_rfc1918' => 1,
            'auto_prune_days'   => 14,
        ]);
    }

    public function testAddWhitelist(): void
    {
        $postData = [
            'indicator_value' => '198.51.100.99',
            'reason'          => 'Diagnostic monitoring node',
            'csrf_test_name'  => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('security/threat-intel/add-whitelist', $postData);

        $result->assertStatus(302);
        $this->seeInDatabase('threat_intel_whitelist', [
            'indicator_value' => '198.51.100.99',
        ]);
    }

    public function testDeleteWhitelist(): void
    {
        $db = \Config\Database::connect();
        $db->table('threat_intel_whitelist')->insert([
            'id'              => 'wl_del_test',
            'indicator_value' => '198.51.100.123',
            'reason'          => 'Temporary allow',
            'created_by'      => 'admin',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $delResult = $this->withSession($this->sessionData())
                          ->post('security/threat-intel/delete-whitelist/wl_del_test', [
                              'csrf_test_name' => $this->csrfToken,
                          ]);

        $delResult->assertStatus(302);
        $this->dontSeeInDatabase('threat_intel_whitelist', [
            'id' => 'wl_del_test',
        ]);
    }

    public function testCompileRulesetEndpoint(): void
    {
        $result = $this->withSession($this->sessionData())->get('security/threat-intel/compile');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertStringContainsString('table inet micronos', $result->getBody());
        $this->assertStringContainsString('set threat_intel_ipv4', $result->getBody());
    }

    public function testCompileDnsblRulesetEndpoint(): void
    {
        $result = $this->withSession($this->sessionData())->get('security/threat-intel/compile-dnsbl');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertStringContainsString('ThreatIntelEngine', $result->getBody());
        $this->assertStringContainsString('Unbound local-zone definitions', $result->getBody());
    }

    public function testAddManualIocControllerSingleAndBulk(): void
    {
        $postData = [
            'indicator_value'  => "198.51.100.77\nmalicious-c2.test-portal.org\n203.0.113.0/24",
            'threat_type'      => 'c2',
            'confidence_score' => '100',
            'comment'          => 'Bulk IoC import test',
            'expires_days'     => '30',
            'csrf_test_name'   => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('security/threat-intel/add-manual-ioc', $postData);

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('security/threat-intel'));

        $this->seeInDatabase('threat_indicators', [
            'indicator_value' => '198.51.100.77',
            'indicator_type'  => 'ipv4',
            'feed_id'         => 'feed_manual_ioc',
            'comment'         => 'Bulk IoC import test',
        ]);

        $this->seeInDatabase('threat_indicators', [
            'indicator_value' => 'malicious-c2.test-portal.org',
            'indicator_type'  => 'fqdn',
            'feed_id'         => 'feed_manual_ioc',
        ]);

        $this->seeInDatabase('threat_indicators', [
            'indicator_value' => '203.0.113.0/24',
            'indicator_type'  => 'ipv4_cidr',
            'feed_id'         => 'feed_manual_ioc',
        ]);
    }

    public function testAddManualIocWithPrivateIpSafeguard(): void
    {
        $postData = [
            'indicator_value'  => '10.50.0.1',
            'threat_type'      => 'custom_blacklist',
            'confidence_score' => '100',
            'csrf_test_name'   => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('security/threat-intel/add-manual-ioc', $postData);

        $result->assertStatus(302);
        $this->dontSeeInDatabase('threat_indicators', [
            'indicator_value' => '10.50.0.1',
            'feed_id'         => 'feed_manual_ioc',
        ]);
    }

    public function testDeleteManualIocController(): void
    {
        $db = \Config\Database::connect();
        $id = 'ind_test_' . bin2hex(random_bytes(4));
        $db->table('threat_indicators')->insert([
            'id'               => $id,
            'feed_id'          => 'feed_manual_ioc',
            'indicator_value'  => '198.51.100.88',
            'indicator_type'   => 'ipv4',
            'threat_type'      => 'botnet',
            'confidence_score' => 100,
            'comment'          => 'To be deleted',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $delResult = $this->withSession($this->sessionData())
                          ->post('security/threat-intel/delete-manual-ioc/' . $id, [
                              'csrf_test_name' => $this->csrfToken,
                          ]);

        $delResult->assertStatus(302);
        $this->dontSeeInDatabase('threat_indicators', [
            'id' => $id,
        ]);
    }
}
