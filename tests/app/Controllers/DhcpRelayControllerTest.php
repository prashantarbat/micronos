<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * DHCP Relay live per-instance controls: the page renders the shared service
 * widget pointed at the relay JSON endpoints; the status poll maps each
 * `dhcp-helper@<slug>` unit's state and the control endpoint dispatches
 * `dhcp.relay_service` for a compiled slug — refusing unknown slugs and
 * unsupported actions before any daemon call.
 *
 * @internal
 */
final class DhcpRelayControllerTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['dhcp.view', 'dhcp.edit', 'dhcp.restart'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        foreach (['dhcp_relay_interfaces', 'dhcp_relay_destinations', 'dhcp_server_settings'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->emptyTable();
            }
        }
    }

    private function seedRelay(): void
    {
        $this->db->table('dhcp_relay_destinations')->insert([
            'id' => 'd1', 'name' => 'Branch', 'servers' => '10.0.0.10',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('dhcp_relay_interfaces')->insert([
            'id' => 'r1', 'interface_id' => 'dmz', 'destination_id' => 'd1', 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testIndexRendersLiveWidgetForCompiledInstances(): void
    {
        $this->seedRelay();

        $result = $this->withSession($this->session())->get('dhcp/relay');

        $result->assertOK();
        // The shared widget is pointed at the relay JSON endpoints and carries
        // the compiled slug as its data-service id.
        $result->assertSee('dhcp/relay/instances');
        $result->assertSee('data-service="branch"');
        $result->assertSee('dhcp-helper@branch.service');
        // Default mock reports no daemon units: the relay is configured but not
        // applied, shown both on the plan badge and the widget's LED label.
        $result->assertSee('data-unknown="not applied"');
        $result->assertSee('NOT APPLIED');
        // Not applied → the widget disables the action buttons so no control
        // can be clicked that would only error.
        $result->assertSee('data-lock-unknown="1"');
        $result->assertSee('data-action="start" disabled');
    }

    public function testIndexMarksAppliedInstanceWithItsDaemonState(): void
    {
        $this->seedRelay();

        $ipc = new FakeIpcClient([
            'dhcp.relay_status' => ['result' => [
                'success'   => true,
                'status'    => 'ok',
                'instances' => [['name' => 'branch', 'unit' => 'dhcp-helper@branch.service', 'state' => 'running']],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('dhcp/relay');

        $result->assertOK();
        $result->assertSee('ON DAEMON');
        $result->assertSee('RUNNING');
        $result->assertSee('applied on daemon');
        $result->assertDontSee('NOT APPLIED');
    }

    public function testIndexListsOrphanDaemonUnitsNotInThePlan(): void
    {
        $this->seedRelay();

        $ipc = new FakeIpcClient([
            'dhcp.relay_status' => ['result' => [
                'success'   => true,
                'status'    => 'ok',
                'instances' => [
                    ['name' => 'branch', 'unit' => 'dhcp-helper@branch.service', 'state' => 'running'],
                    ['name' => 'stale-relay', 'unit' => 'dhcp-helper@stale-relay.service', 'state' => 'stopped'],
                ],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('dhcp/relay');

        $result->assertOK();
        $result->assertSee('On the daemon but not in this plan');
        $result->assertSee('stale-relay');
    }

    public function testStatusReportsMappedRelayStates(): void
    {
        $ipc = new FakeIpcClient([
            'dhcp.relay_status' => ['result' => [
                'success'   => true,
                'status'    => 'ok',
                'instances' => [['name' => 'branch', 'unit' => 'dhcp-helper@branch.service', 'state' => 'running']],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('dhcp/relay/instances/status?ids=branch,ghost');

        $result->assertOK();
        $json = json_decode((string) $result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame(['branch' => 'running', 'ghost' => 'unknown'], $json['services']);
    }

    public function testControlDispatchesActionForACompiledSlug(): void
    {
        $this->seedRelay();

        $ipc = new FakeIpcClient([
            'dhcp.relay_service' => ['result' => [
                'success' => true, 'status' => 'ok', 'action' => 'restart', 'state' => 'running',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('dhcp/relay/instances/branch/control', $this->csrf(['action' => 'restart']));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true, 'service' => 'branch', 'status' => 'running']);
        $this->assertSame('dhcp.relay_service', $ipc->calls[0]['method']);
        $this->assertSame('branch', $ipc->calls[0]['params']['slug']);
        $this->assertSame('restart', $ipc->calls[0]['params']['action']);
    }

    public function testControlRefusesAnUnknownSlugWithoutCallingTheDaemon(): void
    {
        $this->seedRelay();

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('dhcp/relay/instances/ghost/control', $this->csrf(['action' => 'restart']));
        $result->assertStatus(404);

        $this->assertSame([], array_filter($ipc->calls, static fn ($c) => $c['method'] === 'dhcp.relay_service'));
    }

    public function testControlRefusesAnUnsupportedActionWithoutCallingTheDaemon(): void
    {
        $this->seedRelay();

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('dhcp/relay/instances/branch/control', $this->csrf(['action' => 'poweroff']));
        $result->assertStatus(422);

        $this->assertSame([], array_filter($ipc->calls, static fn ($c) => $c['method'] === 'dhcp.relay_service'));
    }

    public function testLogsReturnsJournalForACompiledSlug(): void
    {
        $this->seedRelay();

        $ipc = new FakeIpcClient([
            'dhcp.relay_logs' => ['result' => ['success' => true, 'status' => 'ok', 'logs' => 'helper ready'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('dhcp/relay/instances/branch/logs');

        $result->assertOK();
        $result->assertJSONFragment(['service' => 'Branch', 'logs' => 'helper ready']);

        $this->assertSame('dhcp.relay_logs', $ipc->calls[0]['method']);
        $this->assertSame('branch', $ipc->calls[0]['params']['slug']);
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
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
}
