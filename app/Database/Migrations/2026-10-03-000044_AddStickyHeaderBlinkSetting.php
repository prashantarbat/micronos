<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sticky identification header — attention-drawing options.
 *
 *   webgui_sticky_blink_enabled → make the three segments of the device bar
 *                                 flash/blink so an operator working across
 *                                 several browser tabs notices the appliance
 *                                 identity. Purely cosmetic: the bar stays
 *                                 non-interactive.
 *
 * Font sizing is handled in CSS (clamp() against the bar height) so no column
 * is needed for it.
 */
class AddStickyHeaderBlinkSetting extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('system_settings', [
            'webgui_sticky_blink_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'webgui_sticky_right',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('system_settings', 'webgui_sticky_blink_enabled');
    }
}