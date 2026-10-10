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
