<?php

declare(strict_types=1);

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;
use App\Models\DashboardLayoutModel;

final class DashboardModernizationTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['dashboard.view', 'interfaces.view', 'interfaces.edit'];

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('ipc', new FakeIpcClient([
            'system.info'         => ['result' => ['hostname' => 'micronos-fw01', 'kernel' => '6.6.30-micronos'], 'error' => null],
            'interface.list'      => ['result' => [], 'error' => null],
            'wireguard.status'    => ['result' => [], 'error' => null],
            'openvpn.status'      => ['result' => [], 'error' => null],
            'squid.status'        => ['result' => [], 'error' => null],
            'firewall.list_rules' => ['result' => [], 'error' => null],
        ]));

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function session(): array
    {
        return [
            'user_id'          => 1,
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrfToken(): string
    {
        return csrf_hash();
    }

    public function testDashboardRendersChassisFaceplateAndModularCards(): void
    {
        $page = $this->withSession($this->session())->get('dashboard');
        $page->assertStatus(200);

        // Verify FortiGate-style chassis faceplate
        $page->assertSee('CHASSIS PORT TOPOLOGY');
        $page->assertSee('MICRON OS SECURITY APPLIANCE');
        $page->assertSee('chassis-faceplate');
        $page->assertSee('ports-matrix');

        // Verify modular card controls (pfSense / FortiGate style)
        $page->assertSee('+ Add Card');
        $page->assertSee('btnSaveDashboardStructure');
        $page->assertSee('Save Structure');
        $page->assertSee('SERVER_SAVED_LAYOUT');
        $page->assertSee('dash-masonry-grid');
        $page->assertSee('card_widget_gauges');
        $page->assertSee('card_widget_traffic');
        $page->assertSee('card_widget_interfaces');
        $page->assertSee('card_widget_filter_log');
    }

    public function testDashboardLayoutApiReturnsDefaultLayout(): void
    {
        $result = $this->withSession($this->session())->get('dashboard/layout');
        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('layout', $json);
        $this->assertEquals(3, $json['layout']['columns'] ?? null);
    }

    public function testDashboardLayoutSaveAndReset(): void
    {
        // 1. Save custom layout
        $customLayout = [
            'columns'             => 2,
            'refresh_interval_ms' => 5000,
            'cards'               => [
                ['id' => 'card_widget_gauges', 'visible' => true],
                ['id' => 'card_widget_traffic', 'visible' => true],
            ],
        ];

        $csrfName = csrf_token();
        $csrfHash = $this->csrfToken();

        $saveResult = $this->withSession($this->session())->post('dashboard/layout/save', [
            $csrfName => $csrfHash,
            'layout'  => json_encode($customLayout),
            'profile' => 'default',
        ]);
        $saveResult->assertStatus(200);

        $savedJson = json_decode($saveResult->getJSON(), true);
        $this->assertTrue($savedJson['success']);

        // 2. Verify retrieval reflects saved changes
        $layoutModel = new DashboardLayoutModel();
        $record = $layoutModel->getLayoutForUser(1, 'default');
        $this->assertEquals(2, $record['column_count']);
        $this->assertEquals(5000, $record['refresh_interval_ms']);

        // 3. Reset layout to factory default
        $resetResult = $this->withSession($this->session())->post('dashboard/layout/reset', [
            $csrfName => $this->csrfToken(),
            'profile' => 'default',
        ]);
        $resetResult->assertStatus(200);

        $resetRecord = $layoutModel->getLayoutForUser(1, 'default');
        $this->assertEquals(3, $resetRecord['column_count']);
    }

    public function testDashboardStructurePersistedAndRendered(): void
    {
        $customStructure = [
            'columns'             => 1,
            'refresh_interval_ms' => 1000,
            'cards'               => [
                ['id' => 'card_widget_traffic', 'visible' => true, 'order' => 1],
                ['id' => 'card_widget_gauges',  'visible' => true, 'order' => 2],
            ],
            'active_widgets'      => ['card_widget_traffic', 'card_widget_gauges'],
        ];

        $csrfName = csrf_token();
        $csrfHash = $this->csrfToken();

        $saveResponse = $this->withSession($this->session())->post('dashboard/layout/save', [
            $csrfName => $csrfHash,
            'layout'  => json_encode($customStructure),
            'profile' => 'default',
        ]);
        $saveResponse->assertStatus(200);

        // Fetch dashboard and verify the saved structure is rendered into the view
        $dashboardPage = $this->withSession($this->session())->get('dashboard');
        $dashboardPage->assertStatus(200);
        $body = $dashboardPage->getBody();

        $this->assertStringContainsString('card_widget_traffic', $body);
        $this->assertStringContainsString('card_widget_gauges', $body);
        $this->assertStringContainsString('"columns":1', $body);
        $this->assertStringContainsString('"refresh_interval_ms":1000', $body);

        // Clean up: reset layout
        $layoutModel = new DashboardLayoutModel();
        $default = $layoutModel->getDefaultLayout('default');
        $layoutModel->saveLayoutForUser(1, 'default', $default);
    }

    public function testDashboardTelemetryEndpointReturnsMetrics(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'system.health' => ['result' => ['success' => true, 'uptime_sec' => 3600, 'load_avg' => [0.5, 0.4, 0.3],
                'cpu_count' => 4, 'mem_total_mb' => 4000, 'mem_used_mb' => 1000,
                'disks' => [['mount' => '/', 'total_gb' => 10.0, 'used_gb' => 2.0, 'free_gb' => 8.0, 'used_pct' => 20.0]],
                'conntrack_count' => 12, 'conntrack_max' => 65536, 'temperature_c' => null,
                'interfaces' => [['name' => 'eth0', 'operstate' => 'up', 'carrier' => true, 'mac' => '02:00:00:00:00:01',
                    'mtu' => 1500, 'speed_mbps' => 1000, 'rx_bytes' => 1000, 'tx_bytes' => 500, 'rx_packets' => 10,
                    'tx_packets' => 5, 'rx_errors' => 0, 'tx_errors' => 0, 'virtual' => false]]], 'error' => null],
        ]));
        $result = $this->withSession($this->session())->get('dashboard/telemetry');
        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('gauges', $json);
        $this->assertArrayHasKey('ports', $json);
        $this->assertArrayHasKey('traffic', $json);
        $this->assertIsArray($json['ports']);
        $this->assertNotEmpty($json['ports']);

        $this->assertSame('live', $json['mode']);
        $this->assertSame(13, $json['gauges']['cpu_pct'], 'load 0.5 on 4 cores, from the daemon — not a random number');
        $this->assertSame(12, $json['gauges']['states']);
        $this->assertNull($json['gauges']['temperature'], 'no sensor → null, not an invented 37.5 °C');

        $firstPort = $json['ports'][0];
        $this->assertArrayHasKey('device', $firstPort);
        $this->assertArrayHasKey('rx_mbps', $firstPort);
        $this->assertArrayHasKey('tx_mbps', $firstPort);
        $this->assertArrayHasKey('speed', $firstPort);
    }

    public function testDashboardPortActionToggleAndPcap(): void
    {
        $csrfName = csrf_token();

        // 1. Toggle action on a configured interface (only the stored state changes)
        if ($this->db->table('interfaces')->where('physical_device', 'eth0')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert(['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1,
                'ip_assignment_type' => 'dhcp']);
        }
        $toggleResult = $this->withSession($this->session())->post('dashboard/port-action', [
            $csrfName => $this->csrfToken(),
            'device'  => 'eth0',
            'action'  => 'toggle',
        ]);
        $toggleResult->assertStatus(200);
        $toggleJson = json_decode($toggleResult->getJSON(), true);
        $this->assertTrue($toggleJson['success']);

        $this->assertTrue($toggleJson['pending_apply'], 'the toggle must say it still needs an interface apply');

        // 2. On a live appliance the quick capture points at the real capture tool.
        $live = $this->withSession($this->session())->post('dashboard/port-action', [
            $csrfName => $this->csrfToken(), 'device' => 'eth0', 'action' => 'pcap',
        ]);
        $live->assertStatus(400);
        $this->assertStringContainsString('diag', json_decode($live->getJSON(), true)['redirect']);

        // 3. Sample packets exist only for the development client, and are labelled.
        Services::injectMock('ipc', (new FakeIpcClient())->asDevelopmentMock());
        $pcapResult = $this->withSession($this->session())->post('dashboard/port-action', [
            $csrfName => $this->csrfToken(),
            'device'  => 'eth0',
            'action'  => 'pcap',
        ]);
        $pcapResult->assertStatus(200);
        $pcapJson = json_decode($pcapResult->getJSON(), true);
        $this->assertTrue($pcapJson['success']);
        $this->assertArrayHasKey('packets', $pcapJson);
        $this->assertNotEmpty($pcapJson['packets']);
        $this->assertStringContainsString('SIMULATED', $pcapJson['packets'][0]);
    }

    public function testAddOnWidgetCardsRenderAndAreInTheCatalogue(): void
    {
        $body = $this->withSession($this->session())->get('dashboard')->getBody();

        foreach (['ports', 'storage', 'dhcp', 'conntrack', 'plugins', 'actions'] as $w) {
            $this->assertStringContainsString('id="card_widget_' . $w . '"', $body, "widget {$w} must be rendered");
            $this->assertStringContainsString("{ id: 'card_widget_{$w}'", $body, "widget {$w} must be offered in Add Widget");
        }
        // The VPN card states come from systemd, not fixed "ONLINE"/"RUNNING" labels.
        $this->assertStringNotContainsString('2FA TOTP Active', $body);
    }

    public function testDefaultLayoutIncludesTheNewCards(): void
    {
        $layout = (new \App\Models\DashboardLayoutModel())->getDefaultLayout();
        foreach (['card_widget_ports', 'card_widget_storage', 'card_widget_dhcp', 'card_widget_plugins', 'card_widget_actions'] as $id) {
            $this->assertContains($id, $layout['active_widgets']);
            $this->assertTrue($layout['widget_configs'][$id]['visible']);
        }
    }
}
