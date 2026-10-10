<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOpenvpnServersTable extends Migration
{
    public function up()
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
            'interface_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'tun0',
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => 'udp', // 'udp', 'tcp'
            ],
            'listen_port' => [
                'type'    => 'INT',
                'default' => 1194,
            ],
            'tunnel_subnet' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'auth_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 24,
                'default'    => 'cert_ldap_2fa',
            ],
            'ldap_server_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
            ],
            'totp_enforced' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'dns_servers' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'routes_pushed' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addForeignKey('ldap_server_id', 'ldap_servers', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('openvpn_servers', true);
    }

    public function down()
    {
        $this->forge->dropTable('openvpn_servers', true);
    }
}
