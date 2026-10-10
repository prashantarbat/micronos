<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSystemBackupsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'file_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'file_size_bytes' => [
                'type' => 'BIGINT',
            ],
            'is_encrypted' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'sha256_checksum' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'backup_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'full',
            ],
            'storage_destination' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'local', // 'local', 'sftp', 's3', 'central'
            ],
            'created_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'admin',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('system_backups', true);
    }

    public function down()
    {
        $this->forge->dropTable('system_backups', true);
    }
}
