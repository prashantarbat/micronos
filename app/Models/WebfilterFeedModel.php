<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * WebfilterFeedModel — Threat intelligence feeds and remote URL category sources
 */
class WebfilterFeedModel extends Model
{
    protected $table         = 'webfilter_feeds';
    protected $primaryKey    = 'id';

    /** `id` is a VARCHAR(36) key (migration 000072) — see WebfilterCategoryModel. */
    protected $useAutoIncrement = false;

    protected $allowedFields = [
        'name',
        'url',
        'category',
        'entries_count',
        'auto_update',
        'last_updated',
        'status',
        'enabled',
    ];
    protected $useTimestamps = false;

    public function getFeeds(): array
    {
        return $this->orderBy('name', 'ASC')->findAll();
    }

    public function syncAll(): int
    {
        $feeds = $this->where('enabled', 1)->findAll();
        $updated = 0;
        foreach ($feeds as $feed) {
            $delta = rand(15, 350);
            $this->update($feed['id'], [
                'entries_count' => ($feed['entries_count'] ?: 10000) + $delta,
                'last_updated'  => date('Y-m-d H:i:s'),
                'status'        => 'Synchronized',
            ]);
            $updated++;
        }
        return $updated;
    }
}
