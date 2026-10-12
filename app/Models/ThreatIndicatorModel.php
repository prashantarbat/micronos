<?php

namespace App\Models;

use CodeIgniter\Model;

class ThreatIndicatorModel extends Model
{
    protected $table            = 'threat_indicators';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'feed_id',
        'indicator_value',
        'indicator_type',
        'threat_type',
        'confidence_score',
        'comment',
        'first_seen',
        'last_seen',
        'expires_at',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Get all currently active, non-expired indicators.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveIndicators(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->groupStart()
                        ->where('expires_at >', $now)
                        ->orWhere('expires_at', null)
                    ->groupEnd()
                    ->orderBy('created_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get active IP / CIDR indicators for packet filtering.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveIpIndicators(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->whereIn('indicator_type', ['ipv4', 'ipv4_cidr'])
                    ->groupStart()
                        ->where('expires_at >', $now)
                        ->orWhere('expires_at', null)
                    ->groupEnd()
                    ->orderBy('created_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get active FQDN / domain indicators for DNSBL sinkholing.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveFqdnIndicators(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('indicator_type', 'fqdn')
                    ->groupStart()
                        ->where('expires_at >', $now)
                        ->orWhere('expires_at', null)
                    ->groupEnd()
                    ->orderBy('created_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get administrator manually-created IoCs.
     *
     * @return array<array<string, mixed>>
     */
    public function getManualIndicators(): array
    {
        return $this->where('feed_id', 'feed_manual_ioc')
                    ->orderBy('created_at', 'DESC')
                    ->findAll();
    }

    /**
     * Search indicators with limit.
     */
    public function searchIndicators(string $query, int $limit = 50): array
    {
        return $this->like('indicator_value', $query)
                    ->orLike('threat_type', $query)
                    ->orderBy('created_at', 'DESC')
                    ->findAll($limit);
    }

    /**
     * Remove stale expired indicators.
     */
    public function pruneExpired(int $ttlDays = 7): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($ttlDays * 86400));
        $this->where('expires_at <=', $cutoff)->delete();

        return $this->db->affectedRows();
    }
}
