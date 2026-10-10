<?php

namespace App\Models;

use CodeIgniter\Model;

class SslInspectionProfileModel extends Model
{
    protected $table            = 'ssl_inspection_profiles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'description',
        'mode',
        'ca_id',
        'min_tls_version',
        'allow_invalid_certs',
        'block_untrusted_issuers',
        'exempt_categories',
        'exempt_domains',
        'log_inspected_sessions',
        'is_default',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getDefaultProfile(): ?array
    {
        return $this->where('is_default', 1)->first() ?? $this->first();
    }
}
