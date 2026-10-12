<?php

namespace App\Models;

use CodeIgniter\Model;

class ThreatFeedModel extends Model
{
    protected $table            = 'threat_feeds';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'slug',
        'feed_type',
        'url',
        'api_key',
        'min_confidence',
        'update_interval_hours',
        'enabled',
        'last_sync_at',
        'last_status',
        'last_error',
        'indicator_count',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get all feeds that are enabled and ready to sync.
     *
     * @return array<array<string, mixed>>
     */
    public function getEnabledFeeds(): array
    {
        return $this->where('enabled', 1)->orderBy('name', 'ASC')->findAll();
    }
}
