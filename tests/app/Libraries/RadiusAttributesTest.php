<?php

namespace Tests\App\Libraries;

use App\Libraries\RadiusAttributes;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Normalisation of the RADIUS reply attributes recorded for a PPPoE session.
 *
 * The attribute names are not uniform across implementations, so these cover the
 * shapes an operator will actually meet: the standard session/framed attributes,
 * the two common rate-limit spellings, and the case where a vendor sends
 * something this code has never heard of.
 *
 * @internal
 */
final class RadiusAttributesTest extends CIUnitTestCase
{
    public function testEmptyMapDescribesNothing(): void
    {
        $this->assertSame([], RadiusAttributes::describe([]));
    }

    public function testStandardAttributesAreExtracted(): void
    {
        $result = RadiusAttributes::describe([
            'Framed-IP-Address' => '10.0.0.42',
            'Session-Timeout'   => '3600',
            'Idle-Timeout'      => '300',
            'Filter-Id'         => '2048/1024',
        ]);

        $this->assertSame(3600, $result['session_timeout']);
        $this->assertSame(300, $result['idle_timeout']);
        $this->assertSame('10.0.0.42', $result['framed_ip']);
        $this->assertSame('2048/1024', $result['rate_limit']);
    }

    public function testAttributeNamesAreMatchedCaseInsensitively(): void
    {
        // A server that spells the attribute differently must not hide it.
        $result = RadiusAttributes::describe([
            'session-timeout'   => '600',
            'FRAMED-IP-ADDRESS' => '192.0.2.7',
        ]);

        $this->assertSame(600, $result['session_timeout']);
        $this->assertSame('192.0.2.7', $result['framed_ip']);
    }

    public function testVendorRateLimitIsUsedWhenFilterIdIsAbsent(): void
    {
        $result = RadiusAttributes::describe([
            'Mikrotik-Rate-Limit' => '5M/10M',
        ]);

        $this->assertSame('5M/10M', $result['rate_limit']);
    }

    public function testWisprBandwidthAttributesAreCombined(): void
    {
        $result = RadiusAttributes::describe([
            'WISPr-Bandwidth-Max-Down' => '2048000',
            'WISPr-Bandwidth-Max-Up'   => '512000',
        ]);

        $this->assertSame('2048/512 kbps', $result['rate_limit']);
    }

    public function testAnUnknownVendorRateLimitIsStillRecognised(): void
    {
        $result = RadiusAttributes::describe([
            'Some-Vendor-Rate-Limit' => '100M',
        ]);

        $this->assertSame('100M', $result['rate_limit']);
    }

    public function testAFramedAddressThatIsNotAnAddressIsLeftForDiagnosis(): void
    {
        $result = RadiusAttributes::describe(['Framed-IP-Address' => 'not-an-ip']);

        $this->assertNull($result['framed_ip']);
        // It is still exposed, rather than silently discarded.
        $this->assertArrayHasKey('Framed-IP-Address', $result['others']);
    }

    public function testANonNumericTimeoutIsNotFabricated(): void
    {
        $result = RadiusAttributes::describe(['Session-Timeout' => 'unlimited']);

        $this->assertNull($result['session_timeout']);
        $this->assertArrayHasKey('Session-Timeout', $result['others']);
    }

    public function testConsumedAttributesAreNotRepeatedInOthers(): void
    {
        $result = RadiusAttributes::describe([
            'Session-Timeout'   => '3600',
            'Framed-IP-Address' => '10.0.0.42',
            'Filter-Id'         => '1M/1M',
            'Class'             => '0xdeadbeef',
        ]);

        $this->assertSame(['Class' => '0xdeadbeef'], $result['others']);
    }

    public function testAllKeepsEveryAttributeIncludingTheConsumedOnes(): void
    {
        $result = RadiusAttributes::describe([
            'Session-Timeout' => '60',
            'Class'           => '0x01',
        ]);

        $this->assertSame(['Session-Timeout' => '60', 'Class' => '0x01'], $result['all']);
    }

    public function testBlankAttributeNamesAndValuesAreIgnored(): void
    {
        $result = RadiusAttributes::describe([
            ''               => 'x',
            'Session-Timeout' => '',
            'Framed-IP-Address' => '10.1.1.1',
        ]);

        $this->assertNull($result['session_timeout']);
        $this->assertSame('10.1.1.1', $result['framed_ip']);
        $this->assertArrayNotHasKey('', $result['all']);
    }

    public function testInterimIntervalIsRecognised(): void
    {
        // The accounting cadence the server asked for; pppd sends no interim
        // records without it, so it is worth surfacing.
        $result = RadiusAttributes::describe(['Acct-Interim-Interval' => '300']);

        $this->assertSame(300, $result['interim_interval']);
        $this->assertArrayNotHasKey('Acct-Interim-Interval', $result['others']);
    }

    public function testInterimIntervalIsNullWhenTheServerDidNotAskForIt(): void
    {
        $result = RadiusAttributes::describe(['Session-Timeout' => '60']);

        $this->assertNull($result['interim_interval']);
    }

    public function testZeroTimeoutIsReportedAsZeroNotNull(): void
    {
        // 0 means "no limit" and must be distinguishable from "not sent".
        $result = RadiusAttributes::describe(['Session-Timeout' => '0']);

        $this->assertSame(0, $result['session_timeout']);
    }

    public function testClassAndFramedPoolStayAvailableForDiagnosis(): void
    {
        $result = RadiusAttributes::describe([
            'Class'        => '0x4d6963726f6e',
            'Framed-Pool'  => 'subscribers',
            'Framed-IPv6-Prefix' => '2001:db8::/64',
        ]);

        $this->assertArrayHasKey('Class', $result['others']);
        $this->assertArrayHasKey('Framed-Pool', $result['others']);
        $this->assertArrayHasKey('Framed-IPv6-Prefix', $result['others']);
    }
}
