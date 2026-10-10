<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for RIP & IS-IS (FRRouting ripd / isisd):
 *
 *   POST routing/rip/settings/save
 *   POST routing/rip/networks/store|delete
 *   POST routing/rip/neighbors/store|delete
 *   POST routing/rip/passive/store|delete
 *   POST routing/isis/settings/save
 *   POST routing/isis/interfaces/store|update|delete
 *
 * The routes and their controllers run the real validation and builder code
 * paths, so persistence, defaulting, duplicate rejection and referential
 * checks (route-map / prefix-list) are asserted end-to-end.
 *
 * @internal
 */
final class RipIsisTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    // Shared in-memory database, migrated once; rows are cleared per test.
    protected $refresh = false;

    /** Permission keys the SuperAdmin-style session is granted. */
    private const PERMISSIONS = ['routing.table.view', 'routing.static.manage'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('frr_rip_settings')->emptyTable();
        $this->db->table('frr_rip_networks')->emptyTable();
        $this->db->table('frr_rip_neighbors')->emptyTable();
        $this->db->table('frr_rip_passive_interfaces')->emptyTable();
        $this->db->table('frr_isis_settings')->emptyTable();
        $this->db->table('frr_isis_interfaces')->emptyTable();
        $this->db->table('frr_route_maps')->emptyTable();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    // ------------------------------------------------------------------
    // RIP settings
    // ------------------------------------------------------------------

    public function testRipSettingsArePersistedWithDefaults(): void
    {
        $this->post('routing/rip/settings/save', $this->csrf([
            'enabled'                => '1',
            'version'                => '2',
            'redistribute_connected' => '1',
            'redistribute_static'    => '1',
            'maximum_paths'          => '6',
            'timers_update'          => '15',
            'timers_timeout'         => '90',
            'timers_garbage'         => '60',
            'passive_default'        => '1',
            'auth_mode'              => 'md5',
            'auth_password'          => 's3cret',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertNotNull($row, 'RIP settings were not persisted.');
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame(2, (int) $row['version']);
        $this->assertSame(6, (int) $row['maximum_paths']);
        $this->assertSame(15, (int) $row['timers_update']);
        $this->assertSame(90, (int) $row['timers_timeout']);
        $this->assertSame(60, (int) $row['timers_garbage']);
        $this->assertSame('md5', $row['auth_mode']);
        $this->assertSame('s3cret', $row['auth_password']);
    }

    public function testRipSettingsDefaultRouteMapAttachment(): void
    {
        $this->seedRouteMap('rm_known', 'Known_RM', []);

        $this->post('routing/rip/settings/save', $this->csrf([
            'enabled'                => '1',
            'version'                => '2',
            'default_information'    => '1',
            'default_information_rm' => 'Known_RM',
            'auth_mode'              => 'none',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertSame('Known_RM', $row['default_information_rm']);

        // An unknown route-map must not be stored — ripd would reject the config.
        $this->post('routing/rip/settings/save', $this->csrf([
            'enabled'                => '1',
            'version'                => '2',
            'default_information'    => '1',
            'default_information_rm' => 'Does_Not_Exist',
            'auth_mode'              => 'none',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertNull($row['default_information_rm']);
    }

    public function testRipSettingsAttachRedistributionRouteMaps(): void
    {
        $this->seedRouteMap('rm_known', 'Known_RM', []);

        $this->post('routing/rip/settings/save', $this->csrf([
            'enabled'                   => '1',
            'version'                   => '2',
            'redistribute_connected'    => '1',
            'redistribute_connected_rm' => 'Known_RM',
            'redistribute_static'       => '1',
            'redistribute_static_rm'    => 'Ghost_RM',
            'auth_mode'                 => 'none',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertSame('Known_RM', $row['redistribute_connected_rm'], 'Known route-map must persist.');
        $this->assertNull($row['redistribute_static_rm'], 'Unknown route-map must be dropped.');
        $this->assertNull($row['redistribute_bgp_rm']);
    }

    public function testRipSettingsRejectsUnknownAuthMode(): void
    {
        $this->post('routing/rip/settings/save', $this->csrf([
            'enabled'   => '1',
            'version'   => '2',
            'auth_mode' => 'bogus',
        ]))->assertRedirect();

        $this->assertNull(
            $this->db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray(),
            'Invalid auth mode must not persist settings.'
        );
    }

    public function testRipSettingsNormalisesInvalidVersionToTwo(): void
    {
        $this->post('routing/rip/settings/save', $this->csrf([
            'enabled'   => '1',
            'version'   => '9',
            'auth_mode' => 'none',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertSame(2, (int) $row['version']);
    }

    // ------------------------------------------------------------------
    // RIP networks
    // ------------------------------------------------------------------

    public function testStoreRipNetwork(): void
    {
        $this->post('routing/rip/networks/store', $this->csrf([
            'network'     => '192.168.1.0/24',
            'description' => 'LAN',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_networks')->where('network', '192.168.1.0/24')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('rn_', $row['id']);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame('LAN', $row['description']);
    }

    public function testStoreRipNetworkRejectsDuplicate(): void
    {
        $this->post('routing/rip/networks/store', $this->csrf(['network' => '10.0.0.0/8']))->assertRedirect();
        $this->post('routing/rip/networks/store', $this->csrf(['network' => '10.0.0.0/8']))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_rip_networks')->countAllResults());
    }

    public function testStoreRipNetworkRejectsInvalidCidr(): void
    {
        $this->post('routing/rip/networks/store', $this->csrf(['network' => 'not-a-network']))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_rip_networks')->countAllResults());
    }

    public function testDeleteRipNetwork(): void
    {
        $this->post('routing/rip/networks/store', $this->csrf(['network' => '172.16.0.0/12']))->assertRedirect();
        $id = $this->db->table('frr_rip_networks')->get()->getRowArray()['id'];

        $this->post("routing/rip/networks/{$id}/delete", $this->csrf([]))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_rip_networks')->countAllResults());
    }

    // ------------------------------------------------------------------
    // RIP neighbors
    // ------------------------------------------------------------------

    public function testStoreRipNeighbor(): void
    {
        $this->post('routing/rip/neighbors/store', $this->csrf([
            'neighbor_ip' => '10.100.0.2',
            'description' => 'Core switch',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_neighbors')->where('neighbor_ip', '10.100.0.2')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('rb_', $row['id']);
    }

    public function testStoreRipNeighborRejectsInvalidIp(): void
    {
        $this->post('routing/rip/neighbors/store', $this->csrf(['neighbor_ip' => '999.1.1.1']))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_rip_neighbors')->countAllResults());
    }

    public function testStoreRipNeighborRejectsDuplicate(): void
    {
        $this->post('routing/rip/neighbors/store', $this->csrf(['neighbor_ip' => '10.0.0.9']))->assertRedirect();
        $this->post('routing/rip/neighbors/store', $this->csrf(['neighbor_ip' => '10.0.0.9']))->assertRedirect();
        $this->assertSame(1, $this->db->table('frr_rip_neighbors')->countAllResults());
    }

    // ------------------------------------------------------------------
    // RIP passive interfaces
    // ------------------------------------------------------------------

    public function testStoreAndDeleteRipPassiveInterface(): void
    {
        $this->post('routing/rip/passive/store', $this->csrf([
            'interface'   => 'eth1',
            'description' => 'LAN listen only',
        ]))->assertRedirect();

        $row = $this->db->table('frr_rip_passive_interfaces')->where('interface', 'eth1')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('rp_', $row['id']);

        $this->post('routing/rip/passive/store', $this->csrf(['interface' => 'eth1']))->assertRedirect();
        $this->assertSame(1, $this->db->table('frr_rip_passive_interfaces')->countAllResults());

        $this->post("routing/rip/passive/{$row['id']}/delete", $this->csrf([]))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_rip_passive_interfaces')->countAllResults());
    }

    public function testStoreRipPassiveInterfaceRequiresInterface(): void
    {
        $this->post('routing/rip/passive/store', $this->csrf(['interface' => '']))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_rip_passive_interfaces')->countAllResults());
    }

    // ------------------------------------------------------------------
    // IS-IS settings
    // ------------------------------------------------------------------

    public function testIsisSettingsArePersisted(): void
    {
        $this->post('routing/isis/settings/save', $this->csrf([
            'enabled'                => '1',
            'area_tag'               => 'DEFAULT',
            'net'                    => '49.0001.1921.6800.1001.00',
            'is_type'                => 'level-1-2',
            'metric_style'           => 'wide',
            'log_adjacency'          => '1',
            'lsp_gen_interval'       => '33',
            'spf_interval_l1'        => '50',
            'redistribute_connected' => '1',
            'auth_mode'              => 'none',
        ]))->assertRedirect();

        $row = $this->db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame('DEFAULT', $row['area_tag']);
        $this->assertSame('49.0001.1921.6800.1001.00', $row['net']);
        $this->assertSame('level-1-2', $row['is_type']);
        $this->assertSame('wide', $row['metric_style']);
        $this->assertSame(33, (int) $row['lsp_gen_interval']);
    }

    public function testIsisSettingsAttachRedistributionRouteMaps(): void
    {
        $this->seedRouteMap('rm_known', 'Known_RM', []);

        $this->post('routing/isis/settings/save', $this->csrf([
            'enabled'                 => '1',
            'area_tag'                => 'DEFAULT',
            'is_type'                 => 'level-1-2',
            'metric_style'            => 'wide',
            'redistribute_ospf'       => '1',
            'redistribute_ospf_rm'     => 'Known_RM',
            'redistribute_bgp'        => '1',
            'redistribute_bgp_rm'      => 'Ghost_RM',
            'auth_mode'               => 'none',
        ]))->assertRedirect();

        $row = $this->db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertSame('Known_RM', $row['redistribute_ospf_rm']);
        $this->assertNull($row['redistribute_bgp_rm']);
    }

    public function testIsisSettingsRejectMalformedNet(): void
    {
        $this->post('routing/isis/settings/save', $this->csrf([
            'enabled'      => '1',
            'area_tag'     => 'DEFAULT',
            'net'          => 'not a net',
            'is_type'      => 'level-1-2',
            'metric_style' => 'wide',
            'auth_mode'    => 'none',
        ]))->assertRedirect();

        $this->assertNull($this->db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray());
    }

    public function testIsisSettingsRejectInvalidMetricStyle(): void
    {
        $this->post('routing/isis/settings/save', $this->csrf([
            'enabled'      => '1',
            'area_tag'     => 'DEFAULT',
            'is_type'      => 'level-1-2',
            'metric_style' => 'gigantic',
            'auth_mode'    => 'none',
        ]))->assertRedirect();

        $this->assertNull($this->db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray());
    }

    public function testIsisSettingsRejectInvalidIsType(): void
    {
        $this->post('routing/isis/settings/save', $this->csrf([
            'enabled'      => '1',
            'area_tag'     => 'DEFAULT',
            'is_type'      => 'level-3',
            'metric_style' => 'wide',
            'auth_mode'    => 'none',
        ]))->assertRedirect();

        $this->assertNull($this->db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray());
    }

    // ------------------------------------------------------------------
    // IS-IS interfaces
    // ------------------------------------------------------------------

    public function testStoreIsisInterface(): void
    {
        $this->post('routing/isis/interfaces/store', $this->csrf([
            'interface'        => 'eth0',
            'description'      => 'WAN p2p',
            'circuit_type'     => 'level-2-only',
            'network_type'     => 'point-to-point',
            'metric_l2'        => '20',
            'hello_interval'   => '3',
            'hello_multiplier' => '3',
            'bfd_enabled'      => '1',
        ]))->assertRedirect();

        $row = $this->db->table('frr_isis_interfaces')->where('interface', 'eth0')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('ii_', $row['id']);
        $this->assertSame('level-2-only', $row['circuit_type']);
        $this->assertSame('point-to-point', $row['network_type']);
        $this->assertSame(20, (int) $row['metric_l2']);
        $this->assertNull($row['metric_l1']);
        $this->assertSame(1, (int) $row['bfd_enabled']);
    }

    public function testStoreIsisInterfaceDefaultsHelloTimers(): void
    {
        $this->post('routing/isis/interfaces/store', $this->csrf([
            'interface'    => 'eth1',
            'circuit_type' => 'level-1-2',
            'network_type' => 'broadcast',
        ]))->assertRedirect();

        $row = $this->db->table('frr_isis_interfaces')->where('interface', 'eth1')->get()->getRowArray();
        $this->assertSame(10, (int) $row['hello_interval']);
        $this->assertSame(3, (int) $row['hello_multiplier']);
    }

    public function testStoreIsisInterfaceRejectsDuplicateInterface(): void
    {
        $params = ['interface' => 'eth1', 'circuit_type' => 'level-1-2', 'network_type' => 'broadcast'];
        $this->post('routing/isis/interfaces/store', $this->csrf($params))->assertRedirect();
        $this->post('routing/isis/interfaces/store', $this->csrf($params))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_isis_interfaces')->countAllResults());
    }

    public function testStoreIsisInterfaceRejectsInvalidCircuitType(): void
    {
        $this->post('routing/isis/interfaces/store', $this->csrf([
            'interface'    => 'eth1',
            'circuit_type' => 'level-9',
            'network_type' => 'broadcast',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_isis_interfaces')->countAllResults());
    }

    public function testUpdateIsisInterface(): void
    {
        $this->post('routing/isis/interfaces/store', $this->csrf([
            'interface'    => 'eth1',
            'circuit_type' => 'level-1-2',
            'network_type' => 'broadcast',
        ]))->assertRedirect();
        $id = $this->db->table('frr_isis_interfaces')->get()->getRowArray()['id'];

        $this->post("routing/isis/interfaces/{$id}/update", $this->csrf([
            'interface'        => 'eth1',
            'circuit_type'     => 'level-1',
            'network_type'     => 'broadcast',
            'metric_l1'        => '42',
            'hello_interval'   => '5',
            'hello_multiplier' => '4',
        ]))->assertRedirect();

        $row = $this->db->table('frr_isis_interfaces')->where('id', $id)->get()->getRowArray();
        $this->assertSame('level-1', $row['circuit_type']);
        $this->assertSame(42, (int) $row['metric_l1']);
        $this->assertSame(5, (int) $row['hello_interval']);
    }

    public function testDeleteIsisInterface(): void
    {
        $this->post('routing/isis/interfaces/store', $this->csrf([
            'interface'    => 'eth2',
            'circuit_type' => 'level-1-2',
            'network_type' => 'broadcast',
        ]))->assertRedirect();
        $id = $this->db->table('frr_isis_interfaces')->get()->getRowArray()['id'];

        $this->post("routing/isis/interfaces/{$id}/delete", $this->csrf([]))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_isis_interfaces')->countAllResults());
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

    private function seedRouteMap(string $id, string $name, array $rules): void
    {
        $this->db->table('frr_route_maps')->insert([
            'id'          => $id,
            'name'        => $name,
            'description' => 'Seeded for tests',
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
