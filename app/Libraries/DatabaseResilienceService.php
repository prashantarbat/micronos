<?php

namespace App\Libraries;

use Throwable;

/**
 * MicronOS Database Resilience & Self-Healing Service
 * ===================================================
 * 
 * Provides automated power-loss resilience, Write-Ahead Logging (WAL) enforcement,
 * transaction barriers, non-blocking snapshotting (VACUUM INTO), and automated
 * self-healing / rollback against SQLite filesystem corruption.
 */
class DatabaseResilienceService
{
    protected string $dbPath;
    protected string $snapshotPath;

    public function __construct(?string $dbPath = null)
    {
        if ($dbPath !== null && $dbPath !== '') {
            $this->dbPath = $dbPath;
        } else {
            $config = config('Database');
            $this->dbPath = (string) ($config->default['database'] ?? (WRITEPATH . 'micron_config.db'));
        }

        $this->snapshotPath = $this->dbPath . '.snapshot';
    }

    /**
     * Returns the active database path.
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Returns the snapshot file path.
     */
    public function getSnapshotPath(): string
    {
        return $this->snapshotPath;
    }

    /**
     * Enforces crash-invariant PRAGMA settings on the SQLite database:
     * - journal_mode = WAL (Write-Ahead Logging: reads don't block writes, uncommitted writes discarded on power cut)
     * - synchronous = 2 (FULL: forces fsync write barriers before transaction commit)
     * - wal_autocheckpoint = 1000 (regularly merges WAL back to master database)
     * - busy_timeout = 5000 (prevents lock contention)
     *
     * @return array<string, mixed>
     */
    public function enforceWal(): array
    {
        if ($this->dbPath === ':memory:' || $this->dbPath === '') {
            return ['status' => 'skipped', 'reason' => 'in_memory_or_empty'];
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            $sqlite = new \SQLite3($this->dbPath);
            $sqlite->busyTimeout(5000);

            $journalMode = $sqlite->querySingle('PRAGMA journal_mode = WAL;');
            $sqlite->exec('PRAGMA synchronous = 2;');
            $sqlite->exec('PRAGMA wal_autocheckpoint = 1000;');

            $synchronous = $sqlite->querySingle('PRAGMA synchronous;');
            $walAutocheckpoint = $sqlite->querySingle('PRAGMA wal_autocheckpoint;');

            $sqlite->close();

            return [
                'status'             => 'applied',
                'journal_mode'       => strtolower((string) $journalMode),
                'synchronous'        => (int) $synchronous,
                'wal_autocheckpoint' => (int) $walAutocheckpoint,
                'busy_timeout'       => 5000,
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Executes PRAGMA quick_check to verify internal B-Tree and page integrity.
     * Fast check suitable for every service boot (< 100ms on multi-megabyte databases).
     *
     * @return array{healthy: bool, status: string, result: string, errors: array<string>}
     */
    public function verifyIntegrity(): array
    {
        if (!file_exists($this->dbPath)) {
            return [
                'healthy' => false,
                'status'  => 'missing',
                'result'  => 'Database file not found',
                'errors'  => ['File does not exist: ' . $this->dbPath],
            ];
        }

        try {
            $sqlite = new \SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
            $sqlite->busyTimeout(3000);

            $result = $sqlite->querySingle('PRAGMA quick_check;');
            $sqlite->close();

            $healthy = (strtolower((string) $result) === 'ok');

            return [
                'healthy' => $healthy,
                'status'  => $healthy ? 'ok' : 'corrupted',
                'result'  => (string) $result,
                'errors'  => $healthy ? [] : [(string) $result],
            ];
        } catch (Throwable $e) {
            return [
                'healthy' => false,
                'status'  => 'exception',
                'result'  => $e->getMessage(),
                'errors'  => [$e->getMessage()],
            ];
        }
    }

    /**
     * Creates an atomic, non-blocking crash-consistent shadow snapshot using VACUUM INTO.
     * SQLite 3.27+ VACUUM INTO creates an isolated clean copy without transaction interruption.
     *
     * @param string|null $targetPath Destination path for snapshot. Defaults to .snapshot.
     * @return array{success: bool, path: string, size: int, sha256: string, error?: string}
     */
    public function createSnapshot(?string $targetPath = null): array
    {
        $target = $targetPath ?: $this->snapshotPath;

        if (!file_exists($this->dbPath)) {
            return [
                'success' => false,
                'path'    => $target,
                'size'    => 0,
                'sha256'  => '',
                'error'   => 'Source database file does not exist',
            ];
        }

        $tempTarget = $target . '.tmp_' . bin2hex(random_bytes(4));

        try {
            // Delete temp target if already exists
            if (file_exists($tempTarget)) {
                @unlink($tempTarget);
            }

            $sqlite = new \SQLite3($this->dbPath);
            $sqlite->busyTimeout(5000);

            // Escape single quotes for SQLite string literal
            $escapedTarget = str_replace("'", "''", $tempTarget);
            $res = $sqlite->exec("VACUUM INTO '{$escapedTarget}';");
            $sqlite->close();

            if (!$res || !file_exists($tempTarget)) {
                return [
                    'success' => false,
                    'path'    => $target,
                    'size'    => 0,
                    'sha256'  => '',
                    'error'   => 'VACUUM INTO failed to create snapshot file',
                ];
            }

            // Verify integrity of the newly generated snapshot before publishing
            $snapSqlite = new \SQLite3($tempTarget, SQLITE3_OPEN_READONLY);
            $snapCheck = (string) $snapSqlite->querySingle('PRAGMA quick_check;');
            $snapSqlite->close();

            if (strtolower($snapCheck) !== 'ok') {
                @unlink($tempTarget);
                return [
                    'success' => false,
                    'path'    => $target,
                    'size'    => 0,
                    'sha256'  => '',
                    'error'   => 'Generated snapshot failed integrity check: ' . $snapCheck,
                ];
            }

            // Atomically replace destination
            if (file_exists($target)) {
                @chmod($target, 0666);
                @unlink($target);
            }
            rename($tempTarget, $target);

            $size = (int) filesize($target);
            $hash = (string) hash_file('sha256', $target);

            return [
                'success' => true,
                'path'    => $target,
                'size'    => $size,
                'sha256'  => $hash,
            ];
        } catch (Throwable $e) {
            if (file_exists($tempTarget)) {
                @unlink($tempTarget);
            }
            return [
                'success' => false,
                'path'    => $target,
                'size'    => 0,
                'sha256'  => '',
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Safely restores the active database from a verified shadow snapshot.
     * Cleans up stale WAL / SHM files to ensure no uncheckpointed stale frames conflict.
     *
     * @param string|null $sourceSnapshotPath Defaults to .snapshot.
     * @return array{success: bool, message: string}
     */
    public function restoreFromSnapshot(?string $sourceSnapshotPath = null): array
    {
        $snapshot = $sourceSnapshotPath ?: $this->snapshotPath;

        if (!file_exists($snapshot)) {
            return [
                'success' => false,
                'message' => 'Snapshot file not found: ' . $snapshot,
            ];
        }

        // Verify snapshot integrity first
        try {
            $snapSqlite = new \SQLite3($snapshot, SQLITE3_OPEN_READONLY);
            $check = (string) $snapSqlite->querySingle('PRAGMA quick_check;');
            $snapSqlite->close();

            if (strtolower($check) !== 'ok') {
                return [
                    'success' => false,
                    'message' => 'Snapshot file is corrupted; aborting restore: ' . $check,
                ];
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to verify snapshot before restoration: ' . $e->getMessage(),
            ];
        }

        // Copy snapshot into temp file in destination directory
        $dir = dirname($this->dbPath);
        $tempRestore = $dir . DIRECTORY_SEPARATOR . '.tmp_restore_' . bin2hex(random_bytes(4));

        if (!copy($snapshot, $tempRestore)) {
            return [
                'success' => false,
                'message' => 'Failed to copy snapshot to temporary staging file',
            ];
        }

        // Remove existing stale WAL and SHM files
        $walPath = $this->dbPath . '-wal';
        $shmPath = $this->dbPath . '-shm';
        if (file_exists($walPath)) {
            @unlink($walPath);
        }
        if (file_exists($shmPath)) {
            @unlink($shmPath);
        }

        // Atomic replace
        if (file_exists($this->dbPath)) {
            @chmod($this->dbPath, 0666);
            @unlink($this->dbPath);
        }

        if (!rename($tempRestore, $this->dbPath)) {
            return [
                'success' => false,
                'message' => 'Failed to replace active database with staged restore file',
            ];
        }

        // Enforce WAL and synchronous barriers on freshly restored database
        $this->enforceWal();

        return [
            'success' => true,
            'message' => 'Database successfully restored from shadow snapshot.',
        ];
    }

    /**
     * Automated self-healing routine:
     * 1. Check integrity.
     * 2. If healthy -> enforce WAL and take updated snapshot if snapshot is missing or older than 1 hour.
     * 3. If corrupted -> automatically restore from shadow snapshot.
     *
     * @return array{healthy: bool, healed: bool, action: string, details: array<string, mixed>}
     */
    public function autoHeal(): array
    {
        $check = $this->verifyIntegrity();

        if ($check['healthy']) {
            $walResult = $this->enforceWal();

            // Refresh snapshot if missing or older than 1 hour (3600 seconds)
            $needsSnapshot = !file_exists($this->snapshotPath) || (time() - filemtime($this->snapshotPath) > 3600);
            $snapResult = $needsSnapshot ? $this->createSnapshot() : ['success' => true, 'action' => 'snapshot_fresh'];

            return [
                'healthy' => true,
                'healed'  => false,
                'action'  => 'none_needed',
                'details' => [
                    'check'    => $check,
                    'wal'      => $walResult,
                    'snapshot' => $snapResult,
                ],
            ];
        }

        // Database corrupted: attempt self-healing
        if (file_exists($this->snapshotPath)) {
            $restore = $this->restoreFromSnapshot();
            return [
                'healthy' => $restore['success'],
                'healed'  => $restore['success'],
                'action'  => $restore['success'] ? 'restored_from_snapshot' : 'restore_failed',
                'details' => [
                    'check'   => $check,
                    'restore' => $restore,
                ],
            ];
        }

        return [
            'healthy' => false,
            'healed'  => false,
            'action'  => 'unrecoverable_no_snapshot',
            'details' => [
                'check' => $check,
            ],
        ];
    }
}
