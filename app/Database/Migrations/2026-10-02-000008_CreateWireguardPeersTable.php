<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWireguardPeersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'tunnel_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'public_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'preshared_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'endpoint' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'allowed_ips' => [
                'type' => 'TEXT',
            ],
            'persistent_keepalive' => [
                'type'    => 'INT',
                'default' => 25,
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
        $this->forge->addForeignKey('tunnel_id', 'wireguard_tunnels', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('wireguard_peers', true);
    }

    public function down()
    {
        $this->forge->dropTable('wireguard_peers', true);
    }
}
