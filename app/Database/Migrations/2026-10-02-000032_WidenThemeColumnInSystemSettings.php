<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Widens system_settings.theme so every colour theme persists.
 *
 * Migration 030 created `theme` as ENUM('dark','light') — on MySQL that rejects
 * 'midnight'/'ocean'/'forest'/'slate', and on SQLite the forge turns it into
 * `TEXT CHECK(theme IN ('dark','light'))`, which aborts the save with a
 * constraint error. That is why the new themes could never be saved.
 *
 * 031's comment claiming theme was already a VARCHAR was incorrect.
 */
class WidenThemeColumnInSystemSettings extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('system_settings', [
            'theme' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'dark',
            ],
        ]);
    }

    public function down(): void
    {
        // Re-apply the original restriction; values outside dark/light
        // are coerced to the default by the DB on MySQL, dropped elsewhere.
        $this->forge->modifyColumn('system_settings', [
            'theme' => [
                'type'       => 'ENUM',
                'constraint' => ['dark', 'light'],
                'default'    => 'dark',
            ],
        ]);
    }
}
