<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * BondModel — Manages IEEE 802.3ad LACP and Linux bonding configurations.
 */
class BondModel extends Model
{
    protected $table            = 'interface_bonds';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'name',
        'mode',
        'slaves',
        'lacp_rate',
        'xmit_hash_policy',
        'miimon',
        'updelay',
        'downdelay',
        'mtu',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Supported Linux bonding modes.
     */
    public const MODES = [
        '802.3ad'        => 'IEEE 802.3ad Dynamic Link Aggregation (LACP)',
        'active-backup'  => 'Active-Backup (Fault Tolerance)',
        'balance-xor'    => 'Balance-XOR (Static Trunking / Hash)',
        'balance-rr'     => 'Balance-RR (Round Robin)',
        'broadcast'      => 'Broadcast (Fault Tolerance)',
        'balance-tlb'    => 'Adaptive Transmit Load Balancing (TLB)',
        'balance-alb'    => 'Adaptive Load Balancing (ALB)',
    ];

    /**
     * Transmit hash policies for 802.3ad and balance-xor.
     */
    public const HASH_POLICIES = [
        'layer2+3' => 'Layer 2+3 (MAC + IP Hash — Recommended for switches)',
        'layer3+4' => 'Layer 3+4 (IP + Port Hash — Highest distribution)',
        'layer2'   => 'Layer 2 (MAC Address only)',
    ];

    /**
     * Retrieve all bonds with decoded slaves array.
     */
    public function getBondsWithDetails(): array
    {
        $bonds = $this->orderBy('id', 'ASC')->findAll();
        foreach ($bonds as &$b) {
            $slaves = json_decode((string) ($b['slaves'] ?? '[]'), true);
            $b['slave_list'] = is_array($slaves) ? $slaves : [];
            $b['slave_count'] = count($b['slave_list']);
        }
        return $bonds;
    }
}
