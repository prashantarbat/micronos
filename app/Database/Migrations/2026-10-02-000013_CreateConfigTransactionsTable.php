<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateConfigTransactionsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'admin_user' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 24, // 'staged', 'applied', 'confirmed', 'rolled_back'
                'default'    => 'staged',
            ],
            'candidate_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'backup_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'applied_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'confirmed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('config_transactions', true);
    }

    public function down()
    {
        $this->forge->dropTable('config_transactions', true);
    }
}
