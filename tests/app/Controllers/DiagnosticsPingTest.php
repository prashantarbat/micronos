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
 * Diagnostics → Ping (the pfSense field set).
 *
 * The page carries pfSense's Ping form — hostname, IP protocol, source address,
 * maximum number of pings and seconds between them — and the endpoint validates
 * the request before the daemon ever sees it, forwards every field, and returns
 * the daemon's own output rather than the simulated one.
 *
 * @internal
 */
final class DiagnosticsPingTest extends BaseTestCase
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

    public function testThePingTabRendersThePfSenseFieldSet(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->withSession($this->session())->get('diag');

        $result->assertOK();
        $body = (string) $result->getBody();

        // pfSense's Ping page, field for field.
        $this->assertStringContainsString('Hostname', $body);
        $this->assertStringContainsString('IP Protocol', $body);
        $this->assertStringContainsString('Source address', $body);
        $this->assertStringContainsString('Maximum number of pings', $body);
        $this->assertStringContainsString('Seconds between pings', $body);
        $this->assertStringContainsString('Automatically selected (default)', $body);
        // The controls the button reads, and the button itself.
        foreach (['pingHost', 'pingProto', 'pingSource', 'pingCount', 'pingWait', 'btnRunPing'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $body);
        }
        $this->assertStringContainsString('>IPv4<', $body);
        $this->assertStringContainsString('>IPv6<', $body);
    }

    public function testPingForwardsEveryFieldToTheDaemon(): void
    {
        $ipc = new FakeIpcClient([
            'diag.ping' => ['result' => ['output' => "PING 10.0.100.51\n", 'returncode' => 0], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/ping', $this->csrf([
            'host'     => '10.0.100.51',
            'ipproto'  => 'ipv4',
            'sourceip' => '192.168.1.1',
            'count'    => '3',
            'wait'     => '1',
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['mock' => false]);

        $this->assertSame('diag.ping', $ipc->calls[0]['method']);
        $this->assertSame([
            'host'     => '10.0.100.51',
            'ipproto'  => 'ipv4',
            'sourceip' => '192.168.1.1',
            'count'    => 3,
            'wait'     => 1,
        ], $ipc->calls[0]['params']);
    }

    /**
     * The result lives under the IPC envelope's `result`; reading it from the
     * envelope's top level made every real response look absent and fall through
     * to the simulated output — the page worked on the dev box and nowhere else.
     */
    public function testPingReturnsTheDaemonsOwnOutputRatherThanTheMock(): void
    {
        $ipc = new FakeIpcClient([
            'diag.ping' => ['result' => ['output' => 'PING output from the daemon', 'returncode' => 0], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/ping', $this->csrf(['host' => '1.1.1.1']));

        $result->assertOK();
        $this->assertSame(
            'PING output from the daemon',
            json_decode((string) $result->getJSON(), true)['output']
        );
    }

    public function testPingRefusesInvalidInputWithoutCallingTheDaemon(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $cases = [
            'a missing host'               => [['host' => ''], 'Hostname is required.'],
            'a host that is not a name'    => [['host' => 'not a host!'], 'Hostname must be a valid hostname or IP address.'],
            'an unknown protocol'          => [['host' => '1.1.1.1', 'ipproto' => 'sctp'], 'IP Protocol must be IPv4 or IPv6.'],
            'an IPv6 host under IPv4'      => [['host' => 'fe80::1', 'ipproto' => 'ipv4'], 'When using IPv4, the target host must be an IPv4 address or hostname.'],
            'an IPv4 host under IPv6'      => [['host' => '1.1.1.1', 'ipproto' => 'ipv6'], 'When using IPv6, the target host must be an IPv6 address or hostname.'],
            'a count above the maximum'    => [['host' => '1.1.1.1', 'count' => '99'], 'Maximum number of pings must be between 1 and 10.'],
            'a count of zero'              => [['host' => '1.1.1.1', 'count' => '0'], 'Maximum number of pings must be between 1 and 10.'],
            'a wait above the maximum'     => [['host' => '1.1.1.1', 'wait' => '11'], 'Seconds between pings must be between 1 and 10.'],
            'a source that is not an IP'   => [['host' => '1.1.1.1', 'sourceip' => 'lan0'], 'Source address must be a valid IP address.'],
            'an IPv6 source under IPv4'    => [['host' => '1.1.1.1', 'sourceip' => '2001:db8::1'], 'An IPv6 source address requires the IPv6 protocol.'],
            'an IPv4 source under IPv6'    => [['host' => '2001:db8::1', 'ipproto' => 'ipv6', 'sourceip' => '192.168.1.1'], 'An IPv4 source address requires the IPv4 protocol.'],
        ];

        foreach ($cases as $label => [$payload, $message]) {
            $result = $this->post('diag/ping', $this->csrf($payload));

            $result->assertStatus(422);
            $decoded = json_decode((string) $result->getJSON(), true);
            $this->assertSame($message, $decoded['error'], $label);
            // The same text reaches the terminal, so the operator sees it.
            $this->assertSame($message, $decoded['output'], $label);
        }

        $this->assertSame([], $ipc->calls, 'No invalid request may reach the daemon.');
    }

    /** A request the daemon itself turns down is reported, not simulated. */
    public function testPingSurfacesADaemonRefusal(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.ping' => ['result' => ['success' => false, 'message' => 'IP protocol must be IPv4 or IPv6'], 'error' => null],
        ]));

        $result = $this->post('diag/ping', $this->csrf(['host' => '1.1.1.1']));

        $result->assertStatus(422);
        $result->assertJSONFragment(['error' => 'IP protocol must be IPv4 or IPv6']);
    }

    /**
     * Every response hands back the next CSRF hash.
     *
     * Regeneration is enabled and the CSRF cookie is `HttpOnly`, so the hash the
     * page rendered is spent by its first request and JavaScript cannot read the
     * replacement. A response without it leaves the page unable to run a second
     * probe — any tool, not just ping.
     */
    public function testEveryPingResponseCarriesTheNextCsrfHash(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.ping' => ['result' => ['output' => 'ok\n', 'returncode' => 0], 'error' => null],
        ]));

        $ok = $this->post('diag/ping', $this->csrf(['host' => '1.1.1.1']));
        $ok->assertOK();
        $this->assertNotEmpty(json_decode((string) $ok->getJSON(), true)['csrf'] ?? null, 'A success must carry the next hash.');

        $refused = $this->post('diag/ping', $this->csrf(['host' => '']));
        $refused->assertStatus(422);
        $this->assertNotEmpty(json_decode((string) $refused->getJSON(), true)['csrf'] ?? null, 'A refusal must carry it too.');
    }

    /** The page adopts that hash; the HttpOnly cookie is not readable from JS. */
    public function testThePageAdoptsTheNextCsrfHashFromTheResponseBody(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $body = (string) $this->withSession($this->session())->get('diag')->getBody();

        $this->assertStringContainsString('data.csrf) CSRF_HASH = data.csrf;', $body);
        $this->assertStringNotContainsString('csrf_cookie_name', $body);
    }

    /** With no daemon (the dev box) the simulated output still reflects the form. */
    public function testPingFallsBackToSimulatedOutputWhenTheDaemonIsUnreachable(): void
    {
        Services::injectMock('ipc', (new FakeIpcClient())->asDevelopmentMock());

        $result = $this->post('diag/ping', $this->csrf([
            'host'     => '2001:db8::5',
            'ipproto'  => 'ipv6',
            'sourceip' => '2001:db8::2',
            'count'    => '2',
            'wait'     => '1',
        ]));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);

        $this->assertStringContainsString('PING6 2001:db8::5', $decoded['output']);
        $this->assertStringContainsString('from 2001:db8::2', $decoded['output']);
        // Two probes were asked for, so two replies are shown.
        $this->assertSame(2, substr_count($decoded['output'], 'icmp_seq='));
        $this->assertStringContainsString('2 packets transmitted, 2 received', $decoded['output']);
    }
}
