<?php

namespace Tests\Support\Libraries;

use App\Libraries\TerminalClient;

/**
 * Stand-in for micron-terminald's control socket: records every call and
 * answers like the service does.
 */
class FakeTerminalClient extends TerminalClient
{
    /** @var list<array{method: string, params: array}> */
    public array $calls = [];

    public bool $running = true;

    /** @var list<array<string, mixed>> */
    public array $sessionList = [];

    /** @var array<string, mixed>|null */
    public ?array $issueAnswer = null;

    public function __construct()
    {
    }

    public function status(): ?array
    {
        $this->calls[] = ['method' => 'terminal.status', 'params' => []];

        return $this->running ? ['success' => true, 'version' => 'test', 'uptime' => 1, 'sessions' => count($this->sessionList),
            'limits' => ['max_sessions_per_user' => 3, 'max_sessions_total' => 16, 'idle_timeout' => 900,
                'admin_idle_timeout' => 600, 'max_lifetime' => 28800, 'admin_max_lifetime' => 3600, 'detach_grace' => 120],
            'standard_user_present' => true] : null;
    }

    public function issue(array $p): ?array
    {
        $this->calls[] = ['method' => 'terminal.issue', 'params' => $p];
        if (! $this->running) {
            return null;
        }

        return $this->issueAnswer ?? ['success' => true, 'ticket' => 'tkt-' . count($this->calls), 'expires_in' => 30];
    }

    public function sessions(?string $userId = null): ?array
    {
        $this->calls[] = ['method' => 'terminal.list', 'params' => ['user_id' => $userId]];

        return $this->running ? array_values(array_filter($this->sessionList, static fn ($s) => $userId === null || $s['user_id'] === $userId)) : null;
    }

    public function kill(string $sessionId, ?string $userId, string $reason = ''): bool
    {
        $this->calls[] = ['method' => 'terminal.kill', 'params' => ['session_id' => $sessionId, 'user_id' => $userId]];
        foreach ($this->sessionList as $s) {
            if ($s['id'] === $sessionId && ($userId === null || $s['user_id'] === $userId)) {
                return true;
            }
        }

        return false;
    }

    public function revokeUser(string $userId, string $reason, ?string $level = null): void
    {
        $this->calls[] = ['method' => 'terminal.revoke_user', 'params' => ['user_id' => $userId, 'level' => $level]];
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }
}
