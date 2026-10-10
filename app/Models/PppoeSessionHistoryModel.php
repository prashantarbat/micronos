<?php

namespace App\Models;

use App\Libraries\RadiusAttributes;
use CodeIgniter\Model;

/**
 * PppoeSessionHistoryModel — Manages historical PPPoE subscriber logins
 * and tracks RADIUS reply attributes (rate limits, timeouts, framed IPs)
 * over time.
 */
class PppoeSessionHistoryModel extends Model
{
    protected $table            = 'pppoe_session_history';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'subscriber_id',
        'username',
        'server_id',
        'server_name',
        'interface',
        'assigned_ip',
        'mac_address',
        'session_id',
        'login_at',
        'logout_at',
        'uptime_seconds',
        'terminate_cause',
        'rx_bytes',
        'tx_bytes',
        'rate_limit',
        'session_timeout',
        'idle_timeout',
        'interim_interval',
        'framed_ip',
        'radius_attributes',
        'created_at',
    ];

    /**
     * Fetch all session records for a subscriber (matched by ID or username).
     *
     * @return list<array>
     */
    public function getSubscriberHistory(string $subscriberIdOrUsername): array
    {
        return $this->where('subscriber_id', $subscriberIdOrUsername)
            ->orWhere('username', $subscriberIdOrUsername)
            ->orderBy('login_at', 'DESC')
            ->findAll();
    }

    /**
     * Computes limit and policy changes between consecutive logins for a subscriber.
     *
     * Processes logins chronologically from oldest to newest to compute limit deltas,
     * then returns them newest-first for presentation.
     *
     * @return list<array>
     */
    public function getSubscriberHistoryWithDeltas(string $subscriberIdOrUsername): array
    {
        $rawSessions = $this->where('subscriber_id', $subscriberIdOrUsername)
            ->orWhere('username', $subscriberIdOrUsername)
            ->orderBy('login_at', 'ASC')
            ->findAll();

        if (empty($rawSessions)) {
            return [];
        }

        $enriched = [];
        $prev = null;

        foreach ($rawSessions as $session) {
            $changes = [];
            $hasLimitChange = false;

            // 1. Rate Limit changes
            $currLimit = trim((string) ($session['rate_limit'] ?? ''));
            $prevLimit = $prev !== null ? trim((string) ($prev['rate_limit'] ?? '')) : null;

            if ($prev !== null && $currLimit !== $prevLimit) {
                $hasLimitChange = true;
                $changes[] = [
                    'type'     => 'rate_limit',
                    'label'    => 'Rate Limit',
                    'old'      => $prevLimit ?: 'None',
                    'new'      => $currLimit ?: 'None',
                    'summary'  => sprintf('%s → %s', $prevLimit ?: 'None', $currLimit ?: 'None'),
                    'badge'    => 'badge-amber',
                ];
            }

            // 2. Session Timeout changes
            $currTimeout = $session['session_timeout'] !== null ? (int) $session['session_timeout'] : null;
            $prevTimeout = $prev !== null && $prev['session_timeout'] !== null ? (int) $prev['session_timeout'] : null;

            if ($prev !== null && $currTimeout !== $prevTimeout) {
                $hasLimitChange = true;
                $changes[] = [
                    'type'     => 'session_timeout',
                    'label'    => 'Session Timeout',
                    'old'      => $prevTimeout !== null ? $this->formatUptime($prevTimeout) : 'Unlimited',
                    'new'      => $currTimeout !== null ? $this->formatUptime($currTimeout) : 'Unlimited',
                    'summary'  => sprintf(
                        '%s → %s',
                        $prevTimeout !== null ? $this->formatUptime($prevTimeout) : 'Unlimited',
                        $currTimeout !== null ? $this->formatUptime($currTimeout) : 'Unlimited'
                    ),
                    'badge'    => 'badge-cyan',
                ];
            }

            // 3. Idle Timeout changes
            $currIdle = $session['idle_timeout'] !== null ? (int) $session['idle_timeout'] : null;
            $prevIdle = $prev !== null && $prev['idle_timeout'] !== null ? (int) $prev['idle_timeout'] : null;

            if ($prev !== null && $currIdle !== $prevIdle) {
                $changes[] = [
                    'type'     => 'idle_timeout',
                    'label'    => 'Idle Timeout',
                    'old'      => $prevIdle !== null ? $this->formatUptime($prevIdle) : 'None',
                    'new'      => $currIdle !== null ? $this->formatUptime($currIdle) : 'None',
                    'summary'  => sprintf(
                        '%s → %s',
                        $prevIdle !== null ? $this->formatUptime($prevIdle) : 'None',
                        $currIdle !== null ? $this->formatUptime($currIdle) : 'None'
                    ),
                    'badge'    => 'badge-muted',
                ];
            }

            // 4. Framed IP changes
            $currIp = trim((string) ($session['framed_ip'] ?? $session['assigned_ip'] ?? ''));
            $prevIp = $prev !== null ? trim((string) ($prev['framed_ip'] ?? $prev['assigned_ip'] ?? '')) : null;

            if ($prev !== null && $currIp !== '' && $prevIp !== '' && $currIp !== $prevIp) {
                $changes[] = [
                    'type'     => 'framed_ip',
                    'label'    => 'Framed IP',
                    'old'      => $prevIp,
                    'new'      => $currIp,
                    'summary'  => sprintf('%s → %s', $prevIp, $currIp),
                    'badge'    => 'badge-purple',
                ];
            }

            // Decode raw RADIUS attributes
            $rawAttrs = [];
            if (! empty($session['radius_attributes'])) {
                $decoded = json_decode((string) $session['radius_attributes'], true);
                if (is_array($decoded)) {
                    $rawAttrs = $decoded;
                }
            }
            $radius = RadiusAttributes::describe($rawAttrs);

            $uptime = max(0, (int) ($session['uptime_seconds'] ?? 0));
            $rx     = max(0, (int) ($session['rx_bytes'] ?? 0));
            $tx     = max(0, (int) ($session['tx_bytes'] ?? 0));

            $session['has_limit_change']  = $hasLimitChange;
            $changesList = $changes;
            $session['changes']           = $changesList;
            $session['change_count']      = count($changesList);
            $session['uptime_human']      = $this->formatUptime($uptime);
            $session['rx_human']          = $this->formatBytes($rx);
            $session['tx_human']          = $this->formatBytes($tx);
            $session['total_bytes_human'] = $this->formatBytes($rx + $tx);
            $session['radius']            = $radius;
            $session['raw_attributes']    = $rawAttrs;
            $session['is_active']         = empty($session['logout_at']) && (strtolower((string) ($session['terminate_cause'] ?? '')) === 'active');

            $enriched[] = $session;
            $prev = $session;
        }

        // Return newest sessions first
        return array_reverse($enriched);
    }

    /**
     * Compute summary usage and limit metrics for a subscriber across their history.
     */
    public function getSubscriberMetrics(array $sessionsWithDeltas): array
    {
        if (empty($sessionsWithDeltas)) {
            return [
                'total_sessions'       => 0,
                'total_uptime_seconds' => 0,
                'total_uptime_human'   => '0s',
                'total_rx_bytes'       => 0,
                'total_tx_bytes'       => 0,
                'total_bytes_human'    => '0 B',
                'active_sessions'      => 0,
                'total_limit_changes'  => 0,
                'current_rate_limit'   => 'None',
                'first_rate_limit'     => 'None',
            ];
        }

        $totalUptime    = 0;
        $totalRx        = 0;
        $totalTx        = 0;
        $activeSessions = 0;
        $limitChanges   = 0;

        foreach ($sessionsWithDeltas as $s) {
            $totalUptime += (int) ($s['uptime_seconds'] ?? 0);
            $totalRx     += (int) ($s['rx_bytes'] ?? 0);
            $totalTx     += (int) ($s['tx_bytes'] ?? 0);
            if (! empty($s['is_active'])) {
                $activeSessions++;
            }
            if (! empty($s['has_limit_change'])) {
                $limitChanges++;
            }
        }

        $newest = reset($sessionsWithDeltas);
        $oldest = end($sessionsWithDeltas);

        return [
            'total_sessions'       => count($sessionsWithDeltas),
            'total_uptime_seconds' => $totalUptime,
            'total_uptime_human'   => $this->formatUptime($totalUptime),
            'total_rx_bytes'       => $totalRx,
            'total_tx_bytes'       => $totalTx,
            'total_bytes_human'    => $this->formatBytes($totalRx + $totalTx),
            'active_sessions'      => $activeSessions,
            'total_limit_changes'  => $limitChanges,
            'current_rate_limit'   => $newest['rate_limit'] ?? 'None',
            'first_rate_limit'     => $oldest['rate_limit'] ?? 'None',
        ];
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs    = $seconds % 60;

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }
        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        }

        return $secs . 's';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
