<?php

namespace Tests\App\Controllers;

use App\Libraries\SuricataCompiler;
use App\Models\IdsAlertModel;
use App\Models\IdsIpRuleModel;
use App\Models\IdsSettingsModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for IDS/IPS Comprehensive Features:
 *  - Real-time Threat Alerts & Classification Explorer
 *  - IP Whitelist (Passlist) and Blacklist (Drop/Banlist) CRUD & Validation
 *  - GeoIP Country Whitelisting (Geo-Fencing) & Blacklisting (Geo-Blocking)
 *  - Engine Operational Mode (Passive IDS vs. Inline IPS) & Threat Intelligence Feeds
 *  - Dynamic Suricata 7+ Configuration & Rule Compilation
 *
 * @internal
 */
final class IdsCrudTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'ids.view',
        'ids.edit',
        'ids.rules.manage',
        'ids.apply',
        'ids.restart',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Clean test rows
        $this->db->table('ids_ip_rules')->whereIn('ip_address', ['198.51.100.99', '10.200.0.0/16', '203.0.113.250'])->delete();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('ids_ip_rules')->whereIn('ip_address', ['198.51.100.99', '10.200.0.0/16', '203.0.113.250'])->delete();
        parent::tearDown();
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'admin',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params): array
    {
        $this->withSession($this->session());
        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    public function testIndexRendersDashboardWithTabsAndStats(): void
    {
        $result = $this->withSession($this->session())->get('ids');

        $result->assertOK();
        $result->assertSee('Intrusion Detection &amp; Prevention (IDS / IPS)');
        $result->assertSee('Threat Alerts &amp; Events');
        $result->assertSee('IP Whitelist &amp; Blacklist');
        $result->assertSee('GeoIP Country Filtering');
        $result->assertSee('Engine &amp; Threat Feeds');
    }

    public function testSaveSettingsUpdatesOperationalModeAndInterfaces(): void
    {
        $payload = $this->csrf([
            'enabled'      => '1',
            'mode'         => 'ids',
            'interfaces'   => ['wan', 'lan', 'eth2'],
            'rule_sources' => ['et_open', 'abuse_ch_c2'],
            'auto_update'  => '1',
            'log_payloads' => '1',
            'log_packets'  => '1',
        ]);

        $result = $this->post('ids/settings/save', $payload);

        $result->assertRedirectTo(base_url('ids#settings'));
        $result->assertSessionHas('success');

        $settingsModel = new IdsSettingsModel();
        $settings = $settingsModel->getSettings();
        $this->assertSame('ids', $settings['mode']);
        $this->assertContains('eth2', $settings['interfaces']);
        $this->assertContains('abuse_ch_c2', $settings['rule_sources']);
        $this->assertSame(1, (int)$settings['log_packets']);
    }

    public function testStoreIpRuleAddsWhitelistRule(): void
    {
        $payload = $this->csrf([
            'list_type'   => 'whitelist',
            'ip_address'  => '10.200.0.0/16',
            'description' => 'Branch Office Internal Subnet',
        ]);

        $result = $this->post('ids/rules/store', $payload);

        $result->assertRedirectTo(base_url('ids#iprules'));
        $result->assertSessionHas('success');

        $ruleModel = new IdsIpRuleModel();
        $rule = $ruleModel->where('ip_address', '10.200.0.0/16')->first();
        $this->assertNotNull($rule);
        $this->assertSame('whitelist', $rule['list_type']);
        $this->assertSame('Branch Office Internal Subnet', $rule['description']);
    }

    public function testStoreIpRuleAddsBlacklistRule(): void
    {
        $payload = $this->csrf([
            'list_type'   => 'blacklist',
            'ip_address'  => '198.51.100.99',
            'description' => 'Mirai C2 Exploit Node',
        ]);

        $result = $this->post('ids/rules/store', $payload);

        $result->assertRedirectTo(base_url('ids#iprules'));
        $result->assertSessionHas('success');

        $ruleModel = new IdsIpRuleModel();
        $rule = $ruleModel->where('ip_address', '198.51.100.99')->first();
        $this->assertNotNull($rule);
        $this->assertSame('blacklist', $rule['list_type']);
        $this->assertSame(1, (int)$rule['enabled']);
    }

    public function testStoreIpRuleRejectsInvalidIpOrCidr(): void
    {
        $payload = $this->csrf([
            'list_type'   => 'blacklist',
            'ip_address'  => '999.999.999.999',
            'description' => 'Bogus IP',
        ]);

        $result = $this->post('ids/rules/store', $payload);

        $result->assertRedirectTo(base_url('ids#iprules'));
        $result->assertSessionHas('error');
    }

    public function testToggleIpRuleInvertsStatus(): void
    {
        $ruleModel = new IdsIpRuleModel();
        $id = $ruleModel->addRule('blacklist', '198.51.100.99', 'Toggle Test');

        $payload = $this->csrf([]);
        $result = $this->post("ids/rules/{$id}/toggle", $payload);

        $result->assertRedirectTo(base_url('ids#iprules'));

        $rule = $ruleModel->find($id);
        $this->assertSame(0, (int)$rule['enabled']);

        // Toggle back with fresh CSRF token
        $payload2 = $this->csrf([]);
        $this->post("ids/rules/{$id}/toggle", $payload2);
        $rule = $ruleModel->find($id);
        $this->assertSame(1, (int)$rule['enabled']);
    }

    public function testDeleteIpRuleRemovesEntry(): void
    {
        $ruleModel = new IdsIpRuleModel();
        $id = $ruleModel->addRule('whitelist', '203.0.113.250', 'To Be Deleted');

        $payload = $this->csrf([]);
        $result = $this->post("ids/rules/{$id}/delete", $payload);

        $result->assertRedirectTo(base_url('ids#iprules'));
        $this->assertNull($ruleModel->find($id));
    }

    public function testSaveCountryFilterUpdatesBlacklistModeAndCountries(): void
    {
        $payload = $this->csrf([
            'country_filter_mode'   => 'blacklist',
            'country_filter_action' => 'reject',
            'selected_countries'    => ['RU', 'CN', 'KP', 'IR', 'BY'],
        ]);

        $result = $this->post('ids/country/save', $payload);

        $result->assertRedirectTo(base_url('ids#geoip'));
        $result->assertSessionHas('success');

        $settingsModel = new IdsSettingsModel();
        $settings = $settingsModel->getSettings();
        $this->assertSame('blacklist', $settings['country_filter_mode']);
        $this->assertSame('reject', $settings['country_filter_action']);
        $this->assertContains('KP', $settings['selected_countries']);
    }

    public function testQuickActionAddsAttackerToBlacklist(): void
    {
        $payload = $this->csrf([
            'action'    => 'blacklist',
            'ip'        => '198.51.100.99',
            'signature' => 'ET SCAN SSH Brute Force',
        ]);

        $result = $this->post('ids/quick-action', $payload);

        $result->assertRedirectTo(base_url('ids'));
        $result->assertSessionHas('success');

        $ruleModel = new IdsIpRuleModel();
        $rule = $ruleModel->where('list_type', 'blacklist')->where('ip_address', '198.51.100.99')->first();
        $this->assertNotNull($rule);
    }

    public function testCompilerProducesDynamicConfigAndRuleSets(): void
    {
        $compiler = new SuricataCompiler();
        $compiled = $compiler->compileAll();

        $this->assertArrayHasKey('/etc/suricata/suricata.yaml', $compiled);
        $this->assertArrayHasKey('/var/lib/suricata/rules/custom_whitelist.rules', $compiled);
        $this->assertArrayHasKey('/var/lib/suricata/rules/custom_blacklist.rules', $compiled);
        $this->assertArrayHasKey('/var/lib/suricata/rules/geoip_filter.rules', $compiled);

        // Verify content includes expected keywords
        $yaml = $compiled['/etc/suricata/suricata.yaml'];
        $this->assertStringContainsString('af-packet:', $yaml);
        $this->assertStringContainsString('custom_whitelist.rules', $yaml);
        $this->assertStringContainsString('custom_blacklist.rules', $yaml);
        $this->assertStringContainsString('geoip_filter.rules', $yaml);

        $whitelist = $compiled['/var/lib/suricata/rules/custom_whitelist.rules'];
        $this->assertStringContainsString('pass ip', $whitelist);

        $blacklist = $compiled['/var/lib/suricata/rules/custom_blacklist.rules'];
        $this->assertStringContainsString('drop ip', $blacklist);

        $geoip = $compiled['/var/lib/suricata/rules/geoip_filter.rules'];
        $this->assertStringContainsString('geoip:src', $geoip);
    }

    public function testApplyCompilesAndDispatchesIpc(): void
    {
        $payload = $this->csrf([]);
        $result = $this->post('ids/apply', $payload);

        $result->assertRedirectTo(base_url('ids'));
        $result->assertSessionHas('success');
    }
}
