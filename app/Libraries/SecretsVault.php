<?php

namespace App\Libraries;

/**
 * Connector secret store — AEAD primitives.
 *
 * Implements the crypto half of specification §7: each secret is stored as
 * AES-256-GCM with a 12-byte nonce and 16-byte tag, keyed by a 32-byte master
 * key from {@see ConnectorKeyStore}. The algorithm matches the existing backup
 * engine, so no new dependency is introduced.
 *
 * Scope note: this class intentionally exposes only the primitives the backup
 * policy (§7.6) needs — encrypt, decrypt and post-restore verification of
 * restored rows. The full store (`set`/`get` per instance/field, lazy key
 * rotation, `key_version` keyring) arrives with the connector engine in
 * Phase 2; it will build on these methods rather than replace them.
 *
 * Nonce discipline (threat-model F4): every `encrypt()` draws a fresh random
 * 12-byte nonce, so a nonce is never reused under one key.
 */
final class SecretsVault
{
    public const ALGO       = 'aes-256-gcm';
    public const NONCE_LEN  = 12;
    public const TAG_LEN    = 16;

    private ConnectorKeyStore $keyStore;

    public function __construct(ConnectorKeyStore $keyStore)
    {
        $this->keyStore = $keyStore;
    }

    /**
     * Encrypts one secret field.
     *
     * @param string      $plaintext  The secret value.
     * @param string|null $key        Master key (32 raw bytes); null reads the key file.
     * @param int         $keyVersion Stamped onto the row for rotation.
     *
     * @return array{ciphertext: string, nonce: string, tag: string, key_version: int}
     *
     * @throws \RuntimeException when no master key is available
     */
    public function encrypt(string $plaintext, ?string $key = null, int $keyVersion = 1): array
    {
        $key = $key ?? $this->requireKey();

        $nonce = random_bytes(self::NONCE_LEN);
        $tag   = '';

        $ciphertext = openssl_encrypt($plaintext, self::ALGO, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('connector secret encryption failed');
        }

        return [
            'ciphertext'  => base64_encode($ciphertext),
            'nonce'       => base64_encode($nonce),
            'tag'         => base64_encode($tag),
            'key_version' => $keyVersion,
        ];
    }

    /**
     * Decrypts one stored secret field.
     *
     * @param array<string, mixed> $row A `connector_secrets` row.
     * @param string|null          $key Master key; null reads the key file.
     *
     * @return string|null Plaintext, or null when the row is malformed or the
     *                     authentication tag does not verify.
     */
    public function decrypt(array $row, ?string $key = null): ?string
    {
        $key = $key ?? $this->keyStore->read();
        if ($key === null) {
            return null;
        }

        $ciphertext = base64_decode((string) ($row['ciphertext'] ?? ''), true);
        $nonce      = base64_decode((string) ($row['nonce'] ?? ''), true);
        $tag        = base64_decode((string) ($row['tag'] ?? ''), true);

        if ($ciphertext === false || $nonce === false || $tag === false) {
            return null;
        }

        if (strlen($nonce) !== self::NONCE_LEN || strlen($tag) !== self::TAG_LEN) {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, self::ALGO, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Post-restore verification: attempt to decrypt every restored row.
     *
     * A restore must never leave a connector that silently fails to
     * authenticate (§7.6), so the caller surfaces a failed verification and
     * marks those connectors `needs_credentials`.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{ok: bool, checked: int, failed: list<string>, reason: string|null}
     */
    public function verifyRows(array $rows, ?string $key = null): array
    {
        $resolvedKey = $key ?? $this->keyStore->read();
        if ($resolvedKey === null) {
            return [
                'ok'      => $rows === [],
                'checked' => 0,
                'failed'  => [],
                'reason'  => 'no connector master key is installed',
            ];
        }

        $failed = [];
        foreach ($rows as $row) {
            if ($this->decrypt($row, $resolvedKey) === null) {
                $failed[] = (string) ($row['id'] ?? ($row['instance_id'] ?? 'unknown'));
            }
        }

        return [
            'ok'      => $failed === [],
            'checked' => count($rows),
            'failed'  => $failed,
            'reason'  => $failed === [] ? null : 'one or more secrets failed to decrypt with the installed master key',
        ];
    }

    /**
     * @throws \RuntimeException when the key file is absent or invalid
     */
    private function requireKey(): string
    {
        $key = $this->keyStore->read();
        if ($key === null) {
            throw new \RuntimeException('no connector master key is available');
        }

        return $key;
    }
}
