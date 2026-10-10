<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * PPPoE authentication against RADIUS.
 * ------------------------------------
 * A PPPoE server authenticated subscribers only against its local
 * `pppoe_server_users` list. That does not scale past a handful of accounts, and
 * it cannot express what an ISP actually needs: a per-account session timeout,
 * a per-account address, a concurrent-session limit, or a rate profile, all of
 * which change centrally without touching the appliance.
 *
 * RADIUS is the standard answer, and pppd reaches it through the radius plugin:
 * pppd sends the subscriber's CHAP credentials to the server, and the reply
 * carries back the pool address, session timeout and DNS servers as attributes.
 *
 * One column per server selects the backend. When it is `radius`, the local
 * subscriber list is not consulted at all — the plugin answers first, so a stale
 * local entry can never shadow a RADIUS decision. The local list is still
 * compiled, so switching back needs no data to be re-entered.
 *
 * The shared secret is encrypted, like every other credential in the appliance.
 */
class AddRadiusAuthToPppoeServers extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('pppoe_servers')) {
            return;
        }

        $columns = [
            // 'local' | 'radius' — the source of subscriber credentials.
            'auth_backend'    => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'local', 'after' => 'auth_required'],
            'radius_server'   => ['type' => 'VARCHAR', 'constraint' => 45,  'null' => true, 'default' => null],
            'radius_port'     => ['type' => 'INTEGER', 'null' => false, 'default' => 1812],
            'radius_secret'   => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'radius_timeout'  => ['type' => 'INTEGER', 'null' => false, 'default' => 5],
            'radius_retries'  => ['type' => 'INTEGER', 'null' => false, 'default' => 3],
            // The NAS identity RADIUS logs and attributes the session to.
            'radius_nas_id'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            // Optional second server, tried when the primary is unreachable.
            'radius_server2'  => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
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

        // CI4's SQLite table rebuild cannot drop an unindexed column through the
        // forge, so the raw statement is used where the driver needs it.
        $columns = [
            'auth_backend', 'radius_server', 'radius_port', 'radius_secret',
            'radius_timeout', 'radius_retries', 'radius_nas_id', 'radius_server2',
        ];

        foreach ($columns as $column) {
            if (! $this->db->fieldExists($column, 'pppoe_servers')) {
                continue;
            }

            if ($this->db->DBDriver === 'SQLite3') {
                $table = $this->db->prefixTable('pppoe_servers');
                $this->db->query("ALTER TABLE {$table} DROP COLUMN {$column}");

                continue;
            }

            $this->forge->dropColumn('pppoe_servers', $column);
        }
    }
}
