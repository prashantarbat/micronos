<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * IdsSettingsModel — Suricata IDS/IPS engine settings & GeoIP country filtering configuration
 */
class IdsSettingsModel extends Model
{
    protected $table         = 'ids_settings';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'enabled',
        'mode',
        'interfaces',
        'rule_sources',
        'auto_update',
        'log_payloads',
        'log_packets',
        'country_filter_mode',
        'country_filter_action',
        'selected_countries',
        'nfqueue_num',
        'queue_fail_mode',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;

    /**
     * Default settings template
     */
    public function defaultSettings(): array
    {
        return [
            'id'                    => 1,
            'enabled'               => 1,
            'mode'                  => 'ips',
            'interfaces'            => ['wan', 'lan'],
            'rule_sources'          => ['et_open', 'abuse_ch_c2', 'sslbl', 'suricata_app_layer'],
            'auto_update'           => 1,
            'log_payloads'          => 1,
            'log_packets'           => 0,
            'country_filter_mode'   => 'blacklist',
            'country_filter_action' => 'drop',
            'selected_countries'    => ['RU', 'CN', 'KP', 'IR'],
            'nfqueue_num'           => 0,
            'queue_fail_mode'       => 'open',
        ];
    }

    /**
     * Retrieve the active IDS/IPS settings record with decoded JSON arrays.
     */
    public function getSettings(): array
    {
        $row = $this->find(1);
        if (! $row) {
            $defaults = $this->defaultSettings();
            $this->insert([
                'id'                    => 1,
                'enabled'               => $defaults['enabled'],
                'mode'                  => $defaults['mode'],
                'interfaces'            => json_encode($defaults['interfaces']),
                'rule_sources'          => json_encode($defaults['rule_sources']),
                'auto_update'           => $defaults['auto_update'],
                'log_payloads'          => $defaults['log_payloads'],
                'log_packets'           => $defaults['log_packets'],
                'country_filter_mode'   => $defaults['country_filter_mode'],
                'country_filter_action' => $defaults['country_filter_action'],
                'selected_countries'    => json_encode($defaults['selected_countries']),
            ]);
            return $defaults;
        }

        $row['interfaces']         = is_string($row['interfaces'] ?? null) ? (json_decode($row['interfaces'], true) ?: []) : ($row['interfaces'] ?? []);
        $row['rule_sources']       = is_string($row['rule_sources'] ?? null) ? (json_decode($row['rule_sources'], true) ?: []) : ($row['rule_sources'] ?? []);
        $row['selected_countries']  = is_string($row['selected_countries'] ?? null) ? (json_decode($row['selected_countries'], true) ?: []) : ($row['selected_countries'] ?? []);

        return $row;
    }

    /**
     * Save updated settings
     */
    public function saveSettings(array $data): bool
    {
        if (isset($data['interfaces']) && is_array($data['interfaces'])) {
            $data['interfaces'] = json_encode(array_values(array_unique(array_filter($data['interfaces']))));
        }
        if (isset($data['rule_sources']) && is_array($data['rule_sources'])) {
            $data['rule_sources'] = json_encode(array_values(array_unique(array_filter($data['rule_sources']))));
        }
        if (isset($data['selected_countries']) && is_array($data['selected_countries'])) {
            $data['selected_countries'] = json_encode(array_values(array_unique(array_filter($data['selected_countries']))));
        }

        if ($this->find(1)) {
            return (bool) $this->update(1, $data);
        }

        $data['id'] = 1;
        return (bool) $this->insert($data);
    }
}
