<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVpnEphemeralLeasesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'user_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'vpn_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'wireguard', 'openvpn'
            ],
            'assigned_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'client_public_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'authenticated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'active', // 'active', 'expired', 'revoked'
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('vpn_ephemeral_leases', true);
    }

    public function down()
    {
        $this->forge->dropTable('vpn_ephemeral_leases', true);
    }
}
