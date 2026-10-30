<?php

namespace App\Libraries;

/**
 * GuacamoleTokenGenerator — Generates signed HMAC authorization tokens
 * for the in-browser HTML5 RDP/SSH/VNC WebSocket clientless viewer.
 *
 * Token format: `b64url(json) . '.' . hex(hmac_sha256(json))`. The signature
 * covers the exact JSON bytes, so micron-guacd-bridge (Python) can verify it
 * without re-encoding the payload the way PHP would.
 */
class GuacamoleTokenGenerator
{
    /**
     * Signing key shared with micron-guacd-bridge, which creates it
     * (0640 root:www-data) when the service first starts.
     */
    public const KEY_PATH = '/etc/micron/ztna-tunnel.key';

    private static function getSecret(): string
    {
        if (is_readable(self::KEY_PATH)) {
            $key = trim((string) file_get_contents(self::KEY_PATH));
            if ($key !== '') {
                return $key;
            }
        }

        // On the appliance (a production Linux deployment) the key must come
        // from the bridge: never sign with a guessable fallback there.
        if (ENVIRONMENT === 'production' && DIRECTORY_SEPARATOR === '/') {
            throw new \RuntimeException('Clientless Gateway signing key ' . self::KEY_PATH . ' is missing or unreadable; is micron-guacd-bridge running?');
        }

        // Development / test fallback.
        $appKey = config('Encryption')->key ?? 'micron-os-secret-key-32-chars-long!';
        return hash('sha256', $appKey);
    }

    /**
     * Generate signed connection parameter token.
     *
     * @param array{type: string, internal_host: string, internal_port: int, name: string} $resource
     * @param string $username
     * @param int $ttlSeconds
     * @param array{guacd_host?: string, guacd_port?: int} $guacd guacd the bridge should connect through
     * @return string URL-safe token
     * @throws \RuntimeException when the appliance's signing key is missing
     */
    public static function generateToken(array $resource, string $username, int $ttlSeconds = 3600, array $guacd = []): string
    {
        $payload = [
            'type'       => $resource['type'],
            'host'       => $resource['internal_host'],
            'port'       => (int) $resource['internal_port'],
            'name'       => $resource['name'],
            'username'   => $username,
            'guacd_host' => (string) (($guacd['guacd_host'] ?? '') ?: '127.0.0.1'),
            'guacd_port' => (int) (($guacd['guacd_port'] ?? 0) ?: 4822),
            'issued_at'  => time(),
            'expires'    => time() + $ttlSeconds,
            'nonce'      => bin2hex(random_bytes(8)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = hash_hmac('sha256', $json, self::getSecret());

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '.' . $sig;
    }

    /**
     * Validate and decode connection token.
     *
     * @return array<string, mixed>|null Payload array or null if invalid/expired
     */
    public static function verifyToken(string $token): ?array
    {
        $dot = strrpos($token, '.');
        if ($dot === false) return null;

        $json = base64_decode(strtr(substr($token, 0, $dot), '-_', '+/'), true);
        $sig  = substr($token, $dot + 1);
        if ($json === false || $json === '') return null;

        try {
            $expectedSig = hash_hmac('sha256', $json, self::getSecret());
        } catch (\RuntimeException $e) {
            return null;
        }
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) return null;

        if (($payload['expires'] ?? 0) < time()) {
            return null; // Expired
        }

        return $payload;
    }
}
