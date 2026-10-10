<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFirewallRulesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'sequence' => [
                'type' => 'INT',
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 8, // 'pass', 'block', 'reject'
            ],
            'direction' => [
                'type'       => 'VARCHAR',
                'constraint' => 8, // 'in', 'out'
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'tcp', 'udp', 'icmp', 'any'
            ],
            'source_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'any', 'network', 'ip', 'alias'
                'default'    => 'any',
            ],
            'source_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'any',
            ],
            'source_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
            ],
            'dest_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'any',
            ],
            'dest_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'any',
            ],
            'dest_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
            ],
            'log_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
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
        $this->forge->addKey('sequence');
        $this->forge->addForeignKey('interface_id', 'interfaces', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('firewall_rules', true);
    }

    public function down()
    {
        $this->forge->dropTable('firewall_rules', true);
    }
}
