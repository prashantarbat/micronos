<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Default schedule for refreshing the Internet Service Database.
 *
 * Each catalogue entry is backed by a public prefix feed that changes as the
 * platform grows. A nightly download keeps the sets current — and, with --geo,
 * refreshes the geography feeds referenced by address objects too — without an
 * admin remembering to press Synchronize.
 *
 * The row is idempotent on its fixed id, so re-running never duplicates it.
 */
class SeedServiceSyncCronJob extends Migration
{
    private const JOB_ID = 'cron_service_sync';

    public function up(): void
    {
        if ($this->db->table('cron_jobs')->where('id', self::JOB_ID)->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->insert([
            'id'           => self::JOB_ID,
            'minute'       => '15',
            'hour'         => '3',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'www-data',
            'command'      => 'php spark micron:sync-services --geo',
            'description'  => 'Sync Internet Service Database (public prefix feeds + geography)',
            'enabled'      => 1,
            'last_status'  => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('cron_jobs')->where('id', self::JOB_ID)->delete();
    }
}
