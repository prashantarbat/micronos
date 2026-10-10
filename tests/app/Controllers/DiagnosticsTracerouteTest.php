<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Diagnostics → Traceroute (the pfSense field set).
 *
 * The page carries pfSense's Traceroute form — hostname, IP protocol, source
 * address, maximum number of hops and the Reverse Address Lookup and Use ICMP
 * switches — and the endpoint validates the request before the daemon ever sees
 * it, forwards every field, and returns the daemon's own output rather than the
 * simulated one.
 *
 * @internal
 */
final class DiagnosticsTracerouteTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'system.info.view',
        'diag.ping',
        'diag.logs.view',
        'diag.audit.view',
        'diag.arp.view',
        'diag.states.view',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
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

    public function testTheTracerouteTabRendersThePfSenseFieldSet(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->withSession($this->session())->get('diag');

        $result->assertOK();
        $body = (string) $result->getBody();

        // pfSense's Traceroute page, field for field.
        $this->assertStringContainsString('Maximum number of hops', $body);
        $this->assertStringContainsString('Reverse Address Lookup', $body);
        $this->assertStringContainsString('Use ICMP', $body);
        $this->assertStringContainsString('Select the protocol to use.', $body);
        $this->assertStringContainsString('Select source address for the trace.', $body);
        $this->assertStringContainsString('Select the maximum number of network hops to trace.', $body);
        // pfSense explains both switches in the same words.
        $this->assertStringContainsString('traceroute will attempt to perform a PTR lookup', $body);
        $this->assertStringContainsString('traceroute uses UDP but that may be blocked by some routers', $body);

        // The controls the button reads, and the button itself.
        foreach (['traceHost', 'traceProto', 'traceSource', 'traceTtl', 'traceResolve', 'traceIcmp', 'btnRunTrace'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $body);
        }
        $this->assertStringContainsString('type="checkbox" id="traceResolve"', $body);
        $this->assertStringContainsString('type="checkbox" id="traceIcmp"', $body);
        // pfSense's hop select runs to 64 and opens on 18.
        $this->assertStringContainsString('value="64"', $body);
        $this->assertStringContainsString('value="18" selected', $body);
    }

    /** pfSense's Source Address select opens on "Any", which means "let the OS choose". */
    public function testTheSourceSelectDefaultsToAny(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $body = (string) $this->withSession($this->session())->get('diag')->getBody();

        $this->assertStringContainsString('<option value="any">Any</option>', $body);
    }

    public function testTracerouteForwardsEveryFieldToTheDaemon(): void
    {
        $ipc = new FakeIpcClient([
            'diag.traceroute' => ['result' => ['output' => "traceroute to 8.8.8.8\n", 'returncode' => 0], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/traceroute', $this->csrf([
            'host'     => 'pfsense.org',
            'ipproto'  => 'ipv4',
            'sourceip' => '192.168.1.1',
            'ttl'      => '18',
            'resolve'  => 'yes',
            'useicmp'  => 'yes',
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['mock' => false]);

        $this->assertSame('diag.traceroute', $ipc->calls[0]['method']);
        $this->assertSame([
            'host'     => 'pfsense.org',
            'ipproto'  => 'ipv4',
            'sourceip' => '192.168.1.1',
            'ttl'      => 18,
            'resolve'  => true,
            'useicmp'  => true,
        ], $ipc->calls[0]['params']);
    }

    /** The switches are booleans, and an unchecked box posts nothing at all. */
    public function testTheSwitchesReachTheDaemonAsBooleans(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        foreach ([[], ['resolve' => '0', 'useicmp' => 'off'], ['resolve' => '', 'useicmp' => 'false']] as $switches) {
            $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1'] + $switches));
        }

        $this->assertCount(3, $ipc->calls);
        foreach ($ipc->calls as $call) {
            $this->assertFalse($call['params']['resolve']);
            $this->assertFalse($call['params']['useicmp']);
        }

        $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1', 'resolve' => 'on', 'useicmp' => '1']));
        $this->assertTrue($ipc->calls[3]['params']['resolve']);
        $this->assertTrue($ipc->calls[3]['params']['useicmp']);
    }

    /** A missing hop count takes pfSense's default of 18. */
    public function testAMissingHopCountTakesTheDefault(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1', 'ttl' => '']));

        $this->assertSame(18, $ipc->calls[0]['params']['ttl']);
    }

    /** "Any" is the select's default label, not an address. */
    public function testAnySourceIsSentAsNoSource(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1', 'sourceip' => 'any']));

        $this->assertSame('', $ipc->calls[0]['params']['sourceip']);
    }

    /**
     * The result lives under the IPC envelope's `result`; reading it from the
     * envelope's top level made every real response look absent and fall through
     * to the simulated output.
     */
    public function testTracerouteReturnsTheDaemonsOwnOutputRatherThanTheMock(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.traceroute' => ['result' => ['output' => 'trace output from the daemon', 'returncode' => 0], 'error' => null],
        ]));

        $result = $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1']));

        $result->assertOK();
        $this->assertSame(
            'trace output from the daemon',
            json_decode((string) $result->getJSON(), true)['output']
        );
    }

    public function testTracerouteRefusesInvalidInputWithoutCallingTheDaemon(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $cases = [
            'a missing host'            => [['host' => ''], 'Hostname is required.'],
            'a host that is not a name' => [['host' => 'not a host!'], 'Hostname must be a valid hostname or IP address.'],
            'a bare option as the host' => [['host' => '-f'], 'Hostname must be a valid hostname or IP address.'],
            'an unknown protocol'       => [['host' => '1.1.1.1', 'ipproto' => 'sctp'], 'IP Protocol must be IPv4 or IPv6.'],
            'an IPv6 host under IPv4'   => [['host' => '2606:4700::1111', 'ipproto' => 'ipv4'], 'When using IPv4, the target host must be an IPv4 address or hostname.'],
            'an IPv4 host under IPv6'   => [['host' => '1.1.1.1', 'ipproto' => 'ipv6'], 'When using IPv6, the target host must be an IPv6 address or hostname.'],
            'a hop count of zero'       => [['host' => '1.1.1.1', 'ttl' => '0'], 'Maximum number of hops must be between 1 and 64.'],
            'a hop count above 64'      => [['host' => '1.1.1.1', 'ttl' => '65'], 'Maximum number of hops must be between 1 and 64.'],
            'a source that is not an IP' => [['host' => '1.1.1.1', 'sourceip' => 'lan0'], 'Source address must be a valid IP address.'],
            'an option-shaped source'   => [['host' => '1.1.1.1', 'sourceip' => '-f'], 'Source address must be a valid IP address.'],
            'an IPv6 source under IPv4' => [['host' => '1.1.1.1', 'sourceip' => '2001:db8::1'], 'An IPv6 source address requires the IPv6 protocol.'],
            'an IPv4 source under IPv6' => [['host' => '2001:db8::1', 'ipproto' => 'ipv6', 'sourceip' => '192.168.1.1'], 'An IPv4 source address requires the IPv4 protocol.'],
        ];

        foreach ($cases as $label => [$payload, $message]) {
            $result = $this->post('diag/traceroute', $this->csrf($payload));

            $result->assertStatus(422);
            $decoded = json_decode((string) $result->getJSON(), true);
            $this->assertSame($message, $decoded['error'], $label);
            // The same text reaches the terminal, so the operator sees it.
            $this->assertSame($message, $decoded['output'], $label);
        }

        $this->assertSame([], $ipc->calls, 'No invalid request may reach the daemon.');
    }

    /** A request the daemon itself turns down is reported, not simulated. */
    public function testTracerouteSurfacesADaemonRefusal(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.traceroute' => ['result' => ['success' => false, 'message' => 'Maximum number of hops must be between 1 and 64'], 'error' => null],
        ]));

        $result = $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1']));

        $result->assertStatus(422);
        $result->assertJSONFragment(['error' => 'Maximum number of hops must be between 1 and 64']);
    }

    /** Every response hands back the next CSRF hash, refusals included. */
    public function testEveryTracerouteResponseCarriesTheNextCsrfHash(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.traceroute' => ['result' => ['output' => "1  192.168.1.1\n", 'returncode' => 0], 'error' => null],
        ]));

        $ok = $this->post('diag/traceroute', $this->csrf(['host' => '1.1.1.1']));
        $ok->assertOK();
        $this->assertNotEmpty(json_decode((string) $ok->getJSON(), true)['csrf'] ?? null, 'A success must carry the next hash.');

        $refused = $this->post('diag/traceroute', $this->csrf(['host' => '']));
        $refused->assertStatus(422);
        $this->assertNotEmpty(json_decode((string) $refused->getJSON(), true)['csrf'] ?? null, 'A refusal must carry it too.');
    }

    /** With no daemon (the dev box) the simulated trace still reflects the form. */
    public function testTracerouteFallsBackToSimulatedOutputWhenTheDaemonIsUnreachable(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->post('diag/traceroute', $this->csrf([
            'host'    => '2001:db8::5',
            'ipproto' => 'ipv6',
            'ttl'     => '2',
            'useicmp' => 'yes',
        ]));

        $result->assertOK();
        $output = json_decode((string) $result->getJSON(), true)['output'];

        // Two hops were asked for, so two hop lines are shown.
        $this->assertSame(2, preg_match_all('/^\s+\d+\s/m', $output));
        $this->assertStringContainsString('2001:db8::5', $output);
        $this->assertStringContainsString('ICMP', $output);
        // Reverse lookup is off, so no hop carries a name.
        $this->assertStringNotContainsString('isp-edge.net', $output);
    }

    /** With the switch on, the simulated trace shows the PTR names. */
    public function testTheReverseLookupSwitchChangesTheSimulatedOutput(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $named = $this->post('diag/traceroute', $this->csrf([
            'host'    => '8.8.8.8',
            'ttl'     => '3',
            'resolve' => 'yes',
        ]));

        $output = json_decode((string) $named->getJSON(), true)['output'];

        $this->assertStringContainsString('gateway.local (192.168.1.1)', $output);
        $this->assertSame(3, preg_match_all('/^\s+\d+\s/m', $output));
    }
}
