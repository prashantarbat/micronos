<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * FRRouting Policy Tables (prefix-lists & route-maps)
 *
 * Creates:
 *  - frr_prefix_lists : Named prefix-lists (rules stored as JSON so a list and
 *                       its ordered rules are read/written in one row)
 *  - frr_route_maps   : Named route-maps, each rule matching a prefix-list and
 *                       optionally setting OSPF metric / metric-type / tag
 *
 * Also extends frr_ospf_settings with per-source route-map columns so a
 * redistribution source (connected/static/bgp/rip) can be filtered through a
 * route-map, e.g. `redistribute static route-map RM_INTERNAL_STATIC`.
 */
class CreateFrrPolicyTables extends Migration
{
    public function up(): void
    {
        // ── frr_prefix_lists ────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'name'        => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            // JSON [{seq, action, prefix, ge, le, description}]
            'rules'       => ['type' => 'TEXT',     'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('frr_prefix_lists', true);

        // ── frr_route_maps ──────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'name'        => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            // JSON [{seq, action, match_prefix_list, set_metric, set_metric_type, set_tag, description}]
            'rules'       => ['type' => 'TEXT',     'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('frr_route_maps', true);

        // ── frr_ospf_settings: redistribution route-maps ────────────────
        $this->forge->addColumn('frr_ospf_settings', [
            'redistribute_connected_rm' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'redistribute_static_rm'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'redistribute_bgp_rm'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'redistribute_rip_rm'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('frr_ospf_settings', [
            'redistribute_connected_rm',
            'redistribute_static_rm',
            'redistribute_bgp_rm',
            'redistribute_rip_rm',
        ]);
        $this->forge->dropTable('frr_route_maps', true);
        $this->forge->dropTable('frr_prefix_lists', true);
    }
}
