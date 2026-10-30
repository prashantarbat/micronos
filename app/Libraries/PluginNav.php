<?php

namespace App\Libraries;

use App\Plugins\PluginRegistry;
use Config\Database;

/**
 * PluginNav — navigation entries contributed by *installed* plugins.
 *
 * A plugin manifest may declare `provides.menu` entries (validated and pinned
 * under `/plugins/` by {@see \App\Plugins\PluginManifest}). The layout renders
 * them for installed plugins only, and only when the current session holds the
 * entry's declared permission. Nothing here trusts the manifest beyond what the
 * loader already validated.
 */
final class PluginNav
{
    /**
     * Where each first-party plugin's operational page belongs in the rail.
     *
     * The Plugins group stays the place to install, configure and troubleshoot
     * plugins; this map additionally places each installed plugin's *feature*
     * next to the built-in pages an operator would look for it beside
     * (docs/MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md §8). Keys are
     * `NavGroups` parent keys; an unmapped plugin appears under Plugins only.
     */
    public const SECTIONS = [
        'lldpd'       => 'network',   // neighbours belong with the interfaces
        'ipv6-tunnel' => 'network',   // a 6in4 tunnel is an interface
        'nat64'       => 'routing',   // NAT64/DNS64 is a routing feature
        'zerotier'    => 'vpn',       // overlays are looked for under VPN
        'netbird'     => 'vpn',
        'iperf3'      => 'admin',     // throughput is a diagnostic
        'darkstat'    => 'security',  // traffic accounting / reporting
        'lightsquid'  => 'security',  // proxy usage reports
    ];

    /**
     * Installed plugins' entries that belong in one functional rail section.
     *
     * @return list<array{id: string, label: string, path: string, permission: string, name: string}>
     */
    public static function entriesFor(string $section, ?array $entries = null): array
    {
        return array_values(array_filter(
            $entries ?? self::entries(),
            static fn (array $e): bool => (self::SECTIONS[$e['id']] ?? null) === $section
        ));
    }

    /** The functional section that owns a plugin page path such as `/plugins/lldpd`, if any. */
    public static function sectionForPath(string $path): ?string
    {
        if (preg_match('#^/plugins/([a-z0-9][a-z0-9-]*)(?:/|$)#', $path, $m) !== 1) {
            return null;
        }

        return self::SECTIONS[$m[1]] ?? null;
    }

    /**
     * Installed plugins' menu entries for the current session.
     *
     * @param PluginRegistry|null $registry injectable for tests / callers that
     *                                      already hold a registry
     *
     * @return list<array{id: string, label: string, path: string, permission: string, name: string}>
     */
    public static function entries(?PluginRegistry $registry = null): array
    {
        try {
            $db = Database::connect();
            if (! $db->tableExists('plugins')) {
                return [];
            }
            $rows = $db->table('plugins')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }

        if ($rows === []) {
            return [];
        }

        $registry ??= new PluginRegistry();
        $super = (bool) session('is_superadmin');
        $perms = (array) (session('permissions') ?? []);

        $out = [];
        foreach ($rows as $row) {
            $manifest = $registry->get((string) ($row['id'] ?? ''));
            if ($manifest === null) {
                continue;
            }

            foreach ($manifest->menu() as $entry) {
                if ($entry['permission'] !== '' && ! $super && ! in_array($entry['permission'], $perms, true)) {
                    continue;
                }
                $out[] = [
                    'id'         => $manifest->id(),
                    'label'      => $entry['label'],
                    'path'       => $entry['path'],
                    'permission' => $entry['permission'],
                    'name'       => $manifest->name(),
                ];
            }
        }

        return $out;
    }
}
