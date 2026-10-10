<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWebfilterCategoriesTable extends Migration
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
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'block', // 'block', 'allow', 'bypass_ssl'
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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('webfilter_categories', true);
    }

    public function down()
    {
        $this->forge->dropTable('webfilter_categories', true);
    }
}
