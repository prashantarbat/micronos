<?php

namespace App\Database\Migrations;

use App\Libraries\ServiceSources;
use CodeIgniter\Database\Migration;

/**
 * Back-fills `internet_services.source_url` for the catalogue entries that have
 * a public prefix feed registered in App\Libraries\ServiceSources.
 *
 * The URL is stored on the row so the UI can show where a service's prefixes
 * come from and so an admin can point a service at a mirror without a code
 * change. The fetch engine still treats ServiceSources as the source of truth.
 */
class SeedInternetServiceSourceUrls extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (ServiceSources::SOURCES as $key => $source) {
            $this->db->table('internet_services')
                ->where('id', $key)
                ->update([
                    'source_url' => $source['url'],
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(ServiceSources::SOURCES) as $key) {
            $this->db->table('internet_services')
                ->where('id', $key)
                ->update(['source_url' => null]);
        }
    }
}
