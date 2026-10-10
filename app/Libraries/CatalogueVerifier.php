<?php

namespace App\Libraries;

use Config\PluginCatalogue;

/**
 * CatalogueVerifier — verifies a signed plugin catalogue before it is trusted.
 *
 * The publisher ships a single envelope document so verification never depends
 * on JSON canonicalisation: the *exact bytes* of the inner catalogue travel
 * base64-encoded in `payload`, and the detached signature over those bytes
 * travels beside it. Only a key shipped in the image (or configured for dev)
 * may sign a catalogue that the appliance accepts; an unknown key, a tampered
 * payload, a bad signature, an expired document or an unexpected schema are all
 * refused with a reason, and nothing is cached.
 *
 * This is a management-plane check, done with OpenSSL — no daemon call and no
 * shelling out. The daemon remains the sole authority on which packages and
 * units a plugin id may touch (Plugin Spec §5, P1/P2), so a valid catalogue can
 * only ever *describe* versions; it can never introduce a package set.
 */
final class CatalogueVerifier
{
    public const ALGORITHM = 'RSA-SHA256';

    private PluginCatalogue $config;

    public function __construct(?PluginCatalogue $config = null)
    {
        $this->config = $config ?? config(PluginCatalogue::class);
    }

    /**
     * Trusted public keys, `key_id => PEM`: the keyring directory plus any
     * inline dev keys. A keyring file's stem is its key id.
     *
     * @return array<string, string>
     */
    public function trustedKeys(): array
    {
        $keys = [];

        foreach ($this->config->trustedKeys as $id => $pem) {
            if (is_string($pem) && trim($pem) !== '') {
                $keys[(string) $id] = $pem;
            }
        }

        $dir = trim($this->config->keyringPath);
        if ($dir !== '' && is_dir($dir)) {
            foreach (glob(rtrim($dir, '/\\') . '/*.{pem,pub,key}', GLOB_BRACE) ?: [] as $file) {
                $pem = @file_get_contents($file);
                if (is_string($pem) && trim($pem) !== '') {
                    $keys[pathinfo($file, PATHINFO_FILENAME)] = $pem;
                }
            }
        }

        return $keys;
    }

    /**
     * Verify a raw envelope document.
     *
     * @return array{ok: bool, error: string|null, key_id: string|null, catalogue: array<string, mixed>|null}
     */
    public function verify(string $raw): array
    {
        $envelope = json_decode($raw, true);
        if (! is_array($envelope)) {
            return $this->fail('catalogue is not valid JSON');
        }

        $payload   = $envelope['payload'] ?? null;
        $signature = $envelope['signature'] ?? null;

        if (! is_string($payload) || trim($payload) === '') {
            return $this->fail('catalogue carries no signed payload');
        }
        if (! is_array($signature)) {
            return $this->fail('catalogue is unsigned');
        }

        $algorithm = strtoupper((string) ($signature['algorithm'] ?? ''));
        if ($algorithm !== self::ALGORITHM) {
            return $this->fail('unsupported signature algorithm: ' . ($algorithm !== '' ? $algorithm : 'none'));
        }

        $keyId  = trim((string) ($signature['key_id'] ?? ''));
        $sigB64 = (string) ($signature['value'] ?? '');
        if ($keyId === '' || $sigB64 === '') {
            return $this->fail('catalogue signature is incomplete');
        }

        $keys = $this->trustedKeys();
        if (! isset($keys[$keyId])) {
            return $this->fail("catalogue is signed by an untrusted key: {$keyId}");
        }

        $bytes = base64_decode($payload, true);
        if ($bytes === false) {
            return $this->fail('signed payload is not valid base64');
        }

        $signatureBytes = base64_decode($sigB64, true);
        if ($signatureBytes === false) {
            return $this->fail('signature value is not valid base64');
        }

        $publicKey = @openssl_pkey_get_public($keys[$keyId]);
        if ($publicKey === false) {
            return $this->fail("trusted key '{$keyId}' is not a usable public key");
        }

        if (@openssl_verify($bytes, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->fail('catalogue signature does not verify');
        }

        $catalogue = json_decode($bytes, true);
        if (! is_array($catalogue)) {
            return $this->fail('signed payload is not valid JSON');
        }
        if ((int) ($catalogue['schema'] ?? 0) !== 1) {
            return $this->fail('unsupported catalogue schema');
        }

        $now     = time();
        $skew    = max(0, $this->config->maxSkewSeconds);
        $issued  = strtotime((string) ($catalogue['issued_at'] ?? ''));
        $expires = strtotime((string) ($catalogue['expires_at'] ?? ''));

        if ($issued === false) {
            return $this->fail('catalogue has no valid issued_at');
        }
        if ($expires === false) {
            return $this->fail('catalogue has no valid expires_at');
        }
        if ($issued > $now + $skew) {
            return $this->fail('catalogue is issued in the future');
        }
        if ($expires + $skew < $now) {
            return $this->fail('catalogue has expired');
        }

        $plugins = $catalogue['plugins'] ?? null;
        if (! is_array($plugins)) {
            return $this->fail('catalogue lists no plugins');
        }

        $seen = [];
        foreach (array_values($plugins) as $index => $entry) {
            if (! is_array($entry)) {
                return $this->fail('plugin entry #' . $index . ' is malformed');
            }

            $id = (string) ($entry['id'] ?? '');
            if (preg_match('#^[a-z0-9][a-z0-9-]{0,63}$#', $id) !== 1) {
                return $this->fail('plugin entry #' . $index . ' has an invalid id');
            }
            if (isset($seen[$id])) {
                return $this->fail("catalogue lists {$id} more than once");
            }
            $seen[$id] = true;

            if (trim((string) ($entry['version'] ?? '')) === '') {
                return $this->fail("plugin '{$id}' has no version");
            }
        }

        return [
            'ok'        => true,
            'error'     => null,
            'key_id'    => $keyId,
            'catalogue' => $catalogue,
        ];
    }

    /**
     * @return array{ok: false, error: string, key_id: null, catalogue: null}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'key_id' => null, 'catalogue' => null];
    }
}
