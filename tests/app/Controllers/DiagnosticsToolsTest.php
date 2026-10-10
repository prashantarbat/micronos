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
 * The remaining Diagnostics tools and their IPC envelope.
 *
 * DNS Lookup, Packet Capture, Route Lookup, the Firewall Logs stream, the
 * Connection States table and the Kernel Routing / NDP tables all read the
 * daemon's payload out of the IPC envelope's `result`. The pages used to look
 * for `output`/`events`/`states`/`routes`/`neighbors` on the envelope itself,
 * so on a live appliance — where the daemon actually runs the command — every
 * response looked absent and the page silently showed simulated output.
 *
 * @internal
 */
final class DiagnosticsToolsTest extends BaseTestCase
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

    public function testDnsLookupReturnsTheDaemonsOwnOutput(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.dns_lookup' => ['result' => ['output' => 'DAEMON-DNS-OUTPUT-42', 'returncode' => 0], 'error' => null],
        ]));

        $result = $this->post('diag/dns-lookup', $this->csrf(['name' => 'cloudflare.com', 'type' => 'A']));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame('DAEMON-DNS-OUTPUT-42', $decoded['output']);
        $this->assertFalse($decoded['mock']);
    }

    public function testPacketCaptureReturnsTheDaemonsOwnOutput(): void
    {
        $ipc = new FakeIpcClient([
            'diag.tcpdump' => ['result' => ['output' => 'DAEMON-CAPTURE-OUTPUT-42', 'returncode' => 0], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/packet-capture', $this->csrf(['interface' => 'eth0', 'filter' => 'icmp', 'packets' => '5']));

        $result->assertOK();
        $this->assertSame('DAEMON-CAPTURE-OUTPUT-42', json_decode((string) $result->getJSON(), true)['output']);
        $this->assertSame('diag.tcpdump', $ipc->calls[0]['method']);
    }

    public function testRouteLookupReturnsTheDaemonsOwnOutput(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.route_lookup' => ['result' => ['output' => 'DAEMON-ROUTE-OUTPUT-42', 'returncode' => 0], 'error' => null],
        ]));

        $result = $this->post('diag/route-lookup', $this->csrf(['target' => '8.8.8.8']));

        $result->assertOK();
        $this->assertSame('DAEMON-ROUTE-OUTPUT-42', json_decode((string) $result->getJSON(), true)['output']);
    }

    public function testTheFirewallLogStreamReturnsTheDaemonsOwnEvents(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.firewall_logs' => ['result' => ['events' => [
                ['time' => '12:34:56', 'action' => 'block', 'iface' => 'wan', 'proto' => 'tcp', 'src' => '198.51.100.9', 'sport' => 4001, 'dst' => '203.0.113.10', 'dport' => 22, 'rule_id' => 0, 'rule' => 'DAEMON-EVENT-42', 'flags' => '—'],
            ]], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/logs/data');

        $result->assertOK();
        $events = json_decode((string) $result->getJSON(), true)['events'];
        $this->assertSame('DAEMON-EVENT-42', $events[0]['rule']);
    }

    public function testTheConnectionStatesTableReturnsTheDaemonsOwnStates(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.conntrack_states' => ['result' => ['states' => [
                ['iface' => 'lan', 'proto' => 'tcp', 'src' => '10.9.9.9', 'sport' => 1234, 'dst' => '1.2.3.4', 'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 7, 'bytes' => 700, 'ttl' => '11s'],
            ]], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/states/data');

        $result->assertOK();
        $states = json_decode((string) $result->getJSON(), true)['states'];
        $this->assertSame('10.9.9.9', $states[0]['src']);
        $this->assertCount(1, $states);
    }

    public function testTheRoutingTableComesFromTheDaemon(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.routing_table' => ['result' => ['routes' => [
                ['destination' => '203.0.113.0/24', 'gateway' => '198.51.100.77', 'interface' => 'eth9', 'metric' => 7, 'protocol' => 'static', 'flags' => 'UG'],
            ]], 'error' => null],
        ]));

        $body = (string) $this->withSession($this->session())->get('diag')->getBody();

        // The daemon's single row is shown instead of the simulated six.
        $this->assertStringContainsString('198.51.100.77', $body);
        $this->assertStringNotContainsString('192.168.20.0/24', $body, 'The simulated table must not be shown when the daemon answers.');
    }

    public function testTheNdpTableComesFromTheDaemon(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.ndp_table' => ['result' => ['neighbors' => [
                ['ip' => '2001:db8:dead::1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'interface' => 'eth7', 'state' => 'REACHABLE'],
            ]], 'error' => null],
        ]));

        $body = (string) $this->withSession($this->session())->get('diag')->getBody();

        $this->assertStringContainsString('2001:db8:dead::1', $body);
    }

    /** With no daemon (the dev box) every tool still answers with its simulation. */
    public function testEachToolFallsBackToItsSimulationWhenTheDaemonIsUnreachable(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $dns = json_decode((string) $this->post('diag/dns-lookup', $this->csrf(['name' => 'cloudflare.com', 'type' => 'A']))->getJSON(), true);
        $this->assertStringContainsString('DiG', $dns['output']);

        $capture = json_decode((string) $this->post('diag/packet-capture', $this->csrf(['interface' => 'eth0', 'filter' => 'icmp']))->getJSON(), true);
        $this->assertStringContainsString('tcpdump', $capture['output']);

        $route = json_decode((string) $this->post('diag/route-lookup', $this->csrf(['target' => '8.8.8.8']))->getJSON(), true);
        $this->assertStringContainsString('FIB Route Decision', $route['output']);
    }

    // ── Live packet capture (the streamed runner) ──────────────────────────

    /** The page ships the live runner: the button, the stop control and the poll wiring. */
    public function testTheDiagnosticsPageRendersTheLiveCaptureRunner(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $body = (string) $this->withSession($this->session())->get('diag')->getBody();

        $this->assertStringContainsString('id="btnRunPcap"', $body);
        $this->assertStringContainsString('id="btnStopTerminal"', $body);
        $this->assertStringContainsString("MicronScheduler.poll('diag.pcap'", $body);
        $this->assertStringContainsString("diag/packet-capture/start", $body);
        $this->assertStringContainsString("diag/packet-capture/stream?session=", $body);
    }

    public function testLiveCaptureStartForwardsTheFormAndReturnsTheSession(): void
    {
        $ipc = new FakeIpcClient([
            'diag.capture_start' => ['result' => [
                'session' => self::SESSION_ID,
                'running' => true,
                'output'  => '',
                'cmd'     => 'timeout 120 tcpdump -i eth0 -n -l -c 10 icmp',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/packet-capture/start', $this->csrf([
            'interface' => 'eth0',
            'filter'    => 'icmp',
            'packets'   => '10',
        ]));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame(self::SESSION_ID, $decoded['session']);
        $this->assertTrue($decoded['running']);
        $this->assertSame('diag.capture_start', $ipc->calls[0]['method']);
        $this->assertSame(['interface' => 'eth0', 'filter' => 'icmp', 'count' => 10], $ipc->calls[0]['params']);
    }

    /** The interface, the frame count and the filter text are checked here, before the daemon. */
    public function testLiveCaptureStartValidatesTheFilterAndInterfaceOnTheServer(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $cases = [
            'an interface that reads as an option' => [['interface' => '-i'], 'Interface name is not valid.'],
            'a shell-ish interface'                => [['interface' => 'eth0;rm'], 'Interface name is not valid.'],
            'a frame count of zero'                => [['interface' => 'eth0', 'packets' => '0'], 'Frame count must be between 1 and 50.'],
            'a frame count above the cap'          => [['interface' => 'eth0', 'packets' => '51'], 'Frame count must be between 1 and 50.'],
            'a filter with a newline'              => [['interface' => 'eth0', 'filter' => "icmp\nsh"], 'Capture filter is not valid.'],
            'an over-long filter'                  => [['interface' => 'eth0', 'filter' => str_repeat('a', 513)], 'Capture filter is not valid.'],
        ];

        foreach ($cases as $label => [$payload, $message]) {
            $result = $this->post('diag/packet-capture/start', $this->csrf($payload));

            $result->assertStatus(422);
            $this->assertSame($message, json_decode((string) $result->getJSON(), true)['error'], $label);
        }

        $this->assertSame([], $ipc->calls, 'No invalid capture request may reach the daemon.');
    }

    /** The daemon compiles the BPF expression; its refusal reaches the operator verbatim. */
    public function testLiveCaptureStartSurfacesABpfRefusalFromTheDaemon(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.capture_start' => ['result' => ['success' => false, 'message' => 'BPF filter is not valid: syntax error'], 'error' => null],
        ]));

        $result = $this->post('diag/packet-capture/start', $this->csrf(['interface' => 'eth0', 'filter' => 'tcp port ']));

        $result->assertStatus(422);
        $this->assertSame('BPF filter is not valid: syntax error', json_decode((string) $result->getJSON(), true)['error']);
    }

    public function testLiveCaptureStreamReturnsTheNewOutput(): void
    {
        $ipc = new FakeIpcClient([
            'diag.capture_poll' => ['result' => [
                'output' => '12:00:01.1 IP 1.1.1.1 > 2.2.2.2: ICMP echo request',
                'running' => true,
                'returncode' => null,
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('diag/packet-capture/stream?session=' . self::SESSION_ID);

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertStringContainsString('ICMP echo request', $decoded['output']);
        $this->assertTrue($decoded['running']);
        $this->assertSame('diag.capture_poll', $ipc->calls[0]['method']);
        $this->assertSame(['session' => self::SESSION_ID], $ipc->calls[0]['params']);
    }

    public function testLiveCaptureStreamRefusesAnUnknownSessionWithoutCallingTheDaemon(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('diag/packet-capture/stream?session=not-a-session');

        $result->assertStatus(422);
        $this->assertSame([], $ipc->calls);
    }

    public function testLiveCaptureStreamReportsASessionTheDaemonHasReaped(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.capture_poll' => ['result' => ['success' => false, 'message' => 'no such capture session'], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/packet-capture/stream?session=' . self::SESSION_ID);

        $result->assertStatus(404);
    }

    public function testLiveCaptureStopReturnsTheTail(): void
    {
        $ipc = new FakeIpcClient([
            'diag.capture_stop' => ['result' => ['output' => '10 packets captured', 'running' => false, 'stopped' => true], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/packet-capture/stop', $this->csrf(['session' => self::SESSION_ID]));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame('10 packets captured', $decoded['output']);
        $this->assertFalse($decoded['running']);
        $this->assertSame('diag.capture_stop', $ipc->calls[0]['method']);
    }

    // ── Port probe and DNS benchmark (the last two tools with no daemon path) ──

    public function testPortProbeReturnsTheDaemonsOwnOutput(): void
    {
        $ipc = new FakeIpcClient([
            'diag.port_probe' => ['result' => ['output' => 'DAEMON-PORT-OUTPUT-42', 'status' => 'open', 'rtt' => 12.5], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/port-probe', $this->csrf(['host' => '1.1.1.1', 'port' => '443', 'protocol' => 'tcp']));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame('DAEMON-PORT-OUTPUT-42', $decoded['output']);
        $this->assertSame('open', $decoded['status']);
        $this->assertFalse($decoded['mock']);
        $this->assertSame('diag.port_probe', $ipc->calls[0]['method']);
        $this->assertSame(
            ['host' => '1.1.1.1', 'port' => 443, 'protocol' => 'tcp', 'timeout' => 2.5],
            $ipc->calls[0]['params']
        );
    }

    public function testPortProbeRefusesAnInvalidHostWithoutCallingTheDaemon(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        foreach (['', 'not a host!', '-f'] as $host) {
            $result = $this->post('diag/port-probe', $this->csrf(['host' => $host, 'port' => '443']));

            $result->assertStatus(422);
            $this->assertSame(
                'Hostname must be a valid hostname or IP address.',
                json_decode((string) $result->getJSON(), true)['error']
            );
        }

        $this->assertSame([], $ipc->calls, 'No invalid probe may reach the daemon.');
    }

    public function testPortProbeSurfacesADaemonRefusal(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.port_probe' => ['result' => ['success' => false, 'message' => 'Port must be between 1 and 65535'], 'error' => null],
        ]));

        $result = $this->post('diag/port-probe', $this->csrf(['host' => '1.1.1.1', 'port' => '443']));

        $result->assertStatus(422);
        $this->assertSame('Port must be between 1 and 65535', json_decode((string) $result->getJSON(), true)['error']);
    }

    public function testDnsBenchmarkReturnsTheDaemonsOwnOutput(): void
    {
        $ipc = new FakeIpcClient([
            'diag.dns_benchmark' => ['result' => ['output' => 'DAEMON-BENCH-OUTPUT-42'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/dns-benchmark', $this->csrf(['domain' => 'cloudflare.com']));

        $result->assertOK();
        $decoded = json_decode((string) $result->getJSON(), true);
        $this->assertSame('DAEMON-BENCH-OUTPUT-42', $decoded['output']);
        $this->assertFalse($decoded['mock']);
        $this->assertSame('diag.dns_benchmark', $ipc->calls[0]['method']);
        $this->assertSame(['domain' => 'cloudflare.com'], $ipc->calls[0]['params']);
    }

    public function testDnsBenchmarkRefusesAnInvalidDomainWithoutCallingTheDaemon(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('diag/dns-benchmark', $this->csrf(['domain' => 'not a domain!']));

        $result->assertStatus(422);
        $this->assertSame(
            'Hostname must be a valid hostname or IP address.',
            json_decode((string) $result->getJSON(), true)['error']
        );
        $this->assertSame([], $ipc->calls);
    }

    /**
     * A flow on a port that is not assigned to any interface keeps the raw device
     * name — pfSense's own fallback (`$interfaces[$state['if']] ?? $state['if']`)
     * rather than a fabricated label.
     */
    public function testAnUnassignedInterfaceKeepsItsRawDeviceName(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.conntrack_states' => ['result' => ['states' => [
                ['iface' => 'eth7', 'proto' => 'tcp', 'src' => '10.9.9.9', 'sport' => 1234, 'dst' => '1.2.3.4', 'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 7, 'bytes' => 700, 'ttl' => '11s'],
            ]], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/states/data');

        $result->assertOK();
        $this->assertSame('eth7', json_decode((string) $result->getJSON(), true)['states'][0]['iface']);
    }

    /** The States table shows the configured description, not the raw Linux device. */
    public function testTheStatesInterfaceIsShownAsItsFriendlyName(): void
    {
        $this->db->table('interfaces')->insert([
            'id'                 => 'teststates',
            'name'               => 'TESTNET',
            'physical_device'    => 'eth77',
            'enabled'            => 1,
            'ip_assignment_type' => 'static',
            'mtu'                => 1500,
        ]);

        try {
            Services::injectMock('ipc', new FakeIpcClient([
                'diag.conntrack_states' => ['result' => ['states' => [
                    ['iface' => 'eth77', 'proto' => 'tcp', 'src' => '10.9.9.9', 'sport' => 1234, 'dst' => '1.2.3.4', 'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 7, 'bytes' => 700, 'ttl' => '11s'],
                ]], 'error' => null],
            ]));

            $result = $this->withSession($this->session())->get('diag/states/data');

            $result->assertOK();
            $states = json_decode((string) $result->getJSON(), true)['states'];
            // The raw device the daemon reported is replaced by the description.
            $this->assertSame('TESTNET', $states[0]['iface']);
        } finally {
            $this->db->table('interfaces')->where('id', 'teststates')->delete();
        }
    }

    /** Both exports carry the friendly name, never the raw device. */
    public function testTheStatesExportCarriesTheFriendlyInterfaceName(): void
    {
        $this->db->table('interfaces')->insert([
            'id'                 => 'teststates',
            'name'               => 'TESTNET',
            'physical_device'    => 'eth77',
            'enabled'            => 1,
            'ip_assignment_type' => 'static',
            'mtu'                => 1500,
        ]);

        try {
            Services::injectMock('ipc', new FakeIpcClient([
                'diag.conntrack_states' => ['result' => ['states' => [
                    ['iface' => 'eth77', 'proto' => 'tcp', 'src' => '10.9.9.9', 'sport' => 1234, 'dst' => '1.2.3.4', 'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 7, 'bytes' => 700, 'ttl' => '11s'],
                ]], 'error' => null],
            ]));

            $csv = (string) $this->withSession($this->session())->get('diag/states/export-csv')->getBody();
            $this->assertStringContainsString('TESTNET', $csv);
            $this->assertStringNotContainsString('eth77', $csv, 'The raw device must not survive into the export.');

            // The FeatureTest response carries the debug toolbar around the body,
            // as it does for the CSV export above, so the payload is matched as text.
            $json = (string) $this->withSession($this->session())->get('diag/states/export-json')->getBody();
            $this->assertStringContainsString('"iface": "TESTNET"', $json);
            $this->assertStringNotContainsString('eth77', $json, 'The raw device must not survive into the export.');
        } finally {
            $this->db->table('interfaces')->where('id', 'teststates')->delete();
        }
    }

    /** A flow with no interface resolved exports an empty column, never a guessed device. */
    public function testTheStatesExportDoesNotInventAnInterface(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.conntrack_states' => ['result' => ['states' => [
                // No `iface` at all: the old export filled that with `eth0`.
                ['proto' => 'tcp', 'src' => '10.9.9.9', 'sport' => 1234, 'dst' => '1.2.3.4', 'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 7, 'bytes' => 700, 'ttl' => '11s'],
            ]], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('diag/states/export-csv');

        $result->assertOK();
        $csv = (string) $result->getBody();
        $this->assertStringContainsString('10.9.9.9', $csv);
        $this->assertStringNotContainsString('eth0', $csv, 'An unresolved interface must not be exported as a guessed device.');
    }

    /** The States page must not paint a device name the daemon never reported. */
    public function testTheStatesPageNeverFabricatesAnInterface(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/diag/states.php');

        $this->assertStringNotContainsString("'eth0'", $view);
    }

    /** Every response hands back the next CSRF hash, so the page's next probe is not refused. */
    public function testEachToolResponseCarriesTheNextCsrfHash(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'diag.dns_lookup' => ['result' => ['output' => 'dig output', 'returncode' => 0], 'error' => null],
        ]));

        $result = $this->post('diag/dns-lookup', $this->csrf(['name' => 'cloudflare.com']));

        $this->assertNotEmpty(json_decode((string) $result->getJSON(), true)['csrf'] ?? null);
    }
}
