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

    /** @var list<array<string, mixed>> */
    private array $savedRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Config\Services::injectMock('ipc', new \App\Libraries\IpcClient());
        // Point WAN/LAN at the development machine's eno1/enp2s0 for this test,
        // keeping whatever rows were there so later tests see them unchanged.
        $this->savedRows = $this->db->table('interfaces')->whereIn('id', ['wan', 'lan'])->get()->getResultArray();
        $this->db->table('interfaces')->whereIn('id', ['wan', 'lan'])->delete();
        $this->db->table('interfaces')->insert(['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eno1', 'enabled' => 1, 'ip_assignment_type' => 'dhcp']);
        $this->db->table('interfaces')->insert(['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'enp2s0', 'enabled' => 1, 'ip_assignment_type' => 'static', 'ipv4_address' => '192.168.1.1', 'ipv4_netmask' => 24]);
    }

    protected function tearDown(): void
    {
        $this->db->table('interfaces')->whereIn('id', ['wan', 'lan'])->delete();
        foreach ($this->savedRows as $row) {
            $this->db->table('interfaces')->insert($row);
        }
        parent::tearDown();
    }

    public function testFaceplateIsBuiltFromTheKernelPortList(): void
    {
        $response = $this->withSession($this->session())->get('dashboard');
        $response->assertStatus(200);

        $body = $response->getBody();

        // Chassis furniture is kept.
        $this->assertStringContainsString('chassis-faceplate', $body);
        $this->assertStringContainsString('CHASSIS PORT TOPOLOGY', $body);
        $this->assertStringContainsString('fg-rj45-socket', $body);
        $this->assertStringContainsString('chassis-hud-bar', $body);

        // Ports carry their real kernel names (the development client reports a
        // machine with predictable names, as an installed ISO does).
        foreach (['enp3s0', 'enp4s0', 'enp5s0f0', 'enp5s0f1'] as $dev) {
            $this->assertStringContainsString('id="port_card_' . $dev . '"', $body, "{$dev} must be on the panel");
        }
        $this->assertStringContainsString('data-bay="wan"', $body);
        $this->assertMatchesRegularExpression('/data-bay="wan".*?id="port_card_eno1"/s', $body, 'the configured WAN device sits in the WAN bay');
        $this->assertStringContainsString('data-bay="lan"', $body);
        $this->assertStringContainsString('data-bay="sfp"', $body, 'fibre ports get their own bay');
        $this->assertStringContainsString('sfp-optical-cage', $body);

        // No invented hardware: no fixed 16-port matrix, placeholder ports,
        // console jack or second WAN that the machine does not have.
        $this->assertStringNotContainsString('INTERNAL SWITCH MATRIX', $body);
        $this->assertStringNotContainsString('port_card_port16', $body);
        $this->assertStringNotContainsString('port_card_eth18', $body);
        $this->assertStringNotContainsString('port_card_sfp0', $body);
        $this->assertStringNotContainsString('00:1a:2b:3c:4d', $body);

        // No radio on this machine, so no wireless bay.
        $this->assertStringNotContainsString('data-bay="wireless"', $body);
    }

    public function testAWirelessRadioGetsItsOwnBay(): void
    {
        putenv('MICRON_MOCK_WIRELESS=1');
        try {
            $response = $this->withSession($this->session())->get('dashboard');
        } finally {
            putenv('MICRON_MOCK_WIRELESS');
        }
        $response->assertStatus(200);
        $body = $response->getBody();

        $this->assertStringContainsString('data-bay="wireless"', $body);
        $this->assertStringContainsString('id="port_card_wlp6s0"', $body);
    }
}
