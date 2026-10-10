<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WebFilter "Do Not Cache" Destination List
 *
 * Squid caches aggressively by default; operators need a way to exempt
 * specific destinations (analytics hosts, internal services by IP, portals
 * that misbehave when served from cache). Until now the only cache bypasses
 * were the query-string rule and disabling caching entirely.
 *
 * Adds a single free-form column:
 *   - cache_do_not_cache : one domain, IP, or CIDR per line
 *
 * The Squid config compiler classifies the entries and emits the matching
 * `dstdomain` / `dst` ACLs plus `cache deny`. Idempotent, and existing values
 * are never overwritten.
 */
class AddWebfilterCacheBypassList extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->fieldExists('cache_do_not_cache', 'webfilter_settings')) {
            $this->forge->addColumn('webfilter_settings', [
                'cache_do_not_cache' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if ($db->fieldExists('cache_do_not_cache', 'webfilter_settings')) {
            $this->forge->dropColumn('webfilter_settings', 'cache_do_not_cache');
        }
    }
}
