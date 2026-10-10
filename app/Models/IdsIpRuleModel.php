<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * IdsIpRuleModel — IP and Subnet Whitelist & Blacklist Rules for Suricata IDS/IPS
 */
class IdsIpRuleModel extends Model
{
    protected $table         = 'ids_ip_rules';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'list_type', // 'whitelist' or 'blacklist'
        'ip_address',
        'description',
        'enabled',
        'hits',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;

    /**
     * Get rules by list type
     *
     * @param string|null $type 'whitelist', 'blacklist', or null for all
     * @return array
     */
    public function getRules(?string $type = null): array
    {
        $builder = $this->orderBy('created_at', 'DESC');
        if ($type !== null) {
            $builder->where('list_type', $type);
        }
        return $builder->findAll();
    }

    /**
     * Get all active IP rules formatted for rule generation
     *
     * @param string $type
     * @return array
     */
    public function getActiveRules(string $type): array
    {
        return $this->where('list_type', $type)
            ->where('enabled', 1)
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Add or update an IP rule
     */
    public function addRule(string $listType, string $ipAddress, ?string $description = null): int|false
    {
        $ipAddress = trim($ipAddress);
        $existing = $this->where('list_type', $listType)
            ->where('ip_address', $ipAddress)
            ->first();

        if ($existing) {
            $this->update($existing['id'], [
                'description' => $description ?? $existing['description'],
                'enabled'     => 1,
            ]);
            return (int) $existing['id'];
        }

        return $this->insert([
            'list_type'   => $listType,
            'ip_address'  => $ipAddress,
            'description' => $description ?: ($listType === 'whitelist' ? 'Whitelisted IP/Subnet' : 'Threat Blacklist Entry'),
            'enabled'     => 1,
            'hits'        => 0,
        ]);
    }

    /**
     * Increment hit counter for an IP rule
     */
    public function incrementHit(string $ipAddress): void
    {
        $this->builder()
            ->where('ip_address', $ipAddress)
            ->increment('hits', 1);
    }
}
