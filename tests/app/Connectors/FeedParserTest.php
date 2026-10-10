<?php

declare(strict_types=1);

use App\Connectors\FeedParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The feed parser: every closed-enum format becomes a canonical, validated,
 * de-duplicated typed set with accurate drop counts.
 *
 * @internal
 */
final class FeedParserTest extends CIUnitTestCase
{
    private FeedParser $parser;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FeedParser();
        $this->dir    = sys_get_temp_dir() . '/micron_parse_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function parse(string $format, string $content, ?string $hint = null): array
    {
        $path = $this->dir . '/feed.' . $format;
        file_put_contents($path, $content);

        return $this->parser->parseFile($format, $path, $hint);
    }

    // ------------------------------------------------------------------
    // plain-domain-list
    // ------------------------------------------------------------------

    public function testPlainDomainListValidatesNormalisesAndDedupes(): void
    {
        $result = $this->parse('plain-domain-list', implode("\n", [
            '# blocked domains',
            'Evil.Example.com',
            '.tracker.example',
            'ads.example.com # inline comment',
            'evil.example.com',          // duplicate
            'not a valid domain',
            'localhost',                 // no dot / letters-only TLD
            '',
        ]));

        $this->assertTrue($result['ok']);
        $this->assertSame('domain', $result['type']);
        $this->assertSame(['ads.example.com', 'evil.example.com', 'tracker.example'], $result['entries']);
        $this->assertSame(2, $result['counts']['dropped']);
        $this->assertNotEmpty($result['dropped_samples']);
    }

    public function testDomainRuleMirrorsMicrond(): void
    {
        // Anything the control plane accepts must be something micrond compiles.
        foreach (['evil.example.com', '_dmarc.example.com', 'a-b.example.co.uk'] as $domain) {
            $this->assertNotNull(FeedParser::normaliseDomain($domain), "should accept: {$domain}");
        }
        foreach (['localhost', '-bad.example.com', 'a..example.com', 'example.c', 'evil.example.com/path'] as $domain) {
            $this->assertNull(FeedParser::normaliseDomain($domain), "should reject: {$domain}");
        }
    }

    // ------------------------------------------------------------------
    // plain-ip-list
    // ------------------------------------------------------------------

    public function testPlainIpListCanonicalisesAddresses(): void
    {
        $result = $this->parse('plain-ip-list', implode("\n", [
            '# ip ranges',
            '1.2.3.4',
            '10.0.0.9/24',      // masked to the network address
            '2001:DB8::1',      // lower-cased host prefix added
            '1.2.3.4 5.6.7.8',  // multiple tokens on a line
            '1.2.3.4-1.2.3.9',  // a range is not a CIDR
        ]));

        $this->assertTrue($result['ok']);
        $this->assertSame('address', $result['type']);
        $this->assertSame(['1.2.3.4/32', '10.0.0.0/24', '2001:db8::1/128', '5.6.7.8/32'], $result['entries']);
        $this->assertSame(1, $result['counts']['dropped']);
    }

    // ------------------------------------------------------------------
    // hostfile
    // ------------------------------------------------------------------

    public function testHostfileTakesTheTrailingHostname(): void
    {
        $result = $this->parse('hostfile', implode("\n", [
            '127.0.0.1 localhost',
            '0.0.0.0 evil.example.com',
            '0.0.0.0 tracker.example.com # comment',
            '::1 loop.example.net',
            'not-a-hostfile-line',
        ]));

        $this->assertSame('domain', $result['type']);
        $this->assertContains('evil.example.com', $result['entries']);
        $this->assertContains('tracker.example.com', $result['entries']);
        $this->assertContains('loop.example.net', $result['entries']);
        $this->assertNotContains('localhost', $result['entries']);
        $this->assertSame(2, $result['counts']['dropped']);
    }

    // ------------------------------------------------------------------
    // json
    // ------------------------------------------------------------------

    public function testJsonIpListPrefersEntryKeys(): void
    {
        $json = json_encode([
            'values' => [
                ['name' => 'AzureCloud.eastus', 'properties' => ['addressPrefixes' => ['20.0.0.0/16', '20.1.2.3']]],
            ],
        ]);

        $result = $this->parse('json-ip-list', (string) $json);

        $this->assertSame('address', $result['type']);
        $this->assertSame(['20.0.0.0/16', '20.1.2.3/32'], $result['entries']);
        $this->assertGreaterThanOrEqual(1, $result['counts']['dropped']); // the "name" metadata string
    }

    public function testJsonDomainListAcceptsAPlainArray(): void
    {
        $result = $this->parse('json-domain-list', (string) json_encode(['evil.example.com', 'tracker.example.net', 'nope']));

        $this->assertSame('domain', $result['type']);
        $this->assertSame(['evil.example.com', 'tracker.example.net'], $result['entries']);
        $this->assertSame(1, $result['counts']['dropped']);
    }

    public function testInvalidJsonFailsTheParse(): void
    {
        $result = $this->parse('json-ip-list', '{ this is not json');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    // ------------------------------------------------------------------
    // csv
    // ------------------------------------------------------------------

    public function testCsvSkipsHeaderAndTakesTheFirstCell(): void
    {
        $csv = "domain,category\nevil.example.com,malware\n\ntracker.example.net,ads\nnot valid,malware\n";

        $result = $this->parse('csv', $csv, 'domain');

        $this->assertSame('domain', $result['type']);
        $this->assertSame(['evil.example.com', 'tracker.example.net'], $result['entries']);
        $this->assertSame(1, $result['counts']['dropped']);
    }

    public function testCsvTypeComesFromTheHint(): void
    {
        $csv = "1.2.3.4\n10.0.0.0/24\n";

        $this->assertSame('address', $this->parse('csv', $csv, 'address')['type']);
        $this->assertSame('address', $this->parse('csv', $csv)['type']);
    }

    // ------------------------------------------------------------------
    // rfc1035-spf
    // ------------------------------------------------------------------

    public function testSpfExtractsIpMechanisms(): void
    {
        $result = $this->parse('rfc1035-spf', 'v=spf1 ip4:203.0.113.0/24 ip6:2001:db8::/32 mx -all');

        $this->assertSame('address', $result['type']);
        $this->assertSame(['2001:db8::/32', '203.0.113.0/24'], $result['entries']);
    }

    // ------------------------------------------------------------------
    // Failure modes
    // ------------------------------------------------------------------

    public function testUnknownFormatAndMissingFileFailCleanly(): void
    {
        $unknown = $this->parser->parseFile('exec-shell', $this->dir . '/missing');
        $this->assertFalse($unknown['ok']);
        $this->assertNotNull($unknown['error']);

        $missing = $this->parser->parseFile('plain-ip-list', $this->dir . '/missing');
        $this->assertFalse($missing['ok']);
        $this->assertSame('address', $missing['type']);
    }

    public function testOnlyCommentsAndBlanksYieldAnEmptyValidParse(): void
    {
        $result = $this->parse('plain-domain-list', "# just a comment\n\n   \n");

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['entries']);
        $this->assertSame(0, $result['counts']['accepted']);
        $this->assertSame(0, $result['counts']['dropped']);
    }
}
