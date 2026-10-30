<?php

namespace Tests\App\Commands;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

final class NetworkStatusWebUiLinksTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const IDS = ['lan', 'opt1', 'opt2', 'opt3', 'wan'];
    private array $originalRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::IDS as $id) {
            $row = $this->db->table('interfaces')->where('id', $id)->get()->getRowArray();
            if ($row !== null) {
                $this->originalRows[$id] = $row;
            }
        }
        $this->db->table('interfaces')->whereIn('id', self::IDS)->delete();
        $now = date('Y-m-d H:i:s');
        $this->db->table('interfaces')->insertBatch([
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'enp2s0', 'enabled' => 1, 'ip_assignment_type' => 'dhcp', 'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'opt1', 'name' => 'OPT1', 'physical_device' => 'enp3s0', 'enabled' => 1, 'ip_assignment_type' => 'dhcp', 'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'opt2', 'name' => 'OPT2', 'physical_device' => 'enp4s0', 'enabled' => 1, 'ip_assignment_type' => 'dhcp', 'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'opt3', 'name' => 'OPT3', 'physical_device' => 'enp5s0', 'enabled' => 0, 'ip_assignment_type' => 'dhcp', 'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'enp1s0', 'enabled' => 1, 'ip_assignment_type' => 'dhcp', 'mtu' => 1500, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Services::injectMock('ipc', new FakeIpcClient([
            'network.status' => [
                'result' => [
                    'effective' => [
                        'enp1s0' => ['ipv4' => [['cidr' => '192.0.2.1/24']]],
                        'enp2s0' => ['ipv4' => [['cidr' => '10.0.0.1/24']]],
                        'enp3s0' => ['ipv4' => [['cidr' => '10.30.0.1/24']]],
                        'enp4s0' => ['ipv4' => [['cidr' => '10.40.0.1/24']]],
                        'enp5s0' => ['ipv4' => [['cidr' => '10.50.0.1/24']]],
                    ],
                    'ipv6_effective' => false,
                ],
                'error' => null,
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $this->db->table('interfaces')->whereIn('id', self::IDS)->delete();
        if ($this->originalRows !== []) {
            $this->db->table('interfaces')->insertBatch(array_values($this->originalRows));
        }
        parent::tearDown();
    }

    public function testNetworkStatusShowsWebUiUrlsForLanAndEnabledOptionalInterfacesOnly(): void
    {
        $this->resetStreamFilterBuffer();
        command('network status');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Web UI (lan): http://10.0.0.1/ (Port 80)', $output);
        $this->assertStringContainsString('Web UI (opt1): http://10.30.0.1/ (Port 80)', $output);
        $this->assertStringContainsString('Web UI (opt2): http://10.40.0.1/ (Port 80)', $output);
        $this->assertStringNotContainsString('http://192.0.2.1/', $output);
        $this->assertStringNotContainsString('http://10.50.0.1/', $output);
    }
}
