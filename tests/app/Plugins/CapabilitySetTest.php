<?php

declare(strict_types=1);

use App\Plugins\CapabilitySet;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CapabilitySet: the absolute protected-unit floor and the declared-vs-granted
 * comparison (Plugin Spec §7).
 *
 * @internal
 */
final class CapabilitySetTest extends CIUnitTestCase
{
    public function testProtectedUnitsAreRecognisedWithAndWithoutTheServiceSuffix(): void
    {
        $this->assertTrue(CapabilitySet::isProtectedUnit('nftables'));
        $this->assertTrue(CapabilitySet::isProtectedUnit('nftables.service'));
        $this->assertTrue(CapabilitySet::isProtectedUnit('openvpn@server'));
        $this->assertTrue(CapabilitySet::isProtectedUnit('unbound'));
        $this->assertFalse(CapabilitySet::isProtectedUnit('lldpd'));
        $this->assertFalse(CapabilitySet::isProtectedUnit('zerotier-one'));
    }

    public function testForbiddenReturnsOnlyCapabilitiesNamingProtectedUnits(): void
    {
        $declared = ['system.service:nftables', 'system.service:lldpd', 'net.raw'];

        $this->assertSame(['system.service:nftables'], CapabilitySet::forbidden($declared));
    }

    public function testDeclaredButNotGrantedIsTheDifference(): void
    {
        $declared = ['system.service:lldpd', 'net.raw', 'root.rpc:plugin.status'];
        $granted  = ['system.service:lldpd'];

        $this->assertSame(['net.raw', 'root.rpc:plugin.status'], CapabilitySet::declaredButNotGranted($declared, $granted));
        $this->assertSame([], CapabilitySet::declaredButNotGranted($declared, $declared));
    }

    public function testBaseUnitNormalisesCaseAndSuffix(): void
    {
        $this->assertSame('wireguard', CapabilitySet::baseUnit('WireGuard.service'));
        $this->assertSame('openvpn@server', CapabilitySet::baseUnit('openvpn@server'));
    }
}
