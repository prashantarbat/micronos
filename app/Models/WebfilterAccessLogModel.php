<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * WebfilterAccessLogModel — Real-time web proxy access events and traffic analytics
 */
class WebfilterAccessLogModel extends Model
{
    protected $table         = 'webfilter_access_logs';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'timestamp',
        'client_ip',
        'method',
        'url',
        'domain',
        'http_code',
        'squid_action',
        'filter_action',
        'category',
        'user_agent',
        'bytes_sent',
        'duration_ms',
    ];
    protected $useTimestamps = false;

    /**
     * Filter and paginate access logs
     */
    public function getFilteredLogs(?string $search = null, ?string $action = null, int $limit = 50): array
    {
        $builder = $this->orderBy('timestamp', 'DESC');

        if (!empty($search)) {
            $builder->groupStart()
                ->like('client_ip', $search)
                ->orLike('domain', $search)
                ->orLike('url', $search)
                ->orLike('category', $search)
                ->orLike('user_agent', $search)
                ->groupEnd();
        }

        if (!empty($action) && $action !== 'all') {
            $builder->where('filter_action', strtoupper($action));
        }

        return $builder->limit($limit)->get()->getResultArray();
    }

    /**
     * Clear all access log records
     */
    public function clearLogs(): bool
    {
        return (bool) $this->emptyTable();
    }
}
