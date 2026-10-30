<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates `frr_access_lists` table and enhances BGP & Static Route options
 */
class CreateRoutingPolicyAndEnhanceRoutes extends Migration
{
    public function up(): void
    {
        // 1. Create frr_access_lists table
        if (! $this->forge->getConnection()->tableExists('frr_access_lists')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
                'type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => false,
                    'default'    => 'standard', // standard | extended | ipv6
                ],
                'description' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'default'    => null,
                ],
                // JSON [{seq, action: 'permit'|'deny', prefix, description}]
                'rules' => [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'default' => null,
                ],
                'enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 1,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'default' => null,
                ],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('name');
            $this->forge->createTable('frr_access_lists', true);
        }

        // 2. Enhance frr_bgp_neighbors with prefix-list and filter-list columns
        $bgpNeighborFields = [];
        $bgpNeighborCols = $this->forge->getConnection()->getFieldNames('frr_bgp_neighbors');
        if (! in_array('prefix_list_in', $bgpNeighborCols, true)) {
            $bgpNeighborFields['prefix_list_in'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! in_array('prefix_list_out', $bgpNeighborCols, true)) {
            $bgpNeighborFields['prefix_list_out'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! in_array('filter_list_in', $bgpNeighborCols, true)) {
            $bgpNeighborFields['filter_list_in'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! in_array('filter_list_out', $bgpNeighborCols, true)) {
            $bgpNeighborFields['filter_list_out'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! empty($bgpNeighborFields)) {
            $this->forge->addColumn('frr_bgp_neighbors', $bgpNeighborFields);
        }

        // 3. Enhance frr_bgp_settings with redistribution route-map columns
        $bgpSettingsFields = [];
        $bgpSettingsCols = $this->forge->getConnection()->getFieldNames('frr_bgp_settings');
        if (! in_array('redistribute_connected_rm', $bgpSettingsCols, true)) {
            $bgpSettingsFields['redistribute_connected_rm'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! in_array('redistribute_static_rm', $bgpSettingsCols, true)) {
            $bgpSettingsFields['redistribute_static_rm'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! in_array('redistribute_ospf_rm', $bgpSettingsCols, true)) {
            $bgpSettingsFields['redistribute_ospf_rm'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null];
        }
        if (! empty($bgpSettingsFields)) {
            $this->forge->addColumn('frr_bgp_settings', $bgpSettingsFields);
        }

        // 4. Enhance static_routes with route type and table id
        $staticFields = [];
        $staticCols = $this->forge->getConnection()->getFieldNames('static_routes');
        if (! in_array('type', $staticCols, true)) {
            $staticFields['type'] = ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => 'gateway'];
        }
        if (! in_array('table_id', $staticCols, true)) {
            $staticFields['table_id'] = ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => 254];
        }
        if (! empty($staticFields)) {
            $this->forge->addColumn('static_routes', $staticFields);
        }
    }

    public function down(): void
    {
        if ($this->forge->getConnection()->tableExists('frr_access_lists')) {
            $this->forge->dropTable('frr_access_lists', true);
        }
    }
}
