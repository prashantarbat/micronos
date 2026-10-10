<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds route-based (VTI) tunnel addresses plus IKE identity fields to
 * `ipsec_tunnels`.
 *
 * Policy-based IPsec has no tunnel interface: traffic selectors
 * (`local_subnet` / `remote_subnet`) ARE the tunnel. Route-based IPsec
 * (strongSwan `if_id_in` / `if_id_out`, i.e. a VTI) gives the
 * tunnel a real interface with its own address pair, exactly like OpenVPN's
 * `ifconfig` or WireGuard's Address:
 *
 *   ip tunnel add vti0 local <us> remote <peer> mode vti key <mark>
 *   ip addr add <vti_local_ip>/30 dev vti0
 *   ip route add <remote_lan> dev vti0
 *
 * Fields added:
 *   vti_enabled   → 1 = route-based tunnel with a VTI interface
 *   vti_interface → kernel device name (vti0, vti1, …)
 *   vti_local_ip  → this appliance's tunnel address
 *   vti_remote_ip → the peer's tunnel address
 *   vti_mark      → strongSwan if_id value tying the CHILD_SA to the VTI (default 1)
 *
 * Identity fields the compiler already reads but the table never had:
 *   local_gateway → local source address for IKE (blank = %defaultroute)
 *   local_id      → IKE local identifier (blank = local address)
 *   remote_id     → IKE peer identifier  (blank = remote address)
 */
class AddVtiTunnelAddressesToIpsec extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ipsec_tunnels', [
            'local_gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'after'      => 'remote_gateway',
            ],
            'local_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'remote_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'vti_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'vti_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
            ],
            'vti_local_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'vti_remote_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'vti_mark' => [
                'type'    => 'INT',
                'default' => 1,
                'null'    => true,
            ],
        ]);
    }

    public function down(): void
    {
        foreach ([
            'local_gateway', 'local_id', 'remote_id', 'vti_enabled',
            'vti_interface', 'vti_local_ip', 'vti_remote_ip', 'vti_mark',
        ] as $column) {
            $this->forge->dropColumn('ipsec_tunnels', $column);
        }
    }
}
