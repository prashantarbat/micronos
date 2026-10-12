<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 115: Create interface_bridges table for Layer 2 Linux Network Bridges.
 * Enables combining multiple physical interfaces into a switch bridge (typical router setup: WAN + br0[LAN ports]).
 */
class CreateInterfaceBridgesTable extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('interface_bridges')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32, // e.g. br0, br-lan
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64, // e.g. LAN Bridge (Switch Ports)
                ],
                'slaves' => [
                    'type' => 'TEXT', // JSON array of member interface names: ["eth1", "eth2", "eth3"]
                ],
                'stp' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1, // 802.1D Spanning Tree Protocol (1 = on, 0 = off)
                ],
                'forward_delay' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 2, // STP forward delay in seconds
                ],
                'hello_time' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 2, // STP hello time in seconds
                ],
                'max_age' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 20, // STP max message age in seconds
                ],
                'priority' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 32768, // Bridge STP priority (0 - 65535)
                ],
                'mtu' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 1500,
                ],
                'description' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('interface_bridges', true);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('interface_bridges')) {
            $this->forge->dropTable('interface_bridges', true);
        }
    }
}
