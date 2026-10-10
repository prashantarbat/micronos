<?php

namespace App\Models;

use CodeIgniter\Model;

class PkiAuthorityModel extends Model
{
    protected $table            = 'pki_authorities';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'common_name',
        'organization',
        'country',
        'state',
        'city',
        'certificate',
        'private_key',
        'serial_number',
        'valid_from',
        'valid_until',
        'key_length',
        'digest',
        'is_default',
        'source',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'        => 'required|max_length[100]',
        'common_name' => 'required|max_length[128]',
    ];
}
