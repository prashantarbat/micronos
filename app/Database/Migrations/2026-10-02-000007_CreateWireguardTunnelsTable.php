<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWireguardTunnelsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'interface_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'unique'     => true,
            ],
            'listen_port' => [
                'type'    => 'INT',
                'default' => 51820,
            ],
            'private_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'public_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'ipv4_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'ipv4_subnet' => [
                'type'    => 'INT',
                'default' => 24,
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
        $this->forge->createTable('wireguard_tunnels', true);
    }

    public function down()
    {
        $this->forge->dropTable('wireguard_tunnels', true);
    }
}
