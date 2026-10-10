<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * IdsAlertModel — Suricata EVE JSON alert feed & threat detections
 */
class IdsAlertModel extends Model
{
    protected $table         = 'ids_alerts';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'timestamp',
        'src_ip',
        'src_port',
        'dst_ip',
        'dst_port',
        'proto',
        'signature',
        'signature_id',
        'severity',
        'category',
        'action',
        'country_code',
        'interface',
        'payload',
        'created_at',
    ];
    protected $useTimestamps = false;

    /**
     * Retrieve recent alerts with optional filters
     */
    public function getAlerts(int $limit = 50, ?int $severity = null, ?string $search = null): array
    {
        $builder = $this->orderBy('timestamp', 'DESC')->limit($limit);

        if ($severity !== null && $severity > 0) {
            $builder->where('severity', $severity);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $builder->groupStart()
                ->like('src_ip', $term)
                ->orLike('dst_ip', $term)
                ->orLike('signature', $term)
                ->orLike('category', $term)
                ->orLike('country_code', $term)
                ->groupEnd();
        }

        return $builder->findAll();
    }

    /**
     * Get threat severity statistics
     */
    public function getSeverityStats(): array
    {
        $total  = $this->countAllResults(false);
        $sev1   = $this->where('severity', 1)->countAllResults(false);
        $sev2   = $this->where('severity', 2)->countAllResults(false);
        $sev3   = $this->where('severity', 3)->countAllResults(false);
        $drops  = $this->whereIn('action', ['Blocked', 'Dropped'])->countAllResults(false);

        return [
            'total'   => $total,
            'high'    => $sev1,
            'medium'  => $sev2,
            'low'     => $sev3,
            'blocked' => $drops,
        ];
    }
}
