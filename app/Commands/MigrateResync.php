<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\MigrationRunner;

/**
 * Repairs migration history after a migration class has been renamed.
 *
 * CodeIgniter identifies a migration by `digits(version) . className` and skips
 * any migration whose identity already appears in the `migrations` table. That
 * makes renaming a migration class dangerous: the renamed file no longer
 * matches its history row, so it is treated as brand new and executed a second
 * time — re-creating dropped tables, re-adding retired columns, or replaying a
 * data migration.
 *
 * This command rewrites the stored class name for any history row whose
 * *version* (the timestamp prefix, which never changes) still exists on disk
 * under a different class. Run it once, before `spark migrate`, on any
 * appliance that has already migrated:
 *
 *     php spark micron:migrate-resync
 *     php spark migrate
 *
 * It is idempotent: with nothing to repair it reports so and exits.
 */
class MigrateResync extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:migrate-resync';
    protected $description = 'Repoint migration-history rows at renamed migration classes so they are not executed twice.';
    protected $usage       = 'micron:migrate-resync [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Report the rows that would change without writing anything.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        CLI::write('MicronOS — migration history resync', 'yellow');
        CLI::write('=====================================', 'yellow');

        /** @var MigrationRunner $runner */
        $runner = service('migrations');

        // version (timestamp prefix) => fully-qualified class name on disk
        $onDisk = [];
        foreach ($runner->findMigrations() as $migration) {
            $onDisk[$migration->version] = $migration->class;
        }

        if ($onDisk === []) {
            CLI::write('No migration files found.', 'red');

            return EXIT_ERROR;
        }

        $db      = db_connect();
        $history = $db->table('migrations')->get()->getResultArray();

        if ($history === []) {
            CLI::write('Migration history is empty — nothing to resync.', 'light_gray');

            return EXIT_SUCCESS;
        }

        // Group by version: a renamed class can leave a stale row behind if the
        // migration already executed once under the new name (a partial
        // roll-out), and history must never hold two rows for one version.
        $byVersion = [];
        foreach ($history as $row) {
            $version = (string) ($row['version'] ?? '');
            if ($version !== '') {
                $byVersion[$version][] = $row;
            }
        }

        $repointed = 0;
        $pruned    = 0;
        $orphaned  = 0;

        foreach ($byVersion as $version => $rows) {
            // A history row whose migration file is gone (dropped, or renamed
            // beyond recognition) is left alone on purpose: removing it could
            // make the runner report a gap on the next run.
            if (! isset($onDisk[$version])) {
                $orphaned += count($rows);

                continue;
            }

            $current = $onDisk[$version];
            $stale   = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (string) ($r['class'] ?? '') !== $current
            ));

            if ($stale === []) {
                continue;
            }

            $hasCurrent = count($rows) !== count($stale);

            CLI::write('  ' . $version, 'light_gray');
            CLI::write('    current class: ' . $current, 'green');

            if ($hasCurrent) {
                // The good row is already there — the others are leftovers from
                // a re-execution and only need removing.
                foreach ($stale as $dup) {
                    CLI::write('    pruning stale: ' . $dup['class'] . ' (batch ' . $dup['batch'] . ')', 'red');
                    if (! $dryRun) {
                        $db->table('migrations')
                            ->where('version', $version)
                            ->where('class', $dup['class'])
                            ->where('batch', $dup['batch'])
                            ->delete();
                    }
                    $pruned++;
                }

                continue;
            }

            // No row matches disk yet: repoint the first stale row and drop any
            // further duplicates so exactly one row per version survives.
            $first = array_shift($stale);
            CLI::write('    was: ' . $first['class'], 'red');

            if (! $dryRun) {
                $db->table('migrations')
                    ->where('version', $version)
                    ->where('class', $first['class'])
                    ->where('batch', $first['batch'])
                    ->update(['class' => $current]);
            }
            $repointed++;

            foreach ($stale as $dup) {
                CLI::write('    pruning duplicate: ' . $dup['class'] . ' (batch ' . $dup['batch'] . ')', 'red');
                if (! $dryRun) {
                    $db->table('migrations')
                        ->where('version', $version)
                        ->where('class', $dup['class'])
                        ->where('batch', $dup['batch'])
                        ->delete();
                }
                $pruned++;
            }
        }

        CLI::write('');
        if ($repointed === 0 && $pruned === 0) {
            CLI::write('History already in sync — no changes needed.', 'green');
        } else {
            CLI::write(
                ($dryRun ? '[DRY RUN] ' : '')
                . ($repointed > 0 ? $repointed . ' row(s) repointed. ' : '')
                . ($pruned > 0 ? $pruned . ' stale row(s) pruned.' : ''),
                $dryRun ? 'yellow' : 'green'
            );
        }

        if ($orphaned > 0) {
            CLI::write($orphaned . ' history row(s) have no matching file and were left untouched.', 'light_gray');
        }

        if (! $dryRun && ($repointed > 0 || $pruned > 0)) {
            CLI::write('Now run: php spark migrate', 'cyan');
        }

        return EXIT_SUCCESS;
    }
}
