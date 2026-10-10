<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds layout preset to system_settings.
 * layout: 'sidebar' (default) | 'topnav'
 * NOTE: theme is still ENUM('dark','light') here — migration 032 widens it.
 */
class AddLayoutToSystemSettings extends Migration
{
    public function up(): void
    {
        // Add layout column after theme
        $this->forge->addColumn('system_settings', [
            'layout' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'sidebar',
                'after'      => 'theme',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('system_settings', 'layout');
    }
}
