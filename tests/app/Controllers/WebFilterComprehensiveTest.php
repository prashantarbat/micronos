<?php

namespace Tests\App\Controllers;

use App\Libraries\SquidConfigCompiler;
use App\Libraries\WpadGenerator;
use App\Models\WebfilterAccessLogModel;
use App\Models\WebfilterAvDetectionModel;
use App\Models\WebfilterCategoryModel;
use App\Models\WebfilterFeedModel;
use App\Models\WebfilterSettingsModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Feature tests for Enterprise Web Proxy, Content Filtering, ACLs, and WPAD/PAC Auto-Discovery
 *
 * @internal
 */
final class WebFilterComprehensiveTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh = false;

    private const PERMISSIONS = [
        'webfilter.view',
        'webfilter.edit',
        'webfilter.policy.edit',
        'webfilter.categories.manage',
        'webfilter.bypass.manage',
        'webfilter.logs.view',
        'webfilter.reload',
        'webfilter.wpad.manage',
        'webfilter.cache.clear',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function sessionData(): array
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

    private function csrf(array $params = []): array
    {
        $this->withSession($this->sessionData());
        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    public function testIndexRendersSuccessfully(): void
    {
        $result = $this->withSession($this->sessionData())->get('/webfilter');
        $result->assertStatus(200);
        $result->assertSee('Enterprise Web Proxy &amp; Content Filter');
        $result->assertSee('General Proxy Settings');
        $result->assertSee('Access Control Lists (ACLs)');
        $result->assertSee('Content Filtering &amp; Categories');
        $result->assertSee('SSL / TLS Deep Inspection');
        $result->assertSee('Auto-Discovery &amp; PAC Engine (WPAD)');
        $result->assertSee('Cache &amp; Acceleration Engine');
        $result->assertSee('Freshness &amp; Cache Refresh Patterns');
        $result->assertSee('Emit refresh_pattern Rules');
        $result->assertSee('should never be cached');
        $result->assertSee('Traffic Logs &amp; Analytics');
    }

    public function testSaveGeneralSettings(): void
    {
        $payload = $this->csrf([
            'enabled' => '1',
            'mode' => 'hybrid',
            'http_port' => 8080,
            'https_port' => 8081,
            'ssl_port' => 8082,
            'interfaces' => ['lan', 'opt1'],
            'ipv6_enabled' => '1',
            'visible_hostname' => 'test-gateway.micronos.internal',
            'admin_email' => 'netadmin@test.local',
            'forwarded_for' => 'transparent',
            'suppress_headers' => '1',
            'log_client_ip' => '1',
            'log_user_agent' => '1',
            'log_query_terms' => '0',
            'log_retention_days' => 45,
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-general', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=general'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame(8080, (int) $settings['http_port']);
        $this->assertSame(8081, (int) $settings['https_port']);
        $this->assertSame('hybrid', $settings['mode']);
        $this->assertSame('test-gateway.micronos.internal', $settings['visible_hostname']);
        $this->assertContains('lan', $settings['interfaces']);
    }

    public function testSaveAcls(): void
    {
        $payload = $this->csrf([
            'allowed_subnets' => "10.10.0.0/16\n192.168.10.0/24",
            'unrestricted_ips' => "10.10.1.50",
            'banned_ips' => "10.10.1.200",
            'banned_user_agents' => "BadBot*\nEvilCrawler*",
            'banned_extensions' => ".exe\n.msi\n.torrent",
            'banned_mime_types' => "application/x-bittorrent",
            'max_upload_size_mb' => 100,
            'max_download_size_mb' => 4096,
            'max_client_connections' => 200,
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-acls', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=acls'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertStringContainsString('10.10.0.0/16', $settings['allowed_subnets']);
        $this->assertStringContainsString('BadBot*', $settings['banned_user_agents']);
        $this->assertSame(100, (int) $settings['max_upload_size_mb']);
    }

    public function testSaveFilteringAndToggleCategory(): void
    {
        $payload = $this->csrf([
            'custom_whitelist' => "trusted-vendor.com\n*.partner.org",
            'custom_blacklist' => "shady-ads.xyz\n*.cryptotrap.online",
            'custom_regex_blacklist' => "^https?://.*badword.*",
            'safesearch_enabled' => '1',
            'youtube_restricted_mode' => 'strict',
            'block_page_title' => 'Custom Restriction Notice',
            'block_page_message' => 'Policy violation blocked.',
            'block_page_redirect_url' => '',
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-filtering', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=filtering'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertStringContainsString('trusted-vendor.com', $settings['custom_whitelist']);
        $this->assertSame('strict', $settings['youtube_restricted_mode']);

        // Toggle category
        $togglePayload = $this->csrf();
        $toggleResult = $this->withSession($this->sessionData())->post('/webfilter/category/cat_malware/toggle', $togglePayload);
        $toggleResult->assertStatus(302);

        $cat = (new WebfilterCategoryModel())->find('cat_malware');
        $this->assertNotNull($cat);
    }

    public function testSyncThreatFeeds(): void
    {
        $payload = $this->csrf();
        $result = $this->withSession($this->sessionData())->post('/webfilter/feeds/sync', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=filtering'));

        $feeds = (new WebfilterFeedModel())->getFeeds();
        $this->assertNotEmpty($feeds);
    }

    public function testSaveSslSettings(): void
    {
        $payload = $this->csrf([
            'ssl_bump_enabled' => '1',
            'ssl_inspection_mode' => 'deep_inspection',
            'ca_cert_path' => '/etc/squid/certs/micron_ca.pem',
            'ca_key_path' => '/etc/squid/certs/micron_ca.key',
            'ssl_bypass_domains' => "*.paypal.com\n*.chase.com\n*.apple.com",
            'ssl_min_tls_version' => '1.3',
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-ssl', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=ssl'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame('deep_inspection', $settings['ssl_inspection_mode']);
        $this->assertSame('1.3', $settings['ssl_min_tls_version']);
    }

    public function testSaveWpadSettings(): void
    {
        $payload = $this->csrf([
            'wpad_enabled' => '1',
            'wpad_proxy_host' => '192.168.1.254',
            'wpad_proxy_port' => 3128,
            'wpad_failover_proxy' => '192.168.1.253:3128',
            'wpad_fallback_policy' => 'DIRECT',
            'wpad_bypass_plain_hosts' => '1',
            'wpad_bypass_rfc1918' => '1',
            'wpad_bypass_networks' => "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16",
            'wpad_bypass_domains' => "*.local\n*.corp\n*.internal",
            'wpad_dhcp_option252' => '1',
            'wpad_dns_override' => '1',
            'wpad_anti_bypass_firewall' => '1',
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-wpad', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=wpad'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame('192.168.1.254', $settings['wpad_proxy_host']);
        $this->assertSame(1, (int) $settings['wpad_anti_bypass_firewall']);
    }

    public function testWpadSimulatorLogic(): void
    {
        $wpad = new WpadGenerator();
        $settings = [
            'wpad_proxy_host' => '192.168.1.1',
            'wpad_proxy_port' => 3128,
            'wpad_failover_proxy' => '',
            'wpad_fallback_policy' => 'DIRECT',
            'wpad_bypass_plain_hosts' => 1,
            'wpad_bypass_rfc1918' => 1,
            'wpad_bypass_networks' => '10.0.0.0/8',
            'wpad_bypass_domains' => "*.local\n*.company.internal",
        ];

        // 1. Plain hostname
        $res1 = $wpad->simulate('intranet', $settings);
        $this->assertTrue($res1['success']);
        $this->assertSame('DIRECT', $res1['decision']);

        // 2. Domain pattern
        $res2 = $wpad->simulate('http://hr.company.internal/portal', $settings);
        $this->assertTrue($res2['success']);
        $this->assertSame('DIRECT', $res2['decision']);

        // 3. RFC 1918 IP
        $res3 = $wpad->simulate('http://192.168.1.50/api', $settings);
        $this->assertTrue($res3['success']);
        $this->assertSame('DIRECT', $res3['decision']);

        // 4. Public Internet site -> PROXY
        $res4 = $wpad->simulate('https://www.google.com', $settings);
        $this->assertTrue($res4['success']);
        $this->assertStringContainsString('PROXY 192.168.1.1:3128', $res4['decision']);
    }

    public function testRootWpadEndpoints(): void
    {
        $endpoints = ['/wpad.dat', '/wpad.da', '/proxy.pac', '/wpad.pac'];
        foreach ($endpoints as $uri) {
            $result = $this->get($uri);
            $result->assertStatus(200);
            $result->assertHeader('Content-Type', 'application/x-ns-proxy-autoconfig');
            $body = $result->response()->getBody();
            $this->assertStringContainsString('FindProxyForURL', $body);
        }
    }

    public function testDownloadCaAndPacEndpoints(): void
    {
        $pacResult = $this->withSession($this->sessionData())->get('/webfilter/download-pac');
        $pacResult->assertStatus(200);
        $this->assertStringContainsString('FindProxyForURL', $pacResult->response()->getBody());

        $caResult = $this->withSession($this->sessionData())->get('/webfilter/download-ca');
        $caResult->assertStatus(200);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $caResult->response()->getBody());
    }

    public function testQuickActionWhitelistAndBlacklist(): void
    {
        // 1. Quick Blacklist
        $payload1 = $this->csrf([
            'domain' => 'phishing-login.biz',
            'action' => 'blacklist',
        ]);
        $res1 = $this->withSession($this->sessionData())->post('/webfilter/quick-action', $payload1);
        $res1->assertStatus(302);

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertStringContainsString('phishing-login.biz', $settings['custom_blacklist']);

        // 2. Quick Whitelist
        $payload2 = $this->csrf([
            'domain' => 'trusted-partner-cloud.net',
            'action' => 'whitelist',
        ]);
        $res2 = $this->withSession($this->sessionData())->post('/webfilter/quick-action', $payload2);
        $res2->assertStatus(302);

        $settings2 = (new WebfilterSettingsModel())->getSettings();
        $this->assertStringContainsString('trusted-partner-cloud.net', $settings2['custom_whitelist']);
    }

    public function testCachePurgeAndReload(): void
    {
        $clearPayload = $this->csrf();
        $resClear = $this->withSession($this->sessionData())->post('/webfilter/cache/clear', $clearPayload);
        $resClear->assertStatus(302);
        $resClear->assertRedirectTo(base_url('webfilter?tab=cache'));

        $reloadPayload = $this->csrf();
        $resReload = $this->withSession($this->sessionData())->post('/webfilter/reload', $reloadPayload);
        $resReload->assertStatus(302);
        $resReload->assertRedirectTo(base_url('webfilter'));
    }

    public function testIpcOutcomeSurfacesDaemonFailures(): void
    {
        $controller = new \App\Controllers\WebFilter();
        $method     = new \ReflectionMethod(\App\Controllers\WebFilter::class, 'ipcOutcome');
        $method->setAccessible(true);

        $cases = [
            'explicit success flag'      => [['result' => ['success' => true, 'status' => 'purged'], 'error' => null], true],
            'explicit success false'     => [['result' => ['success' => false, 'message' => 'spool locked'], 'error' => null], false],
            'status-only reload success' => [['result' => ['status' => 'reconfigured'], 'error' => null], true],
            'status error with output'   => [['result' => ['status' => 'error', 'output' => 'squid: bad config'], 'error' => null], false],
            'rpc error object'           => [['result' => null, 'error' => ['code' => -32000, 'message' => 'boom']], false],
            'missing result'             => [[], false],
        ];

        foreach ($cases as $label => [$response, $expected]) {
            $outcome = $method->invoke($controller, $response);
            $this->assertSame($expected, $outcome['ok'], $label);
        }

        $failure = $method->invoke($controller, ['result' => ['success' => false, 'message' => 'cache rebuild failed'], 'error' => null]);
        $this->assertStringContainsString('cache rebuild failed', $failure['reason']);

        $rpcError = $method->invoke($controller, ['result' => null, 'error' => ['message' => 'Method not found: webfilter.reload']]);
        $this->assertStringContainsString('Method not found', $rpcError['reason']);

        $statusOnly = $method->invoke($controller, ['result' => ['status' => 'error', 'output' => 'squid: ERROR: Invalid configuration'], 'error' => null]);
        $this->assertStringContainsString('Invalid configuration', $statusOnly['reason']);
    }

    public function testCompilerProducesValidSquidConfig(): void
    {
        $compiler = new SquidConfigCompiler();
        $output = $compiler->compile();

        $this->assertStringContainsString('Enterprise Web Proxy & Content Filter', $output);
        $this->assertStringContainsString('http_port', $output);
        $this->assertStringContainsString('acl localnet', $output);
        $this->assertStringContainsString('acl Safe_ports', $output);
        $this->assertStringContainsString('http_access allow localnet', $output);
        $this->assertStringContainsString('visible_hostname', $output);
    }

    // ------------------------------------------------------------------
    // C-ICAP + ClamAV antivirus inspection
    // ------------------------------------------------------------------

    public function testIndexRendersAntivirusTab(): void
    {
        $result = $this->withSession($this->sessionData())->get('/webfilter?tab=antivirus');
        $result->assertStatus(200);
        $result->assertSee('Antivirus &amp; Malware');
        $result->assertSee('ICAP Antivirus Bridge');
        $result->assertSee('Malware Detections');
    }

    public function testSaveAntivirusSettings(): void
    {
        $payload = $this->csrf([
            'icap_antivirus_enabled' => '1',
            'icap_host'              => '10.0.0.9',
            'icap_port'              => 1344,
            'icap_service'           => 'squidclamav',
            'icap_scan_mode'         => 'both',
            'icap_preview_kb'        => 2048,
            'icap_max_file_mb'       => 50,
            'icap_failure_mode'      => 'block',
            'icap_scan_https'        => '1',
            'icap_bypass_domains'    => "*.windowsupdate.com\n*.apple.com",
            'clamav_update_schedule' => 'hourly',
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-antivirus', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=antivirus'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame('10.0.0.9', $settings['icap_host']);
        $this->assertSame('both', $settings['icap_scan_mode']);
        $this->assertSame('block', $settings['icap_failure_mode']);
        $this->assertSame('hourly', $settings['clamav_update_schedule']);
        $this->assertSame(2048, (int) $settings['icap_preview_kb']);
    }

    public function testSaveAntivirusRejectsInvalidEnumValues(): void
    {
        $payload = $this->csrf([
            'icap_scan_mode'         => 'sideways',
            'icap_failure_mode'      => 'explode',
            'clamav_update_schedule' => 'fortnightly',
            'icap_port'              => 99999,
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/save-antivirus', $payload)->assertStatus(302);

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame('response', $settings['icap_scan_mode']);
        $this->assertSame('allow', $settings['icap_failure_mode']);
        $this->assertSame('daily', $settings['clamav_update_schedule']);
        $this->assertSame(1344, (int) $settings['icap_port']);
    }

    public function testUpdateSignaturesPersistsTelemetry(): void
    {
        $result = $this->withSession($this->sessionData())->post('/webfilter/av/update-signatures', $this->csrf());
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=antivirus'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertGreaterThan(0, (int) $settings['clamav_signatures']);
        $this->assertSame(date('Ymd'), (string) $settings['clamav_defs_version']);
        $this->assertNotNull($settings['clamav_last_update_at']);
    }

    public function testClearDetectionsEmptiesLog(): void
    {
        $model = new WebfilterAvDetectionModel();
        $model->insert([
            'detected_at' => date('Y-m-d H:i:s'),
            'client_ip'   => '192.168.1.77',
            'domain'      => 'malware-drop-site.xyz',
            'url'         => 'http://malware-drop-site.xyz/payload.exe',
            'threat_name' => 'Eicar-Signature',
            'action'      => 'block',
        ]);
        $this->assertGreaterThan(0, $model->countAllResults());

        $result = $this->withSession($this->sessionData())->post('/webfilter/av/clear-detections', $this->csrf());
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=antivirus'));

        $this->assertSame(0, (new WebfilterAvDetectionModel())->countAllResults());
    }

    public function testCompilerEmitsIcapStanzasWhenEnabled(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'icap_antivirus_enabled' => 1,
            'icap_host'              => '127.0.0.1',
            'icap_port'              => 1344,
            'icap_service'           => 'squidclamav',
            'icap_scan_mode'         => 'both',
            'icap_failure_mode'      => 'allow',
            'icap_bypass_domains'    => '*.apple.com',
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('icap_enable on', $output);
        $this->assertStringContainsString('icap://127.0.0.1:1344/squidclamav', $output);
        $this->assertStringContainsString('icap_service svc_reqmod reqmod_precache bypass=1', $output);
        $this->assertStringContainsString('icap_service svc_respmod respmod_precache bypass=1', $output);
        $this->assertStringContainsString('acl av_scan_bypass dstdomain *.apple.com', $output);
        $this->assertStringContainsString('adaptation_access svc_respmod deny av_scan_bypass', $output);
        $this->assertStringContainsString('adaptation_access svc_respmod allow all', $output);
    }

    public function testCompilerOmitsIcapStanzasWhenDisabled(): void
    {
        (new WebfilterSettingsModel())->saveSettings(['icap_antivirus_enabled' => 0]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringNotContainsString('icap_enable on', $output);
        $this->assertStringNotContainsString('adaptation_access', $output);
        $this->assertStringContainsString('# ICAP antivirus inspection disabled.', $output);
    }

    public function testCompilerFailClosedAndRequestOnlyModes(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'icap_antivirus_enabled' => 1,
            'icap_scan_mode'         => 'request',
            'icap_failure_mode'      => 'block',
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('reqmod_precache bypass=0', $output);
        $this->assertStringNotContainsString('svc_respmod', $output);
    }

    // ------------------------------------------------------------------
    // Squid refresh_pattern freshness controls
    // ------------------------------------------------------------------

    public function testSaveCacheRefreshPatternSettings(): void
    {
        $payload = $this->csrf([
            'cache_enabled'                => '1',
            'cache_mem_mb'                 => 512,
            'cache_size_mb'                => 8192,
            'cache_max_obj_kb'             => 2048,
            'cache_replacement_policy'     => 'heap GDSF',
            'cache_ignore_queries'         => '1',
            'cache_refresh_enabled'        => '1',
            'cache_refresh_min_minutes'    => 30,
            'cache_refresh_percent'        => 40,
            'cache_refresh_max_minutes'    => 7200,
            'cache_refresh_ignore_reload'  => '1',
            'cache_refresh_ignore_private' => '1',
            'cache_refresh_custom'         => 'refresh_pattern ^cdn\\. 60 50% 1440',
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-cache', $payload);
        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=cache'));

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame(1, (int) $settings['cache_refresh_enabled']);
        $this->assertSame(30, (int) $settings['cache_refresh_min_minutes']);
        $this->assertSame(40, (int) $settings['cache_refresh_percent']);
        $this->assertSame(7200, (int) $settings['cache_refresh_max_minutes']);
        $this->assertSame(1, (int) $settings['cache_refresh_ignore_reload']);
        $this->assertSame(1, (int) $settings['cache_refresh_ignore_private']);
        // Unchecked checkboxes are absent from the POST and must reset to 0.
        $this->assertSame(0, (int) $settings['cache_refresh_ignore_no_store']);
        $this->assertSame(0, (int) $settings['cache_refresh_reload_into_ims']);
        $this->assertStringContainsString('refresh_pattern ^cdn\.', $settings['cache_refresh_custom']);
    }

    public function testSaveCacheClampsRefreshPatternBounds(): void
    {
        $payload = $this->csrf([
            'cache_refresh_percent'     => 250,
            'cache_refresh_min_minutes' => -5,
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/save-cache', $payload)->assertStatus(302);

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame(100, (int) $settings['cache_refresh_percent']);
        $this->assertSame(0, (int) $settings['cache_refresh_min_minutes']);
    }

    public function testCompilerEmitsRefreshPatternRules(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'                 => 1,
            'cache_refresh_enabled'         => 1,
            'cache_refresh_min_minutes'     => 15,
            'cache_refresh_percent'         => 25,
            'cache_refresh_max_minutes'     => 2880,
            'cache_refresh_ignore_reload'   => 1,
            'cache_refresh_ignore_no_store' => 0,
            'cache_refresh_ignore_private'  => 0,
            'cache_refresh_reload_into_ims' => 0,
            'cache_refresh_custom'          => 'refresh_pattern ^cdn\.example\.com 60 50% 1440',
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('refresh_pattern ^cdn\.example\.com 60 50% 1440', $output);
        $this->assertStringContainsString('refresh_pattern . 15 25% 2880 ignore-reload', $output);
        // The catch-all must be emitted after specific rules (first match wins).
        $customPos  = strpos($output, 'refresh_pattern ^cdn\.example\.com');
        $catchAllPos = strpos($output, 'refresh_pattern . 15 25% 2880');
        $this->assertNotFalse($customPos);
        $this->assertNotFalse($catchAllPos);
        $this->assertLessThan($catchAllPos, $customPos);
    }

    public function testCompilerDiscardsNonRefreshPatternLines(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'         => 1,
            'cache_refresh_enabled' => 1,
            'cache_refresh_custom'  => "http_access allow all\nrefresh_pattern ^safe\\. 30 20% 600\n# a comment",
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('refresh_pattern ^safe\. 30 20% 600', $output);
        $this->assertStringNotContainsString('http_access allow all', $output);
        $this->assertStringNotContainsString('# a comment', $output);
    }

    public function testCompilerOmitsRefreshPatternsWhenDisabled(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'         => 1,
            'cache_refresh_enabled' => 0,
            'cache_refresh_custom'  => 'refresh_pattern . 0 20% 4320',
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringNotContainsString('# 8b. Freshness', $output);
        $this->assertStringNotContainsString('refresh_pattern .', $output);
    }

    // ------------------------------------------------------------------
    // Squid "Do Not Cache" destination list
    // ------------------------------------------------------------------

    public function testSaveCacheDoNotCacheList(): void
    {
        $payload = $this->csrf([
            'cache_do_not_cache' => "app.example.internal\nportal.example.corp\n192.168.10.66",
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/save-cache', $payload)->assertStatus(302);

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertStringContainsString('app.example.internal', $settings['cache_do_not_cache']);
        $this->assertStringContainsString('192.168.10.66', $settings['cache_do_not_cache']);
    }

    public function testCompilerEmitsCacheBypassAcls(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'      => 1,
            'cache_do_not_cache' => "app.example.internal\n*.tracker.net\n192.168.10.66\n192.168.10.0/24\n2001:db8::1",
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('# 8c. Do Not Cache Destinations', $output);
        $this->assertStringContainsString('acl cache_bypass_domains dstdomain .app.example.internal .tracker.net', $output);
        $this->assertStringContainsString('acl cache_bypass_ips dst 192.168.10.66 192.168.10.0/24 2001:db8::1', $output);
        $this->assertStringContainsString('cache deny cache_bypass_domains', $output);
        $this->assertStringContainsString('cache deny cache_bypass_ips', $output);
    }

    public function testCompilerSkipsInvalidCacheBypassEntries(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'      => 1,
            'cache_do_not_cache' => "not a domain; cache deny all\n# comment\n999.1.1.1\nfoo bar.com",
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringNotContainsString('# 8c. Do Not Cache Destinations', $output);
        $this->assertStringNotContainsString('cache_bypass', $output);
        $this->assertStringNotContainsString('not a domain', $output);
    }

    public function testCompilerOmitsCacheBypassWhenCachingDisabled(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'      => 0,
            'cache_do_not_cache' => 'app.example.internal',
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('cache deny all', $output);
        $this->assertStringNotContainsString('cache_bypass', $output);
    }

    // ------------------------------------------------------------------
    // Daemon-failure surfacing (IpcClient resolved from the container)
    // ------------------------------------------------------------------

    private function injectIpc(array $responses): FakeIpcClient
    {
        $ipc = new FakeIpcClient($responses);
        Services::injectMock('ipc', $ipc);

        return $ipc;
    }

    public function testCachePurgeSurfacesDaemonFailureToOperator(): void
    {
        $ipc = $this->injectIpc([
            'webfilter.cache.clear' => [
                'result' => ['success' => false, 'status' => 'error', 'message' => 'spool is locked'],
                'error'  => null,
            ],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/cache/clear', $this->csrf());

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=cache'));
        $result->assertSessionHas('error', 'Cache purge failed: spool is locked');
        $this->assertSame('webfilter.cache.clear', $ipc->calls[0]['method']);
    }

    public function testCachePurgeSurfacesTransportError(): void
    {
        $this->injectIpc([
            'webfilter.cache.clear' => [
                'result' => null,
                'error'  => ['code' => -32601, 'message' => 'Method not found: webfilter.cache.clear'],
            ],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/cache/clear', $this->csrf());

        $result->assertStatus(302);
        $result->assertSessionHas('error', 'Cache purge failed: Method not found: webfilter.cache.clear');
    }

    public function testCachePurgeReportsSuccess(): void
    {
        $this->injectIpc([
            'webfilter.cache.clear' => ['result' => ['success' => true, 'status' => 'purged', 'bytes_freed' => 2048], 'error' => null],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/cache/clear', $this->csrf());

        $result->assertStatus(302);
        $result->assertSessionHas('success', 'Proxy cache storage purged and rebuilt successfully.');
    }

    public function testReloadSurfacesDaemonFailureToOperator(): void
    {
        $this->injectIpc([
            'webfilter.reload' => [
                'result' => ['success' => false, 'status' => 'error', 'output' => 'squid: ERROR: Invalid configuration'],
                'error'  => null,
            ],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/reload', $this->csrf());

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter'));
        $result->assertSessionHas('error', 'Reload failed: squid: ERROR: Invalid configuration');
    }

    public function testReloadReportsSuccessOnStatusOnlyResponse(): void
    {
        $this->injectIpc([
            'webfilter.reload' => ['result' => ['status' => 'reconfigured'], 'error' => null],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/reload', $this->csrf());

        $result->assertStatus(302);
        $result->assertSessionHas('success', 'Web Proxy & Content Filter rules reloaded and active.');
    }

    // ------------------------------------------------------------------
    // Persistent audit trail of daemon-side failures
    // ------------------------------------------------------------------

    private function latestAudit(string $action): ?array
    {
        return $this->db->table('audit_logs')
            ->where('action', $action)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
    }

    public function testFailedCachePurgeIsRecordedInAuditLog(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.cache.clear.failed')->delete();

        $this->injectIpc([
            'webfilter.cache.clear' => [
                'result' => ['success' => false, 'status' => 'error', 'message' => 'spool is locked'],
                'error'  => null,
            ],
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/cache/clear', $this->csrf())->assertStatus(302);

        $row = $this->latestAudit('webfilter.cache.clear.failed');

        $this->assertNotNull($row, 'A failed cache purge must leave a persistent audit entry');
        $this->assertSame('alert', $row['action_type']);
        $this->assertSame('squid', $row['target']);
        $this->assertSame('WebFilter', $row['target_resource']);
        $this->assertStringContainsString('Cache purge failed: spool is locked', $row['new_state']);
    }

    public function testFailedReloadIsRecordedInAuditLog(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.reload.failed')->delete();

        $this->injectIpc([
            'webfilter.reload' => [
                'result' => ['success' => false, 'status' => 'error', 'output' => 'squid: ERROR: Invalid configuration'],
                'error'  => null,
            ],
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/reload', $this->csrf())->assertStatus(302);

        $row = $this->latestAudit('webfilter.reload.failed');

        $this->assertNotNull($row, 'A failed reload must leave a persistent audit entry');
        $this->assertSame('alert', $row['action_type']);
        $this->assertSame('reload requested', $row['old_state']);
        $this->assertStringContainsString('squid: ERROR: Invalid configuration', $row['new_state']);
    }

    public function testSuccessfulReloadIsNotAuditedAsFailure(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.reload.failed')->delete();

        $this->injectIpc([
            'webfilter.reload' => ['result' => ['status' => 'reconfigured'], 'error' => null],
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/reload', $this->csrf())->assertStatus(302);

        $this->assertNull($this->latestAudit('webfilter.reload.failed'), 'A successful reload must not record a failure');
    }

    public function testSuccessfulCachePurgeIsNotAuditedAsFailure(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.cache.clear.failed')->delete();

        $this->injectIpc([
            'webfilter.cache.clear' => ['result' => ['success' => true, 'status' => 'purged'], 'error' => null],
        ]);

        $this->withSession($this->sessionData())->post('/webfilter/cache/clear', $this->csrf())->assertStatus(302);

        $this->assertNull($this->latestAudit('webfilter.cache.clear.failed'), 'A successful purge must not record a failure');
    }

    // ------------------------------------------------------------------
    // Antivirus save / signature update daemon-failure paths
    // ------------------------------------------------------------------

    public function testSaveAntivirusSurfacesProxyReloadFailure(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.antivirus.save.failed')->delete();

        $this->injectIpc([
            'webfilter.reload' => [
                'result' => ['success' => false, 'status' => 'error', 'output' => 'squid: ERROR: Invalid ICAP service'],
                'error'  => null,
            ],
        ]);

        $payload = $this->csrf([
            'icap_antivirus_enabled' => '1',
            'icap_scan_mode'         => 'both',
            'icap_failure_mode'      => 'block',
            'clamav_update_schedule' => 'hourly',
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-antivirus', $payload);

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=antivirus'));
        $result->assertSessionHas('error', 'Saved, but the proxy reload failed: squid: ERROR: Invalid ICAP service');

        // The settings are still persisted even though the reload failed.
        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertSame('both', $settings['icap_scan_mode']);

        $row = $this->latestAudit('webfilter.antivirus.save.failed');
        $this->assertNotNull($row, 'A failed proxy reload must leave a persistent audit entry');
        $this->assertSame('alert', $row['action_type']);
        $this->assertStringContainsString('Saved, but the proxy reload failed', $row['new_state']);
    }

    public function testSaveAntivirusReportsSuccessAndReloadsProxy(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.antivirus.save.failed')->delete();

        $ipc = $this->injectIpc([
            'webfilter.reload' => ['result' => ['success' => true, 'status' => 'reconfigured'], 'error' => null],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/save-antivirus', $this->csrf([
            'icap_antivirus_enabled' => '1',
        ]));

        $result->assertStatus(302);
        $result->assertSessionHas('success', 'Antivirus (C-ICAP + ClamAV) inspection settings saved.');
        $this->assertSame('webfilter.reload', $ipc->calls[0]['method']);
        $this->assertNull($this->latestAudit('webfilter.antivirus.save.failed'), 'A successful save must not record a failure');
    }

    public function testUpdateSignaturesSurfacesDaemonFailure(): void
    {
        $this->db->table('audit_logs')->where('action', 'webfilter.antivirus.update')->delete();

        $this->injectIpc([
            'webfilter.clamav.update' => [
                'result' => ['success' => false, 'status' => 'error', 'message' => 'freshclam: database update failed'],
                'error'  => null,
            ],
        ]);

        $result = $this->withSession($this->sessionData())->post('/webfilter/av/update-signatures', $this->csrf());

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=antivirus'));
        // The operator sees the daemon's real reason, not a generic message.
        $result->assertSessionHas('error', 'Update failed: freshclam: database update failed');

        $settings = (new WebfilterSettingsModel())->getSettings();
        $this->assertStringContainsString('freshclam: database update failed', (string) $settings['clamav_last_update_status']);

        $row = $this->latestAudit('webfilter.antivirus.update');
        $this->assertNotNull($row, 'A failed definition update must leave a persistent audit entry');
        $this->assertSame('alert', $row['action_type']);
    }

    public function testUpdateSignaturesAjaxReturnsFailureJson(): void
    {
        $this->injectIpc([
            'webfilter.clamav.update' => [
                'result' => null,
                'error'  => ['code' => -32000, 'message' => 'definitions server unreachable'],
            ],
        ]);

        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession($this->sessionData())
            ->post('/webfilter/av/update-signatures', $this->csrf());

        $result->assertStatus(200);

        $json = json_decode($result->response()->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertStringContainsString('definitions server unreachable', $json['clamav_last_update_status']);
    }

    // ------------------------------------------------------------------
    // OS / software update-site caching preset
    // ------------------------------------------------------------------

    public function testSaveCachePersistsUpdateSitePresetToggle(): void
    {
        $this->withSession($this->sessionData())->post('/webfilter/save-cache', $this->csrf([
            'cache_enabled'                => '1',
            'cache_update_preset_enabled'  => '1',
        ]))->assertStatus(302);

        $this->assertSame(1, (int) (new WebfilterSettingsModel())->getSettings()['cache_update_preset_enabled']);

        // Unchecked checkboxes are absent from the POST and must reset to 0.
        $this->withSession($this->sessionData())->post('/webfilter/save-cache', $this->csrf(['cache_enabled' => '1']))->assertStatus(302);
        $this->assertSame(0, (int) (new WebfilterSettingsModel())->getSettings()['cache_update_preset_enabled']);
    }

    public function testCompilerEmitsUpdateSitePresetRules(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'               => 1,
            'cache_update_preset_enabled' => 1,
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('windowsupdate\\.com', $output);
        $this->assertStringContainsString('microsoft\\.com', $output);
        $this->assertStringContainsString('apple\\.com', $output);
        $this->assertStringContainsString('4320 80% 43200 reload-into-ims', $output);
    }

    public function testCompilerUpdatePresetWorksWithoutTheCatchAll(): void
    {
        // The update preset is independent of the operator catch-all toggle.
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'               => 1,
            'cache_refresh_enabled'       => 0,
            'cache_update_preset_enabled' => 1,
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringContainsString('windowsupdate\\.com', $output);
        $this->assertStringNotContainsString('refresh_pattern . ', $output);
    }

    public function testCompilerEmitsUpdatePresetBeforeCatchAll(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'               => 1,
            'cache_refresh_enabled'       => 1,
            'cache_update_preset_enabled' => 1,
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $presetPos   = strpos($output, 'windowsupdate\\.com');
        $catchAllPos = strpos($output, 'refresh_pattern . ');
        $this->assertNotFalse($presetPos);
        $this->assertNotFalse($catchAllPos);
        // Squid uses the first match, so the preset must precede the catch-all.
        $this->assertLessThan($catchAllPos, $presetPos);
    }

    public function testCompilerOmitsUpdatePresetWhenDisabled(): void
    {
        (new WebfilterSettingsModel())->saveSettings([
            'cache_enabled'               => 1,
            'cache_update_preset_enabled' => 0,
        ]);

        $output = (new SquidConfigCompiler())->compile();

        $this->assertStringNotContainsString('windowsupdate\\.com', $output);
    }

    public function testIndexRendersUpdateSitePresetToggle(): void
    {
        $result = $this->withSession($this->sessionData())->get('/webfilter?tab=cache');
        $result->assertStatus(200);
        $result->assertSee('Cache OS &amp; Software Update Downloads');
    }

    // ------------------------------------------------------------------
    // Real-Time access log stream
    // ------------------------------------------------------------------

    public function testIndexRendersRealTimeLogStream(): void
    {
        $result = $this->withSession($this->sessionData())->get('/webfilter?tab=logs');
        $result->assertStatus(200);
        $result->assertSee('Real Time Proxy Access Log');
        $result->assertSee('Recorded Web Proxy Access Log');
    }

    public function testLiveLogDataReturnsStreamEntries(): void
    {
        $result = $this->withSession($this->sessionData())->get('/webfilter/logs/data');
        $result->assertStatus(200);

        $json = json_decode($result->response()->getBody(), true);
        $this->assertTrue($json['available']);
        $this->assertNotEmpty($json['entries']);
        $this->assertArrayHasKey('action', $json['entries'][0]);
        $this->assertArrayHasKey('domain', $json['entries'][0]);
    }

    public function testLiveLogDataPassesThroughInjectedEntries(): void
    {
        $this->injectIpc([
            'webfilter.access_log' => [
                'result' => ['success' => true, 'entries' => [
                    [
                        'time'    => '12:00:00',
                        'client'  => '10.0.0.9',
                        'action'  => 'BLOCK',
                        'result'  => 'TCP_DENIED/403',
                        'method'  => 'GET',
                        'domain'  => 'evil.example',
                        'url'     => 'http://evil.example/payload',
                        'bytes'   => 0,
                    ],
                ]],
                'error' => null,
            ],
        ]);

        $result = $this->withSession($this->sessionData())->get('/webfilter/logs/data');
        $result->assertStatus(200);

        $json = json_decode($result->response()->getBody(), true);
        $this->assertTrue($json['available']);
        $this->assertSame('BLOCK', $json['entries'][0]['action']);
        $this->assertSame('evil.example', $json['entries'][0]['domain']);
    }

    public function testLiveLogDataReportsUnavailableWhenDaemonFails(): void
    {
        $this->injectIpc([
            'webfilter.access_log' => [
                'result' => null,
                'error'  => ['code' => -32601, 'message' => 'Method not found: webfilter.access_log'],
            ],
        ]);

        $result = $this->withSession($this->sessionData())->get('/webfilter/logs/data');
        $result->assertStatus(200);

        $json = json_decode($result->response()->getBody(), true);
        $this->assertFalse($json['available']);
        $this->assertSame([], $json['entries']);
    }
}
