<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use App\Libraries\AcmeManager;
use App\Libraries\CronJobLog;

/**
 * Default schedule for the ACME renewal sweep.
 *
 * An expiry is a *dated* failure: a certificate that has not been renewed stops
 * being presented, and the service behind it fails for everyone at once. Nothing
 * about it is gradual, so it needs an observation that runs ahead of the date
 * rather than a button somebody remembers to press.
 *
 * Daily, at an hour chosen against the CA's rate limits: Let's Encrypt allows
 * five duplicate certificates per week, and the sweep only considers
 * certificates inside the renewal window, so a daily check cannot spend more
 * than one issuance per certificate — it simply retries tomorrow if something
 * was wrong today. Renewal is also idempotent, because the window is derived
 * from `valid_until` rather than from a stored "renewal requested" flag.
 *
 * The row is idempotent on its fixed id, so re-running never duplicates it.
 */
class SeedAcmeRenewCronJob extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('cron_jobs')) {
            return;
        }

        if ($this->db->table('cron_jobs')->where('id', CronJobLog::ACME_RENEW_JOB)->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->insert([
            'id'           => CronJobLog::ACME_RENEW_JOB,
            'minute'       => '20',
            'hour'         => '3',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'www-data',
            'command'      => 'php spark micron:acme-renew',
            'description'  => 'Renew ACME / Let\'s Encrypt certificates inside their '
                . AcmeManager::RENEW_WINDOW_DAYS . '-day window and install the result (DNS-01 certificates are reported for the operator to publish by hand)',
            'enabled'      => 1,
            'last_status'  => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('cron_jobs')) {
            $this->db->table('cron_jobs')->where('id', CronJobLog::ACME_RENEW_JOB)->delete();
        }
    }
}
