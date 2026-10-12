<?php

namespace App\Libraries;

/**
 * GuacamoleTokenGenerator — Generates signed HMAC authorization payloads
 * for the in-browser HTML5 RDP/SSH/VNC WebSocket clientless viewer.
 */
class GuacamoleTokenGenerator
{
    private static function getSecret(): string
    {
        $appKey = config('Encryption')->key ?? 'micron-os-secret-key-32-chars-long!';
        return hash('sha256', $appKey);
    }

    /**
     * Generate signed connection parameter payload.
     *
     * @param array{type: string, internal_host: string, internal_port: int, name: string} $resource
     * @param string $username
     * @param int $ttlSeconds
     * @return string URL-safe Base64 encoded JSON token
     */
    public static function generateToken(array $resource, string $username, int $ttlSeconds = 3600): string
    {
        $payload = [
            'type'      => $resource['type'],
            'host'      => $resource['internal_host'],
            'port'      => (int) $resource['internal_port'],
            'name'      => $resource['name'],
            'username'  => $username,
            'issued_at' => time(),
            'expires'   => time() + $ttlSeconds,
            'nonce'     => bin2hex(random_bytes(8)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = hash_hmac('sha256', $json, self::getSecret());

        $envelope = [
            'payload' => $payload,
            'sig'     => $sig,
        ];

        return rtrim(strtr(base64_encode(json_encode($envelope)), '+/', '-_'), '=');
    }

    /**
     * Validate and decode connection token.
     *
     * @return array<string, mixed>|null Payload array or null if invalid/expired
     */
    public static function verifyToken(string $token): ?array
    {
        $b64 = strtr($token, '-_', '+/');
        $json = base64_decode($b64);
        if (!$json) return null;

        $envelope = json_decode($json, true);
        if (!isset($envelope['payload'], $envelope['sig'])) return null;

        $payload = $envelope['payload'];
        $sig     = $envelope['sig'];

        $expectedSig = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), self::getSecret());
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        if (($payload['expires'] ?? 0) < time()) {
            return null; // Expired
        }

        return $payload;
    }
}
