<?php

use App\Libraries\ObjectTip;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for ObjectTip — the hover-detail tooltip builder used across the
 * firewall object pages. Each method returns a `data-fw-tip` attribute whose
 * value is an escaped HTML card; these assert the attribute shape and that the
 * expected fields (and only populated ones) make it into the card.
 *
 * @internal
 */
final class ObjectTipTest extends CIUnitTestCase
{
    /** Decodes the attribute value back into readable HTML for assertions. */
    private function decode(string $attr): string
    {
        $this->assertStringStartsWith(' data-fw-tip="', $attr);
        $html = substr($attr, strlen(' data-fw-tip="'), -1);

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testAddressCardIncludesTypeValueAndInterface(): void
    {
        $attr = ObjectTip::address([
            'name'        => 'Web_Server',
            'type'        => 'ipmask',
            'value'       => '10.0.0.5/32',
            'interface'   => 'any',
            'comment'     => 'Public web host',
            'is_system'   => 0,
        ]);

        $html = $this->decode($attr);
        $this->assertStringContainsString('Web_Server', $html);
        $this->assertStringContainsString('Subnet', $html);
        $this->assertStringContainsString('10.0.0.5/32', $html);
        $this->assertStringContainsString('ANY', $html);
        $this->assertStringContainsString('Public web host', $html);
        $this->assertStringContainsString('User-defined', $html);
    }

    public function testAddressCardSkipsEmptyFields(): void
    {
        $html = $this->decode(ObjectTip::address([
            'name'      => 'Empty',
            'type'      => 'ipaddress',
            'value'     => '1.2.3.4',
            'interface' => null,
            'comment'   => '',
        ]));

        $this->assertStringContainsString('1.2.3.4', $html);
        $this->assertStringNotContainsString('Comment', $html);
    }

    public function testAddressGroupListsMembersAndCount(): void
    {
        $html = $this->decode(ObjectTip::addressGroup([
            'name'    => 'Webs',
            'members' => json_encode(['web1', 'web2']),
            'comment' => 'All web hosts',
        ], [
            'web1' => ['name' => 'web1', 'type' => 'ipaddress', 'value' => '10.0.0.1'],
            'web2' => ['name' => 'web2', 'type' => 'ipaddress', 'value' => '10.0.0.2'],
        ]));

        $this->assertStringContainsString('Address Group', $html);
        $this->assertStringContainsString('2 —', $html);
        $this->assertStringContainsString('web1 (Ipaddress: 10.0.0.1)', $html);
        $this->assertStringContainsString('All web hosts', $html);
    }

    public function testAddressGroupHandlesEmptyMembers(): void
    {
        $html = $this->decode(ObjectTip::addressGroup(['name' => 'Empty', 'members' => '[]']));

        $this->assertStringContainsString('(empty)', $html);
    }

    public function testServiceCardIncludesPorts(): void
    {
        $html = $this->decode(ObjectTip::service([
            'name'      => 'HTTPS',
            'protocol'  => 'tcp',
            'tcp_port'  => '443',
            'udp_port'  => null,
            'category'  => 'Web',
            'comment'   => 'Secure web',
            'is_system' => 1,
        ]));

        $this->assertStringContainsString('HTTPS', $html);
        $this->assertStringContainsString('TCP 443', $html);
        $this->assertStringContainsString('System service (protected)', $html);
        $this->assertStringNotContainsString('UDP', $html);
    }

    public function testServiceGroupListsMemberPorts(): void
    {
        $html = $this->decode(ObjectTip::serviceGroup([
            'name'    => 'Web',
            'members' => json_encode(['HTTPS', 'HTTP']),
        ], [
            'HTTPS' => ['name' => 'HTTPS', 'tcp_port' => '443'],
            'HTTP'  => ['name' => 'HTTP', 'tcp_port' => '80'],
        ]));

        $this->assertStringContainsString('Service Group', $html);
        $this->assertStringContainsString('HTTPS (TCP 443)', $html);
        $this->assertStringContainsString('HTTP (TCP 80)', $html);
    }

    public function testScheduleCardFormatsRecurringWindow(): void
    {
        $html = $this->decode(ObjectTip::schedule([
            'name'         => 'Business_Hours',
            'type'         => 'recurring',
            'days_of_week' => 'mon,tue,wed,thu,fri',
            'start_time'   => '08:00:00',
            'end_time'     => '18:00:00',
        ]));

        $this->assertStringContainsString('Business_Hours', $html);
        $this->assertStringContainsString('Mon–Fri', $html);
        $this->assertStringContainsString('08:00 – 18:00', $html);
    }

    public function testScheduleCardHandlesAlways(): void
    {
        $html = $this->decode(ObjectTip::schedule(['name' => 'always']));

        $this->assertStringContainsString('Always active', $html);
    }

    public function testIfaceCardFormatsCidrAndState(): void
    {
        $html = $this->decode(ObjectTip::iface([
            'name'             => 'LAN',
            'physical_device'  => 'eth1',
            'ipv4_address'     => '192.168.1.1',
            'ipv4_netmask'     => 24,
            'ipv4_gateway'     => null,
            'ip_assignment_type' => 'static',
            'enabled'          => 1,
        ]));

        $this->assertStringContainsString('LAN', $html);
        $this->assertStringContainsString('eth1', $html);
        $this->assertStringContainsString('192.168.1.1/24', $html);
        $this->assertStringContainsString('Enabled', $html);
    }

    public function testIfaceCardShowsUnassignedWhenNoAddress(): void
    {
        $html = $this->decode(ObjectTip::iface([
            'name'            => 'WAN',
            'physical_device' => 'eth0',
            'ipv4_address'    => null,
            'enabled'         => 0,
        ]));

        $this->assertStringContainsString('unassigned', $html);
        $this->assertStringContainsString('Disabled', $html);
    }

    public function testAttributeValueIsEscaped(): void
    {
        $attr = ObjectTip::address([
            'name'    => 'X',
            'type'    => 'ipaddress',
            'value'   => '1.2.3.4',
            'comment' => '"><script>alert(1)</script>',
        ]);

        // The attribute value must not contain raw, executable markup, and must
        // not be terminated early by a quote in the data.
        $this->assertStringNotContainsString('<script>', $attr);
        $this->assertStringNotContainsString('"><script>', $attr);

        // After the browser decodes the attribute it still holds escaped text,
        // not a live <script> element.
        $decoded = $this->decode($attr);
        $this->assertStringNotContainsString('<script>', $decoded);
        $this->assertStringContainsString('alert(1)', $decoded);
    }
}
