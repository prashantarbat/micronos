<?php

namespace App\Models;

use CodeIgniter\Model;

class ConfigRevisionModel extends Model
{
    protected $table            = 'config_revisions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'commit_hash',
        'author',
        'message',
        'subsystem',
        'snapshot_path',
        'diff_summary',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Retrieve recent revisions ordered chronologically.
     */
    public function getRecentRevisions(int $limit = 50): array
    {
        return $this->orderBy('created_at', 'DESC')
                    ->limit($limit)
                    ->findAll();
    }

    /**
     * Find a revision by its Git commit hash or ID.
     */
    public function getByHash(string $hash): ?array
    {
        return $this->where('commit_hash', $hash)
                    ->orWhere('id', $hash)
                    ->first();
    }

    /**
     * Record a new revision in the database.
     */
    public function recordRevision(
        string $hash,
        string $author,
        string $message,
        string $subsystem = 'general',
        ?string $snapshotPath = null,
        ?array $diffStats = null
    ): string {
        $id = 'rev_' . substr($hash, 0, 12);
        $data = [
            'id'            => $id,
            'commit_hash'   => $hash,
            'author'        => $author !== '' ? $author : 'ministrator',
            'message'       => $message,
            'subsystem'     => $subsystem,
            'snapshot_path' => $snapshotPath,
            'diff_summary'  => $diffStats ? json_encode($diffStats) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        // Replace or insert
        $this->replace($data);
        return $id;
    }
}
