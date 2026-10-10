<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Turns WireGuard into a "Tunnels + Peers" model.
 *
 * Before this migration `wireguard_tunnels` only described a bare kernel
 * device (interface_name, listen_port, ipv4_address, ipv4_subnet, keys) with no
 * way to name it, set an MTU or push DNS, and `wireguard_peers` had no
 * dedicated field for the *peer's own tunnel interface address* — it only had a
 * free-form `allowed_ips` string, which conflates "the IP of the other end of
 * the tunnel" with "networks routed through it".
 *
 * Added to `wireguard_tunnels` (the interface — this appliance's tunnel IP):
 *   name         → tunnel label (unique)
 *   description  → free text, never parsed
 *   mtu          → interface MTU
 *   dns_servers  → servers handed to peers that pull their config from us
 *
 * Added to `wireguard_peers` (the other end of the tunnel):
 *   peer_address → the peer's tunnel interface address (e.g. 10.9.0.2/32 or
 *                  fd00::2/128). Compiled as the first entry of AllowedIPs, and
 *                  kept separate from `allowed_ips`, which now means "extra
 *                  networks routed through this peer" (its LANs).
 */
class AddTunnelInterfaceFieldsToWireguard extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('wireguard_tunnels', [
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'id',
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'name',
            ],
            'mtu' => [
                'type'    => 'INT',
                'default' => 1420,
                'null'    => true,
                'after'   => 'ipv4_subnet',
            ],
            'dns_servers' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'mtu',
            ],
        ]);

        $this->forge->addColumn('wireguard_peers', [
            'peer_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'endpoint',
            ],
        ]);
    }

    public function down(): void
    {
        foreach (['name', 'description', 'mtu', 'dns_servers'] as $column) {
            $this->forge->dropColumn('wireguard_tunnels', $column);
        }
        $this->forge->dropColumn('wireguard_peers', 'peer_address');
    }
}
