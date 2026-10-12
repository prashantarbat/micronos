<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDashboardUserLayoutsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'profile_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'default',
            ],
            'column_count' => [
                'type'       => 'INT',
                'constraint' => 4,
                'default'    => 3,
            ],
            'refresh_interval_ms' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 2000,
            ],
            'layout_data' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->addKey('profile_name');
        $this->forge->createTable('dashboard_user_layouts', true);
    }

    public function down()
    {
        $this->forge->dropTable('dashboard_user_layouts', true);
    }
}
