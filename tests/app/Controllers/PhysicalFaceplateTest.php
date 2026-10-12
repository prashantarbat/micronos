<?php

namespace Tests\App\Controllers;

use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\BaseTestCase;

final class PhysicalFaceplateTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private function session(): array
    {
        return [
            'user_id'          => 1,
            'username'         => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => ['dashboard.view', 'interfaces.view'],
            'user_permissions' => ['dashboard.view', 'interfaces.view'],
        ];
    }

    public function testDashboardRenders16PortPhysicalMatrixFaceplate(): void
    {
        $response = $this->withSession($this->session())->get('dashboard');
        $response->assertStatus(200);

        $body = $response->getBody();

        // 1. Verify Hardware Faceplate Chassis container and markings
        $this->assertStringContainsString('chassis-faceplate', $body);
        $this->assertStringContainsString('fg-hardware-chassis', $body);
        $this->assertStringContainsString('chassis-screw', $body);
        $this->assertStringContainsString('CHASSIS PORT TOPOLOGY', $body);

        // 2. Verify Bay 1: Dedicated Uplinks & MGMT (WAN & MGMT & CONSOLE)
        $this->assertStringContainsString('bay-wan-mgmt', $body);
        $this->assertStringContainsString('CONSOLE', $body);
        $this->assertStringContainsString('MGMT', $body);
        $this->assertStringContainsString('WAN 1', $body);

        // 3. Verify Bay 2: 16-Port Switch Matrix (Odd top 1..15, Even bottom 2..16)
        $this->assertStringContainsString('bay-internal-16', $body);
        $this->assertStringContainsString('fg-16port-grid', $body);
        $this->assertStringContainsString('INTERNAL SWITCH MATRIX (1 &ndash; 16)', $body);
        for ($i = 1; $i <= 16; $i++) {
            $this->assertStringContainsString(">$i<", $body, "Silkscreen port number {$i} must be displayed");
        }

        // 4. Verify Physical RJ-45 sockets with LEDs and notch
        $this->assertStringContainsString('fg-rj45-socket', $body);
        $this->assertStringContainsString('fg-socket-leds', $body);
        $this->assertStringContainsString('fg-socket-notch', $body);

        // 5. Verify Bay 3: 10G SFP+ Optical Cages
        $this->assertStringContainsString('bay-sfp', $body);
        $this->assertStringContainsString('sfp-optical-cage', $body);
        $this->assertStringContainsString('SFP+ 1', $body);
        $this->assertStringContainsString('SFP+ 2', $body);

        // 6. Verify Interactive HUD telemetry bar
        $this->assertStringContainsString('chassis-hud-bar', $body);
        $this->assertStringContainsString('hudTitle', $body);
        $this->assertStringContainsString('hudRxRate', $body);
        $this->assertStringContainsString('hudTxRate', $body);
    }
}
