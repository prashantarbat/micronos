<?php

namespace App\Libraries;

/**
 * Control-socket client for micron-terminald (same framing as micrond:
 * 4-byte big-endian length + JSON-RPC 2.0).
 *
 * Every method returns null when the service cannot be reached, so the page
 * can say "the terminal service is not running" instead of failing.
 */
class TerminalClient
{
    private const TIMEOUT = 5;

    public function __construct(private ?string $socketPath = null)
    {
        $this->socketPath ??= config('Terminal')->controlSocket;
    }

    public function available(): bool
    {
        return $this->status() !== null;
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        return $this->request('terminal.status');
    }

    /**
     * @param array{user_id: string, username: string, level: string, origin: string, client_ip: string, attach?: string} $p
     *
     * @return array<string, mixed>|null
     */
    public function issue(array $p): ?array
    {
        return $this->request('terminal.issue', $p);
    }

    /** @return list<array<string, mixed>>|null */
    public function sessions(?string $userId = null): ?array
    {
        $r = $this->request('terminal.list', $userId === null ? [] : ['user_id' => $userId]);

        return $r === null ? null : array_values((array) ($r['sessions'] ?? []));
    }

    public function kill(string $sessionId, ?string $userId, string $reason = 'terminated from the Web UI'): bool
    {
        $p = ['session_id' => $sessionId, 'reason' => $reason];
        if ($userId !== null) {
            $p['user_id'] = $userId;
        }

        return (bool) (($this->request('terminal.kill', $p))['success'] ?? false);
    }

    public function revokeUser(string $userId, string $reason, ?string $level = null): void
    {
        $p = ['user_id' => $userId, 'reason' => $reason];
        if ($level !== null) {
            $p['level'] = $level;
        }
        $this->request('terminal.revoke_user', $p);
    }

    /** @return array<string, mixed>|null */
    private function request(string $method, array $params = []): ?array
    {
        if (str_starts_with(strtoupper(PHP_OS), 'WIN') || ! file_exists((string) $this->socketPath)) {
            return null;
        }
        $sock = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, self::TIMEOUT);
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, self::TIMEOUT);
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
        fwrite($sock, pack('N', strlen($body)) . $body);
        $hdr = fread($sock, 4);
        if ($hdr === false || strlen($hdr) !== 4) {
            fclose($sock);

            return null;
        }
        $len  = unpack('N', $hdr)[1];
        $data = '';
        while (strlen($data) < $len && ! feof($sock)) {
            $chunk = fread($sock, $len - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        fclose($sock);
        $resp = json_decode($data, true);

        return is_array($resp['result'] ?? null) ? $resp['result'] : null;
    }
}
