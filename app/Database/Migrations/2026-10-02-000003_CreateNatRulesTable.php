<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateNatRulesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'rule_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'snat', 'dnat', 'masquerade'
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'tcp', 'udp', 'any'
                'default'    => 'any',
            ],
            'source_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'dest_port' => [
                'type' => 'INT',
                'null' => true,
            ],
            'target_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'target_port' => [
                'type' => 'INT',
                'null' => true,
            ],
            'description' => [
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
        $this->forge->addForeignKey('interface_id', 'interfaces', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('nat_rules', true);
    }

    public function down()
    {
        $this->forge->dropTable('nat_rules', true);
    }
}
