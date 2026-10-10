<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Dynamic Enterprise Routing Tables
 *
 * Creates:
 *  - frr_bgp_neighbors    : FRRouting BGP peer configurations
 *  - frr_ospf_networks    : OSPF network/area declarations
 *  - frr_bfd_peers        : BFD session configurations
 *  - routing_vrfs         : VRF (Virtual Routing & Forwarding) definitions
 *  - routing_pbr_rules    : Policy-Based Routing rules
 *  - wan_gateways         : Multi-WAN gateway definitions
 *  - wan_gateway_groups   : Multi-WAN gateway groups (LB / failover)
 *  - wan_gateway_group_members : Group membership
 */
class CreateDynamicRoutingTables extends Migration
{
    public function up(): void
    {
        // ── frr_bgp_neighbors ────────────────────────────────────────────
        $this->forge->addField([
            'id'              => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'neighbor_ip'     => ['type' => 'VARCHAR',  'constraint' => 45,  'null' => false],  // IPv4/6
            'remote_as'       => ['type' => 'INTEGER',  'null' => false],
            'local_as'        => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'description'     => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'password'        => ['type' => 'VARCHAR',  'constraint' => 128, 'null' => true,  'default' => null],
            'update_source'   => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => true,  'default' => null], // interface or IP
            'next_hop_self'   => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'soft_reconfigure'=> ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'route_map_in'    => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'route_map_out'   => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true,  'default' => null],
            'bfd_enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 0],
            'enabled'         => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'      => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('neighbor_ip');
        $this->forge->createTable('frr_bgp_neighbors', true);

        // ── frr_ospf_networks ────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'network'     => ['type' => 'VARCHAR',  'constraint' => 50,  'null' => false],  // CIDR
            'area'        => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false, 'default' => '0.0.0.0'],
            'area_type'   => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false, 'default' => 'normal'], // normal|stub|nssa
            'cost'        => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true,  'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_ospf_networks', true);

        // ── frr_bfd_peers ────────────────────────────────────────────────
        $this->forge->addField([
            'id'               => ['type' => 'VARCHAR',  'constraint' => 64, 'null' => false],
            'peer_ip'          => ['type' => 'VARCHAR',  'constraint' => 45, 'null' => false],
            'local_ip'         => ['type' => 'VARCHAR',  'constraint' => 45, 'null' => true, 'default' => null],
            'interface'        => ['type' => 'VARCHAR',  'constraint' => 32, 'null' => true, 'default' => null],
            'detect_multiplier'=> ['type' => 'INTEGER',  'null' => false, 'default' => 3],
            'receive_interval' => ['type' => 'INTEGER',  'null' => false, 'default' => 300],  // ms
            'transmit_interval'=> ['type' => 'INTEGER',  'null' => false, 'default' => 300],  // ms
            'echo_mode'        => ['type' => 'TINYINT',  'constraint' => 1, 'null' => false, 'default' => 0],
            'description'      => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true, 'default' => null],
            'enabled'          => ['type' => 'TINYINT',  'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('frr_bfd_peers', true);

        // ── routing_vrfs ─────────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'name'        => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'table_id'    => ['type' => 'INTEGER',  'null' => true,  'default' => null],
            'interfaces'  => ['type' => 'TEXT',     'null' => true,  'default' => null],  // JSON array
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true, 'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true,  'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('routing_vrfs', true);

        // ── routing_pbr_rules ────────────────────────────────────────────
        $this->forge->addField([
            'id'            => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'priority'      => ['type' => 'INTEGER',  'null' => false, 'default' => 100],
            'description'   => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true, 'default' => null],
            'match_src'     => ['type' => 'VARCHAR',  'constraint' => 50,  'null' => true, 'default' => null],
            'match_dst'     => ['type' => 'VARCHAR',  'constraint' => 50,  'null' => true, 'default' => null],
            'match_protocol'=> ['type' => 'VARCHAR',  'constraint' => 20,  'null' => true, 'default' => null],
            'match_dscp'    => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => true, 'default' => null],
            'match_iif'     => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => true, 'default' => null],  // incoming iface
            'set_nexthop'   => ['type' => 'VARCHAR',  'constraint' => 45,  'null' => true, 'default' => null],
            'set_table'     => ['type' => 'INTEGER',  'null' => true, 'default' => null],
            'set_vrf'       => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => true, 'default' => null],
            'action'        => ['type' => 'VARCHAR',  'constraint' => 16,  'null' => false, 'default' => 'permit'], // permit|deny
            'enabled'       => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('routing_pbr_rules', true);

        // ── wan_gateways ─────────────────────────────────────────────────
        $this->forge->addField([
            'id'           => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'name'         => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'interface'    => ['type' => 'VARCHAR',  'constraint' => 32,  'null' => false],
            'gateway_ip'   => ['type' => 'VARCHAR',  'constraint' => 45,  'null' => false],
            'monitor_ip'   => ['type' => 'VARCHAR',  'constraint' => 45,  'null' => true, 'default' => null],
            'weight'       => ['type' => 'INTEGER',  'null' => false, 'default' => 1],
            'priority'     => ['type' => 'INTEGER',  'null' => false, 'default' => 1],
            'latency_warn' => ['type' => 'INTEGER',  'null' => false, 'default' => 200],   // ms
            'latency_down' => ['type' => 'INTEGER',  'null' => false, 'default' => 500],   // ms
            'loss_warn'    => ['type' => 'INTEGER',  'null' => false, 'default' => 10],    // %
            'loss_down'    => ['type' => 'INTEGER',  'null' => false, 'default' => 20],    // %
            'probe_iface'  => ['type' => 'VARCHAR',  'constraint' => 32, 'null' => true, 'default' => null],
            'description'  => ['type' => 'VARCHAR',  'constraint' => 255,'null' => true, 'default' => null],
            'enabled'      => ['type' => 'TINYINT',  'constraint' => 1,  'null' => false, 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('wan_gateways', true);

        // ── wan_gateway_groups ───────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'name'        => ['type' => 'VARCHAR',  'constraint' => 64,  'null' => false],
            'strategy'    => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false, 'default' => 'failover'], // failover|load-balance|round-robin
            'members'     => ['type' => 'TEXT',     'null' => true, 'default' => null],  // JSON [{id,tier,weight}]
            'trigger'     => ['type' => 'VARCHAR',  'constraint' => 20,  'null' => false, 'default' => 'down'],    // down|packetloss|latency
            'description' => ['type' => 'VARCHAR',  'constraint' => 255, 'null' => true, 'default' => null],
            'enabled'     => ['type' => 'TINYINT',  'constraint' => 1,   'null' => false, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('wan_gateway_groups', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('wan_gateway_groups',    true);
        $this->forge->dropTable('wan_gateways',          true);
        $this->forge->dropTable('routing_pbr_rules',     true);
        $this->forge->dropTable('routing_vrfs',          true);
        $this->forge->dropTable('frr_bfd_peers',         true);
        $this->forge->dropTable('frr_ospf_networks',     true);
        $this->forge->dropTable('frr_bgp_neighbors',     true);
    }
}
