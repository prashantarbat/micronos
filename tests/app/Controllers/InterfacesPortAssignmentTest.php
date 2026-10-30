<?php

namespace Tests\App\Controllers;

use App\Commands\NetworkCommand;
use App\Models\InterfaceModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

final class InterfacesPortAssignmentTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'interfaces.view',
        'interfaces.edit',
        'interfaces.apply',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('interfaces')->whereIn('id', ['opt1_test', 'opt2_test'])->delete();

        // Ensure WAN and LAN exist
        $now = date('Y-m-d H:i:s');
        if ($this->db->table('interfaces')->where('id', 'wan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id'                 => 'wan',
                'name'               => 'WAN',
                'physical_device'    => 'eth0',
                'interface_type'     => 'physical',
                'enabled'            => 1,
                'ip_assignment_type' => 'dhcp',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        } else {
            $this->db->table('interfaces')->where('id', 'wan')->update([
                'physical_device'    => 'eth0',
                'ip_assignment_type' => 'dhcp',
            ]);
        }

        if ($this->db->table('interfaces')->where('id', 'lan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id'                 => 'lan',
                'name'               => 'LAN',
                'physical_device'    => 'eth1',
                'interface_type'     => 'physical',
                'enabled'            => 1,
                'ip_assignment_type' => 'static',
                'ipv4_address'       => '192.168.1.1',
                'ipv4_netmask'       => '24',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        } else {
            $this->db->table('interfaces')->where('id', 'lan')->update([
                'physical_device'    => 'eth1',
                'ip_assignment_type' => 'static',
                'ipv4_address'       => '192.168.1.1',
                'ipv4_netmask'       => '24',
            ]);
        }

        $ipc = new FakeIpcClient([
            'network.apply'         => ['error' => null, 'result' => ['status' => 'applied', 'success' => true]],
            'interface.dynamic_dns' => ['error' => null, 'result' => ['success' => true, 'sources' => []]],
            'dns.resolver.apply'    => ['error' => null, 'result' => ['status' => 'applied']],
        ]);
        Services::injectMock('ipc', $ipc);

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

    public function testAssignInterfacesEndpointSetsWanAndLan(): void
    {
        // Reassign WAN to eth1 (2nd interface) and LAN to eth2 (3rd interface)
        $result = $this->post('interfaces/assign', $this->csrf([
            'wan_device' => 'eth1',
            'lan_device' => 'eth2',
        ]));

        $result->assertRedirectTo(base_url('interfaces'));

        $wan = $this->db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
        $lan = $this->db->table('interfaces')->where('id', 'lan')->get()->getRowArray();

        $this->assertSame('eth1', $wan['physical_device']);
        $this->assertSame('eth2', $lan['physical_device']);
        // Verify IP config preserved
        $this->assertSame('dhcp', $wan['ip_assignment_type']);
        $this->assertSame('192.168.1.1', $lan['ipv4_address']);
    }

    public function testAssignInterfacesEndpointInterchangesWanAndLan(): void
    {
        // Set WAN=eth1, LAN=eth2 first
        $this->db->table('interfaces')->where('id', 'wan')->update(['physical_device' => 'eth1']);
        $this->db->table('interfaces')->where('id', 'lan')->update(['physical_device' => 'eth2']);

        // Swap: WAN=eth2, LAN=eth1
        $result = $this->post('interfaces/assign', $this->csrf([
            'wan_device' => 'eth2',
            'lan_device' => 'eth1',
        ]));

        $result->assertRedirectTo(base_url('interfaces'));

        $wan = $this->db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
        $lan = $this->db->table('interfaces')->where('id', 'lan')->get()->getRowArray();

        $this->assertSame('eth2', $wan['physical_device']);
        $this->assertSame('eth1', $lan['physical_device']);
    }

    public function testSaveInterfaceAutoSwapsPorts(): void
    {
        // Start: WAN=eth0, LAN=eth1
        $this->db->table('interfaces')->where('id', 'wan')->update(['physical_device' => 'eth0']);
        $this->db->table('interfaces')->where('id', 'lan')->update(['physical_device' => 'eth1']);

        // Edit WAN, changing physical_device from eth0 to eth1 (which is currently LAN)
        $postData = [
            'id'                 => 'wan',
            'name'               => 'WAN',
            'physical_device'    => 'eth1',
            'ip_assignment_type' => 'dhcp',
            'enabled'            => '1',
            'mtu'                => '1500',
        ];

        $result = $this->post('interfaces/save', $this->csrf($postData));

        $result->assertRedirectTo(base_url('interfaces'));

        $wan = $this->db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
        $lan = $this->db->table('interfaces')->where('id', 'lan')->get()->getRowArray();

        // WAN should now have eth1, and LAN should have been auto-swapped to eth0
        $this->assertSame('eth1', $wan['physical_device']);
        $this->assertSame('eth0', $lan['physical_device']);
    }

    public function testCliAssignAndSwapCommands(): void
    {
        $logger = Services::logger();
        $commands = Services::commands();
        $cmd = new NetworkCommand($logger, $commands);

        // Assign: 2nd interface (eth1) as WAN, 3rd interface (eth2) as LAN
        $cmd->run(['assign', 'eth1', 'eth2']);

        $wan = $this->db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
        $lan = $this->db->table('interfaces')->where('id', 'lan')->get()->getRowArray();

        $this->assertSame('eth1', $wan['physical_device']);
        $this->assertSame('eth2', $lan['physical_device']);

        // Swap: interchange wan and lan
        $cmd->run(['swap', 'wan', 'lan']);

        $wan = $this->db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
        $lan = $this->db->table('interfaces')->where('id', 'lan')->get()->getRowArray();

        $this->assertSame('eth2', $wan['physical_device']);
        $this->assertSame('eth1', $lan['physical_device']);

        // set-wan to eth0
        $cmd->run(['set-wan', 'eth0']);

        $wan = $this->db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
        $this->assertSame('eth0', $wan['physical_device']);
    }
}
