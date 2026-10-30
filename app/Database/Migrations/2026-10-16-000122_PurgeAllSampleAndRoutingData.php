<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 122: Unconditionally purge all sample, demo, and confidential
 * routing artifacts from the database across both appliance and installer environments.
 *
 * Ensures universal installer cleanliness:
 * 1. Purges sample/hardcoded prefix lists and route maps (including any legacy banking/test artifacts).
 * 2. Purges sample distribute lists and area filter references.
 * 3. Cleanses frr_ospf_settings redistribution pointers if referencing purged objects.
 * 4. Purges mock Multi-WAN gateways (gw_wan1_fiber, gw_wan2_cable) and gateway groups.
 * 5. Purges sample static routes.
 * 6. Purges demo mock records across threat intelligence, ZTNA, SD-WAN, and external auth.
 */
class PurgeAllSampleAndRoutingData extends Migration
{
    public function up(): void
    {
        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        $del = static function (string $table, array $where = [], array $whereIn = []) use ($db): void {
            if (! $db->tableExists($table)) {
                return;
            }
            $b = $db->table($table);
            if (! empty($where)) {
                $b->where($where);
            }
            foreach ($whereIn as $col => $values) {
                $b->whereIn($col, $values);
            }
            $b->delete();
        };

        $set = static function (string $table, array $where, array $values) use ($db): void {
            if (! $db->tableExists($table)) {
                return;
            }
            $fields = $db->getFieldNames($table);
            $values = array_intersect_key($values, array_flip($fields));
            if ($values !== []) {
                $db->table($table)->where($where)->update($values);
            }
        };

        // 1. Purge legacy / sample prefix lists
        $del('frr_prefix_lists', [], [
            'name' => [
                'Banking_Applications',
                'Banking_DR_Applications',
                'IFTAS_EKUBER',
                'PL_SAMPLE_DEV',
            ],
        ]);

        // Also delete any prefix lists that reference sensitive subnets in rules
        if ($db->tableExists('frr_prefix_lists')) {
            $rows = $db->table('frr_prefix_lists')->get()->getResultArray();
            foreach ($rows as $r) {
                $rulesText = (string) ($r['rules'] ?? '');
                if (str_contains($rulesText, '10.11.83.') || str_contains($rulesText, '10.29.')) {
                    $db->table('frr_prefix_lists')->where('id', $r['id'])->delete();
                }
            }
        }

        // 2. Purge legacy / sample route maps
        $del('frr_route_maps', [], [
            'name' => [
                'Banking_Applications',
                'Banking_DR_Applications',
                'odl_banking_0001',
                'oaf_branch_0001',
                'RM_SAMPLE_DEV',
            ],
        ]);

        // 3. Purge distribute-lists & area-filters referencing purged objects
        $del('frr_ospf_distribute_lists', [], [
            'prefix_list' => ['Banking_Applications', 'Banking_DR_Applications', 'IFTAS_EKUBER'],
        ]);
        $del('frr_ospf_area_filters', [], [
            'prefix_list' => ['Banking_Applications', 'Banking_DR_Applications', 'IFTAS_EKUBER'],
        ]);

        // 4. Reset frr_ospf_settings redistribution pointers if invalid
        if ($db->tableExists('frr_ospf_settings')) {
            $settings = $db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray();
            if ($settings) {
                $updates = [];
                foreach (['redistribute_connected_rm', 'redistribute_static_rm', 'redistribute_bgp_rm', 'redistribute_rip_rm'] as $col) {
                    $rmName = (string) ($settings[$col] ?? '');
                    if ($rmName !== '') {
                        $exists = $db->table('frr_route_maps')->where('name', $rmName)->countAllResults();
                        if ($exists === 0) {
                            $updates[$col] = null;
                        }
                    }
                }
                if ($updates !== []) {
                    $updates['updated_at'] = $now;
                    $db->table('frr_ospf_settings')->where('id', 'default')->update($updates);
                }
            }
        }

        // 5. Purge sample Multi-WAN Gateways and Groups
        $del('wan_gateway_groups', ['id' => 'gwg_failover_highavail']);
        $del('wan_gateways', [], ['id' => ['gw_wan1_fiber', 'gw_wan2_cable']]);

        // 6. Purge sample static routes
        $del('static_routes', ['id' => 'rt_sample_vpn_001']);
        $del('static_routes', ['destination' => '192.168.100.0/24', 'gateway' => '10.0.0.1']);

        // 7. Purge development demo records across auxiliary tables
        $del('threat_indicators', [], ['id' => ['ti_1', 'ti_2', 'ti_3', 'ti_4']]);
        $del('ids_alerts', [], ['src_ip' => ['198.51.100.44', '203.0.113.88', '45.155.205.233', '192.168.1.105']]);
        $del('ztna_resources', [], ['id' => ['res_erp', 'res_win_dc', 'res_bastion_ssh', 'res_scada_vnc']]);
        $del('sdwan_rules', [], ['id' => ['rule_voip_steering', 'rule_cloud_steering']]);
        $del('sdwan_members', [], ['id' => ['mem_wan1', 'mem_wan2', 'mem_lte']]);
        $del('auth_group_mappings', [], ['id' => ['map_ldap_admins', 'map_ldap_netops', 'map_radius_admins', 'map_radius_operators']]);
        $del('snmp_v3_users', ['id' => 'snmp3_admin']);
    }

    public function down(): void
    {
        // Sample data purge is irreversible by design to maintain security integrity.
    }
}
