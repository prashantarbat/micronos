<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\PppoeBackoff;
use App\Libraries\PppoeCompiler;
use App\Libraries\RadiusAttributes;
use App\Models\PppoeSessionHistoryModel;

/**
 * Pppoe Controller — the appliance's own PPPoE access concentrator.
 *
 * Two halves, mirroring the compiler:
 *  - servers     : one access concentrator per local interface, with the
 *                  address pool it hands out;
 *  - subscribers : the credentials each server authenticates.
 *
 * Nothing is written to disk here. The page saves configuration and `apply()`
 * hands the rendered files to micrond, which owns /etc/ppp.
 */
class Pppoe extends BaseController
{
    private IpcClient $ipc;
    private PppoeCompiler $compiler;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc      = \Config\Services::ipc();
        $this->compiler = new PppoeCompiler();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();

        // The monitor's Refresh button asks for just the session table, so it
        // can update in place without a full page load.
        $partial = $this->request->getGet('partial') === 'sessions';

        $servers = $db->table('pppoe_servers')->orderBy('name', 'ASC')->get()->getResultArray();

        // Attach each server's subscriber list and a live session count.
        foreach ($servers as &$server) {
            // Exposed so the view can label the backend without re-deriving it.
            $server['uses_radius']   = $this->compiler->usesRadius($server);
            $server['has_radius_secret'] = ! empty($server['radius_secret']);
            // Accounting, and where its records would go, so the card can state
            // it without the view re-deriving the host fallback.
            $server['uses_accounting'] = $this->compiler->usesAccounting($server);
            $server['acct_targets']    = $this->compiler->radiusAcctServers($server);
            $server['auth_targets']    = $this->compiler->radiusAuthServers($server);
            // The secret itself is never handed to the view.
            unset($server['radius_secret']);

            $users = $db->table('pppoe_server_users')
                ->where('server_id', $server['id'])
                ->orderBy('username', 'ASC')
                ->get()->getResultArray();

            $server['users']         = $users;
            $server['user_count']    = count($users);
            $server['enabled_users'] = count(array_filter($users, static fn ($u) => (int) $u['enabled'] === 1));
            $server['active']        = (int) $server['enabled'] === 1;
        }
        unset($server);

        $interfaces = $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray();

        $status     = $this->ipc->call('pppoe.status');
        $live       = $status['result'] ?? [];
        $sessions   = $this->decorateSessions($live['sessions'] ?? [], $servers);
        $linkHealth = $this->compiler->checkClientLinkHealth(null, $live['sessions'] ?? []);

        if ($partial) {
            return view('pppoe/_sessions', ['sessions' => $sessions, 'linkHealth' => $linkHealth]);
        }

        // What the daemon has actually written, so a toggle that has not been
        // applied is distinguishable from accounting that is running. Only the
        // full page needs it, so the session refresh does not pay for the call.
        $radiusStatus = $this->ipc->call('pppoe.radius.status', [
            'radius_dirs' => PppoeCompiler::RADIUS_DIRS,
        ])['result'] ?? [];

        // A server whose accounting is on but which the applied configuration
        // does not list is configured-but-pending, not running.
        $appliedAcct = array_map('strtolower', (array) ($radiusStatus['acctserver'] ?? []));
        foreach ($servers as &$server) {
            $server['acct_applied'] = ! empty($server['uses_accounting'])
                && array_intersect(array_map('strtolower', $server['acct_targets']), $appliedAcct) !== [];
        }
        unset($server);

        return view('pppoe/index', [
            'title'      => 'PPPoE Server — MicronOS',
            'breadcrumb' => 'PPPoE',
            'servers'    => $servers,
            'interfaces' => $interfaces,
            'liveStatus' => $live,
            'sessions'   => $sessions,
            'linkHealth' => $linkHealth,
            'sessionSummary' => [
                'client' => count(array_filter($sessions, static fn ($s) => $s['role'] === 'client')),
                'server' => count(array_filter($sessions, static fn ($s) => $s['role'] === 'server')),
                'up'     => count(array_filter($sessions, static fn ($s) => $s['state'] === 'UP')),
                'concentrators' => (int) ($live['concentrators'] ?? 0),
            ],
            'isMock'     => $this->ipc->isMock(),
            'radiusStatus' => $radiusStatus,
        ]);
    }

    /**
     * Adds the presentation fields the session table needs: a human uptime, a
     * human traffic figure, and — for a subscriber session — which subscriber
     * the address belongs to.
     *
     * A subscriber is identified by their address, because pppd records the
     * login in the session's log rather than its argv. A fixed address is an
     * exact match; a pooled address is resolved to whichever subscriber owns
     * it, and left unattributed when nothing claims it.
     *
     * @param  list<array> $sessions
     * @param  list<array> $servers
     * @return list<array>
     */
    private function decorateSessions(array $sessions, array $servers): array
    {
        // Address -> subscriber, and address -> server, across every server.
        $byAddress = [];
        $serverNames = [];
        foreach ($servers as $server) {
            $serverNames[(string) $server['id']] = (string) $server['name'];
            foreach ($server['users'] ?? [] as $user) {
                $address = trim((string) ($user['static_ip'] ?? ''));
                if ($address !== '') {
                    $byAddress[$address] = [
                        'user_id'   => (string) $user['id'],
                        'username'  => (string) $user['username'],
                        'server'    => (string) $server['name'],
                        'server_id' => (string) $server['id'],
                    ];
                }
            }
        }

        $out = [];
        foreach ($sessions as $session) {
            $peer = trim((string) ($session['peer_ip'] ?? ''));
            $match = $peer !== '' ? ($byAddress[$peer] ?? null) : null;

            $uptime = max(0, (int) ($session['uptime_seconds'] ?? 0));
            $rx     = max(0, (int) ($session['rx_bytes'] ?? 0));
            $tx     = max(0, (int) ($session['tx_bytes'] ?? 0));

            $session['subscriber_id'] = $match['user_id'] ?? null;
            $session['username']      = $match['username'] ?? null;
            $session['server']        = $match['server'] ?? null;
            $session['uptime_human']  = $this->formatUptime($uptime);
            $session['rx_human']      = $this->formatBytes($rx);
            $session['tx_human']      = $this->formatBytes($tx);
            $session['is_up']         = strtoupper((string) ($session['state'] ?? '')) === 'UP';

            // The reply attributes the account's login returned, when the
            // session authenticated against RADIUS.
            $raw = $session['radius_attributes'] ?? [];
            $radius = RadiusAttributes::describe(is_array($raw) ? $raw : []);
            $session['radius']        = $radius;
            $session['radius_chips']  = $this->radiusChips($radius, $uptime);
            $session['radius_others'] = array_map(
                static fn ($name, $value) => $name . ': ' . $value,
                array_keys($radius['others'] ?? []),
                array_values($radius['others'] ?? []),
            );

            // Record/update active subscriber session in session history
            if (($session['role'] ?? '') === 'server' && $match !== null) {
                $this->recordLiveSessionToHistory($session, $match);
            }

            $out[] = $session;
        }

        return $out;
    }

    /**
     * The RADIUS attributes worth showing, as ready-to-render strings.
     *
     * The session timeout is paired with how long is left, because that is the
     * question the number answers: a 1-hour limit is only interesting next to
     * the fact that this session has 4 minutes of it remaining.
     *
     * @param array<string,mixed> $radius as returned by RadiusAttributes::describe()
     * @return list<string>
     */
    private function radiusChips(array $radius, int $uptime): array
    {
        $chips = [];

        $timeout = $radius['session_timeout'] ?? null;
        if (is_int($timeout) && $timeout > 0) {
            $chip = 'Session Timeout ' . $this->formatUptime($timeout);
            $remaining = $timeout - $uptime;
            $chip .= $remaining > 0
                ? ' — expires in ' . $this->formatUptime($remaining)
                : ' — expired';
            $chips[] = $chip;
        }

        $idle = $radius['idle_timeout'] ?? null;
        if (is_int($idle) && $idle > 0) {
            $chips[] = 'Idle Timeout ' . $this->formatUptime($idle);
        }

        // The accounting cadence the server asked for, so an operator can see
        // why a session is or is not producing interim records.
        $interim = $radius['interim_interval'] ?? null;
        if (is_int($interim) && $interim > 0) {
            $chips[] = 'Acct Interim ' . $this->formatUptime($interim);
        }

        if (! empty($radius['framed_ip'])) {
            $chips[] = 'Framed IP ' . $radius['framed_ip'];
        }

        if (! empty($radius['rate_limit'])) {
            $chips[] = 'Rate Limit ' . $radius['rate_limit'];
        }

        return $chips;
    }

    /** "3d 4h 12m", "4h 12m", "12m 30s" or "45s". */
    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }
        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        }

        return $secs . 's';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    // ==================================================================
    // Servers
    // ==================================================================

    public function storeServer(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = $this->serverFields();

        if (($error = $this->compiler->validateServer($data)) !== null) {
            return redirect()->back()->with('error', $error);
        }

        // A new server cannot have a stored secret, so one must be supplied.
        $secret = (string) $this->request->getPost('radius_secret');
        if ($secret !== '') {
            $data['radius_secret'] = $this->compiler->encrypt($secret);
        }

        if (($error = $this->compiler->validateRadius($data)) !== null) {
            return redirect()->back()->with('error', $error);
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('pppoe_servers')->insert($data + [
            'id'         => 'pppsrv_' . bin2hex(random_bytes(6)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->to(base_url('pppoe'))
            ->with('success', "PPPoE server '{$data['name']}' created. Apply changes to activate it.");
    }

    public function updateServer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('pppoe_servers')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PPPoE server not found.');
        }

        $data = $this->serverFields();

        if (($error = $this->compiler->validateServer($data)) !== null) {
            return redirect()->back()->with('error', $error);
        }

        // An empty secret field keeps the stored one, so re-saving the form does
        // not wipe a secret the operator cannot read back.
        $secret = (string) $this->request->getPost('radius_secret');
        if ($secret !== '') {
            $data['radius_secret'] = $this->compiler->encrypt($secret);
        }

        if (($error = $this->compiler->validateRadius($data + ['radius_secret_set' => ! empty($row['radius_secret'])])) !== null) {
            return redirect()->back()->with('error', $error);
        }

        $db->table('pppoe_servers')->where('id', $id)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);

        return redirect()->to(base_url('pppoe'))
            ->with('success', 'PPPoE server updated. Apply changes to activate it.');
    }

    public function deleteServer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('pppoe_server_users')->where('server_id', $id)->delete();
        $db->table('pppoe_servers')->where('id', $id)->delete();

        return redirect()->to(base_url('pppoe'))->with('success', 'PPPoE server and its subscribers removed.');
    }

    public function toggleServer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('pppoe_servers')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PPPoE server not found.');
        }

        $enabled = (int) $row['enabled'] === 1 ? 0 : 1;
        $db->table('pppoe_servers')->where('id', $id)->update([
            'enabled'    => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url('pppoe'))
            ->with('success', 'PPPoE server ' . ($enabled ? 'enabled' : 'disabled') . '.');
    }

    // ==================================================================
    // Subscribers
    // ==================================================================

    public function storeUser(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db       = \Config\Database::connect();
        $serverId = (string) $this->request->getPost('server_id');

        $server = $db->table('pppoe_servers')->where('id', $serverId)->get()->getRowArray();
        if (! $server) {
            return redirect()->back()->with('error', 'Select a PPPoE server for this subscriber.');
        }

        $data = $this->userFields();

        if (($error = $this->compiler->validateUser($data + ['password' => $this->request->getPost('password')])) !== null) {
            return redirect()->back()->with('error', $error);
        }

        // A username only has to be unique inside its server, but a duplicate
        // would make chap-secrets ambiguous, so it is rejected outright.
        $duplicate = $db->table('pppoe_server_users')
            ->where('server_id', $serverId)
            ->where('username', $data['username'])
            ->countAllResults();
        if ($duplicate) {
            return redirect()->back()->with('error', "Subscriber '{$data['username']}' already exists on that server.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('pppoe_server_users')->insert($data + [
            'id'         => 'pppuser_' . bin2hex(random_bytes(6)),
            'server_id'  => $serverId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->to(base_url('pppoe'))
            ->with('success', "Subscriber '{$data['username']}' added. Apply changes to activate it.");
    }

    public function updateUser(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('pppoe_server_users')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Subscriber not found.');
        }

        $data = $this->userFields();

        // An empty password keeps the stored one.
        if ((string) $this->request->getPost('password') === '') {
            $data['password'] = $row['password'];
        }

        if (($error = $this->compiler->validateUser($data)) !== null) {
            return redirect()->back()->with('error', $error);
        }

        $db->table('pppoe_server_users')->where('id', $id)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);

        return redirect()->to(base_url('pppoe'))->with('success', 'Subscriber updated.');
    }

    public function deleteUser(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('pppoe_server_users')->where('id', $id)->delete();

        return redirect()->to(base_url('pppoe'))->with('success', 'Subscriber removed.');
    }

    public function toggleUser(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('pppoe_server_users')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Subscriber not found.');
        }

        $enabled = (int) $row['enabled'] === 1 ? 0 : 1;
        $db->table('pppoe_server_users')->where('id', $id)->update([
            'enabled'    => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url('pppoe'))
            ->with('success', 'Subscriber ' . ($enabled ? 'enabled' : 'disabled') . '.');
    }

    // ==================================================================
    // Apply
    // ==================================================================

    /**
     * Renders the PPPoE configuration and hands it to micrond.
     *
     * Both halves travel in one call so the daemon can write /etc/ppp once and
     * restart the affected links together.
     */
    public function apply(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $db = \Config\Database::connect();

            // ---- client side ----
            $clientPeers = [];
            foreach ($this->compiler->fetchClientInterfaces() as $iface) {
                $clientPeers[] = [
                    'interface' => (string) $iface['physical_device'],
                    'file'      => PppoeCompiler::PEER_DIR . '/' . $this->compiler->peerFileName((string) $iface['physical_device']),
                    'content'   => $this->compiler->compileClientPeer($iface),
                ];
            }

            // ---- server side ----
            $serverOptions = [];
            $serverArgv    = [];
            $secrets       = [];

            $radiusFiles = [];

            foreach ($db->table('pppoe_servers')->where('enabled', 1)->orderBy('name', 'ASC')->get()->getResultArray() as $server) {
                $serverOptions[] = [
                    'server'  => $server['name'],
                    'file'    => PppoeCompiler::SERVER_OPTIONS,
                    'content' => $this->compiler->compileServerOptions($server),
                ];
                $serverArgv[] = $this->compiler->serverArgv($server);
                $secrets[]    = $this->compiler->compileServerSecrets($server['id']);

                // A RADIUS-backed server also needs its client configuration and
                // server list written; the daemon places them in whichever
                // radiusclient directory the appliance has.
                if ($this->compiler->usesRadius($server)) {
                    $radiusFiles[] = [
                        'server'  => $server['name'],
                        'content' => $this->compiler->compileRadiusServers($server),
                    ];
                    $radiusConf = $this->compiler->compileRadiusClientConf($server);
                }
            }

            // The client and server halves share chap-secrets, so they are
            // concatenated into the single file pppd reads.
            $clientSecrets = $this->compiler->compileClientSecrets();

            $resp = $this->ipc->call('pppoe.apply', [
                'client_peers'   => $clientPeers,
                'client_secrets' => $clientSecrets,
                'server_options' => $serverOptions,
                'server_argv'    => $serverArgv,
                'server_secrets' => $secrets,
                'secrets_file'   => PppoeCompiler::SECRETS_FILE,
                'radius_servers' => $radiusFiles,
                'radius_conf'    => $radiusConf ?? '',
                'radius_dirs'    => PppoeCompiler::RADIUS_DIRS,
                'safe_mode'      => false,
            ]);

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('pppoe'))
                    ->with('error', 'Failed to apply PPPoE configuration: ' . ($resp['error']['message'] ?? 'daemon error'));
            }

            $result = $resp['result'] ?? [];
            if (in_array($result['status'] ?? 'applied', ['error', 'apply_failed'], true)) {
                return redirect()->to(base_url('pppoe'))
                    ->with('error', 'PPPoE configuration rejected: ' . ($result['message'] ?? $result['error'] ?? 'apply failed'));
            }

            $msg = sprintf(
                'PPPoE applied: %d client link(s), %d server(s)%s.',
                count($clientPeers),
                count($serverOptions),
                $this->ipc->isMock() ? ' (simulated)' : '',
            );

            // A PPPoE client link may have been handed DNS servers by the peer;
            // reflect them in the appliance stub resolver.
            $msg .= $this->reconcileApplianceResolver($this->ipc);

            return redirect()->to(base_url('pppoe'))->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('pppoe'))
                ->with('error', 'Failed to apply PPPoE configuration: ' . $e->getMessage());
        }
    }

    // ==================================================================
    // Field mapping
    // ==================================================================

    /**
     * Tests a server's RADIUS credentials with a live Access-Request.
     *
     * This is the difference between "saved" and "works": a wrong shared secret
     * or an unreachable server is otherwise only discovered when a subscriber is
     * refused. The test runs in the daemon so the secret never leaves the
     * appliance and is not passed on a command line.
     */
    public function testRadius(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('pppoe_servers')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PPPoE server not found.');
        }

        if (! $this->compiler->usesRadius($row)) {
            return redirect()->to(base_url('pppoe'))
                ->with('error', 'This server authenticates locally, so there is no RADIUS server to test.');
        }

        $username = trim((string) $this->request->getPost('test_username'));
        $password = (string) $this->request->getPost('test_password');

        if ($username === '' || $password === '') {
            return redirect()->to(base_url('pppoe'))
                ->with('error', 'Enter a test username and password to send an Access-Request.');
        }

        try {
            $resp = $this->ipc->call('pppoe.radius.test', [
                'server'   => $row['radius_server'],
                'port'     => (int) $row['radius_port'],
                'secret'   => $this->compiler->radiusSecret($row),
                'timeout'  => (int) $row['radius_timeout'],
                'retries'  => (int) $row['radius_retries'],
                'nas_id'   => $row['radius_nas_id'],
                'username' => $username,
                'password' => $password,
            ]);

            $result = $resp['result'] ?? [];
            $status = $result['status'] ?? 'error';

            if ($status === 'accept') {
                return redirect()->to(base_url('pppoe'))
                    ->with('success', "RADIUS accepted '{$username}': " . ($result['message'] ?? 'Access-Accept'));
            }

            if ($status === 'reject') {
                return redirect()->to(base_url('pppoe'))
                    ->with('error', "RADIUS rejected '{$username}': " . ($result['message'] ?? 'Access-Reject'));
            }

            return redirect()->to(base_url('pppoe'))
                ->with('error', 'RADIUS test failed: ' . ($result['message'] ?? $resp['error']['message'] ?? 'no response'));
        } catch (\Throwable $e) {
            return redirect()->to(base_url('pppoe'))
                ->with('error', 'RADIUS test failed: ' . $e->getMessage());
        }
    }

    /** Shared server field mapping from the request. */
    private function serverFields(): array
    {
        $dns1 = trim((string) $this->request->getPost('dns1')) ?: null;
        $dns2 = trim((string) $this->request->getPost('dns2')) ?: null;

        return [
            'name'          => trim((string) $this->request->getPost('name')),
            'interface'     => trim((string) $this->request->getPost('interface')),
            'service_name'  => trim((string) $this->request->getPost('service_name')) ?: 'MicronOS',
            'ac_name'       => trim((string) $this->request->getPost('ac_name')) ?: null,
            'local_ip'      => trim((string) $this->request->getPost('local_ip')),
            'remote_start'  => trim((string) $this->request->getPost('remote_start')),
            'remote_end'    => trim((string) $this->request->getPost('remote_end')),
            'dns1'          => $dns1,
            'dns2'          => $dns2,
            'auth_required' => $this->request->getPost('auth_required') ? 1 : 0,
            'idle_timeout'  => max(0, (int) $this->request->getPost('idle_timeout')),
            'max_sessions'  => max(0, (int) $this->request->getPost('max_sessions')),
            'description'   => trim((string) $this->request->getPost('description')) ?: null,
            'enabled'       => $this->request->getPost('enabled') ? 1 : 0,
            'auth_backend'  => $this->request->getPost('auth_backend') === 'radius' ? 'radius' : 'local',
            'radius_server' => trim((string) $this->request->getPost('radius_server')) ?: null,
            'radius_port'   => max(1, min(65535, (int) ($this->request->getPost('radius_port') ?: 1812))),
            'radius_timeout'=> max(1, min(60, (int) ($this->request->getPost('radius_timeout') ?: 5))),
            'radius_retries'=> max(0, min(10, (int) $this->request->getPost('radius_retries'))),
            'radius_nas_id' => trim((string) $this->request->getPost('radius_nas_id')) ?: null,
            'radius_server2'=> trim((string) $this->request->getPost('radius_server2')) ?: null,
            // Accounting is only ever on for a RADIUS server; the toggle is
            // ignored for a local one rather than stored and silently unused.
            'radius_accounting'  => $this->request->getPost('radius_accounting') ? 1 : 0,
            'radius_acct_port'   => max(1, min(65535, (int) ($this->request->getPost('radius_acct_port') ?: 1813))),
            'radius_acct_server' => trim((string) $this->request->getPost('radius_acct_server')) ?: null,
        ];
    }

    /** Shared subscriber field mapping; the password is encrypted here. */
    private function userFields(): array
    {
        $password = (string) $this->request->getPost('password');

        return [
            'username'    => trim((string) $this->request->getPost('username')),
            'password'    => $password === '' ? '' : $this->compiler->encrypt($password),
            'static_ip'   => trim((string) $this->request->getPost('static_ip')) ?: null,
            'dns1'        => trim((string) $this->request->getPost('dns1')) ?: null,
            'dns2'        => trim((string) $this->request->getPost('dns2')) ?: null,
            'rate_limit'  => trim((string) $this->request->getPost('rate_limit')) ?: null,
            'description' => trim((string) $this->request->getPost('description')) ?: null,
            'enabled'     => $this->request->getPost('enabled') ? 1 : 0,
        ];
    }

    // ==================================================================
    // Redial Now Action & Session History
    // ==================================================================

    /**
     * Live 'Redial now' action: triggers an immediate PPPoE redial and health check.
     *
     * Resets escalating backoff state for the targeted interface(s) and refreshes
     * live link state in place.
     */
    public function redial(): \CodeIgniter\HTTP\ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $targetIface = trim((string) $this->request->getPost('interface'));
        $force       = (bool) ($this->request->getPost('force') ?? true);

        // Fetch configured client interfaces
        $clientInterfaces = $this->compiler->fetchClientInterfaces();
        $clientDevices    = array_column($clientInterfaces, 'physical_device');

        $interfacesToRedial = [];
        if ($targetIface !== '') {
            $interfacesToRedial = [$targetIface];
        } else {
            $interfacesToRedial = $clientDevices;
        }

        // Reset escalating backoff for these interfaces since operator explicitly clicked Redial now
        $backoff = new PppoeBackoff();
        if ($targetIface !== '') {
            $backoff->reset($targetIface);
        } else {
            $backoff->reset();
        }

        // Dispatch redial via IPC daemon
        $resp = $this->ipc->call('pppoe.redial', [
            'interfaces' => $interfacesToRedial,
            'force'      => $force,
            'down_only'  => false,
        ]);

        $result = $resp['result'] ?? [];
        $error  = $resp['error'] ?? null;

        // Re-evaluate live sessions & link health
        $status  = $this->ipc->call('pppoe.status');
        $live    = $status['result'] ?? [];
        $servers = $db->table('pppoe_servers')->orderBy('name', 'ASC')->get()->getResultArray();
        foreach ($servers as &$server) {
            $server['users'] = $db->table('pppoe_server_users')->where('server_id', $server['id'])->get()->getResultArray();
        }
        unset($server);

        $sessions   = $this->decorateSessions($live['sessions'] ?? [], $servers);
        $linkHealth = $this->compiler->checkClientLinkHealth($clientInterfaces, $live['sessions'] ?? [], $backoff);

        $upCount     = (int) ($result['up'] ?? 0);
        $failedCount = (int) ($result['failed'] ?? 0);
        $message     = sprintf('Redial complete: %d link(s) up, %d failed%s.', $upCount, $failedCount, $this->ipc->isMock() ? ' (simulated)' : '');

        // Audit log
        try {
            if ($db->tableExists('audit_logs')) {
                $db->table('audit_logs')->insert([
                    // admin_user/client_ip are NOT NULL on an appliance whose
                    // database predates the migration file's later edits.
                    'admin_user'      => session('username') ?? 'admin',
                    'client_ip'       => $this->request->getIPAddress(),
                    'user_id'         => session('user_id') ?? 'system',
                    'username'        => session('username') ?? 'admin',
                    'action_type'     => 'pppoe',
                    'action'          => 'pppoe.redial',
                    'target_resource' => 'PPPoE Client Links',
                    'target'          => $targetIface !== '' ? $targetIface : 'all',
                    'new_state'       => $message,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {}

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'success'    => empty($error) && $failedCount === 0,
                'message'    => $message,
                'result'     => $result,
                'error'      => $error,
                'html'       => view('pppoe/_sessions', ['sessions' => $sessions, 'linkHealth' => $linkHealth]),
                'linkHealth' => $linkHealth,
                'csrfToken'  => csrf_token(),
                'csrfHash'   => csrf_hash(),
            ]);
        }

        if (! empty($error) || $failedCount > 0) {
            return redirect()->to(base_url('pppoe'))->with('error', $message);
        }

        return redirect()->to(base_url('pppoe'))->with('success', $message);
    }

    /**
     * Per-subscriber session history page showing historical logins and
     * the RADIUS reply attributes (rate limits, timeouts, framed IP) received.
     */
    public function subscriberHistory(string $userId): string
    {
        $db = \Config\Database::connect();

        // Find subscriber by ID or username
        $user = $db->table('pppoe_server_users')
            ->where('id', $userId)
            ->orWhere('username', $userId)
            ->get()->getRowArray();

        $username = $user['username'] ?? $userId;
        $server   = null;

        if ($user && ! empty($user['server_id'])) {
            $server = $db->table('pppoe_servers')->where('id', $user['server_id'])->get()->getRowArray();
        }

        $historyModel = new PppoeSessionHistoryModel();
        $history      = $historyModel->getSubscriberHistoryWithDeltas($user['id'] ?? $userId);
        $metrics      = $historyModel->getSubscriberMetrics($history);

        return view('pppoe/subscriber_history', [
            'title'        => "Session History — {$username} — MicronOS",
            'breadcrumb'   => 'PPPoE / Subscriber History',
            'user'         => $user ?: ['id' => $userId, 'username' => $username, 'enabled' => 1],
            'server'       => $server,
            'history'      => $history,
            'metrics'      => $metrics,
            'username'     => $username,
        ]);
    }

    /**
     * Helper to keep active subscriber sessions recorded in history table.
     */
    private function recordLiveSessionToHistory(array $session, array $match): void
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('pppoe_session_history')) {
            return;
        }

        $username = $match['username'] ?? $session['username'] ?? null;
        if (empty($username)) {
            return;
        }

        $subId    = $match['user_id'] ?? null;
        $rawAttrs = $session['radius_attributes'] ?? [];
        $radius   = RadiusAttributes::describe(is_array($rawAttrs) ? $rawAttrs : []);

        $sessionId = 'pppsess_live_' . substr(md5($username . '_' . ($session['interface'] ?? 'ppp') . '_' . ($session['local_ip'] ?? '')), 0, 16);
        $existing  = $db->table('pppoe_session_history')->where('id', $sessionId)->get()->getRowArray();

        $data = [
            'subscriber_id'     => $subId,
            'username'          => $username,
            'server_id'         => $match['server_id'] ?? null,
            'server_name'       => $match['server'] ?? null,
            'interface'         => $session['interface'] ?? 'ppp',
            'assigned_ip'       => $session['peer_ip'] ?? null,
            'session_id'        => $session['interface'] ?? 'ppp',
            'uptime_seconds'    => max(0, (int) ($session['uptime_seconds'] ?? 0)),
            'terminate_cause'   => 'Active',
            'rx_bytes'          => max(0, (int) ($session['rx_bytes'] ?? 0)),
            'tx_bytes'          => max(0, (int) ($session['tx_bytes'] ?? 0)),
            'rate_limit'        => $radius['rate_limit'] ?? null,
            'session_timeout'   => $radius['session_timeout'] ?? null,
            'idle_timeout'      => $radius['idle_timeout'] ?? null,
            'interim_interval'  => $radius['interim_interval'] ?? null,
            'framed_ip'         => $radius['framed_ip'] ?? $session['peer_ip'] ?? null,
            'radius_attributes' => json_encode($rawAttrs),
        ];

        try {
            if ($existing) {
                $db->table('pppoe_session_history')->where('id', $sessionId)->update($data);
            } else {
                $db->table('pppoe_session_history')->insert($data + [
                    'id'         => $sessionId,
                    'login_at'   => date('Y-m-d H:i:s', time() - max(0, (int) ($session['uptime_seconds'] ?? 0))),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {}
    }
}
