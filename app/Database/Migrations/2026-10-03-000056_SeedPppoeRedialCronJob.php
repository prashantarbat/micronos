<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Default maintenance schedule for PPPoE links.
 *
 * A PPPoE session can go stale in a way the LCP echo timer does not catch: an
 * ISP-side session table reset, a half-open session after a provider outage, a
 * concentrator that dropped the link without a clean LCP terminate. In those
 * cases pppd still believes the link is up, so nothing redials on its own.
 *
 * The check runs every twenty minutes and only rebuilds links that are actually
 * down: a healthy link is left alone, so a scheduled run costs nothing and
 * causes no outage. The command is also a no-op when there are no PPPoE links
 * configured. The job is seeded **disabled**, so it only runs once an operator
 * has a PPPoE link and enables it.
 *
 * The row is idempotent on its fixed id, so re-running never duplicates it.
 */
class SeedPppoeRedialCronJob extends Migration
{
    private const JOB_ID = 'cron_pppoe_redial';

    public function up(): void
    {
        if (! $this->db->tableExists('cron_jobs')) {
            return;
        }

        if ($this->db->table('cron_jobs')->where('id', self::JOB_ID)->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->insert([
            'id'           => self::JOB_ID,
            'minute'       => '*/20',
            'hour'         => '*',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'root',
            'command'      => 'php spark micron:pppoe-redial',
            'description'  => 'PPPoE Link Reconnect & Maintenance (redial stale WAN sessions)',
            'enabled'      => 0,
            'last_status'  => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('cron_jobs')) {
            return;
        }

        $this->db->table('cron_jobs')->where('id', self::JOB_ID)->delete();
    }
}
