<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * WebfilterAvDetectionModel — malware payloads caught (or alerted on) by the
 * C-ICAP + ClamAV inspection bridge.
 */
class WebfilterAvDetectionModel extends Model
{
    protected $table         = 'webfilter_av_detections';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'detected_at',
        'client_ip',
        'domain',
        'url',
        'filename',
        'threat_name',
        'file_size',
        'sha256',
        'action',
        'engine',
    ];
    protected $useTimestamps = false;

    /**
     * Most recent detections, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 50): array
    {
        try {
            return $this->orderBy('detected_at', 'DESC')->findAll($limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Aggregate counters for the Antivirus panel status cards.
     *
     * @return array{total:int, blocked:int, alerted:int, today:int}
     */
    public function stats(): array
    {
        try {
            $total   = $this->countAllResults();
            $blocked = $this->where('action', 'block')->countAllResults();
            $alerted = $this->where('action', 'alert')->countAllResults();
            $today   = $this->where('detected_at >=', date('Y-m-d 00:00:00'))->countAllResults();

            return ['total' => $total, 'blocked' => $blocked, 'alerted' => $alerted, 'today' => $today];
        } catch (\Throwable $e) {
            return ['total' => 0, 'blocked' => 0, 'alerted' => 0, 'today' => 0];
        }
    }

    public function clearAll(): void
    {
        $this->db->table($this->table)->emptyTable();
    }
}
