<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the "networks behind this appliance" field to `openvpn_servers`.
 *
 * A tunnel carries two distinct network lists:
 *
 *   IPv4 Local Network(s)  → networks reachable *behind this appliance*
 *   IPv4 Remote Network(s) → networks reachable *behind the peer*
 *
 * `remote_subnets` already covers the second half (emitted as `route` lines).
 * `local_subnets` is the missing first half: when this row describes a
 * site-to-site client, those networks are what the *remote* server must
 * `iroute` through this tunnel (they are exported in the ccd bundle), and when
 * the row is a server they are the equivalent of `routes_pushed` (what clients
 * are offered via push "route ...").
 */
class AddLocalSubnetsToOpenvpn extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('openvpn_servers', [
            'local_subnets' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'remote_subnets',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('openvpn_servers', 'local_subnets');
    }
}
