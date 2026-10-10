<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the `user_roles` pivot table.
 *
 * A user can hold multiple roles. Effective permissions are the UNION
 * of all granted permissions across all roles (with explicit denials winning).
 */
class CreateUserRolesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INTEGER',
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'role_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'assigned_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Who assigned this role (for audit trail)
            'assigned_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'role_id']);
        $this->forge->createTable('user_roles', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('user_roles', true);
    }
}
