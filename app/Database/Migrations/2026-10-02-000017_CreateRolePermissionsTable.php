<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the `role_permissions` pivot table.
 *
 * Many-to-many relationship between roles and permissions.
 * The `granted` field allows explicitly DENYING a permission
 * even if inherited from another role in future hierarchy support.
 */
class CreateRolePermissionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INTEGER',
                'auto_increment' => true,
            ],
            'role_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'permission_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            // 1 = granted, 0 = explicitly denied
            'granted' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['role_id', 'permission_id']);
        $this->forge->createTable('role_permissions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('role_permissions', true);
    }
}
