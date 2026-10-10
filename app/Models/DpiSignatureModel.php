<?php

namespace App\Models;

use CodeIgniter\Model;

class DpiSignatureModel extends Model
{
    protected $table            = 'dpi_custom_signatures';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'category_id',
        'pattern_type',
        'pattern',
        'port',
        'protocol',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getActiveSignatures(): array
    {
        return $this->where('enabled', 1)->findAll();
    }
}
