<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * OS & Update-Site Caching Preset
 *
 * Adds a single toggle that emits a curated set of Squid `refresh_pattern`
 * rules for software/OS update endpoints (Windows Update, Apple Software
 * Update and the common Linux distribution mirrors). Large update archives are
 * frequently re-downloaded by every client because the origin servers force
 * full reloads; these rules pin the objects for up to 30 days and convert
 * reloads into If-Modified-Since checks instead of re-fetching.
 *
 * Column added to webfilter_settings:
 *   - cache_update_preset_enabled : emit the curated update-site patterns
 *
 * Idempotent: the column is only added when missing and its default (disabled)
 * never overwrites an existing operator choice.
 */
class AddWebfilterUpdateSitePreset extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('cache_update_preset_enabled', 'webfilter_settings')) {
            $this->forge->addColumn('webfilter_settings', [
                'cache_update_preset_enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('cache_update_preset_enabled', 'webfilter_settings')) {
            $this->forge->dropColumn('webfilter_settings', 'cache_update_preset_enabled');
        }
    }
}
