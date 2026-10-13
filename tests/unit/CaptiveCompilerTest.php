<?php

namespace Tests\Unit;

use App\Libraries\CaptiveCompiler;
use App\Models\CaptiveWalledGardenModel;
use App\Models\CaptiveZoneModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for CaptiveCompiler — verifies nftables configuration generation,
 * set declarations, walled garden interval sets, and prerouting redirect rules.
 *
 * @internal
 */
final class CaptiveCompilerTest extends CIUnitTestCase
{
    // -----------------------------------------------------------------------
    // Helper: Build a minimal zone fixture
    // -----------------------------------------------------------------------
    private function makeZone(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'z1',
            'slug'           => 'guest-wifi',
            'name'           => 'Guest WiFi',
            'interface_id'   => 'wlan0',
            'portal_port'    => 8088,
            'portal_ssl_port'=> 8443,
            'enabled'        => 1,
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // sanitizeTag tests
    // -----------------------------------------------------------------------

    public function testSanitizeTagConvertsToLowercase(): void
    {
        $compiler = $this->makeCompilerWithStubs([], []);
        $result   = $compiler->sanitizeTag('GuestWiFi');
        $this->assertSame('guestwifi', $result);
    }

    public function testSanitizeTagReplacesSpecialCharsWithUnderscore(): void
    {
        $compiler = $this->makeCompilerWithStubs([], []);
        $result   = $compiler->sanitizeTag('my-zone/test');
        $this->assertSame('my_zone_test', $result);
    }

    public function testSanitizeTagTruncatesTo24Chars(): void
    {
        $compiler = $this->makeCompilerWithStubs([], []);
        $long     = str_repeat('a', 40);
        $result   = $compiler->sanitizeTag($long);
        $this->assertSame(24, strlen($result));
    }

    public function testSanitizeTagHandlesNumericSlugs(): void
    {
        $compiler = $this->makeCompilerWithStubs([], []);
        $result   = $compiler->sanitizeTag('zone-01');
        $this->assertSame('zone_01', $result);
    }

    // -----------------------------------------------------------------------
    // compile() — empty zones path
    // -----------------------------------------------------------------------

    public function testCompileReturnsNoActiveZonesComment(): void
    {
        $compiler = $this->makeCompilerWithStubs([], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('No active captive zones configured', $output);
    }

    // -----------------------------------------------------------------------
    // compile() — single zone
    // -----------------------------------------------------------------------

    public function testCompileContainsTableDeclaration(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('table inet filter {', $output);
    }

    public function testCompileContainsMacSetWithTimeout(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('set captive_mac_guest_wifi {', $output);
        $this->assertStringContainsString('type ether_addr', $output);
        $this->assertStringContainsString('flags timeout', $output);
    }

    public function testCompileContainsIpSetWithTimeout(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('set captive_ip_guest_wifi {', $output);
        $this->assertStringContainsString('type ipv4_addr', $output);
    }

    public function testCompileContainsWalledGardenIntervalSet(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('set captive_walled_guest_wifi {', $output);
        $this->assertStringContainsString('flags interval', $output);
    }

    public function testCompileIncludesDefaultCnaPrefixes(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        // Apple prefix should appear in walled garden set
        $this->assertStringContainsString('17.0.0.0/8', $output);
        // Google prefix
        $this->assertStringContainsString('142.250.0.0/15', $output);
    }

    public function testCompileContainsPreroutingChain(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('chain captive_prerouting {', $output);
        $this->assertStringContainsString('type nat hook prerouting', $output);
    }

    public function testCompileContainsHttpRedirectOnConfiguredPort(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone(['portal_port' => 9080])], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('tcp dport 80 redirect to :9080', $output);
    }

    public function testCompileContainsHttpsRedirectOnConfiguredPort(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone(['portal_ssl_port' => 9443])], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('tcp dport 443 redirect to :9443', $output);
    }

    public function testCompileContainsForwardDropGuard(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone()], []);
        $output   = $compiler->compile();
        $this->assertStringContainsString('chain captive_forward {', $output);
        // Unauthenticated traffic from captive interface should be dropped
        $this->assertStringContainsString('iifname "wlan0" drop', $output);
    }

    public function testCompileSkipsZoneWithEmptyInterface(): void
    {
        $compiler = $this->makeCompilerWithStubs([$this->makeZone(['interface_id' => ''])], []);
        $output   = $compiler->compile();
        // Table still opens (sets declared) but no prerouting redirect rule for blank iface
        $this->assertStringNotContainsString('tcp dport 80 redirect', $output);
    }

    // -----------------------------------------------------------------------
    // Multi-zone
    // -----------------------------------------------------------------------

    public function testCompileProducesDistinctSetsForMultipleZones(): void
    {
        $zones = [
            $this->makeZone(['id' => 'z1', 'slug' => 'zone-a', 'interface_id' => 'wlan0']),
            $this->makeZone(['id' => 'z2', 'slug' => 'zone-b', 'interface_id' => 'wlan1']),
        ];
        $compiler = $this->makeCompilerWithStubs($zones, []);
        $output   = $compiler->compile();

        $this->assertStringContainsString('set captive_mac_zone_a {', $output);
        $this->assertStringContainsString('set captive_mac_zone_b {', $output);
        $this->assertStringContainsString('iifname "wlan0"', $output);
        $this->assertStringContainsString('iifname "wlan1"', $output);
    }

    // -----------------------------------------------------------------------
    // resolveWalledGardenIps
    // -----------------------------------------------------------------------

    public function testResolveWalledGardenIpsAlwaysContainsDefaultCnaRanges(): void
    {
        $compiler = $this->makeCompilerWithStubs([], []);
        $ips      = $compiler->resolveWalledGardenIps('z1');

        foreach (CaptiveCompiler::DEFAULT_CNA_PREFIXES as $prefix) {
            $this->assertContains($prefix, $ips);
        }
    }

    public function testResolveWalledGardenIpsIncludesValidIpv4FromDb(): void
    {
        $dbRows   = [['target' => '203.0.113.5']];
        $compiler = $this->makeCompilerWithStubs([], $dbRows);
        $ips      = $compiler->resolveWalledGardenIps('z1');

        $this->assertContains('203.0.113.5', $ips);
    }

    public function testResolveWalledGardenIpsIncludesCidrFromDb(): void
    {
        $dbRows   = [['target' => '203.0.113.0/24']];
        $compiler = $this->makeCompilerWithStubs([], $dbRows);
        $ips      = $compiler->resolveWalledGardenIps('z1');

        $this->assertContains('203.0.113.0/24', $ips);
    }

    public function testResolveWalledGardenIpsDeduplicates(): void
    {
        $dbRows   = [
            ['target' => '17.0.0.0/8'], // already in DEFAULT_CNA_PREFIXES
            ['target' => '203.0.113.5'],
        ];
        $compiler = $this->makeCompilerWithStubs([], $dbRows);
        $ips      = $compiler->resolveWalledGardenIps('z1');

        $this->assertSame(count(array_unique($ips)), count($ips));
    }

    // -----------------------------------------------------------------------
    // Factory helper using anonymous stub classes
    // -----------------------------------------------------------------------

    private function makeCompilerWithStubs(array $zones, array $walledGardenRows): CaptiveCompiler
    {
        $zoneModel = new class($zones) extends CaptiveZoneModel {
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function findAll(?int $limit = 0, int $offset = 0) { return $this->rows; }
        };

        $walledModel = new class($walledGardenRows) extends CaptiveWalledGardenModel {
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function getEnabledTargets(string $zoneId): array { return $this->rows; }
        };

        return new CaptiveCompiler($zoneModel, $walledModel);
    }
}
