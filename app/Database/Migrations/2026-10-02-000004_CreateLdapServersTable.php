<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLdapServersTable extends Migration
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
            'hostname' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'port' => [
                'type'    => 'INT',
                'default' => 636,
            ],
            'encryption' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'ldaps', // 'none', 'starttls', 'ldaps'
            ],
            'base_dn' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'bind_dn' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'bind_password' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'user_search_base' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'user_search_filter' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '(&(objectClass=user)(sAMAccountName={username}))',
            ],
            'group_search_base' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'admin_group_mapping' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
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
        $this->forge->createTable('ldap_servers', true);
    }

    public function down()
    {
        $this->forge->dropTable('ldap_servers', true);
    }
}
