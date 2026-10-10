<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * OSPF Route Filtering Tables
 *
 * Creates:
 *  - frr_ospf_distribute_lists : Process-level distribute-lists
 *                                `distribute-list PREFIX-LIST in|out [INTERFACE]`
 *  - frr_ospf_area_filters     : Area filter-lists (ABR inter-area filtering)
 *                                `area AREA filter-list prefix PREFIX-LIST in|out`
 *
 * Both reference a prefix-list by name (frr_prefix_lists). They only affect
 * which routes OSPF accepts or advertises; they do not create policy of their
 * own, which is why they live alongside prefix-lists on the Policy tab.
 */
class CreateOspfFilterTables extends Migration
{
    public function up(): void
    {
        // ── frr_ospf_distribute_lists ───────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'prefix_list' => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'direction'   => ['type' => 'VARCHAR',  'constraint' => 3,   'null' => false, 'default' => 'in'], // in|out
            // Optional for `in` (a specific interface); FRR does not allow an
            // interface on `out`, so it is stored as NULL in that direction.
            'interface'   => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => true,  'default' => null],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_ospf_distribute_lists', true);

        // ── frr_ospf_area_filters ───────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'area_id'     => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false],  // 0.0.0.0 or integer
            'prefix_list' => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'direction'   => ['type' => 'VARCHAR',  'constraint' => 3,   'null' => false, 'default' => 'in'], // in|out
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_ospf_area_filters', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('frr_ospf_area_filters', true);
        $this->forge->dropTable('frr_ospf_distribute_lists', true);
    }
}
