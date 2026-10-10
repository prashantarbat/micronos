<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the `permissions` table.
 *
 * Each permission is a granular capability string in dot-notation, e.g.:
 *   firewall.view, firewall.edit, vpn.wireguard.manage, system.reboot, etc.
 *
 * Permissions are grouped into modules for UI organisation.
 */
class CreatePermissionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            // dot-notation unique key, e.g. "firewall.rules.edit"
            'key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'unique'     => true,
            ],
            // Human-readable label, e.g. "Edit Firewall Rules"
            'label' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            // Module grouping for sidebar/UI, e.g. "Firewall", "VPN", "System"
            'module' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
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
        $this->forge->createTable('permissions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('permissions', true);
    }
}
