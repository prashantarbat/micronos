<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Web GUI presentation settings.
 *
 *   webgui_login_banner_enabled → show the disclaimer/banner as a modal the user
 *                                 must accept before the sign-in form is usable
 *   webgui_login_banner_title   → heading shown at the top of that modal
 *   webgui_login_banner         → (already exists) the disclaimer body text
 *
 *   webgui_sticky_header_enabled → pin a thin device-identification bar to the top
 *                                  of every authenticated page
 *   webgui_sticky_left/center/right → its three segments. Values may contain
 *                                  tokens such as {hostname}, {ip}, {org_address},
 *                                  resolved at render time (see the layout).
 *
 * Useful when several appliances are managed side by side and an operator needs
 * to know at a glance which box they are on.
 */
class AddLoginBannerAndStickyHeaderSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('system_settings', [
            'webgui_login_banner_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'webgui_login_banner',
            ],
            'webgui_login_banner_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'webgui_login_banner_enabled',
            ],
            'webgui_sticky_header_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'webgui_redirect_http',
            ],
            'webgui_sticky_left' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'webgui_sticky_header_enabled',
            ],
            'webgui_sticky_center' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'webgui_sticky_left',
            ],
            'webgui_sticky_right' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'webgui_sticky_center',
            ],
        ]);
    }

    public function down(): void
    {
        foreach ([
            'webgui_login_banner_enabled',
            'webgui_login_banner_title',
            'webgui_sticky_header_enabled',
            'webgui_sticky_left',
            'webgui_sticky_center',
            'webgui_sticky_right',
        ] as $column) {
            $this->forge->dropColumn('system_settings', $column);
        }
    }
}
