<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateConfigRevisionsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'commit_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'author' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'ministrator',
            ],
            'message' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'subsystem' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'general',
            ],
            'snapshot_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'diff_summary' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('commit_hash');
        $this->forge->addKey('created_at');
        $this->forge->createTable('config_revisions', true);
    }

    public function down()
    {
        $this->forge->dropTable('config_revisions', true);
    }
}
