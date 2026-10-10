<?php

namespace App\Models;

use CodeIgniter\Model;

class SslBypassDomainModel extends Model
{
    protected $table            = 'ssl_bypass_domains';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'domain_pattern',
        'reason',
        'category',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getActivePatterns(): array
    {
        return $this->where('enabled', 1)->orderBy('domain_pattern', 'ASC')->findAll();
    }
}
