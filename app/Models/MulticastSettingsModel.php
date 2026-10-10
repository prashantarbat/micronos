<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * MulticastSettingsModel — single-row configuration for IGMP proxy, multicast
 * routing and bridge snooping / mDNS reflection.
 */
class MulticastSettingsModel extends Model
{
    protected $table         = 'multicast_settings';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'enabled',
        'igmp_proxy_enabled',
        'igmp_upstream',
        'igmp_downstreams',
        'igmp_altnets',
        'igmp_quickleave',
        'pim_enabled',
        'pim_mode',
        'pim_rp_address',
        'pim_interfaces',
        'pim_ssm_range',
        'igmp_querier_enabled',
        'igmp_version',
        'snooping_enabled',
        'snooping_bridge',
        'snooping_querier',
        'snooping_query_interval',
        'mdns_enabled',
        'mdns_interfaces',
        'mdns_mode',
        'mdns_filter',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = false;

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'enabled'                 => 0,
        'igmp_proxy_enabled'      => 0,
        'igmp_upstream'           => '',
        'igmp_downstreams'        => '',
        'igmp_altnets'            => '',
        'igmp_quickleave'         => 1,
        'pim_enabled'             => 0,
        'pim_mode'                => 'sparse',
        'pim_rp_address'          => '',
        'pim_interfaces'          => '',
        'pim_ssm_range'           => '232.0.0.0/8',
        'igmp_querier_enabled'    => 0,
        'igmp_version'            => 3,
        'snooping_enabled'        => 0,
        'snooping_bridge'         => '',
        'snooping_querier'        => 0,
        'snooping_query_interval' => 125,
        'mdns_enabled'            => 0,
        'mdns_interfaces'         => '',
        'mdns_mode'               => 'reflect',
        'mdns_filter'             => '',
    ];

    public function defaultSettings(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The settings row, always complete: missing columns fall back to defaults.
     */
    public function getSettings(): array
    {
        $row = $this->where('id', 1)->first() ?? [];

        return array_merge(self::DEFAULTS, is_array($row) ? $row : []);
    }

    public function saveSettings(array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($this->where('id', 1)->countAllResults() === 0) {
            $data['id']         = 1;
            $data['created_at'] = date('Y-m-d H:i:s');

            return (bool) $this->insert($data);
        }

        return (bool) $this->update(1, $data);
    }
}
