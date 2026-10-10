<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates `ipsec_tunnels` table for strongSwan IKEv1/IKEv2 Site-to-Site & Mobile VPN
 */
class CreateIpsecTunnelsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'ike_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => 'ikev2', // 'ikev1', 'ikev2'
            ],
            'mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'site2site', // 'site2site', 'roadwarrior'
            ],
            'remote_gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'auth_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'psk', // 'psk', 'rsa', 'eap'
            ],
            'psk_secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'local_subnet' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => '192.168.1.0/24',
            ],
            'remote_subnet' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'ike_cipher' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'aes256gcm16-prfsha384-ecp384',
            ],
            'esp_cipher' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'aes256gcm16-ecp384',
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('ipsec_tunnels', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ipsec_tunnels', true);
    }
}
