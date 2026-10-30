<?php

namespace App\Models;

use CodeIgniter\Model;

class ZtnaSettingsModel extends Model
{
    protected $table            = 'ztna_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'enabled',
        'portal_title',
        'company_name',
        'logo_url',
        'favicon_url',
        'brand_color',
        'brand_color_hover',
        'brand_accent_color',
        'portal_theme',
        'welcome_heading',
        'welcome_subtitle',
        'notice_banner',
        'notice_banner_type',
        'help_link',
        'help_link_text',
        'footer_text',
        'custom_css',
        'external_domain',
        'external_port',
        'guacd_host',
        'guacd_port',
        'session_timeout_mins',
        'require_mfa',
        'cert_id',
        'acme_enabled',
        'acme_email',
        'acme_staging',
        'acme_last_error',
        'access_mode',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getSettings(): array
    {
        $defaults = [
            'id'                   => 1,
            'enabled'              => 1,
            'company_name'         => 'Micron Security',
            'portal_title'         => 'Micron OS Clientless Gateway',
            'logo_url'             => '',
            'favicon_url'          => '',
            'brand_color'          => '#6366f1',
            'brand_color_hover'    => '#4f46e5',
            'brand_accent_color'   => '#10b981',
            'portal_theme'         => 'light',
            'welcome_heading'      => 'Zero-Trust Clientless Access Portal',
            'welcome_subtitle'     => 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.',
            'notice_banner'        => '',
            'notice_banner_type'   => 'info',
            'help_link'            => '',
            'help_link_text'       => 'Contact Helpdesk',
            'footer_text'          => '© ' . date('Y') . ' Micron OS Zero-Trust Network Access. All rights reserved.',
            'custom_css'           => '',
            'external_domain'      => 'gateway.micronos.local',
            'external_port'        => 443,
            'guacd_host'           => '127.0.0.1',
            'guacd_port'           => 4822,
            'session_timeout_mins' => 60,
            'require_mfa'          => 1,
            'cert_id'              => 0,
            'acme_enabled'         => 0,
            'acme_email'           => '',
            'acme_staging'         => 0,
            'acme_last_error'      => '',
            'access_mode'          => 'open',
        ];

        $row = $this->find(1);
        if (!$row) {
            return $defaults;
        }

        return array_merge($defaults, array_filter($row, fn($v) => $v !== null));
    }
}
