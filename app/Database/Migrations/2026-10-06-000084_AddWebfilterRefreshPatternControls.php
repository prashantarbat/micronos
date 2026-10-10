<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Squid refresh_pattern Freshness Controls
 *
 * Adds the cache freshness surface that was previously absent from the
 * WebFilter module. Without any `refresh_pattern` directive Squid falls back
 * to its compiled-in defaults, leaving operators unable to tune object
 * lifetime, revalidation cadence or the non-standard-cache options.
 *
 * Columns added to webfilter_settings:
 *   - cache_refresh_enabled         : master switch for emitting refresh_pattern
 *   - cache_refresh_min_minutes     : minimum freshness (minutes)
 *   - cache_refresh_percent         : age fraction treated as fresh (%)
 *   - cache_refresh_max_minutes     : maximum freshness (minutes)
 *   - cache_refresh_ignore_reload   : `ignore-reload` option on the catch-all
 *   - cache_refresh_ignore_no_store : `ignore-no-store` option
 *   - cache_refresh_ignore_private  : `ignore-private` option
 *   - cache_refresh_reload_into_ims : `reload-into-ims` option
 *   - cache_refresh_custom          : operator-supplied refresh_pattern rules
 *
 * The custom-rule seed mirrors Squid's stock defaults so the out-of-the-box
 * behaviour is unchanged until an operator edits it. The step is idempotent
 * (columns checked first, existing values never overwritten).
 */
class AddWebfilterRefreshPatternControls extends Migration
{
    /** name => [type, constraint, default] */
    private const SETTINGS_COLUMNS = [
        'cache_refresh_enabled'         => ['TINYINT', 1,    1],
        'cache_refresh_min_minutes'     => ['INT',     null, 0],
        'cache_refresh_percent'         => ['INT',     null, 20],
        'cache_refresh_max_minutes'     => ['INT',     null, 4320],
        'cache_refresh_ignore_reload'   => ['TINYINT', 1,    0],
        'cache_refresh_ignore_no_store' => ['TINYINT', 1,    1],
        'cache_refresh_ignore_private'  => ['TINYINT', 1,    0],
        'cache_refresh_reload_into_ims' => ['TINYINT', 1,    0],
        'cache_refresh_custom'          => ['TEXT',    null, null],
    ];

    /** Squid's stock refresh_pattern rules, kept editable by the operator. */
    private const DEFAULT_CUSTOM_PATTERNS =
        "refresh_pattern ^ftp:             1440  20%  10080\n" .
        "refresh_pattern ^gopher:          1440  0%   1440\n" .
        "refresh_pattern -i (/cgi-bin/|\\?) 0    0%   0";

    public function up(): void
    {
        $db = \Config\Database::connect();

        $added = [];
        foreach (self::SETTINGS_COLUMNS as $name => [$type, $constraint, $default]) {
            if ($db->fieldExists($name, 'webfilter_settings')) {
                continue;
            }

            $field = ['type' => $type, 'null' => $default === null];
            if ($constraint !== null) {
                $field['constraint'] = $constraint;
            }
            if ($default !== null) {
                $field['default'] = $default;
            }

            $this->forge->addColumn('webfilter_settings', [$name => $field]);
            $added[] = $name;
        }

        // Seed the custom-rule textarea only when the column was just created
        // and no value exists yet, so operator edits are never clobbered.
        if (in_array('cache_refresh_custom', $added, true)) {
            $db->table('webfilter_settings')
                ->where('id', 1)
                ->where('cache_refresh_custom', null)
                ->update(['cache_refresh_custom' => self::DEFAULT_CUSTOM_PATTERNS]);
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        foreach (array_keys(self::SETTINGS_COLUMNS) as $name) {
            if ($db->fieldExists($name, 'webfilter_settings')) {
                $this->forge->dropColumn('webfilter_settings', $name);
            }
        }
    }
}
