<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWebfilterSettingsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'    => 'INT',
                'default' => 1,
            ],
            'mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 24,
                'default'    => 'transparent_sni', // 'disabled', 'transparent_sni', 'ssl_bump'
            ],
            'http_port' => [
                'type'    => 'INT',
                'default' => 3128,
            ],
            'https_port' => [
                'type'    => 'INT',
                'default' => 3129,
            ],
            'icap_antivirus_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'safesearch_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'youtube_restricted_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'moderate',
            ],
            'cache_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'cache_size_mb' => [
                'type'    => 'INT',
                'default' => 4096,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('webfilter_settings', true);
    }

    public function down()
    {
        $this->forge->dropTable('webfilter_settings', true);
    }
}
