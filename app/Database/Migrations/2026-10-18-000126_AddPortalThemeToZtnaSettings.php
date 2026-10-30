<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add portal_theme (light/gray/dark) to ztna_settings table.
 */
class AddPortalThemeToZtnaSettings extends Migration
{
    public function up(): void
    {
        $fields = [
            'portal_theme' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'light',
                'null'       => true,
                'after'      => 'brand_accent_color',
            ],
        ];

        $this->forge->addColumn('ztna_settings', $fields);

        // Set default to light
        $this->db->table('ztna_settings')->where('id', 1)->update([
            'portal_theme' => 'light',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ztna_settings', ['portal_theme']);
    }
}
