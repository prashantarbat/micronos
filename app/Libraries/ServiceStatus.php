<?php

namespace App\Libraries;

use Config\Database;

/**
 * ServiceStatus — read-only lookups of daemon run-state.
 *
 * The `system_services` table is the source of truth for daemon status on the
 * web side (micrond keeps the kernel/systemd state in sync). Page headers use
 * this to render the Start/Restart/Stop service-control widget without every
 * controller having to load and pass the table around.
 */
class ServiceStatus
{
    /**
     * Return the service row, or a safe placeholder when the id is unknown.
     *
     * @return array{id:string,name:string,status:string,subsystem:string,pid:int|null}
     */
    public static function get(string $id): array
    {
        $fallback = [
            'id'        => $id,
            'name'      => $id,
            'status'    => 'unknown',
            'subsystem' => '',
            'pid'       => null,
        ];

        try {
            $row = Database::connect()->table('system_services')->where('id', $id)->get()->getRowArray();
        } catch (\Throwable $e) {
            return $fallback;
        }

        if ($row) {
            return $row;
        }

        try {
            $manager  = \Config\Services::packageManager();
            $pluginId = $id;
            $manifest = $manager->registry()->get($pluginId);
            if ($manifest === null) {
                foreach ($manager->catalogue() as $cat) {
                    $m = $manager->registry()->get($cat['id']);
                    if ($m && (in_array($id, $m->units(), true) || in_array($id, $cat['services'] ?? [], true))) {
                        $pluginId = (string) $cat['id'];
                        $manifest = $m;
                        break;
                    }
                }
            }
            if ($manifest !== null) {
                $res    = $manager->status($pluginId);
                $status = (($res['success'] ?? false) === true && isset($res['state'])) ? (string) $res['state'] : 'unknown';
                return [
                    'id'        => $id,
                    'name'      => $manifest->name(),
                    'status'    => $status,
                    'subsystem' => 'plugins',
                    'pid'       => null,
                ];
            }
        } catch (\Throwable $e) {}

        return $fallback;
    }

    public static function isRunning(string $id): bool
    {
        return self::get($id)['status'] === 'running';
    }

    /**
     * Render the Start / Restart / Stop control widget for one or more daemons.
     *
     * Views call this instead of `$this->include(...)`, because CI4's include()
     * does not merge its options array into the view data.
     *
     * @param string|list<string>   $services Daemon id(s) backing the controls.
     * @param array<string,string>  $labels   Optional id => friendly display name,
     *                                        e.g. ['frr' => 'PIM']. The real id
     *                                        still drives the control endpoints.
     */
    public static function widget(string|array $services, array $labels = []): string
    {
        return view('partials/service_controls', [
            'services' => $services,
            'labels'   => $labels,
        ]);
    }

    /**
     * Render the same Start / Restart / Stop widget for compiled DHCP relay
     * helper instances.
     *
     * Relay instances are *not* rows in `system_services`: each enabled
     * destination compiles to a dynamic `dhcp-helper@<slug>` systemd unit. Their
     * run-state and controls therefore live behind the relay JSON endpoints
     * (`dhcp/relay/instances/{slug}/control` and `.../status?ids=`) instead of
     * the daemon API, and the first-paint state is supplied by the caller from
     * `dhcp.relay_status` so the LED is accurate before the first poll.
     *
     * @param list<array{name?:string,slug?:string}> $instances Compiled relay instances.
     * @param array<string,string>                   $statuses  slug => running|stopped.
     */
    public static function relayWidget(array $instances, array $statuses = []): string
    {
        $ids    = [];
        $labels = [];
        foreach ($instances as $instance) {
            $slug = (string) ($instance['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $ids[]            = $slug;
            $labels[$slug]    = (string) ($instance['name'] ?? $slug);
        }

        if ($ids === []) {
            return '';
        }

        return view('partials/service_controls', [
            'services'     => array_values(array_unique($ids)),
            'labels'       => $labels,
            'statuses'     => $statuses,
            'endpoint'     => base_url('dhcp/relay/instances'),
            'logsBase'     => base_url('dhcp/relay/instances'),
            // A compiled slug absent from `dhcp.relay_status` has no unit on the
            // daemon yet, so the LED reads "not applied" instead of "stopped",
            // and its Start/Restart/Stop buttons are disabled — they would only
            // get "relay instance not applied yet" from the daemon.
            'unknownLabel'    => 'not applied',
            'lockWhenUnknown' => true,
        ]);
    }

    /**
     * Render the same Start / Restart / Stop widget for the daemons of
     * *installed* plugins.
     *
     * A plugin's daemon is not a row in `system_services`: the daemon resolves
     * the plugin id to its own systemd unit from the fixed catalogue, so status
     * and controls live behind the plugin daemon JSON endpoints
     * (`plugins/daemons/{id}/control`, `plugins/daemons/status?ids=`). Only
     * installed plugins that declare a unit get a control, and the first-paint
     * state is supplied by the caller from `plugin.status`.
     *
     * @param list<array<string, mixed>> $plugins Catalogue/installed rows with
     *                                            `id`, `name`, `installed`, `units`.
     * @param array<string,string>       $states  plugin id => running|stopped.
     */
    public static function pluginWidget(array $plugins, array $states = []): string
    {
        $ids    = [];
        $labels = [];
        foreach ($plugins as $plugin) {
            if (empty($plugin['installed']) || empty($plugin['units'])) {
                continue;
            }
            $id = (string) ($plugin['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $ids[]         = $id;
            $labels[$id]   = (string) ($plugin['name'] ?? $id);
        }

        if ($ids === []) {
            return '';
        }

        return view('partials/service_controls', [
            'services'     => array_values(array_unique($ids)),
            'labels'       => $labels,
            'statuses'     => $states,
            'endpoint'     => base_url('plugins/daemons'),
            'logsBase'     => base_url('plugins/daemons'),
            'unknownLabel' => 'unknown',
        ]);
    }
}
