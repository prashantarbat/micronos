<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Default schedule for the repository drift sweep.
 *
 * Metadata drifting is a slow failure — a vendor stops publishing, or a signing
 * key's validity runs out — so it needs a periodic observation to be visible at
 * all, not a button an administrator remembers to press. Daily is chosen against
 * the two failure modes: it is frequent enough that a `Valid-Until` lapse is
 * caught with days of warning, and infrequent enough that a curated repository
 * is not polled for a release file that changes at most weekly.
 *
 * Note the deliberate absence of an `--apply`: metadata refresh and drift
 * observation are read-only, while installing from a repository is an explicit
 * operator action (§5.2). A scheduled task must never widen what apt may install.
 *
 * The row is idempotent on its fixed id, so re-running never duplicates it.
 */
class SeedRepoDriftCronJob extends Migration
{
    private const JOB_ID = 'cron_plugin_repo_drift';

    public function up(): void
    {
        if ($this->db->table('cron_jobs')->where('id', self::JOB_ID)->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->insert([
            'id'           => self::JOB_ID,
            'minute'       => '45',
            'hour'         => '4',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'www-data',
            'command'      => 'php spark micron:repo-drift',
            'description'  => 'Refresh curated plugin repository metadata and flag drift (stale or expired metadata, keys expiring or rotated, repositories no longer publishing)',
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
