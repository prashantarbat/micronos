<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * PluginCatalogue — the signed remote plugin catalogue (Plugin Spec §5.2).
 *
 * The appliance detects newer plugin versions from a document published by
 * Micron Network Systems and verified against a key shipped in the image. This
 * config names the trusted keyring, the catalogue URL, and the cache policy.
 *
 * Every property may be overridden by an environment variable using the usual
 * CI4 config convention, e.g. `PluginCatalogue.url=...` in `.env`.
 */
class PluginCatalogue extends BaseConfig
{
    /**
     * Master switch. When false the Updates screen reports the catalogue as
     * unconfigured and never attempts a fetch.
     */
    public bool $enabled = false;

    /** The catalogue URL. HTTPS only unless {@see $requireHttps} is relaxed. */
    public string $url = '';

    /**
     * Directory of trusted public keys, one PEM per file. The file's stem is
     * the `key_id` the catalogue signature names. This is the "key shipped in
     * the image" location (Plugin Spec §5.2).
     */
    public string $keyringPath = '/etc/micron/pkg/keys';

    /**
     * Inline trusted keys — `key_id => PEM`. Used for development and by the
     * test suite; the appliance ships keys in {@see $keyringPath} instead.
     *
     * @var array<string, string>
     */
    public array $trustedKeys = [];

    /**
     * Cache file for the last verified catalogue. Empty means
     * `writable/plugin-catalogue.json`.
     */
    public string $cachePath = '';

    /** Age (hours) after which a cached catalogue is flagged stale in the UI. 0 disables. */
    public int $maxAgeHours = 168;

    /** Clock-skew tolerance (seconds) applied to issued/expires checks. */
    public int $maxSkewSeconds = 300;

    /** Refuse a plaintext-http catalogue URL. */
    public bool $requireHttps = true;

    public function cacheFile(): string
    {
        return $this->cachePath !== '' ? $this->cachePath : WRITEPATH . 'plugin-catalogue.json';
    }

    public function isConfigured(): bool
    {
        return $this->enabled && trim($this->url) !== '';
    }
}
