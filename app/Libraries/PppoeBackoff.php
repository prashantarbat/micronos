<?php

namespace App\Libraries;

use Config\Database;

/**
 * Manages escalating backoff and alert generation for PPPoE redial tasks.
 *
 * When a WAN link goes down and redial repeatedly fails to recover it, retrying
 * continuously can overwhelm access concentrators, flood daemon logs, and hide
 * persistent provider faults. This service enforces an escalating backoff
 * schedule:
 *
 *  - 1st failure: 5 minutes backoff (300s)
 *  - 2nd failure: 15 minutes backoff (900s) + Alert raised in audit logs & system log
 *  - 3rd failure: 30 minutes backoff (1800s) + Alert raised
 *  - 4th failure: 60 minutes backoff (3600s) + Alert raised
 *  - 5+ failures: 120 minutes (2 hours, capped) + Alert raised
 *
 * When a link successfully comes UP, its failure count and backoff state are
 * cleared. A manual operator action ('Redial now' or CLI `--force`) bypasses
 * backoff and resets the retry counters.
 */
class PppoeBackoff
{
    public const TABLE = 'pppoe_backoff_states';

    /** Escalating backoff ladder in seconds */
    public const BACKOFF_SCHEDULE = [
        1 => 300,   // 5 minutes
        2 => 900,   // 15 minutes
        3 => 1800,  // 30 minutes
        4 => 3600,  // 1 hour
        5 => 7200,  // 2 hours (max)
    ];

    public const MAX_BACKOFF_SECONDS = 7200;

    /** Number of consecutive failures before raising a persistent alert */
    public const ALERT_THRESHOLD = 2;

    /** @var \CodeIgniter\Database\BaseConnection|null */
    private $db;

    private string $filePath;

    public function __construct($db = null, ?string $filePath = null)
    {
        $this->db = $db ?? (class_exists(Database::class) ? Database::connect() : null);
        $this->filePath = $filePath ?? (defined('WRITEPATH') ? WRITEPATH . 'pppoe_backoff.json' : sys_get_temp_dir() . '/pppoe_backoff.json');
    }

    /**
     * Compute backoff duration in seconds for a given consecutive failure count.
     */
    public function calculateBackoffSeconds(int $failureCount): int
    {
        if ($failureCount <= 0) {
            return 0;
        }

        if ($failureCount >= 5) {
            return self::MAX_BACKOFF_SECONDS;
        }

        return self::BACKOFF_SCHEDULE[$failureCount] ?? self::MAX_BACKOFF_SECONDS;
    }

    /**
     * Checks if a given interface is currently in backoff (should not be retried yet).
     */
    public function isBackedOff(string $interface, ?int $now = null): bool
    {
        $state = $this->getLinkState($interface, $now);

        return (bool) ($state['is_backed_off'] ?? false);
    }

    /**
     * Retrieves the backoff state for an interface.
     *
     * @return array{
     *     interface: string,
     *     failure_count: int,
     *     last_failure_at: string|null,
     *     next_retry_at: string|null,
     *     last_reason: string|null,
     *     alert_count: int,
     *     is_backed_off: bool,
     *     remaining_seconds: int,
     *     remaining_human: string
     * }
     */
    public function getLinkState(string $interface, ?int $now = null): array
    {
        $currentTime = $now ?? time();
        $record = $this->readState($interface);

        if (! $record) {
            return [
                'interface'         => $interface,
                'failure_count'     => 0,
                'last_failure_at'   => null,
                'next_retry_at'     => null,
                'last_reason'       => null,
                'alert_count'       => 0,
                'is_backed_off'     => false,
                'remaining_seconds' => 0,
                'remaining_human'   => '',
            ];
        }

        $nextRetryTs = ! empty($record['next_retry_at']) ? strtotime($record['next_retry_at']) : 0;
        $remaining = max(0, $nextRetryTs - $currentTime);
        $isBackedOff = ($remaining > 0) && ((int) ($record['failure_count'] ?? 0) > 0);

        return [
            'interface'         => $interface,
            'failure_count'     => (int) ($record['failure_count'] ?? 0),
            'last_failure_at'   => $record['last_failure_at'] ?? null,
            'next_retry_at'     => $record['next_retry_at'] ?? null,
            'last_reason'       => $record['last_reason'] ?? null,
            'alert_count'       => (int) ($record['alert_count'] ?? 0),
            'is_backed_off'     => $isBackedOff,
            'remaining_seconds' => $remaining,
            'remaining_human'   => $this->formatDuration($remaining),
        ];
    }

    /**
     * Record a failure on a PPPoE link, advancing the escalating backoff ladder
     * and raising an alert if the threshold is met.
     */
    public function recordFailure(string $interface, string $reason = '', ?int $now = null): array
    {
        $currentTime = $now ?? time();
        $currentState = $this->getLinkState($interface, $currentTime);

        $newFailureCount = $currentState['failure_count'] + 1;
        $backoffSecs     = $this->calculateBackoffSeconds($newFailureCount);
        $nextRetryTs     = $currentTime + $backoffSecs;

        $lastFailureAt = date('Y-m-d H:i:s', $currentTime);
        $nextRetryAt   = date('Y-m-d H:i:s', $nextRetryTs);

        $alertRaised = false;
        $alertCount  = $currentState['alert_count'];

        if ($newFailureCount >= self::ALERT_THRESHOLD) {
            $this->raiseAlert($interface, $newFailureCount, $backoffSecs, $nextRetryAt, $reason);
            $alertRaised = true;
            $alertCount++;
        }

        $record = [
            'interface'       => $interface,
            'failure_count'   => $newFailureCount,
            'last_failure_at' => $lastFailureAt,
            'next_retry_at'   => $nextRetryAt,
            'last_reason'     => $reason !== '' ? $reason : 'Redial failed',
            'alert_count'     => $alertCount,
            'updated_at'      => $lastFailureAt,
        ];

        $this->writeState($interface, $record);

        return [
            'interface'         => $interface,
            'failure_count'     => $newFailureCount,
            'last_failure_at'   => $lastFailureAt,
            'next_retry_at'     => $nextRetryAt,
            'last_reason'       => $record['last_reason'],
            'alert_count'       => $alertCount,
            'alert_raised'      => $alertRaised,
            'is_backed_off'     => true,
            'remaining_seconds' => $backoffSecs,
            'remaining_human'   => $this->formatDuration($backoffSecs),
        ];
    }

    /**
     * Records a successful connection on the link, clearing backoff state and failure counters.
     */
    public function recordSuccess(string $interface): void
    {
        $this->deleteState($interface);
    }

    /**
     * Resets backoff state for an interface (or all interfaces if null).
     */
    public function reset(?string $interface = null): void
    {
        if ($interface !== null) {
            $this->deleteState($interface);
        } else {
            $this->deleteAllStates();
        }
    }

    /**
     * Returns states for all tracked interfaces.
     *
     * @return array<string, array>
     */
    public function getAllStates(?int $now = null): array
    {
        $all = $this->readAllStates();
        $out = [];
        foreach ($all as $iface => $record) {
            $out[$iface] = $this->getLinkState($iface, $now);
        }

        return $out;
    }

    /**
     * Raises a system alert and audit trail entry for a repeatedly failing PPPoE link.
     */
    public function raiseAlert(string $interface, int $failureCount, int $backoffSeconds, string $nextRetryAt, string $reason = ''): void
    {
        $msg = sprintf(
            'PPPoE link "%s" repeatedly failed to recover (%d consecutive failures). Escalating backoff applied for %s (next retry: %s). %s',
            $interface,
            $failureCount,
            $this->formatDuration($backoffSeconds),
            $nextRetryAt,
            $reason !== '' ? "Reason: {$reason}" : '',
        );

        if (function_exists('log_message')) {
            log_message('error', "[PPPoE ALERT] {$msg}");
        }

        if ($this->db !== null) {
            try {
                if ($this->db->tableExists('audit_logs')) {
                    $this->db->table('audit_logs')->insert([
                        // admin_user/client_ip are NOT NULL on an appliance whose
                        // database predates the migration file's later edits.
                        'admin_user'      => 'system',
                        'client_ip'       => '127.0.0.1',
                        'user_id'         => 'system',
                        'username'        => 'system',
                        'action_type'     => 'alert',
                        'action'          => 'pppoe.link.alert',
                        'target_resource' => 'PPPoE Link',
                        'target'          => $interface,
                        'old_state'       => "consecutive_failures: " . ($failureCount - 1),
                        'new_state'       => "consecutive_failures: {$failureCount}, next_retry_at: {$nextRetryAt}",
                        'created_at'      => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                // Database write failure must not abort operation
            }
        }
    }

    /**
     * Formats seconds into human readable duration ("15m", "1h 30m", "45s").
     */
    public function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        if ($minutes > 0) {
            return $secs > 0 ? "{$minutes}m {$secs}s" : "{$minutes}m";
        }

        return "{$secs}s";
    }

    // ─────────────────────────────────────────────────────────────────
    // Storage: Database with JSON File Fallback
    // ─────────────────────────────────────────────────────────────────

    private function readState(string $interface): ?array
    {
        if ($this->hasTable()) {
            try {
                $row = $this->db->table(self::TABLE)->where('interface', $interface)->get()->getRowArray();
                if ($row) {
                    return $row;
                }
            } catch (\Throwable $e) {
                // fall through to file fallback
            }
        }

        $fileData = $this->readFile();

        return $fileData[$interface] ?? null;
    }

    private function readAllStates(): array
    {
        $results = [];

        if ($this->hasTable()) {
            try {
                $rows = $this->db->table(self::TABLE)->get()->getResultArray();
                foreach ($rows as $row) {
                    $results[$row['interface']] = $row;
                }
                return $results;
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return $this->readFile();
    }

    private function writeState(string $interface, array $record): void
    {
        if ($this->hasTable()) {
            try {
                $exists = $this->db->table(self::TABLE)->where('interface', $interface)->countAllResults() > 0;
                if ($exists) {
                    $this->db->table(self::TABLE)->where('interface', $interface)->update($record);
                } else {
                    $this->db->table(self::TABLE)->insert($record);
                }
            } catch (\Throwable $e) {
                // fall through to file
            }
        }

        $fileData = $this->readFile();
        $fileData[$interface] = $record;
        $this->writeFile($fileData);
    }

    private function deleteState(string $interface): void
    {
        if ($this->hasTable()) {
            try {
                $this->db->table(self::TABLE)->where('interface', $interface)->delete();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        $fileData = $this->readFile();
        unset($fileData[$interface]);
        $this->writeFile($fileData);
    }

    private function deleteAllStates(): void
    {
        if ($this->hasTable()) {
            try {
                $this->db->table(self::TABLE)->emptyTable();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        $this->writeFile([]);
    }

    private function hasTable(): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            return $this->db->tableExists(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function readFile(): array
    {
        if (! file_exists($this->filePath)) {
            return [];
        }

        $content = @file_get_contents($this->filePath);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeFile(array $data): void
    {
        $dir = dirname($this->filePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
