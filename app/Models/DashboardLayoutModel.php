<?php

namespace App\Models;

use CodeIgniter\Model;

class DashboardLayoutModel extends Model
{
    protected $table            = 'dashboard_user_layouts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'user_id',
        'profile_name',
        'column_count',
        'refresh_interval_ms',
        'layout_data',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get or initialize the default dashboard layout for a given user and profile.
     */
    public function getLayoutForUser(int $userId, string $profile = 'default'): array
    {
        $record = $this->where('user_id', $userId)
                       ->where('profile_name', $profile)
                       ->first();

        if ($record && !empty($record['layout_data'])) {
            $decoded = json_decode($record['layout_data'], true);
            if (is_array($decoded)) {
                $record['parsed_layout'] = $decoded;
                return $record;
            }
        }

        // Return standard default layout
        $defaultLayout = $this->getDefaultLayout($profile);
        return [
            'id'                  => 'default_' . $userId . '_' . $profile,
            'user_id'             => $userId,
            'profile_name'        => $profile,
            'column_count'        => $defaultLayout['columns'] ?? 3,
            'refresh_interval_ms' => $defaultLayout['refresh_interval_ms'] ?? 2000,
            'layout_data'         => json_encode($defaultLayout),
            'parsed_layout'       => $defaultLayout,
        ];
    }

    /**
     * Persist user dashboard layout.
     */
    public function saveLayoutForUser(int $userId, string $profile, array $layoutData): bool
    {
        $existing = $this->where('user_id', $userId)
                         ->where('profile_name', $profile)
                         ->first();

        $columns = (int) ($layoutData['columns'] ?? 3);
        $refresh = (int) ($layoutData['refresh_interval_ms'] ?? 2000);
        $jsonStr = json_encode($layoutData);

        if ($existing) {
            return $this->update($existing['id'], [
                'column_count'        => $columns,
                'refresh_interval_ms' => $refresh,
                'layout_data'         => $jsonStr,
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);
        }

        return (bool) $this->insert([
            'id'                  => uniqid('dsh_', true),
            'user_id'             => $userId,
            'profile_name'        => $profile,
            'column_count'        => $columns,
            'refresh_interval_ms' => $refresh,
            'layout_data'         => $jsonStr,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Factory default layouts inspired by pfSense & FortiGate.
     */
    public function getDefaultLayout(string $profile = 'default'): array
    {
        $profile = strtolower(trim($profile));

        if ($profile === 'noc') {
            return [
                'profile'             => 'noc',
                'columns'             => 3,
                'refresh_interval_ms' => 1000,
                'active_widgets'      => [
                    'card_widget_traffic',
                    'card_widget_interfaces',
                    'card_widget_gateways',
                    'card_widget_vpn',
                    'card_widget_gauges',
                    'card_widget_ports',
                    'card_widget_dhcp',
                    'card_widget_conntrack',
                ],
                'widget_configs'      => [
                    'card_widget_ports'      => ['col_span' => 1, 'order' => 6, 'visible' => true],
                    'card_widget_dhcp'       => ['col_span' => 1, 'order' => 6, 'visible' => true],
                    'card_widget_conntrack'  => ['col_span' => 1, 'order' => 7, 'visible' => true],
                    'card_widget_traffic'    => ['col_span' => 2, 'order' => 1, 'visible' => true],
                    'card_widget_gauges'     => ['col_span' => 1, 'order' => 1, 'visible' => true],
                    'card_widget_interfaces' => ['col_span' => 2, 'order' => 2, 'visible' => true],
                    'card_widget_gateways'   => ['col_span' => 1, 'order' => 2, 'visible' => true],
                    'card_widget_vpn'        => ['col_span' => 1, 'order' => 3, 'visible' => true],
                    'card_widget_filter_log' => ['col_span' => 2, 'order' => 4, 'visible' => false],
                    'card_widget_services'   => ['col_span' => 1, 'order' => 4, 'visible' => false],
                    'card_widget_sysinfo'    => ['col_span' => 2, 'order' => 5, 'visible' => false],
                ],
            ];
        }

        if ($profile === 'soc') {
            return [
                'profile'             => 'soc',
                'columns'             => 3,
                'refresh_interval_ms' => 2000,
                'active_widgets'      => [
                    'card_widget_filter_log',
                    'card_widget_services',
                    'card_widget_vpn',
                    'card_widget_sysinfo',
                    'card_widget_gauges',
                    'card_widget_conntrack',
                    'card_widget_plugins',
                ],
                'widget_configs'      => [
                    'card_widget_conntrack'  => ['col_span' => 1, 'order' => 6, 'visible' => true],
                    'card_widget_plugins'    => ['col_span' => 1, 'order' => 6, 'visible' => true],
                    'card_widget_filter_log' => ['col_span' => 2, 'order' => 1, 'visible' => true],
                    'card_widget_services'   => ['col_span' => 1, 'order' => 1, 'visible' => true],
                    'card_widget_vpn'        => ['col_span' => 1, 'order' => 2, 'visible' => true],
                    'card_widget_sysinfo'    => ['col_span' => 2, 'order' => 2, 'visible' => true],
                    'card_widget_gauges'     => ['col_span' => 1, 'order' => 3, 'visible' => true],
                    'card_widget_traffic'    => ['col_span' => 2, 'order' => 4, 'visible' => false],
                    'card_widget_interfaces' => ['col_span' => 2, 'order' => 5, 'visible' => false],
                    'card_widget_gateways'   => ['col_span' => 1, 'order' => 5, 'visible' => false],
                ],
            ];
        }

        return [
            'profile'             => 'default',
            'columns'             => 3,
            'refresh_interval_ms' => 2000,
            'active_widgets'      => [
                'card_widget_gauges',
                'card_widget_traffic',
                'card_widget_interfaces',
                'card_widget_gateways',
                'card_widget_filter_log',
                'card_widget_services',
                'card_widget_vpn',
                'card_widget_sysinfo',
                'card_widget_ports',
                'card_widget_storage',
                'card_widget_dhcp',
                'card_widget_plugins',
                'card_widget_actions',
            ],
            'widget_configs'      => [
                'card_widget_gauges'     => ['col_span' => 1, 'order' => 1, 'visible' => true],
                'card_widget_traffic'    => ['col_span' => 2, 'order' => 1, 'visible' => true],
                'card_widget_interfaces' => ['col_span' => 2, 'order' => 2, 'visible' => true],
                'card_widget_gateways'   => ['col_span' => 1, 'order' => 2, 'visible' => true],
                'card_widget_filter_log' => ['col_span' => 2, 'order' => 3, 'visible' => true],
                'card_widget_services'   => ['col_span' => 1, 'order' => 3, 'visible' => true],
                'card_widget_vpn'        => ['col_span' => 1, 'order' => 4, 'visible' => true],
                'card_widget_sysinfo'    => ['col_span' => 2, 'order' => 4, 'visible' => true],
                'card_widget_ports'      => ['col_span' => 1, 'order' => 5, 'visible' => true],
                'card_widget_storage'    => ['col_span' => 1, 'order' => 5, 'visible' => true],
                'card_widget_dhcp'       => ['col_span' => 1, 'order' => 6, 'visible' => true],
                'card_widget_conntrack'  => ['col_span' => 1, 'order' => 6, 'visible' => false],
                'card_widget_plugins'    => ['col_span' => 1, 'order' => 7, 'visible' => true],
                'card_widget_actions'    => ['col_span' => 1, 'order' => 7, 'visible' => true],
            ],
        ];
    }
}
