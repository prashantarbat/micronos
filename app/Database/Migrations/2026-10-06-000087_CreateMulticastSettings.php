<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Multicast & IGMP configuration
 *
 * MicronOS had no multicast surface at all: no IGMP proxy, no multicast
 * routing, and no bridge snooping/querier or mDNS reflection. This single-row
 * settings table drives three related capabilities, each independently
 * switchable:
 *
 *   1. IGMP proxy (igmpproxy)     — proxy upstream multicast (e.g. ISP IPTV)
 *                                   onto the LAN.
 *   2. Multicast routing          — FRR pimd: IGMP querier + PIM-SM/SSM.
 *   3. Snooping & mDNS reflection — bridge multicast snooping/querier plus an
 *                                   mDNS/Bonjour repeater across VLANs.
 *
 * The row is seeded with everything disabled so behaviour is unchanged until an
 * operator opts in.
 */
class CreateMulticastSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],

            // Global master switch for the whole module.
            'enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],

            // --- 1. IGMP proxy (igmpproxy) ---
            'igmp_proxy_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'igmp_upstream'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'igmp_downstreams'   => ['type' => 'TEXT', 'null' => true],
            'igmp_altnets'       => ['type' => 'TEXT', 'null' => true],
            'igmp_quickleave'    => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],

            // --- 2. Multicast routing (FRR pimd + IGMP) ---
            'pim_enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'pim_mode'             => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'default' => 'sparse'],
            'pim_rp_address'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'pim_interfaces'       => ['type' => 'TEXT', 'null' => true],
            'pim_ssm_range'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => '232.0.0.0/8'],
            'igmp_querier_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'igmp_version'         => ['type' => 'TINYINT', 'constraint' => 2, 'null' => false, 'default' => 3],

            // --- 3. Bridge snooping & mDNS reflection ---
            'snooping_enabled'        => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'snooping_bridge'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'snooping_querier'        => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'snooping_query_interval' => ['type' => 'INT', 'null' => false, 'default' => 125],
            'mdns_enabled'            => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'mdns_interfaces'         => ['type' => 'TEXT', 'null' => true],
            'mdns_mode'               => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'default' => 'reflect'],
            'mdns_filter'             => ['type' => 'TEXT', 'null' => true],

            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('multicast_settings', true);

        $db = \Config\Database::connect();
        if ($db->table('multicast_settings')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('multicast_settings')->insert([
                'enabled'      => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('multicast_settings', true);
    }
}
