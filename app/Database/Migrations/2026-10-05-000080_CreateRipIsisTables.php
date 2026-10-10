<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * FRRouting RIP & IS-IS Tables
 *
 * RIP (ripd):
 *  - frr_rip_settings           : Global `router rip` options (version, timers,
 *                                 redistribution, authentication, distance)
 *  - frr_rip_networks           : `network A.B.C.D/M` statements
 *  - frr_rip_neighbors          : `neighbor A.B.C.D` unicast peering
 *  - frr_rip_passive_interfaces : `passive-interface IFACE`
 *
 * IS-IS (isisd):
 *  - frr_isis_settings          : Global `router isis AREA-TAG` options (NET,
 *                                 is-type, metric-style, SPF/LSP timers, auth)
 *  - frr_isis_interfaces        : Per-interface circuit settings (circuit-type,
 *                                 metric, hello/CSNP/PSNP intervals, BFD)
 */
class CreateRipIsisTables extends Migration
{
    public function up(): void
    {
        // ── frr_rip_settings ────────────────────────────────────────────
        $this->forge->addField([
            'id'                    => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'default'],
            'enabled'               => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'version'               => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 2],   // 1|2
            'redistribute_connected'=> ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_static'   => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_bgp'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_ospf'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_connected_rm'=> ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_static_rm'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_bgp_rm'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_ospf_rm'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_metric'   => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'default_information'   => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'default_information_rm'=> ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'distance'              => ['type' => 'INTEGER',  'null' => true,  'default' => null],   // admin distance
            'maximum_paths'         => ['type' => 'INTEGER',  'null' => false, 'default' => 4],
            'timers_update'         => ['type' => 'INTEGER',  'null' => false, 'default' => 30],   // seconds
            'timers_timeout'        => ['type' => 'INTEGER',  'null' => false, 'default' => 180],  // seconds
            'timers_garbage'        => ['type' => 'INTEGER',  'null' => false, 'default' => 120],  // seconds
            'passive_default'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'auth_mode'             => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'none'], // none|text|md5
            'auth_password'         => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_rip_settings', true);

        // ── frr_rip_networks ────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'network'     => ['type' => 'VARCHAR',  'constraint' => 50,  'null' => false],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_rip_networks', true);

        // ── frr_rip_neighbors ───────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'neighbor_ip' => ['type' => 'VARCHAR',  'constraint' => 45,  'null' => false],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_rip_neighbors', true);

        // ── frr_rip_passive_interfaces ──────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'interface'   => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => false],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_rip_passive_interfaces', true);

        // ── frr_isis_settings ───────────────────────────────────────────
        $this->forge->addField([
            'id'                    => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'default'],
            'enabled'               => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'area_tag'              => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => false, 'default' => 'DEFAULT'],
            'net'                   => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'is_type'               => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'level-1-2'],
            'metric_style'          => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'wide'],
            'log_adjacency'         => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'lsp_gen_interval'      => ['type' => 'INTEGER',  'null' => true,  'default' => null],   // ms
            'spf_interval_l1'       => ['type' => 'INTEGER',  'null' => true,  'default' => null],   // ms
            'spf_interval_l2'       => ['type' => 'INTEGER',  'null' => true,  'default' => null],   // ms
            'max_lsp_lifetime'      => ['type' => 'INTEGER',  'null' => true,  'default' => null],   // seconds
            'redistribute_connected'=> ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_static'   => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_bgp'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_ospf'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_connected_rm'=> ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_static_rm'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_bgp_rm'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_ospf_rm'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true,  'default' => null],
            'redistribute_metric'   => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'auth_mode'             => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'none'], // none|text|md5
            'auth_password'         => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'passive_default'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_isis_settings', true);

        // ── frr_isis_interfaces ─────────────────────────────────────────
        $this->forge->addField([
            'id'               => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'interface'        => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => false],
            'description'      => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'circuit_type'     => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'level-1-2'], // level-1|level-1-2|level-2-only
            'network_type'     => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'broadcast'], // broadcast|point-to-point
            'metric_l1'        => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'metric_l2'        => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'hello_interval'   => ['type' => 'INTEGER',  'null' => false, 'default' => 10],   // seconds
            'hello_multiplier' => ['type' => 'INTEGER',  'null' => false, 'default' => 3],
            'csnp_interval'    => ['type' => 'INTEGER',  'null' => true,  'default' => null], // seconds
            'psnp_interval'    => ['type' => 'INTEGER',  'null' => true,  'default' => null], // seconds
            'password'         => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'bfd_enabled'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'enabled'          => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'       => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('interface');
        $this->forge->createTable('frr_isis_interfaces', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('frr_isis_interfaces', true);
        $this->forge->dropTable('frr_isis_settings', true);
        $this->forge->dropTable('frr_rip_passive_interfaces', true);
        $this->forge->dropTable('frr_rip_neighbors', true);
        $this->forge->dropTable('frr_rip_networks', true);
        $this->forge->dropTable('frr_rip_settings', true);
    }
}
