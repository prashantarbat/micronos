<?php

namespace App\Models;

use CodeIgniter\Model;

class ThreatIntelWhitelistModel extends Model
{
    protected $table            = 'threat_intel_whitelist';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'indicator_value',
        'reason',
        'created_by',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Check if a given IP is whitelisted.
     */
    public function isWhitelisted(string $ip): bool
    {
        return $this->where('indicator_value', $ip)->countAllResults() > 0;
    }
}
