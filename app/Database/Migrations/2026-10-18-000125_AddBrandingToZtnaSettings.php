<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add comprehensive enterprise company branding & gateway port controls
 * to ztna_settings table.
 */
class AddBrandingToZtnaSettings extends Migration
{
    public function up(): void
    {
        $fields = [
            'company_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'Micron Security',
                'null'       => true,
                'after'      => 'portal_title',
            ],
            'logo_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'company_name',
            ],
            'favicon_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'logo_url',
            ],
            'brand_color' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '#6366f1',
                'null'       => true,
                'after'      => 'favicon_url',
            ],
            'brand_color_hover' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '#4f46e5',
                'null'       => true,
                'after'      => 'brand_color',
            ],
            'brand_accent_color' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '#10b981',
                'null'       => true,
                'after'      => 'brand_color_hover',
            ],
            'welcome_heading' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'Zero-Trust Clientless Access Portal',
                'null'       => true,
                'after'      => 'brand_accent_color',
            ],
            'welcome_subtitle' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'welcome_heading',
            ],
            'notice_banner' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'welcome_subtitle',
            ],
            'notice_banner_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'info',
                'null'       => true,
                'after'      => 'notice_banner',
            ],
            'help_link' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'notice_banner_type',
            ],
            'help_link_text' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'Contact Helpdesk',
                'null'       => true,
                'after'      => 'help_link',
            ],
            'footer_text' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '© 2026 Micron OS Zero-Trust Network Access. All rights reserved.',
                'null'       => true,
                'after'      => 'help_link_text',
            ],
            'custom_css' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'footer_text',
            ],
            'external_port' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 443,
                'null'       => true,
                'after'      => 'external_domain',
            ],
        ];

        $this->forge->addColumn('ztna_settings', $fields);

        // Update default row if empty
        $this->db->table('ztna_settings')->where('id', 1)->update([
            'company_name'       => 'Micron Security',
            'brand_color'        => '#6366f1',
            'brand_color_hover'  => '#4f46e5',
            'brand_accent_color' => '#10b981',
            'welcome_heading'    => 'Zero-Trust Clientless Access Portal',
            'welcome_subtitle'   => 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.',
            'notice_banner'      => '',
            'notice_banner_type' => 'info',
            'help_link_text'     => 'Contact Helpdesk',
            'footer_text'        => '© 2026 Micron OS Zero-Trust Network Access. All rights reserved.',
            'external_port'      => 443,
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ztna_settings', [
            'company_name',
            'logo_url',
            'favicon_url',
            'brand_color',
            'brand_color_hover',
            'brand_accent_color',
            'welcome_heading',
            'welcome_subtitle',
            'notice_banner',
            'notice_banner_type',
            'help_link',
            'help_link_text',
            'footer_text',
            'custom_css',
            'external_port',
        ]);
    }
}
