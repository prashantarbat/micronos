<?php

namespace App\Controllers;

use App\Libraries\TerminalClient;
use App\Models\PermissionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Terminal / Console — interactive Linux shells in the browser.
 *
 * This controller is the authorisation point: it decides who may open which
 * kind of shell and asks micron-terminald for a one-time ticket bound to the
 * user, the privilege level, the browser Origin and the client address. The
 * browser then opens the WebSocket with that ticket; the service enforces the
 * ticket, the limits and the timeouts, and never accepts a shell request that
 * did not come through here.
 *
 *   terminal.access  — standard shell as the unprivileged `micron-term` account
 *   terminal.admin   — root shell, after re-entering the account password
 */
class Terminal extends BaseController
{
    private const MAX_REAUTH_FAILURES = 5;

    private function client(): TerminalClient
    {
        return \Config\Services::terminalClient();
    }

    private function userId(): string
    {
        return (string) session('user_id');
    }

    private function can(string $perm): bool
    {
        if (session('is_superadmin')) {
            return true;
        }

        return (new PermissionModel())->userCan($this->userId(), $perm);
    }

    public function index(): string
    {
        $client   = $this->client();
        $status   = $client->status();
        $sessions = $status !== null ? ($client->sessions($this->userId()) ?? []) : [];

        return view('terminal/index', [
            'title'         => 'Terminal — Micron OS',
            'breadcrumb'    => 'Terminal',
            'available'     => $status !== null,
            'status'        => $status,
            'sessions'      => $sessions,
            'canAdmin'      => $this->can('terminal.admin'),
            'adminUnlocked' => $this->adminUnlocked(),
            'transport'     => $this->transportProblem(),
            'wsUrl'         => (string) config('Terminal')->wsUrl,
        ]);
    }

    /** POST terminal/session — a one-time ticket for a new or existing session. */
    public function session(): ResponseInterface
    {
        $level  = (string) ($this->request->getPost('level') ?? 'standard');
        $attach = trim((string) ($this->request->getPost('attach') ?? ''));
        if (! in_array($level, ['standard', 'admin'], true)) {
            return $this->fail(400, 'Unknown privilege level.');
        }
        if ($attach !== '' && preg_match('/^[a-f0-9]{16}$/', $attach) !== 1) {
            return $this->fail(400, 'Invalid session id.');
        }
        if (($problem = $this->transportProblem()) !== null) {
            $this->audit('terminal.denied', $problem);

            return $this->fail(403, $problem);
        }
        if ($level === 'admin') {
            if (! $this->can('terminal.admin')) {
                $this->audit('terminal.denied', 'admin terminal without terminal.admin');

                return $this->fail(403, 'You are not authorised to open an administrative (root) terminal.');
            }
            if (! $this->adminUnlocked()) {
                return $this->fail(401, 'Re-enter your password to open an administrative terminal.', ['reauth' => true]);
            }
        }

        $client = $this->client();
        $r      = $client->issue(array_filter([
            'user_id'   => $this->userId(),
            'username'  => (string) session('username'),
            'level'     => $level,
            'origin'    => $this->origin(),
            'client_ip' => $this->request->getIPAddress(),
            'attach'    => $attach !== '' ? $attach : null,
        ], static fn ($v) => $v !== null));
        if ($r === null) {
            return $this->fail(503, 'The terminal service is not running on this appliance.');
        }
        if (($r['success'] ?? false) !== true) {
            return $this->fail(($r['code'] ?? '') === 'limit' ? 429 : 400, (string) ($r['error'] ?? 'refused'));
        }
        if ($attach === '') {
            $this->audit($level === 'admin' ? 'terminal.admin.open' : 'terminal.open', "{$level} terminal requested");
        }

        return $this->response->setJSON([
            'ok'     => true,
            'ticket' => (string) $r['ticket'],
            'ws_url' => $this->wsUrl(),
            'level'  => $level,
            'csrf'   => csrf_hash(),
        ]);
    }

    /** POST terminal/elevate — re-authenticate for administrative sessions. */
    public function elevate(): ResponseInterface
    {
        if (! $this->can('terminal.admin')) {
            $this->audit('terminal.denied', 'elevation without terminal.admin');

            return $this->fail(403, 'You are not authorised to open an administrative (root) terminal.');
        }
        $fails = (int) session('terminal_reauth_failures');
        $until = (int) session('terminal_reauth_locked_until');
        if ($until > time()) {
            return $this->fail(429, 'Too many failed attempts. Try again in ' . ($until - time()) . ' seconds.');
        }

        $user = \Config\Database::connect()->table('users')->where('id', $this->userId())->get()->getRowArray();
        if (! $user || ($user['auth_source'] ?? 'local') !== 'local' || empty($user['password_hash'])) {
            $this->audit('terminal.denied', 'elevation for a non-local account');

            return $this->fail(403, 'Administrative terminals require a local Micron OS account password.');
        }
        if (! password_verify((string) $this->request->getPost('password'), (string) $user['password_hash'])) {
            $fails++;
            session()->set('terminal_reauth_failures', $fails);
            if ($fails >= self::MAX_REAUTH_FAILURES) {
                session()->set('terminal_reauth_locked_until', time() + 300);
                session()->set('terminal_reauth_failures', 0);
            }
            $this->audit('terminal.reauth_failed', 'wrong password for administrative terminal');

            return $this->fail(401, 'Password incorrect.', ['reauth' => true]);
        }
        session()->set('terminal_reauth_failures', 0);
        session()->set('terminal_admin_until', time() + config('Terminal')->adminReauthSeconds);
        $this->audit('terminal.admin.elevated', 'password re-entered for administrative terminal');

        return $this->response->setJSON(['ok' => true, 'until' => (int) session('terminal_admin_until'), 'csrf' => csrf_hash()]);
    }

    /** GET terminal/sessions — this user's live sessions. */
    public function sessions(): ResponseInterface
    {
        $list = $this->client()->sessions($this->userId());

        return $this->response->setJSON(['ok' => $list !== null, 'sessions' => $list ?? [], 'csrf' => csrf_hash()]);
    }

    /** POST terminal/sessions/{id}/kill — end one of this user's sessions. */
    public function kill(string $id): ResponseInterface
    {
        if (preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
            return $this->fail(400, 'Invalid session id.');
        }
        $ok = $this->client()->kill($id, $this->userId(), 'terminated by ' . session('username'));
        if ($ok) {
            $this->audit('terminal.kill', "session {$id} terminated");
        }

        return $ok ? $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()])
            : $this->fail(404, 'No such session.');
    }

    // ------------------------------------------------------------------

    private function adminUnlocked(): bool
    {
        return (int) session('terminal_admin_until') > time();
    }

    /** Null when the connection may carry a terminal, otherwise why not. */
    private function transportProblem(): ?string
    {
        if ($this->request->isSecure()) {
            return null;
        }
        if (config('Terminal')->requireTls) {
            return 'The terminal requires HTTPS on this appliance.';
        }
        $ip = $this->request->getIPAddress();
        if (self::isPrivate($ip)) {
            return null;
        }

        return 'The terminal over plain HTTP is only available from a management (private) network. Use HTTPS.';
    }

    public static function isPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }
        // FILTER_FLAG_NO_PRIV_RANGE fails for RFC 1918 / fc00::/7; NO_RES for loopback, link-local.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
            return true;
        }
        if (str_contains($ip, ':')) {
            return str_starts_with(strtolower($ip), 'fe80:');
        }
        $long = ip2long($ip);

        // CGNAT 100.64.0.0/10 and link-local 169.254.0.0/16.
        return (($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000))
            || (($long & 0xFFFF0000) === (ip2long('169.254.0.0') & 0xFFFF0000));
    }

    /** The Origin the browser will send for this page. */
    private function origin(): string
    {
        $host = (string) ($this->request->getServer('HTTP_HOST') ?? 'localhost');

        return ($this->request->isSecure() ? 'https' : 'http') . '://' . $host;
    }

    private function wsUrl(): string
    {
        $configured = (string) config('Terminal')->wsUrl;
        if ($configured !== '') {
            return $configured;
        }
        $host = (string) ($this->request->getServer('HTTP_HOST') ?? 'localhost');

        return ($this->request->isSecure() ? 'wss' : 'ws') . '://' . $host . '/terminal/ws';
    }

    private function fail(int $code, string $message, array $extra = []): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON(['ok' => false, 'message' => $message, 'csrf' => csrf_hash()] + $extra);
    }

    private function audit(string $action, string $detail): void
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('audit_logs')) {
                return;
            }
            $db->table('audit_logs')->insert([
                'admin_user'      => (string) (session('username') ?? 'unknown'),
                'client_ip'       => $this->request->getIPAddress(),
                'user_id'         => $this->userId() ?: 'unknown',
                'username'        => (string) (session('username') ?? 'unknown'),
                'action_type'     => 'terminal',
                'action'          => $action,
                'target_resource' => 'Terminal',
                'target'          => 'terminal',
                'new_state'       => mb_substr($detail, 0, 500),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }
}
