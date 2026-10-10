<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'unique'     => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'password_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true, // NULL for LDAP users
            ],
            'auth_source' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'local', // 'local', 'ldap'
            ],
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'Operator', // 'SuperAdmin', 'NetworkAdmin', 'Operator', 'VpnUser'
            ],
            'totp_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'totp_secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true, // Encrypted seed
            ],
            'totp_scratch_codes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'webauthn_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'full_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'last_login_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'failed_logins' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'locked_until' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->createTable('users', true);
    }

    public function down()
    {
        $this->forge->dropTable('users', true);
    }
}
