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
 * Diagnostics → Ping / Traceroute, streamed.
 *
 * A ping prints a line per reply and a traceroute a line per hop, and both can
 * run for a minute or more. Instead of making the page wait for the whole
 * command, each is a session — `start` opens it, `stream` hands back only the
 * bytes produced since the previous poll, and `stop` closes it early — so the
 * terminal fills in as the daemon produces output. The start endpoints validate
 * the same field sets the one-shot forms do, and read the daemon's payload out
 * of the IPC envelope's `result` like every other tool.
 *
 * @internal
 */
final class DiagnosticsProbeStreamTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'system.info.view',
        'diag.ping',
        'diag.packet_capture',
        'diag.logs.view',
        'diag.audit.view',
        'diag.arp.view',
        'diag.states.view',
    ];

    private const SESSION_ID = 'a1b2c3d4e5f60718';

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

    // ── Streamed ping ──────────────────────────────────────────────────────

    public function testPingStartForwardsTheFormAndReturnsTheSession(): void
    {
        $ipc = new FakeIpcClient([
            'diag.ping_start' => ['result' => [
                'session' => self::SESSION_ID,
                'running' => true,
                'output'  => '',
                'cmd'     => 'timeout 300 stdbuf -oL -eL ping -c 3 -i 1 -W 2 1.1.1.1',
                'kind'    => 'ping',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/ping/start', $this->csrf([
            'host'    => '1.1.1.1',
            'ipproto' => 'ipv4',
            'count'   => '3',
            'wait'    => '1',
        ]));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame(self::SESSION_ID, $decoded['session']);
        $this->assertTrue($decoded['running']);
        $this->assertFalse($decoded['mock']);
        $this->assertSame('diag.ping_start', $ipc->calls[0]['method']);
        $this->assertSame(
            ['host' => '1.1.1.1', 'ipproto' => 'ipv4', 'sourceip' => '', 'count' => 3, 'wait' => 1],
            $ipc->calls[0]['params']
        );
    }

    /** The same field set the one-shot form checks, before the daemon sees it. */
    public function testPingStartValidatesTheFormOnTheServer(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $cases = [
            'a missing hostname'      => [['host' => ''], 'Hostname is required.'],
            'an unknown protocol'     => [['host' => '1.1.1.1', 'ipproto' => 'ipx'], 'IP Protocol must be IPv4 or IPv6.'],
            'an IPv6 target as IPv4'  => [['host' => '::1', 'ipproto' => 'ipv4'], 'When using IPv4, the target host must be an IPv4 address or hostname.'],
            'a count of zero'         => [['host' => '1.1.1.1', 'count' => '0'], 'Maximum number of pings must be between 1 and 10.'],
            'a count above the cap'   => [['host' => '1.1.1.1', 'count' => '11'], 'Maximum number of pings must be between 1 and 10.'],
            'a wait above the cap'    => [['host' => '1.1.1.1', 'wait' => '11'], 'Seconds between pings must be between 1 and 10.'],
            'a bare-option hostname'  => [['host' => '-f'], 'Hostname must be a valid hostname or IP address.'],
        ];

        foreach ($cases as $label => [$payload, $message]) {
            $result = $this->post('diag/ping/start', $this->csrf($payload));

            $result->assertStatus(422);
            $this->assertSame($message, json_decode((string) $result->getJSON(), true)['error'], $label);
        }

        $this->assertSame([], $ipc->calls, 'No invalid ping may reach the daemon.');
    }

    public function testPingStartSurfacesADaemonRefusal(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.ping_start' => ['result' => ['success' => false, 'message' => 'IP protocol must be IPv4 or IPv6'], 'error' => null],
        ]));

        $result = $this->post('diag/ping/start', $this->csrf(['host' => '1.1.1.1']));

        $result->assertStatus(422);
        $this->assertSame('IP protocol must be IPv4 or IPv6', json_decode((string) $result->getJSON(), true)['error']);
    }

    // ── Streamed traceroute ────────────────────────────────────────────────

    public function testTracerouteStartForwardsEveryFieldAndReturnsTheSession(): void
    {
        $ipc = new FakeIpcClient([
            'diag.traceroute_start' => ['result' => [
                'session' => self::SESSION_ID,
                'running' => true,
                'output'  => '',
                'cmd'     => 'timeout 300 stdbuf -oL -eL traceroute -n -w 2 -I -m 18 8.8.8.8',
                'kind'    => 'traceroute',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/traceroute/start', $this->csrf([
            'host'     => '8.8.8.8',
            'ipproto'  => 'ipv4',
            'sourceip' => '',
            'ttl'      => '18',
            'resolve'  => '1',
            'useicmp'  => 'on',
        ]));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame(self::SESSION_ID, $decoded['session']);
        $this->assertTrue($decoded['running']);
        $this->assertFalse($decoded['mock']);
        $this->assertSame('diag.traceroute_start', $ipc->calls[0]['method']);
        $this->assertSame(
            ['host' => '8.8.8.8', 'ipproto' => 'ipv4', 'sourceip' => '', 'ttl' => 18, 'resolve' => true, 'useicmp' => true],
            $ipc->calls[0]['params']
        );
    }

    public function testTracerouteStartValidatesTheHopLimitOnTheServer(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/traceroute/start', $this->csrf(['host' => '8.8.8.8', 'ttl' => '65']));

        $result->assertStatus(422);
        $this->assertSame(
            'Maximum number of hops must be between 1 and 64.',
            json_decode((string) $result->getJSON(), true)['error']
        );
        $this->assertSame([], $ipc->calls);
    }

    // ── The shared stream poll / stop ──────────────────────────────────────

    public function testStreamReturnsTheNewOutputSinceTheLastPoll(): void
    {
        $ipc = new FakeIpcClient([
            'diag.stream_poll' => ['result' => [
                'output'     => '64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=4.12 ms',
                'running'    => true,
                'returncode' => null,
                'kind'       => 'ping',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('diag/stream?session=' . self::SESSION_ID);

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertStringContainsString('icmp_seq=1', $decoded['output']);
        $this->assertTrue($decoded['running']);
        $this->assertSame('diag.stream_poll', $ipc->calls[0]['method']);
        $this->assertSame(['session' => self::SESSION_ID], $ipc->calls[0]['params']);
    }

    public function testStreamRefusesAnUnknownSessionWithoutCallingTheDaemon(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('diag/stream?session=not-a-session');

        $result->assertStatus(422);
        $this->assertSame([], $ipc->calls);
    }

    public function testStreamReportsASessionTheDaemonHasReaped(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.stream_poll' => ['result' => ['success' => false, 'message' => 'no such diagnostics session'], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/stream?session=' . self::SESSION_ID);

        $result->assertStatus(404);
    }

    public function testStreamStopReturnsTheTail(): void
    {
        $ipc = new FakeIpcClient([
            'diag.stream_stop' => ['result' => ['output' => '3 packets transmitted, 3 received', 'running' => false, 'stopped' => true], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/stream/stop', $this->csrf(['session' => self::SESSION_ID]));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame('3 packets transmitted, 3 received', $decoded['output']);
        $this->assertFalse($decoded['running']);
        $this->assertSame('diag.stream_stop', $ipc->calls[0]['method']);
    }

    /** Every stream response hands back the next CSRF hash, so the next poll is not refused. */
    public function testStreamResponsesCarryTheNextCsrfHash(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.stream_poll' => ['result' => ['output' => '', 'running' => true], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/stream?session=' . self::SESSION_ID);

        $this->assertNotEmpty(json_decode((string) $result->getJSON(), true)['csrf'] ?? null);
    }

    // ── The page ships the runner ──────────────────────────────────────────

    public function testTheDiagnosticsPageShipsTheStreamedProbeRunner(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $body = (string) $this->withSession($this->session())->get('diag')->getBody();

        $this->assertStringContainsString('diag/ping/start', $body);
        $this->assertStringContainsString('diag/traceroute/start', $body);
        $this->assertStringContainsString("MicronScheduler.poll('diag.probe'", $body);
        $this->assertStringContainsString('diag/stream?session=', $body);
        $this->assertStringContainsString('diag/stream/stop', $body);
    }
}
