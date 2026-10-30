<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * HA configuration synchronisation: copies the shared configuration from this
 * node to its HA peer.
 *
 * What is copied is the policy both firewalls must agree on (rules, NAT,
 * objects, VIPs, local DNS records, administrators and roles). What makes a
 * node itself is never copied: interface addresses, HA role and priority,
 * hostname, certificates in use, DHCP pools, routing and VPN endpoints.
 *
 * Transport: an HTTPS POST to the peer's `/ha/sync/receive`. The appliances
 * usually run self-signed certificates, so the payload does not rely on TLS:
 * it is encrypted with AES-256-GCM and authenticated with HMAC-SHA256, both
 * keyed from the shared sync key (HKDF, separate keys), and carries a
 * timestamp the receiver accepts once and only within five minutes. The
 * receiver also requires the sender's address to be its configured peer.
 */
class HaConfigSync
{
    /** Tables copied to the peer, in the order they are replaced. */
    public const SYNC_TABLES = [
        'firewall_addresses',
        'firewall_address_groups',
        'firewall_services',
        'firewall_service_groups',
        'firewall_schedules',
        'firewall_rules',
        'nat_rules',
        'nat_outbound_rules',
        'nat_one_to_one',
        'nat_ip_pools',
        'ha_virtual_ips',
        'dns_hosts',
        'dns_domain_overrides',
        'roles',
        'role_permissions',
        'users',
        'user_roles',
    ];

    public const PAYLOAD_VERSION = 1;
    public const MAX_SKEW        = 300;
    public const MIN_KEY_LENGTH  = 16;
    public const RECEIVE_PATH    = 'ha/sync/receive';

    private BaseConnection $db;
    private string $stateFile;

    public function __construct(?BaseConnection $db = null, ?string $stateFile = null)
    {
        $this->db        = $db ?? \Config\Database::connect();
        $this->stateFile = $stateFile ?? WRITEPATH . 'ha_sync_state.json';
    }

    // ── Sender ──────────────────────────────────────────────────────────

    /**
     * Why this node cannot push, or null when it can.
     */
    public function pushProblem(array $settings): ?string
    {
        if (empty($settings['enabled'])) {
            return 'High availability is not enabled on this node';
        }
        if (empty($settings['config_sync_enabled'])) {
            return 'Configuration sync is turned off';
        }
        if (filter_var((string) ($settings['config_sync_peer_ip'] ?? ''), FILTER_VALIDATE_IP) === false) {
            return 'The peer sync address is not a valid IP address';
        }
        if (strlen((string) ($settings['config_sync_api_key'] ?? '')) < self::MIN_KEY_LENGTH) {
            return 'The shared sync key must be at least ' . self::MIN_KEY_LENGTH . ' characters';
        }

        return null;
    }

    /** The shared configuration as it stands on this node. */
    public function export(): array
    {
        $tables = [];
        foreach (self::SYNC_TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $tables[$table] = $this->db->table($table)->get()->getResultArray();
            }
        }

        return [
            'version'      => self::PAYLOAD_VERSION,
            'generated_at' => gmdate('c'),
            'tables'       => $tables,
        ];
    }

    /**
     * Seal a payload for the wire.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function seal(array $payload, string $key, ?int $now = null): array
    {
        $ts    = (string) ($now ?? time());
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $iv    = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plain, 'aes-256-gcm', self::encKey($key), OPENSSL_RAW_DATA, $iv, $tag, $ts);
        if ($ct === false) {
            throw new \RuntimeException('could not encrypt the sync payload');
        }
        $body = base64_encode($iv . $tag . $ct);

        return [
            'body'    => $body,
            'headers' => [
                'Content-Type'          => 'text/plain',
                'X-Micron-Sync-Time'    => $ts,
                'X-Micron-Sync-Signature' => hash_hmac('sha256', $ts . '.' . $body, self::macKey($key)),
            ],
        ];
    }

    /**
     * Send the shared configuration to the peer.
     *
     * @return array{ok: bool, message: string}
     */
    public function push(array $settings, ?callable $transport = null): array
    {
        $problem = $this->pushProblem($settings);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem . '.'];
        }

        $sealed = $this->seal($this->export(), (string) $settings['config_sync_api_key']);
        $peer   = (string) $settings['config_sync_peer_ip'];
        $host   = str_contains($peer, ':') ? "[{$peer}]" : $peer;
        $url    = "https://{$host}/" . self::RECEIVE_PATH;

        $transport ??= static function (string $url, string $body, array $headers): array {
            $client = \Config\Services::curlrequest(['timeout' => 30, 'http_errors' => false], null, null, false);
            // Integrity and secrecy come from the sealed payload, not from the
            // peer's (normally self-signed) certificate.
            $res = $client->post($url, ['body' => $body, 'headers' => $headers, 'verify' => false]);

            return ['status' => $res->getStatusCode(), 'body' => (string) $res->getBody()];
        };

        try {
            $res = $transport($url, $sealed['body'], $sealed['headers']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "The peer {$peer} could not be reached: " . $e->getMessage()];
        }

        $reply = json_decode((string) ($res['body'] ?? ''), true);
        if (($res['status'] ?? 0) === 200 && is_array($reply) && ! empty($reply['ok'])) {
            return ['ok' => true, 'message' => "Configuration copied to {$peer}. " . ($reply['message'] ?? '')];
        }

        $why = is_array($reply) && isset($reply['message']) ? $reply['message'] : 'HTTP ' . ($res['status'] ?? '?');

        return ['ok' => false, 'message' => "The peer {$peer} refused the sync: {$why}"];
    }

    // ── Receiver ────────────────────────────────────────────────────────

    /**
     * Verify, decrypt and import a sync from the peer.
     *
     * @return array{status: int, ok: bool, message: string, payload?: array}
     */
    public function open(array $settings, string $remoteIp, string $body, string $ts, string $signature, ?int $now = null): array
    {
        $now ??= time();
        if (empty($settings['enabled']) || empty($settings['config_sync_enabled'])) {
            return ['status' => 403, 'ok' => false, 'message' => 'configuration sync is not enabled on this node'];
        }
        $key = (string) ($settings['config_sync_api_key'] ?? '');
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            return ['status' => 403, 'ok' => false, 'message' => 'this node has no usable sync key'];
        }
        if ($remoteIp === '' || $remoteIp !== (string) ($settings['config_sync_peer_ip'] ?? '')) {
            return ['status' => 403, 'ok' => false, 'message' => 'the sender is not the configured peer'];
        }
        if (! ctype_digit($ts) || abs($now - (int) $ts) > self::MAX_SKEW) {
            return ['status' => 403, 'ok' => false, 'message' => 'the request is too old or the clocks differ by more than five minutes'];
        }
        $expected = hash_hmac('sha256', $ts . '.' . $body, self::macKey($key));
        if (! hash_equals($expected, $signature)) {
            return ['status' => 403, 'ok' => false, 'message' => 'the signature does not match (different sync keys?)'];
        }
        if ((int) $ts <= $this->lastAccepted()) {
            return ['status' => 409, 'ok' => false, 'message' => 'this sync was already applied (replay refused)'];
        }

        $raw = base64_decode($body, true);
        if ($raw === false || strlen($raw) < 29) {
            return ['status' => 400, 'ok' => false, 'message' => 'malformed payload'];
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encKey($key), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $ts);
        $payload = $plain === false ? null : json_decode($plain, true);
        if (! is_array($payload) || ($payload['version'] ?? null) !== self::PAYLOAD_VERSION || ! is_array($payload['tables'] ?? null)) {
            return ['status' => 400, 'ok' => false, 'message' => 'the payload could not be decrypted or has an unknown version'];
        }

        $this->rememberAccepted((int) $ts);

        return ['status' => 200, 'ok' => true, 'message' => 'verified', 'payload' => $payload];
    }

    /**
     * Replace the shared tables with the peer's rows in one transaction.
     * Unknown tables and columns are ignored; a table missing from the
     * payload is left as it is.
     *
     * @return array<string, int> rows written per table
     */
    public function import(array $payload): array
    {
        $written = [];
        $this->db->transException(true)->transStart();
        try {
            // Children first when clearing, parents first when filling.
            foreach (array_reverse(self::SYNC_TABLES) as $table) {
                if (isset($payload['tables'][$table]) && is_array($payload['tables'][$table]) && $this->db->tableExists($table)) {
                    $this->db->table($table)->emptyTable();
                }
            }
            foreach (self::SYNC_TABLES as $table) {
                $rows = $payload['tables'][$table] ?? null;
                if (! is_array($rows) || ! $this->db->tableExists($table)) {
                    continue;
                }
                $columns = array_flip($this->db->getFieldNames($table));
                $count   = 0;
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $clean = array_intersect_key($row, $columns);
                    if ($clean !== []) {
                        $this->db->table($table)->insert($clean);
                        $count++;
                    }
                }
                $written[$table] = $count;
            }
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return $written;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private static function encKey(string $key): string
    {
        return hash_hkdf('sha256', $key, 32, 'micron-ha-sync-enc');
    }

    private static function macKey(string $key): string
    {
        return hash_hkdf('sha256', $key, 32, 'micron-ha-sync-mac');
    }

    private function lastAccepted(): int
    {
        $state = is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;

        return (int) ($state['last_ts'] ?? 0);
    }

    private function rememberAccepted(int $ts): void
    {
        @file_put_contents($this->stateFile, json_encode(['last_ts' => $ts, 'at' => gmdate('c')]), LOCK_EX);
    }
}
