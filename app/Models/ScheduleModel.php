<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * ScheduleModel — Manages recurring and one-time schedule objects for:
 *   - Firewall access policies (/firewall/rules)
 *   - Destination NAT rules (/nat)
 *   - Traffic classification & QoS priority queues (/qos)
 */
class ScheduleModel extends Model
{
    protected $table            = 'firewall_schedules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'name',
        'type',
        'days_of_week',
        'start_time',
        'end_time',
        'start_datetime',
        'end_datetime',
        'is_system',
        'enabled',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Human-readable summary for tables, cards, and tooltips.
     */
    public static function formatSummary(array $schedule): string
    {
        $name = strtolower($schedule['name'] ?? '');
        if ($name === 'always') {
            return 'Always Active (24/7/365)';
        }

        $type = $schedule['type'] ?? 'recurring';
        if ($type === 'onetime') {
            $start = !empty($schedule['start_datetime']) ? date('M d, Y H:i', strtotime($schedule['start_datetime'])) : 'Start';
            $end   = !empty($schedule['end_datetime']) ? date('M d, Y H:i', strtotime($schedule['end_datetime'])) : 'End';
            return "One-Time: {$start} &rarr; {$end}";
        }

        // Recurring
        $days = strtolower($schedule['days_of_week'] ?? 'all');
        if ($days === 'all') {
            $daysLabel = 'Every Day';
        } elseif ($days === 'mon,tue,wed,thu,fri') {
            $daysLabel = 'Mon–Fri';
        } elseif ($days === 'sat,sun') {
            $daysLabel = 'Sat–Sun';
        } else {
            $daysArr = array_map('ucfirst', explode(',', $days));
            $daysLabel = implode(', ', $daysArr);
        }

        $startTime = substr($schedule['start_time'] ?? '00:00:00', 0, 5);
        $endTime   = substr($schedule['end_time'] ?? '23:59:59', 0, 5);

        if ($startTime === '00:00' && $endTime === '23:59') {
            return "{$daysLabel} (All Day)";
        }

        return "{$daysLabel} ({$startTime} – {$endTime})";
    }
}
