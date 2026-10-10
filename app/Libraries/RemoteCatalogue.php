<?php

namespace App\Libraries;

use Config\PluginCatalogue;

/**
 * RemoteCatalogue — fetches, verifies and caches the signed plugin catalogue.
 *
 * The fetch goes through {@see ConnectorEgressGuard}, so it inherits the same
 * SSRF defence, DNS-rebinding pinning, redirect validation and byte caps the
 * connector engine uses. The bytes are then verified by {@see CatalogueVerifier}
 * before anything is written; only a document signed by a key shipped in the
 * image is ever cached, and the cached envelope is re-verified on every read so
 * a rotated or removed key invalidates it immediately.
 *
 * The catalogue is *descriptive*: it reports plugin versions and their declared
 * packages/units. It cannot widen what a plugin may touch — `micrond` re-resolves
 * every plugin id against its own fixed catalogue (Plugin Spec §5, P1/P2).
 */
final class RemoteCatalogue
{
    private ConnectorEgressGuard $guard;

    private CatalogueVerifier $verifier;

    private PluginCatalogue $config;

    private string $cacheFile;

    public function __construct(
        ?ConnectorEgressGuard $guard = null,
        ?CatalogueVerifier $verifier = null,
        ?PluginCatalogue $config = null,
        ?string $cacheFile = null,
    ) {
        $this->config   = $config ?? config(PluginCatalogue::class);
        $this->guard    = $guard ?? new ConnectorEgressGuard();
        $this->verifier = $verifier ?? new CatalogueVerifier($this->config);
        $this->cacheFile = $cacheFile ?? $this->config->cacheFile();
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Fetch, verify and cache the catalogue.
     *
     * @return array{ok: bool, message: string, catalogue: array<string, mixed>|null, meta?: array<string, mixed>}
     */
    public function sync(): array
    {
        if (! $this->config->isConfigured()) {
            return ['ok' => false, 'message' => 'The signed plugin catalogue is not configured on this appliance.', 'catalogue' => null];
        }

        $url = trim($this->config->url);
        if ($this->config->requireHttps && ! str_starts_with(strtolower($url), 'https://')) {
            return ['ok' => false, 'message' => 'The catalogue URL must use https.', 'catalogue' => null];
        }

        $staging = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'micron_cat_' . bin2hex(random_bytes(8));

        $fetch = $this->guard->fetch($url, ['destination' => $staging]);
        if (($fetch['ok'] ?? false) !== true) {
            $this->cleanup($staging);

            return ['ok' => false, 'message' => 'Catalogue fetch failed: ' . (string) ($fetch['error'] ?? 'unknown error'), 'catalogue' => null];
        }

        $raw = @file_get_contents($staging);
        $this->cleanup($staging);

        if (! is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'message' => 'Catalogue fetch returned an empty document.', 'catalogue' => null];
        }

        return $this->cacheVerified($raw, $url, (string) ($fetch['sha256'] ?? hash('sha256', $raw)));
    }

    /**
     * Import a signed catalogue envelope from a local file — the air-gapped
     * path (Plugin Spec §5.2). The operator's explicit action is the consent, so
     * this does not require a configured URL; the signature is still verified
     * against a shipped key before anything is cached.
     *
     * @return array{ok: bool, message: string, catalogue: array<string, mixed>|null, meta?: array<string, mixed>}
     */
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['ok' => false, 'message' => "Catalogue file not found or unreadable: {$path}", 'catalogue' => null];
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'message' => 'Catalogue file is empty.', 'catalogue' => null];
        }

        return $this->cacheVerified($raw, 'file://' . basename($path), hash('sha256', $raw));
    }

    /**
     * Verify a raw envelope and, only when it checks out, cache it.
     *
     * @return array{ok: bool, message: string, catalogue: array<string, mixed>|null, meta?: array<string, mixed>}
     */
    private function cacheVerified(string $raw, string $url, string $sha256): array
    {
        $verified = $this->verifier->verify($raw);
        if (($verified['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Catalogue verification failed: ' . (string) ($verified['error'] ?? 'invalid signature'), 'catalogue' => null];
        }

        $record = [
            'fetched_at' => date('c'),
            'url'        => $url,
            'sha256'     => $sha256,
            'key_id'     => (string) $verified['key_id'],
            'envelope'   => $raw,
        ];

        if (! $this->writeCache($record)) {
            return ['ok' => false, 'message' => 'The verified catalogue could not be written to the cache.', 'catalogue' => $verified['catalogue']];
        }

        return [
            'ok'        => true,
            'message'   => 'Signed plugin catalogue verified and cached.',
            'catalogue' => $verified['catalogue'],
            'meta'      => $this->meta($record),
        ];
    }

    /**
     * The last cached catalogue, re-verified. Null when nothing is cached or the
     * cached document no longer verifies (expired, or its key is gone).
     *
     * @return array<string, mixed>|null
     */
    public function catalogue(): ?array
    {
        $record = $this->readCache();
        if ($record === null) {
            return null;
        }

        $verified = $this->verifier->verify((string) ($record['envelope'] ?? ''));

        return ($verified['ok'] ?? false) === true ? $verified['catalogue'] : null;
    }

    /**
     * Every catalogue plugin entry keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function plugins(): array
    {
        $catalogue = $this->catalogue();
        if ($catalogue === null) {
            return [];
        }

        $out = [];
        foreach ((array) ($catalogue['plugins'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $out[(string) $entry['id']] = $entry;
            }
        }

        return $out;
    }

    /**
     * Catalogue versions, `id => version`.
     *
     * @return array<string, string>
     */
    public function versions(): array
    {
        $out = [];
        foreach ($this->plugins() as $id => $entry) {
            $out[$id] = (string) ($entry['version'] ?? '');
        }

        return $out;
    }

    /**
     * A render-ready snapshot for the Updates screen.
     *
     * @return array{configured: bool, available: bool, url: string, key_id: string,
     *               fetched_at: string, sha256: string, stale: bool, age_seconds: int|null,
     *               version: int|null, issued_at: string|null, expires_at: string|null,
     *               count: int, error: string|null}
     */
    public function status(): array
    {
        $blank = [
            'configured'   => $this->isConfigured(),
            'available'    => false,
            'url'          => trim($this->config->url),
            'key_id'       => '',
            'fetched_at'   => '',
            'sha256'       => '',
            'stale'        => false,
            'age_seconds'  => null,
            'version'      => null,
            'issued_at'    => null,
            'expires_at'   => null,
            'count'        => 0,
            'error'        => null,
        ];

        $record = $this->readCache();
        if ($record === null) {
            return $blank;
        }

        $meta     = $this->meta($record);
        $verified = $this->verifier->verify((string) ($record['envelope'] ?? ''));

        if (($verified['ok'] ?? false) !== true) {
            return $blank + [
                'url'        => (string) ($record['url'] ?? $blank['url']),
                'key_id'     => (string) ($record['key_id'] ?? ''),
                'fetched_at' => (string) ($record['fetched_at'] ?? ''),
                'sha256'     => (string) ($record['sha256'] ?? ''),
                'stale'      => (bool) $meta['stale'],
                'age_seconds' => $meta['age_seconds'],
                'error'      => (string) ($verified['error'] ?? 'cached catalogue no longer verifies'),
            ];
        }

        $catalogue = (array) $verified['catalogue'];

        return [
            'configured'  => $this->isConfigured(),
            'available'   => true,
            'url'         => (string) ($record['url'] ?? $blank['url']),
            'key_id'      => (string) ($record['key_id'] ?? ''),
            'fetched_at'  => (string) ($record['fetched_at'] ?? ''),
            'sha256'      => (string) ($record['sha256'] ?? ''),
            'stale'       => (bool) $meta['stale'],
            'age_seconds' => $meta['age_seconds'],
            'version'     => isset($catalogue['catalogue_version']) ? (int) $catalogue['catalogue_version'] : null,
            'issued_at'   => isset($catalogue['issued_at']) ? (string) $catalogue['issued_at'] : null,
            'expires_at'  => isset($catalogue['expires_at']) ? (string) $catalogue['expires_at'] : null,
            'count'       => is_array($catalogue['plugins'] ?? null) ? count($catalogue['plugins']) : 0,
            'error'       => null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{fetched_at: string, url: string, sha256: string, key_id: string,
     *               age_seconds: int|null, stale: bool}
     */
    private function meta(array $record): array
    {
        $fetched  = strtotime((string) ($record['fetched_at'] ?? ''));
        $age      = $fetched === false ? null : (time() - $fetched);
        $maxAge   = max(0, $this->config->maxAgeHours) * 3600;

        return [
            'fetched_at'  => (string) ($record['fetched_at'] ?? ''),
            'url'         => (string) ($record['url'] ?? ''),
            'sha256'      => (string) ($record['sha256'] ?? ''),
            'key_id'      => (string) ($record['key_id'] ?? ''),
            'age_seconds' => $age,
            'stale'       => $maxAge > 0 && $age !== null && $age > $maxAge,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function writeCache(array $record): bool
    {
        $dir = dirname($this->cacheFile);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $tmp = $this->cacheFile . '.part';
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }
        @chmod($tmp, 0600);

        if (! @rename($tmp, $this->cacheFile)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        if (! is_file($this->cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($this->cacheFile);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function cleanup(string $staging): void
    {
        @unlink($staging);
        @unlink($staging . '.part');
    }
}
