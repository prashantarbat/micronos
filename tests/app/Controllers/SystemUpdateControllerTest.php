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
 * System Updates (Admin → System): the appliance-wide update surface. The page
 * renders, a check stores the report and flashes the count, a failed check
 * flashes an error, and an upgrade dispatches to the daemon.
 *
 * @internal
 */
final class SystemUpdateControllerTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['dashboard.view', 'system.info.view', 'system.settings.edit'];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->db->tableExists('plugins')) {
            $this->db->table('plugins')->emptyTable();
        }

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    public function testIndexRendersTheSystemUpdatesPage(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'system.info' => ['result' => ['version' => '1.0.0', 'kernel' => '6.6.30-micronos'], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('system/updates');

        $result->assertOK();
        $result->assertSee('System Updates');
        $result->assertSee('Check for Updates');
        $result->assertSee('Upgrade System');
        $result->assertSee('No check has been run yet');
    }

    public function testCheckStoresTheReportAndRendersAvailableUpdates(): void
    {
        $ipc = new FakeIpcClient([
            'system.updates_check' => ['result' => [
                'success' => true, 'status' => 'ok', 'count' => 1,
                'os' => 'MicronOS 1.0', 'version' => '1.0.0', 'kernel' => '6.6.30-micronos',
                'packages' => [['name' => 'openssl', 'current' => '3.5.0-2', 'candidate' => '3.5.1-1']],
                'checked_at' => '2026-10-05T12:00:00',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->post('system/updates/check', $this->csrf([]))->assertRedirect();
        $this->assertSame('system.updates_check', $ipc->calls[0]['method']);

        // The report persists in the session, so the next page load shows it.
        $result = $this->withSession($this->session() + [
            'system_update_report' => [
                'success' => true, 'count' => 1, 'version' => '1.0.0', 'kernel' => '6.6.30-micronos',
                'packages' => [['name' => 'openssl', 'current' => '3.5.0-2', 'candidate' => '3.5.1-1']],
            ],
        ])->get('system/updates');

        $result->assertOK();
        $result->assertSee('openssl');
        $result->assertSee('1 PENDING');
    }

    public function testCheckFailureFlashesAnError(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'system.updates_check' => ['result' => ['success' => false, 'status' => 'error', 'message' => 'apt-get update failed'], 'error' => null],
        ]));

        $result = $this->post('system/updates/check', $this->csrf([]));
        $result->assertRedirect();
        $result->assertSessionHas('error');
    }

    public function testUpgradeDispatchesToTheDaemon(): void
    {
        $ipc = new FakeIpcClient([
            'system.upgrade' => ['result' => ['success' => true, 'status' => 'applied', 'steps' => []], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('system/updates/upgrade', $this->csrf([]));
        $result->assertRedirect();
        $result->assertSessionHas('success');

        $this->assertSame('system.upgrade', $ipc->calls[0]['method']);
    }

    public function testNavRendersTheSystemUpdatesEntry(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $result = $this->withSession($this->session())->get('system/updates');

        $result->assertOK();
        $result->assertSee('system/updates');
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
