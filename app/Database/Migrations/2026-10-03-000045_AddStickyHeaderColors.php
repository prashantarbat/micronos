<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sticky identification header — custom colours.
 *
 *   webgui_sticky_bg → background of the bar (hex, e.g. #111827)
 *   webgui_sticky_fg → colour of the three segment labels (hex)
 *
 * Both are NULL/empty by default, which means "follow the active theme":
 * the bar then uses --color-surface-2 with the per-segment cyan/primary/amber
 * accents. Setting a value overrides that, which is what an operator wants
 * when the theme's contrast is too low for a wall-mounted display.
 */
class AddStickyHeaderColors extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('system_settings', [
            'webgui_sticky_bg' => [
                'type'       => 'VARCHAR',
                'constraint' => 7,
                'null'       => true,
                'after'      => 'webgui_sticky_blink_enabled',
            ],
            'webgui_sticky_fg' => [
                'type'       => 'VARCHAR',
                'constraint' => 7,
                'null'       => true,
                'after'      => 'webgui_sticky_bg',
            ],
        ]);
    }

    public function down(): void
    {
        foreach (['webgui_sticky_bg', 'webgui_sticky_fg'] as $column) {
            $this->forge->dropColumn('system_settings', $column);
        }
    }
}