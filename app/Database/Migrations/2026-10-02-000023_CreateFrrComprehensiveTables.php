<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Comprehensive FRRouting Configuration Tables
 *
 * Creates:
 *  - frr_ospf_settings  : Global OSPF process settings (Router-ID, SPF timers, redistribution)
 *  - frr_ospf_areas     : OSPF area configs (type, authentication, ABR shortcut, summarization)
 *  - frr_ospf_ifaces    : Per-interface OSPF settings (hello/dead, auth, network type, BFD)
 *  - frr_ospf_neighbors : Manual OSPF neighbors (point-to-multipoint / NBMA)
 *  - frr_bgp_settings   : Global BGP process settings (timers, redistribution, capabilities)
 */
class CreateFrrComprehensiveTables extends Migration
{
    public function up(): void
    {
        // ── frr_ospf_settings ────────────────────────────────────────────
        $this->forge->addField([
            'id'                    => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'default'],
            'enabled'               => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'router_id'             => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => true,  'default' => null],
            'log_adj_changes'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'spf_hold_time'         => ['type' => 'INTEGER',  'null' => false, 'default' => 1000],   // ms
            'spf_delay'             => ['type' => 'INTEGER',  'null' => false, 'default' => 200],    // ms
            'spf_min_hold'          => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'spf_max_hold'          => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'snmp_agentx'           => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'default_area'          => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false, 'default' => '0.0.0.0'],
            'passive_default'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_connected'=> ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_static'   => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_bgp'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_rip'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_metric'   => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'redistribute_metric_type'=> ['type' => 'TINYINT','constraint' => 1,   'null' => false, 'default' => 2], // E1/E2
            'reference_bandwidth'   => ['type' => 'INTEGER',  'null' => false, 'default' => 100],   // Mbps
            'distance_external'     => ['type' => 'INTEGER',  'null' => true,  'default' => null],   // AD
            'distance_inter'        => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'distance_intra'        => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'max_paths'             => ['type' => 'INTEGER',  'null' => false, 'default' => 4],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_ospf_settings', true);

        // ── frr_ospf_areas ────────────────────────────────────────────────
        $this->forge->addField([
            'id'               => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'area_id'          => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false],  // 0.0.0.0 or integer
            'description'      => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'area_type'        => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'normal'],  // normal|stub|nssa
            'default_cost'     => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'abr_shortcut'     => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'default'], // default|enable|disable
            'auth_type'        => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'none'],    // none|password|md5|sha256
            'no_summary'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'summary_ranges'   => ['type' => 'TEXT',     'null' => true,  'default' => null],   // JSON [{prefix, not_advertise, cost, substitute}]
            'created_at'       => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('area_id');
        $this->forge->createTable('frr_ospf_areas', true);

        // ── frr_ospf_ifaces ───────────────────────────────────────────────
        $this->forge->addField([
            'id'                  => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'interface'           => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => false],
            'description'         => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'network_type'        => ['type' => 'VARCHAR',  'constraint' => 24,  'null' => false, 'default' => 'broadcast'],
            // broadcast|nbma|point-to-multipoint|point-to-point|loopback
            'area'                => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => true,  'default' => null],
            'metric'              => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'passive'             => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'ignore_mtu'          => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'accept_filter'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'auth_type'           => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'none'],
            'auth_password'       => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'router_priority'     => ['type' => 'INTEGER',  'null' => false, 'default' => 1],    // 0-255; 0 = never DR
            'retransmit_interval' => ['type' => 'INTEGER',  'null' => false, 'default' => 5],    // 3-65535
            'hello_interval'      => ['type' => 'INTEGER',  'null' => false, 'default' => 10],   // 1-65535
            'dead_interval'       => ['type' => 'INTEGER',  'null' => false, 'default' => 40],   // 1-65535
            'minimal_hello'       => ['type' => 'INTEGER',  'null' => true,  'default' => null], // override multiplier
            'transmit_delay'      => ['type' => 'INTEGER',  'null' => false, 'default' => 1],    // LSA age increment
            'bfd_enabled'         => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'created_at'          => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('interface');
        $this->forge->createTable('frr_ospf_ifaces', true);

        // ── frr_ospf_neighbors ────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64, 'null' => false],
            'neighbor_ip' => ['type' => 'VARCHAR',  'constraint' => 45, 'null' => false],
            'area'        => ['type' => 'VARCHAR',  'constraint' => 20, 'null' => true,  'default' => null],
            'priority'    => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'poll_interval'=>['type' => 'INTEGER',  'null' => true,  'default' => null], // NBMA poll interval
            'description' => ['type' => 'VARCHAR',  'constraint' => 255,'null' => true, 'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,  'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_ospf_neighbors', true);

        // ── frr_bgp_settings ─────────────────────────────────────────────
        $this->forge->addField([
            'id'                     => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'default'],
            'enabled'                => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'local_as'               => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'router_id'              => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => true,  'default' => null],
            'keepalive_interval'     => ['type' => 'INTEGER',  'null' => false, 'default' => 60],   // seconds
            'hold_time'              => ['type' => 'INTEGER',  'null' => false, 'default' => 180],  // seconds
            'connect_retry'          => ['type' => 'INTEGER',  'null' => false, 'default' => 120],  // seconds
            'log_neighbor_changes'   => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'graceful_restart'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'deterministic_med'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'always_compare_med'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'bestpath_as_path_ignore'=> ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_connected' => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_static'    => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'redistribute_ospf'      => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'networks'               => ['type' => 'TEXT',     'null' => true,  'default' => null], // JSON array of prefixes to advertise
            'max_paths_ebgp'         => ['type' => 'INTEGER',  'null' => false, 'default' => 1],
            'max_paths_ibgp'         => ['type' => 'INTEGER',  'null' => false, 'default' => 1],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_bgp_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('frr_bgp_settings',     true);
        $this->forge->dropTable('frr_ospf_neighbors',   true);
        $this->forge->dropTable('frr_ospf_ifaces',      true);
        $this->forge->dropTable('frr_ospf_areas',       true);
        $this->forge->dropTable('frr_ospf_settings',    true);
    }
}
