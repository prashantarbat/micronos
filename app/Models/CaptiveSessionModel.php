<?php

namespace App\Models;

use CodeIgniter\Model;

class CaptiveSessionModel extends Model
{
    protected $table            = 'captive_sessions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'zone_id',
        'ip_address',
        'mac_address',
        'auth_type',
        'username',
        'bytes_in',
        'bytes_out',
        'connected_at',
        'expires_at',
        'last_seen_at',
        'status',
    ];

    protected $useTimestamps = false;

    /**
     * Find active session by client IP and zone.
     */
    public function findActive(string $ipAddress, ?string $zoneId = null): ?array
    {
        $builder = $this->where('ip_address', $ipAddress)->where('status', 'authorized');
        if ($zoneId !== null) {
            $builder->where('zone_id', $zoneId);
        }
        return $builder->first();
    }

    /**
     * Find active session by MAC address.
     */
    public function findActiveByMac(string $macAddress, ?string $zoneId = null): ?array
    {
        $builder = $this->where('mac_address', strtoupper($macAddress))->where('status', 'authorized');
        if ($zoneId !== null) {
            $builder->where('zone_id', $zoneId);
        }
        return $builder->first();
    }

    /**
     * Fetch all authorized sessions with zone details.
     */
    public function getActiveSessions(?string $zoneId = null): array
    {
        $builder = $this->builder();
        $builder->select('captive_sessions.*, captive_zones.name as zone_name, captive_zones.interface_id')
            ->join('captive_zones', 'captive_zones.id = captive_sessions.zone_id', 'left')
            ->where('captive_sessions.status', 'authorized');

        if ($zoneId !== null) {
            $builder->where('captive_sessions.zone_id', $zoneId);
        }

        return $builder->orderBy('captive_sessions.connected_at', 'DESC')->get()->getResultArray();
    }
}
