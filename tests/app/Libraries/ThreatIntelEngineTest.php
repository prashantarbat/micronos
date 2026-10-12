<?php

namespace Tests\App\Libraries;

use App\Libraries\ThreatIntelEngine;
use App\Models\ThreatFeedModel;
use App\Models\ThreatIndicatorModel;
use App\Models\ThreatIntelSettingsModel;
use App\Models\ThreatIntelWhitelistModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ThreatIntelEngineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private ThreatIntelEngine $engine;
    private ThreatFeedModel $feedModel;
    private ThreatIndicatorModel $indicatorModel;
    private ThreatIntelSettingsModel $settingsModel;
    private ThreatIntelWhitelistModel $whitelistModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feedModel      = new ThreatFeedModel();
        $this->indicatorModel = new ThreatIndicatorModel();
        $this->settingsModel  = new ThreatIntelSettingsModel();
        $this->whitelistModel = new ThreatIntelWhitelistModel();
        $this->engine         = new ThreatIntelEngine(
            null,
            $this->feedModel,
            $this->indicatorModel,
            $this->settingsModel,
            $this->whitelistModel
        );
    }

    public function testRfc1918AndBogonProtection(): void
    {
        // Private / Loopback / CGNAT addresses that MUST be detected as private
        $this->assertTrue($this->engine->isPrivateOrReservedIp('10.0.0.1'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('10.254.1.99/24'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('192.168.1.1'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('192.168.100.5/24'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('172.16.0.1'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('172.31.255.254'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('127.0.0.1'));
        $this->assertTrue($this->engine->isPrivateOrReservedIp('100.64.0.1')); // Carrier-grade NAT
        $this->assertTrue($this->engine->isPrivateOrReservedIp('169.254.10.20')); // Link-local
        $this->assertTrue($this->engine->isPrivateOrReservedIp('invalid_ip_address'));

        // Public threat IP addresses that MUST NOT be flagged as private
        $this->assertFalse($this->engine->isPrivateOrReservedIp('198.51.100.22'));
        $this->assertFalse($this->engine->isPrivateOrReservedIp('203.0.113.88'));
        $this->assertFalse($this->engine->isPrivateOrReservedIp('185.220.101.5'));
        $this->assertFalse($this->engine->isPrivateOrReservedIp('8.8.8.8'));
    }

    public function testParseAbuseIpDbJson(): void
    {
        $json = json_encode([
            'data' => [
                ['ipAddress' => '198.51.100.1', 'abuseConfidenceScore' => 100],
                ['ipAddress' => '198.51.100.2', 'abuseConfidenceScore' => 85],
                ['ipAddress' => '198.51.100.3', 'abuseConfidenceScore' => 40], // Should be skipped with minConfidence 80
            ],
        ]);

        $results = $this->engine->parseAbuseIpDbResponse($json, 80);

        $this->assertCount(2, $results);
        $this->assertSame('198.51.100.1', $results[0]['ip']);
        $this->assertSame(100, $results[0]['score']);
        $this->assertSame('198.51.100.2', $results[1]['ip']);
    }

    public function testParseAlienVaultOtxJson(): void
    {
        $json = json_encode([
            'results' => [
                [
                    'name'       => 'Cobalt Strike C2 Pulse',
                    'indicators' => [
                        ['indicator' => '203.0.113.10', 'type' => 'IPv4'],
                        ['indicator' => '203.0.113.20', 'type' => 'IPv4'],
                        ['indicator' => 'malicious.example.com', 'type' => 'hostname'], // non-IP skipped
                    ],
                ],
            ],
        ]);

        $results = $this->engine->parseAlienVaultOtxResponse($json, 85);

        $this->assertCount(2, $results);
        $this->assertSame('203.0.113.10', $results[0]['ip']);
        $this->assertSame('203.0.113.20', $results[1]['ip']);
    }

    public function testParseMispCsv(): void
    {
        $csv = "# MISP IoC Export\n" .
            "198.51.100.50,ip-dst,malicious\n" .
            "198.51.100.60,ip-dst,scanner\n";

        $results = $this->engine->parseMispResponse($csv, 80);

        $this->assertCount(2, $results);
        $this->assertSame('198.51.100.50', $results[0]['ip']);
        $this->assertSame('198.51.100.60', $results[1]['ip']);
    }

    public function testParseSpamhausDrop(): void
    {
        $raw = "; Spamhaus DROP\n" .
            "198.51.100.0/24 ; SBL123456\n" .
            "; EDROP list\n" .
            "203.0.113.0/24 ; SBL789012\n";

        $results = $this->engine->parseSpamhausDrop($raw, 100);

        $this->assertCount(2, $results);
        $this->assertSame('198.51.100.0/24', $results[0]['ip']);
        $this->assertSame('203.0.113.0/24', $results[1]['ip']);
    }

    public function testCompileNftablesConfig(): void
    {
        $config = $this->engine->compileNftablesConfig();

        $this->assertStringContainsString('table inet micronos', $config);
        $this->assertStringContainsString('set threat_intel_ipv4', $config);
        $this->assertStringContainsString('type ipv4_addr', $config);
        $this->assertStringContainsString('flags interval', $config);
        $this->assertStringContainsString('chain threat_intel_prerouting', $config);
        $this->assertStringContainsString('chain threat_intel_forward', $config);
    }

    public function testDetectIndicatorTypeAndValidFqdn(): void
    {
        $this->assertTrue($this->engine->isValidFqdn('malicious.example.com'));
        $this->assertTrue($this->engine->isValidFqdn('c2.darkweb-network.org'));
        $this->assertTrue($this->engine->isValidFqdn('phishing-bank.co.uk'));

        $this->assertFalse($this->engine->isValidFqdn('bad-domain'));
        $this->assertFalse($this->engine->isValidFqdn('-bad.domain.com'));
        $this->assertFalse($this->engine->isValidFqdn('https://bad.domain.com'));
        $this->assertFalse($this->engine->isValidFqdn('bad..domain.com'));

        $this->assertSame('ipv4', $this->engine->detectIndicatorType('198.51.100.10'));
        $this->assertSame('ipv4_cidr', $this->engine->detectIndicatorType('198.51.100.0/24'));
        $this->assertSame('fqdn', $this->engine->detectIndicatorType('botnet.attacker.net'));
        $this->assertSame('invalid', $this->engine->detectIndicatorType('not a valid host or ip!'));
    }

    public function testAddManualIndicatorIpv4AndFqdn(): void
    {
        // 1. Add valid IPv4 manual indicator
        $ipItem = $this->engine->addManualIndicator(
            '198.51.100.222',
            'scanner',
            95,
            'Manual test note IP',
            date('Y-m-d H:i:s', time() + 86400)
        );
        $this->assertSame('198.51.100.222', $ipItem['indicator_value']);
        $this->assertSame('ipv4', $ipItem['indicator_type']);
        $this->assertSame('feed_manual_ioc', $ipItem['feed_id']);
        $this->assertSame('Manual test note IP', $ipItem['comment']);

        // 2. Add valid FQDN manual indicator
        $fqdnItem = $this->engine->addManualIndicator(
            'c2.malicious-network.org',
            'c2',
            100,
            'C2 Host Domain Note'
        );
        $this->assertSame('c2.malicious-network.org', $fqdnItem['indicator_value']);
        $this->assertSame('fqdn', $fqdnItem['indicator_type']);
        $this->assertSame('feed_manual_ioc', $fqdnItem['feed_id']);

        // 3. Test RFC 1918 rejection for manual indicator
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/RFC 1918/');
        $this->engine->addManualIndicator('192.168.1.100', 'custom_blacklist');
    }

    public function testCompileDnsblConfig(): void
    {
        $this->engine->addManualIndicator(
            'sinkhole-test-domain.org',
            'malware',
            100,
            'Sinkhole verification'
        );

        $dnsbl = $this->engine->compileDnsblConfig();
        $this->assertStringContainsString('ThreatIntelEngine', $dnsbl);
        $this->assertStringContainsString('local-zone: "sinkhole-test-domain.org" static', $dnsbl);
        $this->assertStringContainsString('local-data: "sinkhole-test-domain.org A 0.0.0.0"', $dnsbl);

        // Verify nftables does NOT include the FQDN domain string
        $nft = $this->engine->compileNftablesConfig();
        $this->assertStringNotContainsString('sinkhole-test-domain.org', $nft);
    }

    public function testDeleteManualIndicator(): void
    {
        $item = $this->engine->addManualIndicator('203.0.113.123', 'botnet');
        $this->assertNotNull($this->indicatorModel->find($item['id']));

        $deleted = $this->engine->deleteManualIndicator($item['id']);
        $this->assertTrue($deleted);
        $this->assertNull($this->indicatorModel->find($item['id']));
    }
}
