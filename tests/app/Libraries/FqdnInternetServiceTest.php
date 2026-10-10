<?php

use App\Libraries\Fqdn;
use App\Libraries\InternetService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Wildcard-aware domain handling and the Internet Service Database catalogue.
 *
 * @internal
 */
final class FqdnInternetServiceTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // FQDN
    // ------------------------------------------------------------------

    public function testAcceptsPlainAndWildcardDomains(): void
    {
        $this->assertTrue(Fqdn::isValid('example.com'));
        $this->assertTrue(Fqdn::isValid('*.example.com'));
        $this->assertTrue(Fqdn::isValid('*.a.b.example.co.uk'));
        $this->assertTrue(Fqdn::isValid('a-b.c-d.example.co.uk'));
        // A single label is still a usable intranet hostname.
        $this->assertTrue(Fqdn::isValid('intranet'));
    }

    public function testRejectsMalformedDomains(): void
    {
        $this->assertFalse(Fqdn::isValid(''));
        $this->assertFalse(Fqdn::isValid('*'));
        $this->assertFalse(Fqdn::isValid('-bad.example.com'));
        $this->assertFalse(Fqdn::isValid('exa mple.com'));
        $this->assertFalse(Fqdn::isValid('example..com'));
        // A wildcard must be left-most: there is no single label to walk from
        // when a fixed label sits to its left.
        $this->assertFalse(Fqdn::isValid('api.*.example.com'));
    }

    public function testNormalisesSchemeTrailingDotAndCase(): void
    {
        $this->assertSame('example.com', Fqdn::normalise('HTTPS://Example.COM./path?q=1'));
        $this->assertSame('*.example.com', Fqdn::normalise('*.Example.com:8443'));
    }

    public function testSplitsAndDeduplicatesAList(): void
    {
        $this->assertSame(
            ['example.com', '*.example.com'],
            Fqdn::split('example.com, *.EXAMPLE.com example.com'),
        );
    }

    public function testSetNameIsStableAndSafe(): void
    {
        $this->assertSame('fqdn_example_com', Fqdn::setName('example.com'));
        $this->assertSame('fqdn_wild_example_com', Fqdn::setName('*.example.com'));
        $this->assertSame('fqdn_wild_a_b_example_co_uk', Fqdn::setName('*.a.b.example.co.uk'));
    }

    public function testWildcardBaseIsTheFixedDomain(): void
    {
        $this->assertSame('example.com', Fqdn::wildcardBase('*.example.com'));
        $this->assertSame('a.b.example.co.uk', Fqdn::wildcardBase('*.a.b.example.co.uk'));
        $this->assertSame('', Fqdn::wildcardBase('example.com'));
    }

    public function testValidatesAList(): void
    {
        $this->assertTrue(Fqdn::isValidList('example.com, *.example.org'));
        $this->assertFalse(Fqdn::isValidList('example.com, bad_underscore.com'));
        $this->assertTrue(Fqdn::isValidList(''));
    }

    // ------------------------------------------------------------------
    // Internet Service Database
    // ------------------------------------------------------------------

    public function testCatalogueKeysValidateAndNormalise(): void
    {
        $this->assertTrue(InternetService::isKnown('microsoft_365'));
        $this->assertSame(['microsoft_365', 'zoom'], InternetService::keys('microsoft_365, zoom'));
        $this->assertSame('zoom, microsoft_365', InternetService::normalise('zoom, microsoft_365'));
        $this->assertSame(['nope'], InternetService::invalidKeys('nope, zoom'));
    }

    public function testDescribeFallsBackToRawValue(): void
    {
        $this->assertSame('Microsoft 365, Zoom', InternetService::describe('microsoft_365, zoom'));
        $this->assertSame('unknown_key', InternetService::describe('unknown_key'));
    }

    public function testSetNameIsStable(): void
    {
        $this->assertSame('isdb_microsoft_365', InternetService::setName('microsoft_365'));
        $this->assertSame('isdb_tor', InternetService::setName('TOR'));
    }

    public function testGroupedCoversEveryService(): void
    {
        $grouped = InternetService::grouped();
        $total   = array_sum(array_map('count', $grouped));

        $this->assertSame(count(InternetService::SERVICES), $total);
    }
}
