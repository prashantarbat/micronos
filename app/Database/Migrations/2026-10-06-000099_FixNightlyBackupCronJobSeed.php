<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Corrects the seeded `cron_backup_nightly` row.
 *
 * `2026-10-02-000028` seeded the job **enabled**, running
 * `/usr/local/bin/micron-backup --auto --encrypt`. No such binary ships anywhere
 * in the tree, so from the moment the appliance booted it compiled
 * `0 2 * * * root /usr/local/bin/micron-backup --auto --encrypt` into
 * `/etc/cron.d/micronos` — a command that could only ever fail.
 *
 * The seed also wrote a fabricated run: `last_status: success`, a
 * `last_run_at` of yesterday 02:00 and an output line announcing
 * `/var/backups/micronos_auto_backup.embk (1.2 MB)`. Nothing produced that file
 * — no code emits the `.embk` extension — so the row asserted a successful
 * encrypted offsite backup on an appliance that had taken none. An operator
 * reading the Cron page would have believed a disaster-recovery archive existed.
 *
 * The job is repointed at the command this appliance actually ships,
 * `php spark backup:create` (the same engine behind `microctl backup create`,
 * which registers its archive on the Backup page), and lands **disabled** with
 * no invented history. It stays disabled because automated snapshots, retention
 * and remote export are still planned (architecture §17.6): with no rotation, an
 * enabled nightly job would fill the backup store without bound, and without a
 * passphrase source it could only ever write a plaintext archive. It is left in
 * place as a real, runnable preset an administrator can enable deliberately.
 *
 * This runs against databases that already hold the bad row (where `000028` is
 * history and cannot be rewritten); `000028` was corrected in the same change so
 * a fresh install never writes it in the first place. Both paths converge on the
 * same row values, so they cannot drift.
 */
class FixNightlyBackupCronJobSeed extends Migration
{
    private const JOB_ID = 'cron_backup_nightly';

    /**
     * The command the appliance ships. Deliberately password-free: `--encrypt`
     * without `--passphrase` is refused by `backup:create` rather than silently
     * writing plaintext, and a cron job has nowhere to keep a passphrase.
     */
    private const COMMAND = 'php spark backup:create';

    private const DESCRIPTION = 'Nightly Configuration Snapshot (disabled until scheduled snapshots, retention and offsite export are implemented)';

    public function up(): void
    {
        if ($this->db->table('cron_jobs')->where('id', self::JOB_ID)->countAllResults() === 0) {
            // Nothing was seeded (an install whose operator deleted the row);
            // do not resurrect it.
            return;
        }

        $this->db->table('cron_jobs')->where('id', self::JOB_ID)->update([
            'command'     => self::COMMAND,
            'description' => self::DESCRIPTION,
            // Not compiled into /etc/cron.d/micronos until it can actually run.
            'enabled'     => 0,
            // Clear the seed's sample run. `pending` is what every other seeded
            // job uses for "never executed" (000050, 000052, 000056, 000095).
            'last_run_at' => null,
            'last_status' => 'pending',
            'last_output' => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * No inverse. The only row this migration can produce is the truthful one:
     * restoring the ghost binary and the fabricated `success` would re-assert a
     * backup that never happened, which is the defect being removed, not a
     * previous state worth returning to.
     */
    public function down(): void
    {
    }
}
