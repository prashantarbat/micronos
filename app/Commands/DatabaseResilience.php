<?php

namespace App\Commands;

use App\Libraries\DatabaseResilienceService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * MicronOS Database Resilience & Self-Healing Command
 * ===================================================
 *
 * Verifies SQLite database integrity, enforces Write-Ahead Logging (WAL)
 * durability, generates atomic VACUUM INTO snapshots, and performs automated
 * self-healing against filesystem corruption.
 */
class DatabaseResilience extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:db-resilience';
    protected $description = 'Verifies SQLite integrity, enforces WAL, creates crash-resilient snapshots, and heals corrupted databases.';
    protected $usage       = 'micron:db-resilience [--check] [--snapshot] [--restore] [--enforce-wal] [--auto-heal]';
    protected $options     = [
        '--check'       => 'Run PRAGMA quick_check to verify internal B-tree and database integrity.',
        '--snapshot'    => 'Create a crash-consistent shadow snapshot using VACUUM INTO.',
        '--restore'     => 'Restore the active database from the verified shadow snapshot.',
        '--enforce-wal' => 'Enforce Write-Ahead Logging (WAL) and synchronous=FULL durability barriers.',
        '--auto-heal'   => 'Perform complete health audit and auto-repair corrupted database if snapshot exists.',
    ];

    public function run(array $params)
    {
        $service = new DatabaseResilienceService();
        $dbPath  = $service->getDbPath();

        CLI::write("========================================================", 'cyan');
        CLI::write("    MicronOS Database Resilience & Self-Healing", 'yellow');
        CLI::write("========================================================\n", 'cyan');
        CLI::write("Database target: {$dbPath}\n", 'white');

        $isCheck      = array_key_exists('check', $params) || CLI::getOption('check') !== null;
        $isSnapshot   = array_key_exists('snapshot', $params) || CLI::getOption('snapshot') !== null;
        $isRestore    = array_key_exists('restore', $params) || CLI::getOption('restore') !== null;
        $isEnforceWal = array_key_exists('enforce-wal', $params) || CLI::getOption('enforce-wal') !== null;
        $isAutoHeal   = array_key_exists('auto-heal', $params) || CLI::getOption('auto-heal') !== null;

        // Default mode when no specific flag is given: run complete auto-heal / resilience audit
        if (!$isCheck && !$isSnapshot && !$isRestore && !$isEnforceWal && !$isAutoHeal) {
            $isAutoHeal = true;
        }

        if ($isCheck) {
            $check = $service->verifyIntegrity();
            if ($check['healthy']) {
                CLI::write("[PASS] SQLite Integrity: {$check['result']}", 'green');
            } else {
                CLI::error("[FAIL] SQLite Integrity: {$check['result']}");
                foreach ($check['errors'] as $err) {
                    CLI::error("       - {$err}");
                }
                return EXIT_ERROR;
            }
        }

        if ($isEnforceWal) {
            $wal = $service->enforceWal();
            if (($wal['status'] ?? '') === 'applied') {
                CLI::write("[PASS] WAL Durability: journal_mode={$wal['journal_mode']}, synchronous={$wal['synchronous']}, autocheckpoint={$wal['wal_autocheckpoint']}", 'green');
            } else {
                CLI::error("[FAIL] WAL Durability: " . ($wal['message'] ?? 'Unable to apply'));
                return EXIT_ERROR;
            }
        }

        if ($isSnapshot) {
            $snap = $service->createSnapshot();
            if ($snap['success']) {
                CLI::write("[PASS] Snapshot Created: {$snap['path']} ({$snap['size']} bytes, SHA256: " . substr($snap['sha256'], 0, 16) . "...)", 'green');
            } else {
                CLI::error("[FAIL] Snapshot Failed: " . ($snap['error'] ?? 'Unknown error'));
                return EXIT_ERROR;
            }
        }

        if ($isRestore) {
            $restore = $service->restoreFromSnapshot();
            if ($restore['success']) {
                CLI::write("[PASS] Database Restored: {$restore['message']}", 'green');
            } else {
                CLI::error("[FAIL] Restoration Failed: {$restore['message']}");
                return EXIT_ERROR;
            }
        }

        if ($isAutoHeal) {
            $heal = $service->autoHeal();
            if ($heal['healthy']) {
                if ($heal['healed']) {
                    CLI::write("[HEALED] Corrupted database automatically recovered from shadow snapshot!", 'light_green');
                } else {
                    CLI::write("[PASS] Database is healthy, WAL barriers active, and snapshot verified.", 'green');
                }
            } else {
                CLI::error("[FAIL] Database is corrupted and could not be recovered automatically.");
                return EXIT_ERROR;
            }
        }

        CLI::write("\nResilience audit finished successfully.\n", 'cyan');
        return EXIT_SUCCESS;
    }
}
