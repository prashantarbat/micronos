<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds an optional tunnel-level default pre-shared key.
 *
 * WireGuard defines PresharedKey per *peer*, not per interface, so the key is
 * still stored (and compiled) on `wireguard_peers.preshared_key`. This column
 * only holds the tunnel's *default*: it is what the Add/Edit Tunnel form lets an
 * admin generate once, and new peers created under the tunnel inherit it when
 * they do not specify their own. Keeping the per-peer column authoritative means
 * the compiler and existing downloads need no changes.
 */
class AddPresharedKeyToWireguardTunnels extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('preshared_key', 'wireguard_tunnels')) {
            return;
        }

        $this->forge->addColumn('wireguard_tunnels', [
            'preshared_key' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'public_key',
            ],
        ]);
    }

    public function down(): void
    {
        // CI4's SQLite table-rebuild implementation of dropColumn cannot drop
        // an UNINDEXED column, so fall back to a native ALTER TABLE there.
        if ($this->db->DBDriver === 'SQLite3') {
            $table = $this->db->prefixTable('wireguard_tunnels');
            $this->db->query("ALTER TABLE {$table} DROP COLUMN preshared_key");

            return;
        }

        $this->forge->dropColumn('wireguard_tunnels', 'preshared_key');
    }
}
