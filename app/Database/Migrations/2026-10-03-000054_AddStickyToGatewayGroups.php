<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Multi-WAN — sticky connections.
 * ---------------------------------
 * A load-balanced gateway group can send successive connections out different
 * members. Sticky connections pin a source address to the member it was first
 * assigned, so one host keeps using one upstream for as long as its states
 * live. That matters for anything that breaks when the exit address changes
 * mid-session: banking portals, VPNs, multi-step logins, UDP media.
 *
 * Two settings drive it:
 *
 *  - `sticky_enabled` — remember the assignment per source address.
 *  - `sticky_timeout` — how long an idle assignment is kept, in seconds. A
 *    value of 0 removes the tracking entry as soon as the state expires, so the
 *    next connection from that host advances to the following member.
 *
 * Both live on the group rather than on the members: a group is the unit that
 * load balances, so it is the unit that decides whether its choices stick.
 */
class AddStickyToGatewayGroups extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('sticky_enabled', 'wan_gateway_groups')) {
            $this->forge->addColumn('wan_gateway_groups', [
                'sticky_enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'strategy',
                ],
            ]);
        }

        if (! $this->db->fieldExists('sticky_timeout', 'wan_gateway_groups')) {
            $this->forge->addColumn('wan_gateway_groups', [
                'sticky_timeout' => [
                    'type'    => 'INTEGER',
                    'null'    => false,
                    'default' => 0,
                    'after'   => 'sticky_enabled',
                ],
            ]);
        }
    }

    public function down(): void
    {
        // CI4's SQLite table rebuild cannot drop an unindexed column through
        // the forge, so the raw statement is used where the driver needs it.
        foreach (['sticky_enabled', 'sticky_timeout'] as $column) {
            if (! $this->db->fieldExists($column, 'wan_gateway_groups')) {
                continue;
            }

            if ($this->db->DBDriver === 'SQLite3') {
                $table = $this->db->prefixTable('wan_gateway_groups');
                $this->db->query("ALTER TABLE {$table} DROP COLUMN {$column}");

                continue;
            }

            $this->forge->dropColumn('wan_gateway_groups', $column);
        }
    }
}
