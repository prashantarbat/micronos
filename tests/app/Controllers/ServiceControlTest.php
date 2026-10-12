<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for the page-header service controls:
 *   - App\Libraries\ServiceStatus (status lookup + widget render)
 *   - POST services/{id}/control  (start / stop / restart JSON endpoint)
 *
 * @internal
 */
final class ServiceControlTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['dashboard.view'];
    private const TEST_ID     = 'svc_control_test';

    protected function setUp(): void
    {
        parent::setUp();

        // A dedicated throwaway daemon row, so the real seeds are never mutated.
        $this->db->table('system_services')->where('id', self::TEST_ID)->delete();
        $this->db->table('system_services')->insert([
            'id'                 => self::TEST_ID,
            'name'               => 'test-daemon',
            'subsystem'          => 'system',
            'description'        => 'Fixture service for service-control tests',
            'enabled_at_boot'    => 1,
            'watchdog_monitored' => 0,
            'status'             => 'running',
            'pid'                => 4242,
            'memory_mb'          => 12.5,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('system_services')->where('id', self::TEST_ID)->delete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ServiceStatus helper
    // ------------------------------------------------------------------

    public function testStatusHelperReturnsRowAndSafeFallback(): void
    {
        $row = \App\Libraries\ServiceStatus::get(self::TEST_ID);
        $this->assertSame('test-daemon', $row['name']);
        $this->assertSame('running', $row['status']);
        $this->assertTrue(\App\Libraries\ServiceStatus::isRunning(self::TEST_ID));

        $missing = \App\Libraries\ServiceStatus::get('does_not_exist_xyz');
        $this->assertSame('unknown', $missing['status']);
        $this->assertSame('does_not_exist_xyz', $missing['name']);
        $this->assertFalse(\App\Libraries\ServiceStatus::isRunning('does_not_exist_xyz'));
    }

    public function testWidgetRendersControlButtonsAndState(): void
    {
        $html = \App\Libraries\ServiceStatus::widget(self::TEST_ID);

        $this->assertStringContainsString('mno-svc', $html);
        $this->assertStringContainsString('data-service="' . self::TEST_ID . '"', $html);
        $this->assertStringContainsString('data-status="running"', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringContainsString('data-action="restart"', $html);
        $this->assertStringContainsString('data-action="stop"', $html);
        $this->assertStringContainsString('/control', $html);
    }

    public function testWidgetAcceptsMultipleServices(): void
    {
        $html = \App\Libraries\ServiceStatus::widget([self::TEST_ID, 'squid']);
        $this->assertStringContainsString('data-service="' . self::TEST_ID . '"', $html);
        $this->assertStringContainsString('data-service="squid"', $html);
    }

    public function testWidgetAdvertisesLiveStatusPolling(): void
    {
        $html = \App\Libraries\ServiceStatus::widget(self::TEST_ID);
        $this->assertStringContainsString('data-poll="15000"', $html);
        $this->assertStringContainsString('/status?ids=', $html);
    }

    public function testWidgetHasCollapseToggleAndName(): void
    {
        $html = \App\Libraries\ServiceStatus::widget(self::TEST_ID);

        // Compact control carries the daemon name and an expand affordance.
        $this->assertStringContainsString('mno-svc-name', $html);
        $this->assertStringContainsString('test-daemon', $html);
        $this->assertStringContainsString('mno-svc-toggle', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    public function testWidgetStatusIndicatorOpensSharedLogViewer(): void
    {
        $html = \App\Libraries\ServiceStatus::widget(self::TEST_ID);

        // The LED/name is a logs affordance...
        $this->assertStringContainsString('mno-svc-meta', $html);
        $this->assertStringContainsString('data-action="logs"', $html);

        // ...and the shared viewer modal is rendered exactly once.
        $this->assertStringContainsString('id="serviceLogModal"', $html);
        $this->assertStringContainsString('openServiceLogModal', $html);
        $this->assertSame(1, substr_count($html, 'id="serviceLogModal"'));
    }

    public function testRelayWidgetDisablesControlsUntilTheInstanceIsApplied(): void
    {
        // No daemon state → the compiled instance is not applied yet, so every
        // action button is disabled and the LED reads "not applied".
        $pending = \App\Libraries\ServiceStatus::relayWidget(
            [['slug' => 'branch', 'name' => 'Branch']],
            [],
        );
        $this->assertStringContainsString('data-lock-unknown="1"', $pending);
        $this->assertStringContainsString('not applied', $pending);
        $this->assertMatchesRegularExpression('/data-action="start"[^>]*\bdisabled\b/', $pending);
        $this->assertMatchesRegularExpression('/data-action="restart"[^>]*\bdisabled\b/', $pending);
        $this->assertMatchesRegularExpression('/data-action="stop"[^>]*\bdisabled\b/', $pending);

        // Applied and running → the controls become usable.
        $applied = \App\Libraries\ServiceStatus::relayWidget(
            [['slug' => 'branch', 'name' => 'Branch']],
            ['branch' => 'running'],
        );
        $this->assertStringContainsString('data-lock-unknown="1"', $applied);
        $this->assertStringContainsString('data-status="running"', $applied);
        $this->assertDoesNotMatchRegularExpression('/data-action="start"[^>]*\bdisabled\b/', $applied);
        $this->assertDoesNotMatchRegularExpression('/data-action="stop"[^>]*\bdisabled\b/', $applied);
    }

    // ------------------------------------------------------------------
    // Status endpoint
    // ------------------------------------------------------------------

    public function testStatusEndpointReportsKnownAndUnknownIds(): void
    {
        $result = $this->withSession($this->session())
            ->get('services/status?ids=' . self::TEST_ID . ',squid,does_not_exist');
        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame('running', $json['services'][self::TEST_ID]);
        $this->assertArrayHasKey('squid', $json['services']);
        $this->assertSame('unknown', $json['services']['does_not_exist']);
    }

    public function testStatusEndpointHandlesEmptyIdList(): void
    {
        $result = $this->withSession($this->session())->get('services/status');
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame([], $json['services']);
    }

    public function testStatusEndpointReflectsStateChanges(): void
    {
        $this->db->table('system_services')->where('id', self::TEST_ID)->update(['status' => 'stopped']);

        $result = $this->withSession($this->session())->get('services/status?ids=' . self::TEST_ID);
        $json   = json_decode($result->getJSON(), true);
        $this->assertSame('stopped', $json['services'][self::TEST_ID]);
    }

    // ------------------------------------------------------------------
    // Control endpoint
    // ------------------------------------------------------------------

    public function testControlEndpointStopsThenStartsTheDaemon(): void
    {
        $stop = $this->post('services/' . self::TEST_ID . '/control', $this->csrf(['action' => 'stop']));
        $stop->assertStatus(200);
        $json = json_decode($stop->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame('stopped', $json['status']);
        $this->assertNotEmpty($json['csrf']);

        $dbRow = $this->db->table('system_services')->where('id', self::TEST_ID)->get()->getRowArray();
        $this->assertSame('stopped', $dbRow['status']);
        $this->assertNull($dbRow['pid']);

        $start = $this->post('services/' . self::TEST_ID . '/control', $this->csrf(['action' => 'start']));
        $start->assertStatus(200);
        $json = json_decode($start->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame('running', $json['status']);

        $dbRow = $this->db->table('system_services')->where('id', self::TEST_ID)->get()->getRowArray();
        $this->assertSame('running', $dbRow['status']);
        $this->assertNotNull($dbRow['pid']);
    }

    public function testControlEndpointRestartsWithNameAndMessage(): void
    {
        $result = $this->post('services/' . self::TEST_ID . '/control', $this->csrf(['action' => 'restart']));
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('running', $json['status']);
        $this->assertSame('test-daemon', $json['name']);
        $this->assertStringContainsString('test-daemon', $json['message']);
    }

    public function testControlEndpointControlsDarkstatPluginDaemon(): void
    {
        $result = $this->post('services/darkstat/control', $this->csrf(['action' => 'start']));
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame('darkstat', $json['service']);
        $this->assertSame('running', $json['status']);
    }

    public function testControlEndpointRejectsInvalidActionAndUnknownService(): void
    {
        $bad = $this->post('services/' . self::TEST_ID . '/control', $this->csrf(['action' => 'explode']));
        $bad->assertStatus(422);

        $missing = $this->post('services/nope_missing/control', $this->csrf(['action' => 'start']));
        $missing->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
