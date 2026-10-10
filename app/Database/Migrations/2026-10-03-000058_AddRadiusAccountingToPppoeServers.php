<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RADIUS accounting for PPPoE sessions.
 * -------------------------------------
 * Authentication answers "may this subscriber connect?". Accounting answers the
 * questions an ISP is actually billed on: when the session started, how long it
 * lasted, how many octets moved, and when it stopped. Without it a link that is
 * up but carrying nothing is indistinguishable from one that is busy, and a
 * dropped session leaves no record at all.
 *
 * pppd's radius plugin drives this itself, from the radiusclient configuration:
 *
 *  - `acctserver` present enables accounting; its absence disables it, which is
 *    what the toggle below controls.
 *  - Accounting-Start is sent when IPCP comes up and Accounting-Stop when the
 *    session goes down, both to the accounting port (1813 by convention, and the
 *    port is separate from the authentication port).
 *  - Interim-Update is sent periodically, but only when the RADIUS server asks
 *    for it by returning an Acct-Interim-Interval attribute in the Access-Accept
 *    — pppd enforces a 60-second floor and will not send interim records without
 *    that attribute. It is therefore a server-side cadence, not a NAS setting, so
 *    the appliance surfaces the interval it was told rather than pretending to
 *    choose it.
 *
 * The accounting host defaults to the authentication host; `radius_acct_server`
 * overrides it for the deployments that keep the two on different servers.
 */
class AddRadiusAccountingToPppoeServers extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('pppoe_servers')) {
            return;
        }

        $columns = [
            // 0 | 1 — whether Accounting-Start/Stop/Interim are sent.
            'radius_accounting'  => ['type' => 'INTEGER', 'null' => false, 'default' => 0, 'after' => 'radius_server2'],
            'radius_acct_port'   => ['type' => 'INTEGER', 'null' => false, 'default' => 1813],
            // Optional accounting host; the authentication host is used when unset.
            'radius_acct_server' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
        ];

        foreach ($columns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'pppoe_servers')) {
                $this->forge->addColumn('pppoe_servers', [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('pppoe_servers')) {
            return;
        }

        foreach (['radius_accounting', 'radius_acct_port', 'radius_acct_server'] as $column) {
            if (! $this->db->fieldExists($column, 'pppoe_servers')) {
                continue;
            }

            // CI4's SQLite table rebuild cannot drop an unindexed column through
            // the forge, so the raw statement is used where the driver needs it.
            if ($this->db->DBDriver === 'SQLite3') {
                $table = $this->db->prefixTable('pppoe_servers');
                $this->db->query("ALTER TABLE {$table} DROP COLUMN {$column}");

                continue;
            }

            $this->forge->dropColumn('pppoe_servers', $column);
        }
    }
}
