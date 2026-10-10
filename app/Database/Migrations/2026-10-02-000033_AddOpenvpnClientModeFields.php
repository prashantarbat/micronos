<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds OpenVPN client / site-to-site support to `openvpn_servers`.
 *
 * Until now the table only described a road-warrior *server* (listen_port,
 * tunnel_subnet, auth_mode). A site-to-site peer needs the mirror image:
 * a remote endpoint, a point-to-point tunnel address pair (ifconfig A B) and
 * the remote LANs to route through the tunnel.
 *
 * `mode` is the discriminator on the existing table ('server' | 'client');
 * both kinds share the same crypto/cert columns so no table rename is needed.
 *
 * Field semantics for mode = 'client':
 *   tunnel_local_ip  → address assigned to THIS appliance  (ifconfig A)
 *   tunnel_remote_ip → address of the remote OpenVPN server (ifconfig B)
 *   remote_subnets   → comma-separated remote LANs to route into the tunnel
 */
class AddOpenvpnClientModeFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('openvpn_servers', [
            'mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'server', // 'server' | 'client'
                'after'      => 'name',
            ],
            // Public hostname/IP that remote peers dial for this server
            'public_endpoint' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'listen_port',
            ],
            // ── Client mode: where we dial out ──────────────────────
            'remote_host' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'remote_port' => [
                'type'    => 'INT',
                'default' => 1194,
                'null'    => true,
            ],
            // ── Client mode: point-to-point tunnel addresses ────────
            'tunnel_local_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'tunnel_remote_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'remote_subnets' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            // ── Crypto / TLS ────────────────────────────────────────
            'verify_x509_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'cipher' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'AES-256-GCM',
            ],
            'auth_digest' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'SHA256',
            ],
            'tls_auth' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'redirect_gateway' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'compression' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => '', // '' | 'lzo' | 'lz4'
            ],
        ]);
    }

    public function down(): void
    {
        foreach ([
            'mode', 'public_endpoint', 'remote_host', 'remote_port',
            'tunnel_local_ip', 'tunnel_remote_ip', 'remote_subnets',
            'verify_x509_name', 'cipher', 'auth_digest', 'tls_auth',
            'redirect_gateway', 'compression',
        ] as $column) {
            $this->forge->dropColumn('openvpn_servers', $column);
        }
    }
}
