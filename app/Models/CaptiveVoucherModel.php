<?php

namespace App\Models;

use CodeIgniter\Model;

class CaptiveVoucherModel extends Model
{
    protected $table            = 'captive_vouchers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'zone_id',
        'roll_id',
        'code',
        'duration_minutes',
        'quota_bytes',
        'bandwidth_down_kbps',
        'bandwidth_up_kbps',
        'max_devices',
        'used_count',
        'status',
        'first_used_at',
        'expires_at',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Look up voucher by alphanumeric code.
     */
    public function findByCode(string $code): ?array
    {
        return $this->where('code', strtoupper(trim($code)))->first();
    }

    /**
     * Fetch all vouchers in a specific roll.
     */
    public function getByRoll(string $rollId): array
    {
        return $this->where('roll_id', $rollId)->orderBy('id', 'ASC')->findAll();
    }

    /**
     * Get distinct roll summaries.
     */
    public function getRollSummaries(?string $zoneId = null): array
    {
        $builder = $this->builder();
        $builder->select('roll_id, zone_id, COUNT(*) as total_vouchers, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN status = "used" THEN 1 ELSE 0 END) as used_count, SUM(CASE WHEN status = "expired" THEN 1 ELSE 0 END) as expired_count, duration_minutes, quota_bytes, bandwidth_down_kbps, bandwidth_up_kbps, MAX(created_at) as created_at');

        if ($zoneId !== null) {
            $builder->where('zone_id', $zoneId);
        }

        return $builder->groupBy('roll_id, zone_id, duration_minutes, quota_bytes, bandwidth_down_kbps, bandwidth_up_kbps')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();
    }
}
