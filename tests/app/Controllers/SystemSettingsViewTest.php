<?php

namespace Tests\App\Controllers;

use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\BaseTestCase;

final class SystemSettingsViewTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    public function testSystemSettingsViewRendersTabsAndPanels(): void
    {
        $html = view('system/index', [
            'title'          => 'System Settings — MicronOS',
            'breadcrumb'     => 'System Settings',
            'settings'       => [
                'micron_id'            => 'TEST-APPLIANCE-123',
                'hostname'             => 'micronos-fw01',
                'domain'               => 'firewall.local',
                'dns_default_servers'  => '1.1.1.1, 8.8.8.8',
                'updated_at'           => '2026-10-10 12:00:00',
            ],
            'timezones'      => ['UTC' => ['UTC']],
            'countries'      => ['US' => 'United States'],
            'ldapServer'     => null,
            'radiusPrimary'  => null,
            'radiusBackup'   => null,
            'ldapMappings'   => [],
            'radiusMappings' => [],
            'allRoles'       => [],
            'certificates'   => [],
            'isMock'         => true,
        ]);

        $this->assertStringContainsString('General System Settings', $html);
        $this->assertStringContainsString('sys-tab-btn', $html);
        $this->assertStringContainsString('panel-identity', $html);
        $this->assertStringContainsString('Appliance Identity', $html);
        $this->assertStringContainsString('panel-organisation', $html);
        $this->assertStringContainsString('panel-time', $html);
        $this->assertStringContainsString('panel-network', $html);
        $this->assertStringContainsString('panel-webgui', $html);
        $this->assertStringContainsString('panel-ssh', $html);
        $this->assertStringContainsString('panel-notifications', $html);
        $this->assertStringContainsString('panel-ldap', $html);
        $this->assertStringContainsString('panel-radius', $html);
        $this->assertStringContainsString('panel-misc', $html);
        $this->assertStringContainsString("panel.style.display = 'block'", $html);
        $this->assertStringContainsString("switchTab('identity')", $html);
    }

    public function testGetSystemRouteRendersSuccessfully(): void
    {
        $result = $this->withSession([
            'user_id'          => 1,
            'username'         => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => ['dashboard.view', 'system.info.view', 'system.settings.edit'],
            'user_permissions' => ['dashboard.view', 'system.info.view', 'system.settings.edit'],
        ])->get('system');

        $result->assertOK();
        $result->assertSee('General System Settings');
        $result->assertSee('panel-identity');
        $result->assertSee('panel-webgui');
    }
}
