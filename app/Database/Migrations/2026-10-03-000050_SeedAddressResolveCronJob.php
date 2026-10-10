<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Default schedule for refreshing address object feeds.
 *
 * FQDN, internet-service and geography address objects resolve to CIDR lists
 * that go stale as providers move addresses. A nightly refresh keeps a policy
 * matching the right networks without an admin remembering to run the command.
 *
 * The row is idempotent on its fixed id, so re-running never duplicates it.
 */
class SeedAddressResolveCronJob extends Migration
{
    private const JOB_ID = 'cron_address_resolve';

    public function up(): void
    {
        if ($this->db->table('cron_jobs')->where('id', self::JOB_ID)->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->insert([
            'id'           => self::JOB_ID,
            'minute'       => '45',
            'hour'         => '3',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'www-data',
            'command'      => 'php spark micron:resolve-objects',
            'description'  => 'Refresh Address Object Feeds (FQDN / Internet Service / Geography)',
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
