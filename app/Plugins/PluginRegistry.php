<?php

namespace App\Plugins;

use RuntimeException;

/**
 * Discovers and caches the plugin manifests shipped under
 * `app/Plugins/definitions/<id>/plugin.json`.
 *
 * Manifests are read from disk once per registry instance and cached by plugin
 * id. Like the connector registry, they are never fetched over the network —
 * the security surface stays "which manifests exist", not runtime registration
 * (Plugin Spec §4, PL9).
 */
final class PluginRegistry
{
    /** Directory the registry scans by default. */
    public const DEFAULT_DIR = APPPATH . 'Plugins/definitions';

    private string $dir;

    /** @var array<string, PluginManifest>|null id => manifest, lazily populated. */
    private ?array $cache = null;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? self::DEFAULT_DIR, '/\\');
    }

    /**
     * Every valid manifest, keyed by plugin id.
     *
     * @return array<string, PluginManifest>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];

        if (! is_dir($this->dir)) {
            return $this->cache;
        }

        foreach (glob($this->dir . '/*/plugin.json') ?: [] as $file) {
            $manifest = $this->loadFile($file);
            if ($manifest !== null) {
                $this->cache[$manifest->id()] = $manifest;
            }
        }

        ksort($this->cache);

        return $this->cache;
    }

    public function get(string $id): ?PluginManifest
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * Reads and validates one manifest file.
     *
     * A malformed manifest is refused loudly rather than silently dropped, so a
     * broken bundle cannot make a shipped plugin disappear (Plugin Spec §4).
     */
    private function loadFile(string $file): ?PluginManifest
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        try {
            $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("plugin manifest {$file} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($raw)) {
            throw new RuntimeException("plugin manifest {$file} must decode to an object");
        }

        return PluginManifest::fromArray($raw);
    }
}
