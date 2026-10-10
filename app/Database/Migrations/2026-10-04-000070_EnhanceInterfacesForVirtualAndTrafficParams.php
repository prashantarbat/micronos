<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 70: Enhance Interfaces for virtual interfaces
 * (802.1Q VLANs, Bridges, Physical Port Assignments) and Traffic Parameters
 * (MSS clamping, MAC override, Speed/Duplex, Description).
 */
class EnhanceInterfacesForVirtualAndTrafficParams extends Migration
{
    public function up()
    {
        $newColumns = [
            'interface_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => 'physical', // 'physical', 'vlan', 'bridge', 'loopback'
            ],
            'vlan_parent' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'default'    => null,
            ],
            'vlan_priority' => [
                'type'       => 'INT',
                'null'       => true,
                'default'    => null, // 802.1p priority (0-7)
            ],
            'mss_clamp' => [
                'type'       => 'INT',
                'null'       => true,
                'default'    => null, // e.g. 1452 bytes or null
            ],
            'mac_override' => [
                'type'       => 'VARCHAR',
                'constraint' => 17,
                'null'       => true,
                'default'    => null, // format: 00:11:22:33:44:55
            ],
            'speed_duplex' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'default'    => 'auto', // 'auto', '1000baseT-FD', '100baseT-FD', etc.
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
        ];

        foreach ($newColumns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'interfaces')) {
                $this->forge->addColumn('interfaces', [$name => $definition]);
            }
        }

        // Backfill existing rows
        if ($this->db->tableExists('interfaces')) {
            $this->db->table('interfaces')
                ->where('interface_type IS NULL OR interface_type = \'\'')
                ->update(['interface_type' => 'physical']);

            $this->db->table('interfaces')
                ->where('vlan_tag IS NOT NULL AND vlan_tag > 0')
                ->update(['interface_type' => 'vlan']);
        }
    }

    public function down()
    {
        $columns = [
            'interface_type',
            'vlan_parent',
            'vlan_priority',
            'mss_clamp',
            'mac_override',
            'speed_duplex',
            'description',
        ];

        foreach ($columns as $column) {
            if ($this->db->fieldExists($column, 'interfaces')) {
                $this->forge->dropColumn('interfaces', $column);
            }
        }
    }
}
