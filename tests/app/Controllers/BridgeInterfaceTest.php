<?php

namespace Tests\App\Controllers;

use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\BaseTestCase;

final class BridgeInterfaceTest extends BaseTestCase
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
            'permissions'      => ['interfaces.view', 'interfaces.edit'],
            'user_permissions' => ['interfaces.view', 'interfaces.edit'],
        ];
    }

    private function csrf(array $params): array
    {
        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    public function testInterfacesIndexRendersBridgesTab(): void
    {
        $response = $this->withSession($this->session())->get('interfaces?tab=bridges');
        $response->assertStatus(200);

        $body = $response->getBody();
        $this->assertStringContainsString('tabNavBridges', $body);
        $this->assertStringContainsString('tabPaneBridges', $body);
        $this->assertStringContainsString('addBridgeModal', $body);
        $this->assertStringContainsString('deleteBridgeModal', $body);
    }

    public function testStoreBridgeCreatesBridgeAndInterfaceRecords(): void
    {
        $payload = [
            'id'            => 'br0',
            'name'          => 'LAN Switch Bridge',
            'slaves'        => ['eth1', 'eth2', 'eth3'],
            'stp'           => 1,
            'forward_delay' => 2,
            'mtu'           => 1500,
            'description'   => 'Unit Test Switch Bridge',
        ];

        $response = $this->withSession($this->session())
            ->post('interfaces/bridges/store', $this->csrf($payload));

        $response->assertRedirectTo(base_url('interfaces?tab=bridges'));

        // Verify interface_bridges record
        $bridgeRow = $this->db->table('interface_bridges')->where('id', 'br0')->get()->getRowArray();
        $this->assertNotNull($bridgeRow);
        $this->assertSame('LAN Switch Bridge', $bridgeRow['name']);
        $this->assertSame(1, (int) $bridgeRow['stp']);

        $slaves = json_decode((string) $bridgeRow['slaves'], true);
        $this->assertContains('eth1', $slaves);
        $this->assertContains('eth2', $slaves);
        $this->assertContains('eth3', $slaves);

        // Verify corresponding interface record
        $ifaceRow = $this->db->table('interfaces')->where('id', 'br0')->get()->getRowArray();
        $this->assertNotNull($ifaceRow);
        $this->assertSame('br0', $ifaceRow['physical_device']);
    }

    public function testDeleteBridgeRemovesBridgeRecord(): void
    {
        $response = $this->withSession($this->session())
            ->post('interfaces/bridges/br0/delete', $this->csrf([]));

        $response->assertRedirectTo(base_url('interfaces?tab=bridges'));

        $bridgeRow = $this->db->table('interface_bridges')->where('id', 'br0')->get()->getRowArray();
        $this->assertNull($bridgeRow);
    }
}
