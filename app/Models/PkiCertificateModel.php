<?php

namespace App\Models;

use CodeIgniter\Model;

class PkiCertificateModel extends Model
{
    protected $table            = 'pki_certificates';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'ca_id',
        'cert_type',
        'name',
        'common_name',
        'organization',
        'san_dns',
        'san_ip',
        'certificate',
        'private_key',
        'csr',
        'serial_number',
        'valid_from',
        'valid_until',
        'revoked',
        'revoked_at',
        'description',
        'source',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'        => 'required|max_length[100]',
        'common_name' => 'required|max_length[128]',
        'cert_type'   => 'required|in_list[server,client,user,vpn]',
    ];
}
