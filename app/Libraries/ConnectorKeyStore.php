<?php

namespace App\Libraries;

/**
 * Connector master-key file access.
 *
 * The connector secret store (§7) is encrypted with a single 32-byte master key
 * held **outside** the database at `/etc/micron/secrets.key`, mode `0640`, owner
 * `root:www-data` (the web application runs as www-data and must read it). Keeping it off the database means a database dump — or a
 * plaintext backup — never yields a way to read the ciphertext.
 *
 * This class is deliberately narrow: it reads, installs and compares the key
 * file. The encrypted secret rows live in `SecretsVault`; full CRUD and key
 * rotation land with the connector engine in Phase 2. The backup policy (§7.6)
 * needs exactly these operations, so they exist here now.
 *
 * The key may be stored as 32 raw bytes (what `write()` produces) or as 64 hex
 * characters (what the backup payload carries); both are read transparently.
 */
final class ConnectorKeyStore
{
    /** Default key path on the appliance. */
    public const DEFAULT_PATH = '/etc/micron/secrets.key';

    private const KEY_BYTES = 32;

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? self::DEFAULT_PATH;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Reads the master key, returning 32 raw bytes, or null when absent or
     * unreadable. The key is never logged.
     */
    public function read(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);

        return $raw === false ? null : self::normalise($raw);
    }

    /**
     * Installs or replaces the master key file.
     *
     * Written to a sibling temp file first and then `rename()`d into place so a
     * crash can never leave a truncated key, and refuses to clobber an existing
     * key unless `$replace` is true (the caller must have taken the explicit
     * "replace the connector master key" decision).
     */
    public function write(string $key, bool $replace = false): bool
    {
        $binary = self::normalise($key);
        if ($binary === null) {
            return false;
        }

        if (is_file($this->path) && ! $replace) {
            return false;
        }

        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(6));

        // Exclusive create of the temp file: a pre-existing path is never
        // followed or truncated.
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $binary);
        fclose($handle);

        if ($written !== self::KEY_BYTES) {
            @unlink($tmp);

            return false;
        }

        @chmod($tmp, 0640);
        $this->applyOwnership($tmp);

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);

            return false;
        }

        @chmod($this->path, 0640);

        return true;
    }

    /**
     * True when the on-disk key byte-for-byte equals the supplied key.
     */
    public function matches(string $key): bool
    {
        $current = $this->read();
        $given   = self::normalise($key);

        return $current !== null && $given !== null && hash_equals($current, $given);
    }

    /**
     * A short, non-reversible fingerprint for logs and audit rows.
     *
     * Never derive this from a value you would not want in a log; it is a hash,
     * not the key, but it still identifies *which* key is installed.
     */
    public function fingerprint(): ?string
    {
        $current = $this->read();

        return $current === null ? null : substr(hash('sha256', $current), 0, 16);
    }

    /**
     * Normalises a candidate key to 32 raw bytes.
     *
     * Accepts 32 raw bytes, a 64-character hex string (the backup-payload form)
     * or a 32-character printable string, tolerating a trailing newline; returns
     * null for anything that is not a 32-byte key.
     */
    public static function normalise(string $key): ?string
    {
        if (strlen($key) === self::KEY_BYTES) {
            return $key;
        }

        $trimmed = trim($key);
        if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) === 1) {
            $decoded = @hex2bin($trimmed);

            return $decoded === false || strlen($decoded) !== self::KEY_BYTES ? null : $decoded;
        }

        if (strlen($trimmed) === self::KEY_BYTES) {
            return $trimmed;
        }

        return null;
    }

    /**
     * The CodeIgniter encryption key for this appliance, derived from the master
     * key with HKDF so the master key itself is never used directly for two
     * purposes. Null when no master key is installed.
     */
    public static function appEncryptionKey(?string $path = null): ?string
    {
        $master = (new self($path))->read();

        return $master === null ? null : hash_hkdf('sha256', $master, self::KEY_BYTES, 'micron-app-encryption');
    }

    /**
     * Generates a fresh 32-byte key (raw) for first-boot install / rotation.
     * Provided so callers do not invent their own key derivation.
     */
    public static function generate(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /**
     * Best-effort `root:www-data` ownership, matching the specification. On
     * platforms where `chown` is unavailable or not permitted (Windows dev),
     * failure is not fatal — the file is still `0640`.
     */
    private function applyOwnership(string $path): void
    {
        @chown($path, 'root');
        @chgrp($path, 'www-data');
    }
}
