<?php

namespace App\Connectors;

use RuntimeException;

/**
 * Discovers and caches the connector manifests shipped under
 * `app/Connectors/definitions/<id>/manifest.json`.
 *
 * The catalog is read from disk once per process (or per registry instance) and
 * cached by connector id. Manifests are never fetched over the network — see
 * the connector specification §12.2, which keeps the security surface to "which
 * manifests exist" rather than runtime registration.
 */
final class ManifestRegistry
{
    /** Directory the registry scans by default. */
    public const DEFAULT_DIR = APPPATH . 'Connectors/definitions';

    private string $dir;

    /** @var array<string, Manifest>|null id => manifest, lazily populated. */
    private ?array $cache = null;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? self::DEFAULT_DIR, '/\\');
    }

    /**
     * Every valid manifest, keyed by connector id.
     *
     * @return array<string, Manifest>
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

        foreach (glob($this->dir . '/*/manifest.json') ?: [] as $file) {
            $manifest = $this->loadFile($file);
            if ($manifest !== null) {
                $this->cache[$manifest->id()] = $manifest;
            }
        }

        ksort($this->cache);

        return $this->cache;
    }

    /**
     * Load a single connector by its manifest id, or null when absent.
     */
    public function get(string $id): ?Manifest
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @return list<string> Connector ids, sorted.
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * Reads and validates one manifest file.
     *
     * A malformed or invalid manifest is refused loudly (specification §5: "A
     * manifest that fails validation is refused at boot, not at first use")
     * rather than being quietly dropped from the catalog.
     */
    private function loadFile(string $file): ?Manifest
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        try {
            $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("connector manifest {$file} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($raw)) {
            throw new RuntimeException("connector manifest {$file} must decode to an object");
        }

        return Manifest::fromArray($raw);
    }
}
