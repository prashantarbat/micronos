<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * DHCP Relay schema.
 *
 * A relay forwards DHCP/BOOTP broadcasts from an interface to a DHCP server on
 * another segment. MicronOS uses the maintained `dhcp-helper` agent (ISC DHCP
 * is end-of-life), so the model is kept to what that agent actually supports:
 * a set of interfaces to listen on and a set of servers to forward to.
 *
 * Two tables:
 *  - `dhcp_relay_destinations` — reusable named server lists, so one relay
 *    target can be shared by several interfaces.
 *  - `dhcp_relay_interfaces`   — which interface relays to which destination.
 *
 * NOTE: `dhcp-helper` does not implement DHCP option 82 (circuit/agent ID), so
 * no `agent_info` column is offered — the UI must not imply a capability the
 * agent cannot provide. A relay also binds UDP/67 like the DHCP server, so an
 * interface must not run both (enforced by the compiler's conflict check).
 *
 * Idempotent: each table is created with IF NOT EXISTS.
 */
class CreateDhcpRelayTables extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('dhcp_relay_destinations')) {
            $this->forge->addField([
                'id'         => ['type' => 'VARCHAR', 'constraint' => 36],
                'name'       => ['type' => 'VARCHAR', 'constraint' => 128],
                'servers'    => ['type' => 'VARCHAR', 'constraint' => 255],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('dhcp_relay_destinations', true);
        }

        if (! $db->tableExists('dhcp_relay_interfaces')) {
            $this->forge->addField([
                'id'             => ['type' => 'VARCHAR', 'constraint' => 36],
                'interface_id'   => ['type' => 'VARCHAR', 'constraint' => 32],
                'destination_id' => ['type' => 'VARCHAR', 'constraint' => 36, 'null' => true],
                'enabled'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('dhcp_relay_interfaces', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('dhcp_relay_interfaces', true);
        $this->forge->dropTable('dhcp_relay_destinations', true);
    }
}
