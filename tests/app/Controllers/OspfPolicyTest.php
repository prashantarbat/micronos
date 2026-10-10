<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for OSPF Policy (prefix-lists & route-maps):
 *   POST routing/ospf/policy/prefix-lists/store|update|delete
 *   POST routing/ospf/policy/route-maps/store|update|delete
 *   POST routing/ospf/settings/save    (redistribution route-map attachment)
 *
 * These exercise the real rule-builder code paths through the HTTP routes, so
 * the stored JSON rules, prefix validation and duplicate-name rejection are all
 * asserted end-to-end rather than via reflection into the controller.
 *
 * Auth and permission filters are satisfied through the session
 * (`user_id` + `user_permissions`), which PermissionModel reads before touching
 * the database. CSRF is satisfied by priming the cookie-based token.
 *
 * @internal
 */
final class OspfPolicyTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    // Shared in-memory database, migrated once; policy rows are cleared per test.
    protected $refresh = false;

    /** Permission keys the SuperAdmin-style session is granted. */
    private const PERMISSIONS = ['routing.table.view', 'routing.static.manage'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('frr_prefix_lists')->emptyTable();
        $this->db->table('frr_route_maps')->emptyTable();
        $this->db->table('frr_ospf_distribute_lists')->emptyTable();
        $this->db->table('frr_ospf_area_filters')->emptyTable();
        $this->db->table('frr_ospf_settings')->emptyTable();

        // Prime cookie-based CSRF: Security reads the token from the cookie on
        // construction, so seed it before the service is first resolved.
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    // ------------------------------------------------------------------
    // Prefix lists — rule building
    // ------------------------------------------------------------------

    public function testStoreBuildsRulesOrderedBySequence(): void
    {
        $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name'        => 'Banking_Applications',
            'description' => 'Banking hosts',
            // Deliberately out of order to prove the builder sorts by seq.
            'pl_seq'      => [30, 10, 20],
            'pl_action'   => ['permit', 'permit', 'deny'],
            'pl_prefix'   => ['10.29.3.51/32', '10.11.83.0/24', '10.0.0.0/8'],
            'pl_ge'       => ['', '', '24'],
            'pl_le'       => ['', '32', ''],
            'pl_desc'     => ['third', 'first', 'second'],
        ]))->assertRedirect();

        $row = $this->db->table('frr_prefix_lists')->where('name', 'Banking_Applications')->get()->getRowArray();
        $this->assertNotNull($row, 'Prefix-list was not persisted.');
        $this->assertStringStartsWith('pl_', $row['id']);
        $this->assertSame(1, (int) $row['enabled']);

        $rules = json_decode($row['rules'], true);
        $this->assertSame([10, 20, 30], array_column($rules, 'seq'), 'Rules must be stored in sequence order.');

        $this->assertSame(['seq' => 10, 'action' => 'permit', 'prefix' => '10.11.83.0/24', 'ge' => null, 'le' => 32, 'description' => 'first'], $rules[0]);
        $this->assertSame('deny', $rules[1]['action']);
        $this->assertSame(24, $rules[1]['ge']);
        $this->assertNull($rules[1]['le']);
        $this->assertSame('third', $rules[2]['description']);
    }

    public function testStoreDefaultsActionToPermitAndSequenceWhenBlank(): void
    {
        $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name'      => 'DEFAULTS_TEST',
            'pl_seq'    => ['', ''],
            'pl_action' => ['', ''],
            'pl_prefix' => ['10.1.1.0/24', '10.2.2.0/24'],
        ]))->assertRedirect();

        $rules = json_decode(
            $this->db->table('frr_prefix_lists')->where('name', 'DEFAULTS_TEST')->get()->getRowArray()['rules'],
            true,
        );

        $this->assertSame(10, $rules[0]['seq'], 'A blank sequence should fall back to (index+1)*10.');
        $this->assertSame(20, $rules[1]['seq']);
        $this->assertSame('permit', $rules[0]['action']);
    }

    public function testStoreRejectsInvalidPrefix(): void
    {
        $result = $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name'      => 'BAD_PREFIX',
            'pl_prefix' => ['not-a-prefix'],
        ]));

        $result->assertRedirect();
        $this->assertStringContainsString('sub=policy', (string) $result->getRedirectUrl());
        $this->assertSame(0, $this->db->table('frr_prefix_lists')->where('name', 'BAD_PREFIX')->countAllResults());
    }

    public function testStoreRejectsPrefixWithOutOfRangeLength(): void
    {
        $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name'      => 'BAD_MASK',
            'pl_prefix' => ['10.0.0.0/99'],
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_prefix_lists')->where('name', 'BAD_MASK')->countAllResults());
    }

    public function testStoreRequiresAtLeastOneRule(): void
    {
        $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name' => 'EMPTY_RULES',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_prefix_lists')->where('name', 'EMPTY_RULES')->countAllResults());
    }

    public function testStoreRejectsInvalidName(): void
    {
        $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name'      => 'bad name!!',
            'pl_prefix' => ['10.0.0.0/8'],
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_prefix_lists')->countAllResults());
    }

    public function testStoreRejectsDuplicateName(): void
    {
        $this->seedPrefixList('pl_existing', 'Banking_Applications', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.11.83.0/24', 'ge' => null, 'le' => null, 'description' => 'original'],
        ]);

        $this->post('routing/ospf/policy/prefix-lists/store', $this->csrf([
            'name'      => 'Banking_Applications',
            'pl_prefix' => ['10.99.0.0/16'],
        ]))->assertRedirect();

        $rows = $this->db->table('frr_prefix_lists')->get()->getResultArray();
        $this->assertCount(1, $rows, 'Duplicate name must not create a second prefix-list.');

        $rules = json_decode($rows[0]['rules'], true);
        $this->assertSame('10.11.83.0/24', $rules[0]['prefix'], 'The existing prefix-list must be left untouched.');
    }

    // ------------------------------------------------------------------
    // Prefix lists — update & delete
    // ------------------------------------------------------------------

    public function testUpdateReplacesRules(): void
    {
        $this->seedPrefixList('pl_upd', 'OLD_NAME', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/prefix-lists/pl_upd/update', $this->csrf([
            'name'      => 'NEW_NAME',
            'pl_seq'    => [50, 40],
            'pl_action' => ['permit', 'deny'],
            'pl_prefix' => ['10.2.2.0/24', '10.3.3.0/24'],
        ]))->assertRedirect();

        $row = $this->db->table('frr_prefix_lists')->where('id', 'pl_upd')->get()->getRowArray();
        $this->assertSame('NEW_NAME', $row['name']);
        $rules = json_decode($row['rules'], true);
        $this->assertCount(2, $rules);
        $this->assertSame([40, 50], array_column($rules, 'seq'));
    }

    public function testUpdateRejectsDuplicateNameAndLeavesRowUntouched(): void
    {
        $this->seedPrefixList('pl_a', 'LIST_A', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);
        $this->seedPrefixList('pl_b', 'LIST_B', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.2.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/prefix-lists/pl_b/update', $this->csrf([
            'name'      => 'LIST_A',
            'pl_prefix' => ['10.2.0.0/16'],
        ]))->assertRedirect();

        $row = $this->db->table('frr_prefix_lists')->where('id', 'pl_b')->get()->getRowArray();
        $this->assertSame('LIST_B', $row['name'], 'A rejected rename must not alter the row.');
        $this->assertSame(2, $this->db->table('frr_prefix_lists')->countAllResults());
    }

    public function testDeleteRemovesPrefixList(): void
    {
        $this->seedPrefixList('pl_del', 'DELETE_ME', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/prefix-lists/pl_del/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_prefix_lists')->where('id', 'pl_del')->countAllResults());
    }

    public function testCannotDeletePrefixListReferencedByRouteMap(): void
    {
        $this->seedPrefixList('pl_used', 'PL_USED', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);
        $this->seedRouteMap('rm_ref', 'RM_REF', [
            ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'PL_USED', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/prefix-lists/pl_used/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_prefix_lists')->where('id', 'pl_used')->countAllResults(),
            'A prefix-list matched by a route-map must not be deleted.');
    }

    public function testCannotDeletePrefixListReferencedByDistributeListOrAreaFilter(): void
    {
        $this->seedPrefixList('pl_used', 'PL_USED', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);
        $this->db->table('frr_ospf_distribute_lists')->insert([
            'id' => 'odl_ref', 'prefix_list' => 'PL_USED', 'direction' => 'in', 'interface' => null,
            'description' => '', 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('frr_ospf_area_filters')->insert([
            'id' => 'oaf_ref', 'area_id' => '0.0.0.3', 'prefix_list' => 'PL_USED', 'direction' => 'in',
            'description' => '', 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->post('routing/ospf/policy/prefix-lists/pl_used/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_prefix_lists')->where('id', 'pl_used')->countAllResults(),
            'A prefix-list used by a distribute-list or area filter must not be deleted.');
    }

    public function testDeletePrefixListSucceedsOnceReferencesRemoved(): void
    {
        $this->seedPrefixList('pl_used', 'PL_USED', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null, 'description' => ''],
        ]);
        $this->seedRouteMap('rm_ref', 'RM_REF', [
            ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'PL_USED', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/route-maps/rm_ref/delete', $this->csrf([]))->assertRedirect();
        $this->post('routing/ospf/policy/prefix-lists/pl_used/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_prefix_lists')->where('id', 'pl_used')->countAllResults(),
            'Once the referencing route-map is gone the prefix-list may be deleted.');
    }

    // ------------------------------------------------------------------
    // Route maps — rule building
    // ------------------------------------------------------------------

    public function testStoreBuildsRouteMapRulesWithMatchAndSet(): void
    {
        $this->post('routing/ospf/policy/route-maps/store', $this->csrf([
            'name'               => 'Banking_Applications',
            'description'        => 'Permit banking prefixes',
            'rm_seq'             => [20, 10],
            'rm_action'          => ['permit', 'permit'],
            'rm_match_pl'        => ['IFTAS_EKUBER', 'Banking_Applications'],
            'rm_set_metric'      => ['', '100'],
            'rm_set_metric_type' => ['', 'type-1'],
            'rm_set_tag'         => ['7', ''],
            'rm_desc'            => ['second', 'first'],
        ]))->assertRedirect();

        $row = $this->db->table('frr_route_maps')->where('name', 'Banking_Applications')->get()->getRowArray();
        $this->assertNotNull($row, 'Route-map was not persisted.');
        $this->assertStringStartsWith('rm_', $row['id']);

        $rules = json_decode($row['rules'], true);
        $this->assertSame([10, 20], array_column($rules, 'seq'), 'Rules must be stored in sequence order.');
        $this->assertSame('Banking_Applications', $rules[0]['match_prefix_list']);
        $this->assertSame(100, $rules[0]['set_metric']);
        $this->assertSame('type-1', $rules[0]['set_metric_type']);
        $this->assertNull($rules[0]['set_tag']);
        $this->assertSame(7, $rules[1]['set_tag']);
        $this->assertNull($rules[1]['set_metric']);
    }

    public function testStoreRejectsInvalidMetricType(): void
    {
        $this->post('routing/ospf/policy/route-maps/store', $this->csrf([
            'name'               => 'BAD_TYPE',
            'rm_seq'             => [10],
            'rm_match_pl'        => [''],
            'rm_set_metric'      => ['10'],
            'rm_set_metric_type' => ['type-9'],
        ]))->assertRedirect();

        $row = $this->db->table('frr_route_maps')->where('name', 'BAD_TYPE')->get()->getRowArray();
        $this->assertNotNull($row);
        $rules = json_decode($row['rules'], true);
        $this->assertNull($rules[0]['set_metric_type'], 'An unrecognised metric type must be discarded, not stored.');
    }

    public function testStoreRejectsNoOpRouteMapRule(): void
    {
        // A rule that matches nothing and sets nothing is a no-op, so a
        // route-map made only of such rules must be rejected.
        $this->post('routing/ospf/policy/route-maps/store', $this->csrf([
            'name'               => 'NOOP',
            'rm_seq'             => [10],
            'rm_action'          => ['permit'],
            'rm_match_pl'        => [''],
            'rm_set_metric'      => [''],
            'rm_set_metric_type' => [''],
            'rm_set_tag'         => [''],
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_route_maps')->where('name', 'NOOP')->countAllResults());
    }

    public function testStoreRejectsDuplicateRouteMapName(): void
    {
        $this->seedRouteMap('rm_existing', 'RM_DUP', [
            ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'LIST_A', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/route-maps/store', $this->csrf([
            'name'        => 'RM_DUP',
            'rm_seq'      => [10],
            'rm_match_pl' => ['LIST_B'],
        ]))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_route_maps')->countAllResults());
    }

    public function testUpdateAndDeleteRouteMap(): void
    {
        $this->seedRouteMap('rm_upd', 'RM_OLD', [
            ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'LIST_A', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/route-maps/rm_upd/update', $this->csrf([
            'name'        => 'RM_NEW',
            'rm_seq'      => [10],
            'rm_action'   => ['deny'],
            'rm_match_pl' => ['LIST_B'],
        ]))->assertRedirect();

        $row = $this->db->table('frr_route_maps')->where('id', 'rm_upd')->get()->getRowArray();
        $this->assertSame('RM_NEW', $row['name']);
        $rules = json_decode($row['rules'], true);
        $this->assertSame('deny', $rules[0]['action']);
        $this->assertSame('LIST_B', $rules[0]['match_prefix_list']);

        $this->post('routing/ospf/policy/route-maps/rm_upd/delete', $this->csrf([]))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_route_maps')->where('id', 'rm_upd')->countAllResults());
    }

    public function testCannotDeleteRouteMapReferencedByRedistribution(): void
    {
        $this->seedRouteMap('rm_used', 'RM_USED', []);
        $this->db->table('frr_ospf_settings')->insert([
            'id'                     => 'default',
            'redistribute_static_rm' => 'RM_USED',
        ]);

        $this->post('routing/ospf/policy/route-maps/rm_used/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_route_maps')->where('id', 'rm_used')->countAllResults(),
            'A route-map attached to redistribution must not be deleted.');

        // Detaching it makes deletion possible again.
        $this->db->table('frr_ospf_settings')->where('id', 'default')->update(['redistribute_static_rm' => null]);
        $this->post('routing/ospf/policy/route-maps/rm_used/delete', $this->csrf([]))->assertRedirect();
        $this->assertSame(0, $this->db->table('frr_route_maps')->where('id', 'rm_used')->countAllResults());
    }

    public function testCannotDeleteRouteMapReferencedByBgpNeighbor(): void
    {
        $this->seedRouteMap('rm_used', 'RM_USED', []);
        $this->db->table('frr_bgp_neighbors')->insert([
            'id'            => 'bn_ref',
            'neighbor_ip'   => '203.0.113.9',
            'remote_as'     => 65002,
            'route_map_in'  => 'RM_USED',
            'route_map_out' => null,
            'enabled'       => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->post('routing/ospf/policy/route-maps/rm_used/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(1, $this->db->table('frr_route_maps')->where('id', 'rm_used')->countAllResults(),
            'A route-map applied to a BGP neighbor must not be deleted.');

        $this->db->table('frr_bgp_neighbors')->where('id', 'bn_ref')->delete();
    }

    // ------------------------------------------------------------------
    // Distribute lists
    // ------------------------------------------------------------------

    public function testStoreDistributeListPersistsInterface(): void
    {
        $this->seedPrefixList('pl_dl', 'PL_DL', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/distribute-lists/store', $this->csrf([
            'prefix_list' => 'PL_DL',
            'direction'   => 'in',
            'interface'   => 'eth0',
            'description' => 'Inbound on the WAN link',
        ]))->assertRedirect();

        $row = $this->db->table('frr_ospf_distribute_lists')->get()->getRowArray();
        $this->assertNotNull($row, 'Distribute-list was not persisted.');
        $this->assertStringStartsWith('odl_', $row['id']);
        $this->assertSame('PL_DL', $row['prefix_list']);
        $this->assertSame('in', $row['direction']);
        $this->assertSame('eth0', $row['interface']);
    }

    public function testStoreDistributeListDropsInterfaceForOutbound(): void
    {
        $this->seedPrefixList('pl_dl', 'PL_DL', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        // FRR's outbound form takes no interface; a posted one must be dropped.
        $this->post('routing/ospf/policy/distribute-lists/store', $this->csrf([
            'prefix_list' => 'PL_DL',
            'direction'   => 'out',
            'interface'   => 'eth0',
        ]))->assertRedirect();

        $row = $this->db->table('frr_ospf_distribute_lists')->get()->getRowArray();
        $this->assertSame('out', $row['direction']);
        $this->assertNull($row['interface']);
    }

    public function testStoreDistributeListRejectsUnknownPrefixList(): void
    {
        $this->post('routing/ospf/policy/distribute-lists/store', $this->csrf([
            'prefix_list' => 'GHOST',
            'direction'   => 'in',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_ospf_distribute_lists')->countAllResults());
    }

    public function testDeleteDistributeList(): void
    {
        $this->seedPrefixList('pl_dl', 'PL_DL', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8', 'ge' => null, 'le' => null, 'description' => ''],
        ]);
        $this->db->table('frr_ospf_distribute_lists')->insert([
            'id' => 'odl_del', 'prefix_list' => 'PL_DL', 'direction' => 'in', 'interface' => null,
            'description' => '', 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->post('routing/ospf/policy/distribute-lists/odl_del/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_ospf_distribute_lists')->where('id', 'odl_del')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Area filter lists
    // ------------------------------------------------------------------

    public function testStoreAreaFilterPersists(): void
    {
        $this->seedPrefixList('pl_af', 'PL_AF', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/policy/area-filters/store', $this->csrf([
            'area_id'     => '0.0.0.1',
            'prefix_list' => 'PL_AF',
            'direction'   => 'in',
            'description' => 'Branch area filtering',
        ]))->assertRedirect();

        $row = $this->db->table('frr_ospf_area_filters')->get()->getRowArray();
        $this->assertNotNull($row, 'Area filter-list was not persisted.');
        $this->assertStringStartsWith('oaf_', $row['id']);
        $this->assertSame('0.0.0.1', $row['area_id']);
        $this->assertSame('PL_AF', $row['prefix_list']);
        $this->assertSame('in', $row['direction']);
    }

    public function testStoreAreaFilterRejectsInvalidArea(): void
    {
        $this->seedPrefixList('pl_af', 'PL_AF', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8', 'ge' => null, 'le' => null, 'description' => ''],
        ]);

        foreach (['bad-area', '1.2.3.4.5', ''] as $bad) {
            $this->post('routing/ospf/policy/area-filters/store', $this->csrf([
                'area_id'     => $bad,
                'prefix_list' => 'PL_AF',
                'direction'   => 'in',
            ]))->assertRedirect();
        }

        $this->assertSame(0, $this->db->table('frr_ospf_area_filters')->countAllResults());
    }

    public function testDeleteAreaFilter(): void
    {
        $this->seedPrefixList('pl_af', 'PL_AF', [
            ['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8', 'ge' => null, 'le' => null, 'description' => ''],
        ]);
        $this->db->table('frr_ospf_area_filters')->insert([
            'id' => 'oaf_del', 'area_id' => '0.0.0.2', 'prefix_list' => 'PL_AF', 'direction' => 'in',
            'description' => '', 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->post('routing/ospf/policy/area-filters/oaf_del/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('frr_ospf_area_filters')->where('id', 'oaf_del')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Redistribution attachment
    // ------------------------------------------------------------------

    public function testSaveOspfSettingsAttachesKnownRouteMapToRedistribution(): void
    {
        $this->seedRouteMap('rm_redist', 'Banking_Applications', [
            ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'Banking_Applications', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null, 'description' => ''],
        ]);

        $this->post('routing/ospf/settings/save', $this->csrf([
            'enabled'                => '1',
            'redistribute_static'    => '1',
            'redistribute_static_rm' => 'Banking_Applications',
        ]))->assertRedirect();

        $row = $this->db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertSame('Banking_Applications', $row['redistribute_static_rm']);
    }

    public function testSaveOspfSettingsDropsUnknownRouteMap(): void
    {
        // Referencing a route-map that does not exist would make FRR reject the
        // config at load time, so it must be stored as NULL.
        $this->post('routing/ospf/settings/save', $this->csrf([
            'enabled'             => '1',
            'redistribute_static' => '1',
            'redistribute_static_rm' => 'GHOST_MAP',
        ]))->assertRedirect();

        $row = $this->db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray();
        $this->assertNull($row['redistribute_static_rm']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Compiled FRR policy preview
    // ------------------------------------------------------------------

    public function testCompilePolicyRendersAllFourObjectTypes(): void
    {
        $prefixLists = [[
            'name' => 'PL_PREVIEW', 'description' => 'Preview list', 'enabled' => 1,
            'rules' => json_encode([
                ['seq' => 10, 'action' => 'permit', 'prefix' => '10.11.83.0/24', 'ge' => null, 'le' => null],
                ['seq' => 20, 'action' => 'deny', 'prefix' => '10.0.0.0/8', 'ge' => 24, 'le' => 32],
            ]),
        ]];
        $routeMaps = [[
            'name' => 'RM_PREVIEW', 'description' => '', 'enabled' => 1,
            'rules' => json_encode([
                ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'PL_PREVIEW', 'set_metric' => 100, 'set_metric_type' => 'type-2', 'set_tag' => 50],
            ]),
        ]];
        $distributeLists = [['prefix_list' => 'PL_PREVIEW', 'direction' => 'in', 'interface' => 'eth1', 'enabled' => 1]];
        $areaFilters     = [['area_id' => '0.0.0.5', 'prefix_list' => 'PL_PREVIEW', 'direction' => 'out', 'enabled' => 1]];

        $config = \App\Controllers\Routing::compileOspfPolicy($prefixLists, $routeMaps, $distributeLists, $areaFilters);

        $this->assertStringContainsString('ip prefix-list PL_PREVIEW seq 10 permit 10.11.83.0/24', $config);
        $this->assertStringContainsString('ip prefix-list PL_PREVIEW seq 20 deny 10.0.0.0/8 ge 24 le 32', $config);
        $this->assertStringContainsString('route-map RM_PREVIEW permit 10', $config);
        $this->assertStringContainsString(' match ip address prefix-list PL_PREVIEW', $config);
        $this->assertStringContainsString(' set metric 100', $config);
        $this->assertStringContainsString(' set metric-type type-2', $config);
        $this->assertStringContainsString(' set tag 50', $config);
        $this->assertStringContainsString('router ospf', $config);
        $this->assertStringContainsString(' distribute-list PL_PREVIEW in eth1', $config);
        $this->assertStringContainsString(' area 0.0.0.5 filter-list prefix PL_PREVIEW out', $config);
    }

    public function testCompilePolicyUsesIpv6KeywordForV6Objects(): void
    {
        $prefixLists = [[
            'name' => 'PL6', 'description' => '', 'enabled' => 1,
            'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'prefix' => '2001:db8::/32', 'ge' => null, 'le' => null]]),
        ]];
        $routeMaps = [[
            'name' => 'RM6', 'description' => '', 'enabled' => 1,
            'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'PL6', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null]]),
        ]];

        $config = \App\Controllers\Routing::compileOspfPolicy($prefixLists, $routeMaps, [], []);

        $this->assertStringContainsString('ipv6 prefix-list PL6 seq 10 permit 2001:db8::/32', $config);
        $this->assertStringContainsString(' match ipv6 address prefix-list PL6', $config);
    }

    public function testCompilePolicyOmitsOutboundDistributeListInterface(): void
    {
        $config = \App\Controllers\Routing::compileOspfPolicy([], [], [
            ['prefix_list' => 'PL_OUT', 'direction' => 'out', 'interface' => 'eth9', 'enabled' => 1],
        ], []);

        $this->assertStringContainsString(' distribute-list PL_OUT out', $config);
        $this->assertStringNotContainsString('eth9', $config, 'The outbound form takes no interface argument.');
    }

    public function testCompilePolicyOmitsDisabledObjectsButListsThem(): void
    {
        $prefixLists = [[
            'name' => 'PL_OFF', 'description' => '', 'enabled' => 0,
            'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'prefix' => '10.1.0.0/16', 'ge' => null, 'le' => null]]),
        ]];

        $config = \App\Controllers\Routing::compileOspfPolicy($prefixLists, [], [], []);

        $this->assertStringNotContainsString('ip prefix-list PL_OFF', $config);
        $this->assertStringContainsString('Disabled (not compiled): prefix-list PL_OFF', $config);
    }

    public function testCompilePolicyWithNoObjectsShowsPlaceholders(): void
    {
        $config = \App\Controllers\Routing::compileOspfPolicy([], [], [], []);

        $this->assertStringContainsString('! (none)', $config);
        $this->assertStringContainsString('! (no distribute-lists or area filter-lists)', $config);
    }

    // ------------------------------------------------------------------
    // Stale reference detection
    // ------------------------------------------------------------------

    public function testStaleReferencesFlagMissingPrefixLists(): void
    {
        $routeMaps = [[
            'name' => 'RM_STALE', 'enabled' => 1,
            'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'GONE_PL']]),
        ]];
        $distributeLists = [['prefix_list' => 'GONE_DL', 'direction' => 'in', 'interface' => null, 'enabled' => 1]];
        $areaFilters     = [['area_id' => '0.0.0.1', 'prefix_list' => 'GONE_AF', 'direction' => 'in', 'enabled' => 1]];

        $stale = \App\Controllers\Routing::staleOspfReferences([], $routeMaps, $distributeLists, $areaFilters);

        $this->assertCount(3, $stale);
        $this->assertSame('prefix-list', $stale[0]['type']);
        $this->assertSame('GONE_PL', $stale[0]['name']);
        $this->assertSame('Route-map RM_STALE', $stale[0]['referenced_by']);
        $this->assertSame('GONE_DL', $stale[1]['name']);
        $this->assertSame('OSPF distribute-list inbound', $stale[1]['referenced_by']);
        $this->assertSame('GONE_AF', $stale[2]['name']);
        $this->assertSame('OSPF area 0.0.0.1 filter-list in', $stale[2]['referenced_by']);
    }

    public function testStaleReferencesFlagMissingRedistributionRouteMap(): void
    {
        $ospfSettings = ['redistribute_static_rm' => 'GONE_RM', 'redistribute_connected_rm' => null];

        $stale = \App\Controllers\Routing::staleOspfReferences([], [], [], [], $ospfSettings);

        $this->assertCount(1, $stale);
        $this->assertSame('route-map', $stale[0]['type']);
        $this->assertSame('GONE_RM', $stale[0]['name']);
        $this->assertSame('OSPF redistribute static', $stale[0]['referenced_by']);
    }

    public function testStaleReferencesEmptyWhenEverythingExists(): void
    {
        $prefixLists = [['name' => 'PL_OK', 'enabled' => 1, 'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'prefix' => '10.0.0.0/8']])]];
        $routeMaps   = [[
            'name' => 'RM_OK', 'enabled' => 1,
            'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'PL_OK']]),
        ]];
        $distributeLists = [['prefix_list' => 'PL_OK', 'direction' => 'out', 'interface' => null, 'enabled' => 1]];
        $areaFilters     = [['area_id' => '0.0.0.9', 'prefix_list' => 'PL_OK', 'direction' => 'in', 'enabled' => 1]];
        $ospfSettings    = ['redistribute_bgp_rm' => 'RM_OK'];

        $stale = \App\Controllers\Routing::staleOspfReferences($prefixLists, $routeMaps, $distributeLists, $areaFilters, $ospfSettings);

        $this->assertSame([], $stale);
    }

    public function testCompilePolicyAnnotatesMissingPrefixListReference(): void
    {
        $routeMaps = [[
            'name' => 'RM_STALE', 'description' => '', 'enabled' => 1,
            'rules' => json_encode([['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'GONE_PL']]),
        ]];
        $distributeLists = [['prefix_list' => 'GONE_DL', 'direction' => 'in', 'interface' => null, 'enabled' => 1]];

        $config = \App\Controllers\Routing::compileOspfPolicy([], $routeMaps, $distributeLists, []);

        $this->assertStringContainsString('! WARNING: prefix-list GONE_PL does not exist', $config);
        $this->assertStringContainsString('! WARNING: prefix-list GONE_DL does not exist', $config);
    }

    /** Returns the session array with the granted permission set. */
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

    /** Returns POST params with the current CSRF token merged in. */
    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    private function seedPrefixList(string $id, string $name, array $rules): void
    {
        $this->db->table('frr_prefix_lists')->insert([
            'id'          => $id,
            'name'        => $name,
            'description' => 'Seeded for tests',
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
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
