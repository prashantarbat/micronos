<?php

namespace App\Models;

use CodeIgniter\Model;

class CaptiveCustomizationModel extends Model
{
    protected $table            = 'captive_customizations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'zone_id',
        'venue_title',
        'welcome_message',
        'terms_text',
        'primary_color',
        'bg_color',
        'logo_path',
        'custom_css',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Fetch customization for a zone, or return default branding.
     */
    public function getForZone(string $zoneId): array
    {
        $custom = $this->where('zone_id', $zoneId)->first();
        if ($custom) {
            return $custom;
        }

        return [
            'zone_id'         => $zoneId,
            'venue_title'     => 'Guest Network Access',
            'welcome_message' => 'Welcome to our high-speed guest network. Please authenticate below to gain Internet access.',
            'terms_text'      => 'By connecting, you agree to our Acceptable Use Policy (AUP). Illegal downloading, malicious scanning, and excessive bandwidth consumption are prohibited.',
            'primary_color'   => '#4f46e5',
            'bg_color'        => '#0f172a',
            'logo_path'       => null,
            'custom_css'      => null,
        ];
    }
}
