<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInterfacesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'physical_device' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'ip_assignment_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'static', 'dhcp', 'pppoe'
            ],
            'ipv4_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'ipv4_netmask' => [
                'type'       => 'INT',
                'null'       => true,
            ],
            'ipv4_gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'ipv6_assignment_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'disabled',
            ],
            'ipv6_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'ipv6_prefix' => [
                'type'       => 'INT',
                'null'       => true,
            ],
            'mtu' => [
                'type'       => 'INT',
                'default'    => 1500,
            ],
            'vlan_tag' => [
                'type'       => 'INT',
                'null'       => true,
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
        $this->forge->createTable('interfaces', true);
    }

    public function down()
    {
        $this->forge->dropTable('interfaces', true);
    }
}
