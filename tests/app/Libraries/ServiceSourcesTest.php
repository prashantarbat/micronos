<?php

use App\Libraries\ServiceSources;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The public prefix-feed registry and its parsers.
 *
 * Parsing is the deterministic half of the Internet Service Database sync and
 * is what decides whether a downloaded document becomes usable CIDRs.
 *
 * @internal
 */
final class ServiceSourcesTest extends CIUnitTestCase
{
    public function testRegistryLookups(): void
    {
        $this->assertTrue(ServiceSources::has('aws'));
        $this->assertTrue(ServiceSources::has('microsoft_365'));
        $this->assertFalse(ServiceSources::has('not_a_service'));

        $this->assertStringContainsString('ip-ranges.amazonaws.com', (string) ServiceSources::url('aws'));
        $this->assertSame('m365', ServiceSources::parser('teams'));
        $this->assertNull(ServiceSources::url('not_a_service'));

        $this->assertContains('aws', ServiceSources::keys());
        $this->assertContains('google_cloud', ServiceSources::keys());
    }

    public function testEveryRegisteredSourceHasAUrlAndParser(): void
    {
        foreach (ServiceSources::SOURCES as $key => $source) {
            $this->assertArrayHasKey('url', $source, $key);
            $this->assertArrayHasKey('parser', $source, $key);
            $this->assertNotSame('', $source['url'], $key);
        }
    }

    public function testCidrLinesParser(): void
    {
        $body = "# comment\n203.0.113.0/24\n\n198.51.100.7\nnot-an-ip\n; another comment\n203.0.113.0/24\n";

        $this->assertSame(
            ['203.0.113.0/24', '198.51.100.7'],
            ServiceSources::parse('zoom', $body),
        );
    }

    public function testJsonRangesParserWalksNestedDocuments(): void
    {
        $body = json_encode([
            'prefixes' => [
                ['ip_prefix' => '52.94.0.0/22', 'region' => 'us-east-1'],
                ['ip_prefix' => '99.82.0.0/22'],
                ['ipv6_prefix' => '2600:1f00::/28'],
            ],
            'syncToken' => '1',
        ]);

        $parsed = ServiceSources::parse('aws', (string) $body);
        $this->assertContains('52.94.0.0/22', $parsed);
        $this->assertContains('99.82.0.0/22', $parsed);
        $this->assertContains('2600:1f00::/28', $parsed);
    }

    public function testJsonRangesParserHandlesArrayFields(): void
    {
        // The service-tag document nests arrays of prefixes under properties.
        $body = json_encode([
            'values' => [
                ['properties' => ['addressPrefixes' => ['4.145.74.52/30', '4.149.254.68/30']]],
            ],
        ]);

        $this->assertSame(
            ['4.145.74.52/30', '4.149.254.68/30'],
            ServiceSources::parse('microsoft_azure', (string) $body),
        );
    }

    public function testAzureDeclaresALandingPageLinkPattern(): void
    {
        // The service-tag document is re-issued under a dated file name, so the
        // engine resolves the real link from the landing page.
        $pattern = ServiceSources::linkPattern('microsoft_azure');
        $this->assertNotNull($pattern);

        $page = '<a href="https://download.microsoft.com/download/7/1/d/x/ServiceTags_Public_20260101.json">a</a>';
        $this->assertSame(1, preg_match_all($pattern, $page, $m));
        $this->assertSame('https://download.microsoft.com/download/7/1/d/x/ServiceTags_Public_20260101.json', $m[0][0]);
    }

    public function testJsonKeyedParserUsesConfiguredFields(): void
    {
        $body = json_encode([
            'hooks'    => ['192.30.252.0/22'],
            'web'      => ['140.82.112.0/20'],
            'unused'   => ['10.0.0.0/8'],
        ]);

        $parsed = ServiceSources::parse('github', (string) $body);
        $this->assertContains('192.30.252.0/22', $parsed);
        $this->assertContains('140.82.112.0/20', $parsed);
        $this->assertNotContains('10.0.0.0/8', $parsed);
    }

    public function testM365ParserCollectsEndpointIps(): void
    {
        $body = json_encode([
            ['serviceArea' => 'Exchange', 'ips' => ['13.107.6.152/31', '13.107.18.10/31']],
            ['serviceArea' => 'SharePoint', 'ips' => ['13.107.128.0/22']],
            ['serviceArea' => 'Common', 'urls' => ['*.office.com']],
        ]);

        $parsed = ServiceSources::parse('microsoft_365', (string) $body);
        $this->assertContains('13.107.6.152/31', $parsed);
        $this->assertContains('13.107.128.0/22', $parsed);
        $this->assertCount(3, $parsed);
    }

    public function testCloudflareParserReadsBothFamilies(): void
    {
        $body = json_encode([
            'result' => [
                'ipv4_cidrs' => ['173.245.48.0/20'],
                'ipv6_cidrs' => ['2400:cb00::/32'],
            ],
        ]);

        $parsed = ServiceSources::parse('cloudflare', (string) $body);
        $this->assertSame(['173.245.48.0/20', '2400:cb00::/32'], $parsed);
    }

    public function testUnknownSourceParsesToNothing(): void
    {
        $this->assertSame([], ServiceSources::parse('not_a_service', '203.0.113.0/24'));
    }

    public function testMalformedJsonParsesToNothing(): void
    {
        $this->assertSame([], ServiceSources::parse('aws', 'this is not json'));
    }
}
