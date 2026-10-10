<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS PPPoE Configuration Compiler
 * =====================================
 * Renders the configuration a PPPoE link needs on either side.
 *
 * Client (`interfaces.ip_assignment_type = 'pppoe'`), per interface:
 *
 *   /etc/ppp/peers/micron-<dev>   — the pppd invocation: which device, which
 *                                   credentials file, MTU/MRU, idle timeout and
 *                                   whether pppd installs a default route.
 *   /etc/ppp/chap-secrets         — one line per interface, so a password is
 *                                   never passed on the pppd command line where
 *                                   it would show up in `ps`.
 *
 * Server (`pppoe_servers`), per server:
 *
 *   /etc/ppp/pppoe-server-options — pppd options for the access concentrator,
 *                                   including the address pool handed out via
 *                                   `ipparam`.
 *   /etc/ppp/chap-secrets         — the subscriber list.
 *
 * The two halves deliberately produce *different* sections of the same two
 * files, so both are exposed as discrete renderers and the daemon merges them.
 * Nothing here touches the filesystem: this class returns text, and the daemon
 * owns writing it.
 */
class PppoeCompiler
{
    /** Where pppd reads peer definitions from. */
    public const PEER_DIR = '/etc/ppp/peers';

    /** pppd's shared secrets file, used by both client and server. */
    public const SECRETS_FILE = '/etc/ppp/chap-secrets';

    /** Server options consumed by the pppoe-server wrapper. */
    public const SERVER_OPTIONS = '/etc/ppp/pppoe-server-options';

    /** Default MTU for a PPPoE link (1500 Ethernet less 8 bytes of PPPoE/PPP). */
    public const DEFAULT_MTU = 1492;

    /** Where the EAP-TLS certificates and the secrets file are written. */
    public const EAP_TLS_DIR = '/etc/ppp/eaptls';

    /**
     * The per-peer EAP-TLS secrets file pppd reads on the server side.
     *
     * The certificates are NOT taken from the `ca`/`cert`/`key` options when pppd
     * is the server: it looks them up here, keyed by client and server name.
     */
    public const EAP_TLS_SERVER_FILE = '/etc/ppp/eaptls-server';

    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * The pppd device name for an interface.
     *
     * PPPoE runs on top of the physical device, so `eth0` becomes `pppoe0`. A
     * device that already carries an `@` (a VLAN child such as `eth0.7`) keeps
     * its identity in the suffix so two links cannot collide.
     */
    public function deviceName(string $physicalDevice): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $physicalDevice);

        return 'pppoe-' . ($clean !== '' ? $clean : 'wan');
    }

    /** The peer file name for an interface (without the directory). */
    public function peerFileName(string $physicalDevice): string
    {
        return 'micron-' . preg_replace('/[^A-Za-z0-9]/', '', $physicalDevice);
    }

    /** Whether a stored value looks like a usable IPv4 address. */
    public function isIp(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Clamps a PPP MTU/MRU into the range pppd accepts.
     *
     * PPPoE has 8 bytes of overhead on a 1500-byte Ethernet frame, so 1492 is
     * the ceiling for a standard link; jumbo-frame providers can go higher.
     */
    public function clampMtu(int $value, int $fallback = self::DEFAULT_MTU): int
    {
        if ($value <= 0) {
            return $fallback;
        }

        return max(576, min(9000, $value));
    }

    /**
     * Sanitises a free-text value for a config file.
     *
     * A credential or service name containing a newline would let an operator
     * inject arbitrary pppd options into the file, so control characters are
     * stripped rather than escaped.
     */
    public function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '');
    }

    /** The password for an interface, decrypted. */
    public function clientPassword(array $iface): string
    {
        return $this->decrypt((string) ($iface['pppoe_password'] ?? ''));
    }

    /** The password for a subscriber, decrypted. */
    public function subscriberPassword(array $user): string
    {
        return $this->decrypt((string) ($user['password'] ?? ''));
    }

    /**
     * Decrypts a stored secret, tolerating plaintext.
     *
     * The ciphertext is stored base64-encoded: CI4's Encrypter returns raw
     * bytes, and a TEXT column (SQLite in particular) does not round-trip
     * arbitrary binary faithfully. A value that still fails to decrypt is
     * returned unchanged, which keeps a hand-edited row — or one carried over
     * from an older appliance — working instead of silently locking the link
     * out.
     */
    public function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $encrypter = \Config\Services::encrypter();

        // Preferred form: base64-wrapped ciphertext.
        $binary = base64_decode($value, true);
        if ($binary !== false && $binary !== '') {
            try {
                $decrypted = $encrypter->decrypt($binary);
                if (is_string($decrypted)) {
                    return $decrypted;
                }
            } catch (\Throwable $e) {
                // Fall through to the raw-ciphertext attempt.
            }
        }

        // Older or hand-written rows may hold bare ciphertext.
        try {
            $decrypted = $encrypter->decrypt($value);

            return is_string($decrypted) ? $decrypted : $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /**
     * Encrypts a secret for storage.
     *
     * Base64-wrapped so the value survives a TEXT column intact.
     */
    public function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return base64_encode(\Config\Services::encrypter()->encrypt($value));
    }

    // -------------------------------------------------------------------------
    // Client side
    // -------------------------------------------------------------------------

    /** Every interface configured to dial PPPoE, enabled first. */
    public function fetchClientInterfaces(): array
    {
        try {
            return $this->db->table('interfaces')
                ->where('ip_assignment_type', 'pppoe')
                ->orderBy('name', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Inspects configured PPPoE client interfaces against live session status
     * and backoff states to determine real-time link health.
     *
     * Used by:
     *  - The scheduled PPPoE redial command to identify down links vs healthy vs backed off.
     *  - The PPPoE controller and session panel to render the live link-state indicator
     *    and drive the 'Redial now' action.
     *
     * @param  list<array>|null  $clientInterfaces list of interface rows (null = fetchClientInterfaces())
     * @param  list<array>|null  $sessions         sessions from pppoe.status (null = query daemon)
     * @param  PppoeBackoff|null $backoff          backoff manager (null = instantiate new)
     * @return array{
     *     links: array<string, array{
     *         interface: string,
     *         name: string,
     *         state: string,
     *         is_healthy: bool,
     *         is_backed_off: bool,
     *         badge_class: string,
     *         status_label: string,
     *         status_tooltip: string,
     *         local_ip: string|null,
     *         peer_ip: string|null,
     *         uptime_seconds: int,
     *         uptime_human: string,
     *         failure_count: int,
     *         next_retry_at: string|null,
     *         remaining_seconds: int,
     *         remaining_human: string
     *     }>,
     *     total: int,
     *     up: int,
     *     down: int,
     *     backed_off: int,
     *     overall_state: string
     * }
     */
    public function checkClientLinkHealth(?array $clientInterfaces = null, ?array $sessions = null, ?PppoeBackoff $backoff = null): array
    {
        $interfaces = $clientInterfaces ?? $this->fetchClientInterfaces();

        if ($sessions === null) {
            $status   = (new IpcClient())->call('pppoe.status', []);
            $sessions = $status['result']['sessions'] ?? [];
        }

        $backoff = $backoff ?? new PppoeBackoff($this->db);

        $clientSessions = [];
        foreach ($sessions as $s) {
            if (($s['role'] ?? '') === 'client' && ! empty($s['device'])) {
                $clientSessions[(string) $s['device']] = $s;
            }
        }

        $links     = [];
        $upCount   = 0;
        $downCount = 0;
        $backCount = 0;

        foreach ($interfaces as $iface) {
            $device  = (string) $iface['physical_device'];
            $name    = (string) ($iface['name'] ?? $device);
            $session = $clientSessions[$device] ?? null;
            $bState  = $backoff->getLinkState($device);

            $isUp        = ($session !== null) && (strtoupper((string) ($session['state'] ?? '')) === 'UP');
            $isBackedOff = ! $isUp && (bool) ($bState['is_backed_off'] ?? false);

            if ($isUp) {
                $state         = 'UP';
                $isHealthy     = true;
                $badgeClass    = 'badge-success';
                $statusLabel   = 'UP';
                $statusTooltip = 'PPPoE client link is up and exchanging traffic';
                $upCount++;
            } elseif ($isBackedOff) {
                $state         = 'BACKOFF';
                $isHealthy     = false;
                $badgeClass    = 'badge-warning';
                $statusLabel   = 'BACKOFF (' . ($bState['remaining_human'] ?: 'retrying soon') . ')';
                $statusTooltip = sprintf(
                    'Failed %d consecutive times. Retrying less aggressively; next retry at %s (%s remaining)',
                    $bState['failure_count'],
                    $bState['next_retry_at'] ?? 'unknown',
                    $bState['remaining_human'] ?: 'soon'
                );
                $backCount++;
            } else {
                $state         = 'DOWN';
                $isHealthy     = false;
                $badgeClass    = 'badge-danger';
                $statusLabel   = 'DOWN';
                $statusTooltip = 'PPPoE client link is down or disconnected';
                $downCount++;
            }

            $uptime = max(0, (int) ($session['uptime_seconds'] ?? 0));

            $links[$device] = [
                'interface'         => $device,
                'name'              => $name,
                'state'             => $state,
                'is_healthy'        => $isHealthy,
                'is_backed_off'     => $isBackedOff,
                'badge_class'       => $badgeClass,
                'status_label'      => $statusLabel,
                'status_tooltip'    => $statusTooltip,
                'local_ip'          => $session['local_ip'] ?? null,
                'peer_ip'           => $session['peer_ip'] ?? null,
                'uptime_seconds'    => $uptime,
                'uptime_human'      => $this->formatDuration($uptime),
                'failure_count'     => (int) ($bState['failure_count'] ?? 0),
                'next_retry_at'     => $bState['next_retry_at'] ?? null,
                'remaining_seconds' => (int) ($bState['remaining_seconds'] ?? 0),
                'remaining_human'   => (string) ($bState['remaining_human'] ?? ''),
            ];
        }

        $total = count($interfaces);
        $overallState = 'none';
        if ($total > 0) {
            if ($upCount === $total) {
                $overallState = 'healthy';
            } elseif ($backCount > 0) {
                $overallState = 'backed_off';
            } elseif ($downCount > 0) {
                $overallState = 'down';
            } else {
                $overallState = 'degraded';
            }
        }

        return [
            'links'         => $links,
            'total'         => $total,
            'up'            => $upCount,
            'down'          => $downCount,
            'backed_off'    => $backCount,
            'overall_state' => $overallState,
        ];
    }

    /**
     * Formats seconds into human readable duration string.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs    = $seconds % 60;

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

    /**
     * The pppd peer file for one interface.
     *
     * `persist` and `maxfail 0` keep pppd retrying forever: a WAN link that
     * drops should redial itself without an operator, which is the behaviour a
     * DSL or fibre handover is expected to have.
     */
    public function compileClientPeer(array $iface): string
    {
        $device  = $this->clean((string) ($iface['physical_device'] ?? ''));
        $user    = $this->clean((string) ($iface['pppoe_username'] ?? ''));
        $mtu     = $this->clampMtu((int) ($iface['pppoe_mtu'] ?? 0));
        $mru     = $this->clampMtu((int) ($iface['pppoe_mru'] ?? 0), $mtu);
        $idle    = max(0, (int) ($iface['pppoe_idle_timeout'] ?? 0));
        $default = (int) ($iface['pppoe_default_route'] ?? 1) === 1;
        $service = $this->clean((string) ($iface['pppoe_service'] ?? ''));
        $acName  = $this->clean((string) ($iface['pppoe_ac_name'] ?? ''));

        $lines   = [];
        $lines[] = '# MicronOS PPPoE client — ' . $this->clean((string) ($iface['name'] ?? $device));
        $lines[] = '# Generated by MicronOS. Edits are overwritten on the next apply.';
        $lines[] = '';
        $lines[] = 'plugin rp-pppoe.so';
        $lines[] = 'nic-' . $device;
        $lines[] = 'user "' . $user . '"';
        $lines[] = 'noauth';
        $lines[] = 'noipdefault';
        $lines[] = 'hide-password';
        $lines[] = 'mtu ' . $mtu;
        $lines[] = 'mru ' . $mru;
        $lines[] = 'lcp-echo-interval 10';
        $lines[] = 'lcp-echo-failure 3';

        if ($idle > 0) {
            $lines[] = 'idle ' . $idle;
        } else {
            $lines[] = 'persist';
            $lines[] = 'maxfail 0';
        }

        // "use this link as the default route" — off is how a secondary or
        // backup PPPoE link is kept from hijacking the routing table.
        $lines[] = $default ? 'defaultroute' : 'nodefaultroute';
        $lines[] = 'replacedefaultroute';

        if ($service !== '') {
            $lines[] = 'rp_pppoe_service "' . $service . '"';
        }
        if ($acName !== '') {
            $lines[] = 'rp_pppoe_ac "' . $acName . '"';
        }

        $lines[] = 'ipparam "' . $this->peerFileName($device) . '"';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The client's lines for /etc/ppp/chap-secrets.
     *
     * Format: `user server secret ip-addresses`. The `*` server field matches
     * any access concentrator, since the AC name is frequently unknown until
     * the first dial.
     */
    public function compileClientSecrets(): string
    {
        $lines = [];
        $lines[] = '# MicronOS PPPoE client credentials';
        $lines[] = '# client server secret IP-addresses';

        foreach ($this->fetchClientInterfaces() as $iface) {
            $user = $this->clean((string) ($iface['pppoe_username'] ?? ''));
            if ($user === '') {
                continue;
            }

            $lines[] = sprintf(
                "%s\t*\t%s\t*",
                $user,
                $this->clientPassword($iface),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    // -------------------------------------------------------------------------
    // Server side
    // -------------------------------------------------------------------------

    /** Every PPPoE server, enabled first. */
    public function fetchServers(): array
    {
        try {
            return $this->db->table('pppoe_servers')
                ->orderBy('name', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The subscribers belonging to a server. */
    public function fetchServerUsers(string $serverId): array
    {
        try {
            return $this->db->table('pppoe_server_users')
                ->where('server_id', $serverId)
                ->orderBy('username', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Validates a server definition, returning an error string or null.
     *
     * The address pool must be well-formed and non-empty: pppd hands out
     * `remote_start-remote_end` in order, and an inverted or malformed range
     * would either hand out nothing or hand out addresses outside the intended
     * pool.
     */
    public function validateServer(array $data): ?string
    {
        if ($this->clean((string) ($data['name'] ?? '')) === '') {
            return 'Server name is required.';
        }
        if ($this->clean((string) ($data['interface'] ?? '')) === '') {
            return 'A listening interface is required.';
        }
        if (! $this->isIp($this->clean((string) ($data['local_ip'] ?? '')))) {
            return 'Local address must be a valid IPv4 address.';
        }

        $start = $this->clean((string) ($data['remote_start'] ?? ''));
        $end   = $this->clean((string) ($data['remote_end'] ?? ''));
        if (! $this->isIp($start) || ! $this->isIp($end)) {
            return 'The client address pool must be a valid IPv4 range.';
        }
        if (ip2long($start) > ip2long($end)) {
            return 'The client address pool start must not be above its end.';
        }

        foreach (['dns1', 'dns2'] as $field) {
            $value = $this->clean((string) ($data[$field] ?? ''));
            if ($value !== '' && ! $this->isIp($value)) {
                return 'DNS server addresses must be valid IPv4 addresses.';
            }
        }

        return null;
    }

    /** Validates a subscriber definition, returning an error string or null. */
    public function validateUser(array $data): ?string
    {
        if ($this->clean((string) ($data['username'] ?? '')) === '') {
            return 'Subscriber username is required.';
        }
        if ((string) ($data['password'] ?? '') === '') {
            return 'Subscriber password is required.';
        }

        $static = $this->clean((string) ($data['static_ip'] ?? ''));
        if ($static !== '' && ! $this->isIp($static)) {
            return 'A fixed address must be a valid IPv4 address.';
        }

        return null;
    }

    /**
     * The pppoe-server options file for one server.
     *
     * `ipparam` carries the pool across to /etc/ppp/ip-up, which is what hands
     * each session its address; `ms-dns` publishes the resolvers to the client.
     */
    public function compileServerOptions(array $server): string
    {
        $local   = $this->clean((string) ($server['local_ip'] ?? ''));
        $start   = $this->clean((string) ($server['remote_start'] ?? ''));
        $end     = $this->clean((string) ($server['remote_end'] ?? ''));
        $service = $this->clean((string) ($server['service_name'] ?? 'MicronOS'));
        $acName  = $this->clean((string) ($server['ac_name'] ?? ''));
        $idle    = max(0, (int) ($server['idle_timeout'] ?? 0));
        $auth    = (int) ($server['auth_required'] ?? 1) === 1;

        $lines   = [];
        $lines[] = '# MicronOS PPPoE access concentrator — ' . $this->clean((string) ($server['name'] ?? ''));
        $lines[] = '# Generated by MicronOS. Edits are overwritten on the next apply.';
        $lines[] = '';
        $lines[] = 'require-chap';
        $lines[] = 'login';
        $lines[] = 'proxyarp';
        $lines[] = 'lcp-echo-interval 10';
        $lines[] = 'lcp-echo-failure 3';

        if (! $auth) {
            $lines[] = 'noauth';
        }
        if ($idle > 0) {
            $lines[] = 'idle ' . $idle;
        }

        // RADIUS takes over authentication entirely: the plugin answers before
        // chap-secrets is consulted, so a stale local entry cannot shadow a
        // RADIUS decision. The local list stays compiled for a switch back.
        foreach ($this->radiusServerOptions($server) as $option) {
            $lines[] = $option;
        }

        // EAP-TLS is additive: pppd keeps `require-chap` as its preferred method
        // and uses EAP for a peer that offers it, so certificate and
        // secret-based subscribers can share one concentrator.
        foreach ($this->eapTlsServerOptions($server) as $option) {
            $lines[] = $option;
        }

        $lines[] = 'ms-dns ' . ($this->isIp($this->clean((string) ($server['dns1'] ?? ''))) ? $this->clean((string) $server['dns1']) : $local);
        if ($this->isIp($this->clean((string) ($server['dns2'] ?? '')))) {
            $lines[] = 'ms-dns ' . $this->clean((string) $server['dns2']);
        }

        // The pool is consumed by the ip-up script, not by pppd itself.
        $lines[] = 'ipparam "' . $start . '-' . $end . '"';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The server's lines for /etc/ppp/chap-secrets.
     *
     * A subscriber with a fixed address gets it in the fourth field, which pppd
     * honours in preference to the pool.
     */
    public function compileServerSecrets(string $serverId): string
    {
        $lines = [];
        $lines[] = '# MicronOS PPPoE subscribers';

        foreach ($this->fetchServerUsers($serverId) as $user) {
            if ((int) ($user['enabled'] ?? 1) !== 1) {
                continue;
            }

            $username = $this->clean((string) ($user['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $static = $this->clean((string) ($user['static_ip'] ?? ''));

            $lines[] = sprintf(
                "%s\t*\t%s\t%s",
                $username,
                $this->subscriberPassword($user),
                $static !== '' ? $static : '*',
            );
        }

        return implode("\n", $lines) . "\n";
    }

    // -------------------------------------------------------------------------
    // RADIUS authentication
    // -------------------------------------------------------------------------

    /**
     * Paths the radius plugin reads.
     *
     * A stock FreeRADIUS client package uses /etc/radiusclient; older appliances
     * carry the -ng or freeradius-client spelling. The daemon writes whichever
     * exists, and falls back to the first when none does.
     */
    public const RADIUS_DIRS = [
        '/etc/radiusclient',
        '/etc/radiusclient-ng',
        '/etc/freeradius-client',
    ];

    /** Where radclient keeps the RADIUS dictionaries. */
    public const RADIUS_DICT_DIR = '/usr/share/freeradius';

    /** Whether a server authenticates through RADIUS. */
    public function usesRadius(array $server): bool
    {
        return strtolower(trim((string) ($server['auth_backend'] ?? 'local'))) === 'radius';
    }

    /**
     * Whether a server accepts certificate-based EAP-TLS authentication.
     *
     * EAP-TLS is orthogonal to the RADIUS/local split: it is a method the
     * concentrator offers, and a subscriber may be authenticated by certificate
     * while the local list still serves everyone else. It is therefore its own
     * switch rather than another value of `auth_backend`.
     */
    public function usesEapTls(array $server): bool
    {
        return ! empty($server['eap_tls']);
    }

    /**
     * The certificate material EAP-TLS needs, as text, ready for the daemon.
     *
     * Returns an empty array when EAP-TLS is off, so nothing is written for a
     * server that does not use it.
     *
     * The private key is stored encrypted and is decrypted here, exactly once,
     * on its way to the daemon. The certificate bodies are not: a certificate is
     * not a secret, and the appliance needs to be able to show it back.
     *
     * @return array{dir:string,ca:string,cert:string,key:string,client:string,crl:string,secrets_file:string}
     */
    public function eapTlsFiles(array $server): array
    {
        if (! $this->usesEapTls($server)) {
            return [];
        }

        return [
            'dir'          => self::EAP_TLS_DIR,
            'ca'           => $this->normalisePem((string) ($server['eap_ca_cert'] ?? '')),
            'cert'         => $this->normalisePem((string) ($server['eap_server_cert'] ?? '')),
            'key'          => $this->normalisePem($this->decrypt((string) ($server['eap_server_key'] ?? ''))),
            'client'       => $this->normalisePem((string) ($server['eap_client_cert'] ?? '')),
            'crl'          => $this->normalisePem((string) ($server['eap_crl'] ?? '')),
            'secrets_file' => self::EAP_TLS_SERVER_FILE,
        ];
    }

    /**
     * Trims a pasted PEM block and guarantees it ends with exactly one newline.
     * OpenSSL fails to load a certificate whose final line is not terminated.
     */
    public function normalisePem(string $pem): string
    {
        $pem = str_replace("\r\n", "\n", trim($pem));

        return $pem === '' ? '' : $pem . "\n";
    }

    /**
     * Whether a value looks like a PEM certificate or key.
     *
     * Checked before anything is written, because pppd refuses to start a
     * session when a certificate cannot be loaded, and a truncated paste is the
     * mistake an operator is most likely to make.
     */
    public function isPem(string $pem): bool
    {
        return (bool) preg_match('/-----BEGIN [A-Z0-9 ]+-----.*-----END [A-Z0-9 ]+-----/s', $pem);
    }

    /**
     * The file paths a server's EAP-TLS material is written to.
     *
     * Keyed by server id so two servers on one appliance cannot overwrite each
     * other's certificates, and returned as absolute paths under the EAP-TLS
     * directory so the secrets file and the written files cannot disagree.
     *
     * @return array{ca:string,cert:string,key:string,client:string,crl:string}
     */
    public function eapTlsPaths(array $server): array
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($server['id'] ?? '')) ?: 'server';

        return [
            'ca'     => self::EAP_TLS_DIR . '/' . $id . '-ca.crt',
            'cert'   => self::EAP_TLS_DIR . '/' . $id . '-server.crt',
            'key'    => self::EAP_TLS_DIR . '/' . $id . '-server.key',
            'client' => self::EAP_TLS_DIR . '/' . $id . '-client.crt',
            'crl'    => self::EAP_TLS_DIR . '/' . $id . '-crl.pem',
        ];
    }

    /**
     * The `/etc/ppp/eaptls-server` entry for a server.
     *
     * pppd reads the server-side certificates from this file rather than from
     * the `ca`/`cert`/`key` options, so it is what actually enables certificate
     * authentication. The fields are tab-separated:
     * `client, server, client cert, server cert, CA cert, private key, addrs`.
     *
     * Both names are wildcards: the subscriber presents whatever name it was
     * given, and the certificate — not the name — is what authorises it. A dash
     * means "unspecified", which is how an unrestricted client certificate slot
     * and an absent CRL are expressed.
     */
    public function compileEapTlsServer(array $server): string
    {
        if (! $this->usesEapTls($server)) {
            return '';
        }

        $files = $this->eapTlsPaths($server);

        $fields = [
            '*',
            '*',
            // A pinned subscriber certificate is optional; the CA is what
            // normally authorises a subscriber.
            $this->isPem((string) ($server['eap_client_cert'] ?? '')) ? $files['client'] : '-',
            $files['cert'],
            $files['ca'],
            $files['key'],
        ];

        $lines = [];
        $lines[] = '# MicronOS EAP-TLS peers — ' . $this->clean((string) ($server['name'] ?? ''));
        $lines[] = '# client\tserver\tclient-cert\tserver-cert\tca-cert\tserver-key\t[addresses]';
        $lines[] = implode("\t", $fields);

        return implode("\n", $lines) . "\n";
    }

    /**
     * The pppd options that turn on EAP-TLS for a server.
     *
     * EAP-TLS is compiled into pppd, so there is no plugin to load — the packaged
     * pppd provides radius.so and radattr.so but no EAP-TLS plugin, and naming a
     * library that does not exist would stop the concentrator from starting.
     *
     * `require-eap` is what makes the concentrator demand it, and the CRL, when
     * supplied, is what makes a revoked certificate stop working.
     *
     * @return list<string>
     */
    public function eapTlsServerOptions(array $server): array
    {
        if (! $this->usesEapTls($server)) {
            return [];
        }

        $files = $this->eapTlsPaths($server);

        $options = [
            'require-eap',
            // pppd must be able to load a client certificate to complete the
            // handshake; without these the negotiated address is rejected.
            'ipcp-accept-local',
            'ipcp-accept-remote',
        ];

        if ($this->isPem((string) ($server['eap_crl'] ?? ''))) {
            $options[] = 'crl ' . $files['crl'];
        }

        return $options;
    }

    /**
     * Validates the EAP-TLS settings of a server, or null when they are usable.
     *
     * A certificate cannot be tested for trustworthiness here, but its presence
     * and shape can be checked, and those are the failures that otherwise only
     * appear when a subscriber cannot connect.
     */
    public function validateEapTls(array $data): ?string
    {
        if (empty($data['eap_tls'])) {
            return null;
        }

        foreach (['eap_ca_cert' => 'A CA certificate is required.', 'eap_server_cert' => 'A server certificate is required.'] as $field => $message) {
            $value = (string) ($data[$field] ?? '');
            if ($value === '' && empty($data[$field . '_set'])) {
                return $message;
            }
            if ($value !== '' && ! $this->isPem($value)) {
                return $message . ' It does not look like a PEM certificate.';
            }
        }

        $key = (string) ($data['eap_server_key'] ?? '');
        if ($key === '' && empty($data['eap_server_key_set'])) {
            return 'A server private key is required.';
        }
        if ($key !== '' && ! $this->isPem($key)) {
            return 'The server private key does not look like a PEM key.';
        }

        // The optional material is only checked when it was supplied.
        foreach (['eap_client_cert' => 'client certificate', 'eap_crl' => 'revocation list'] as $field => $label) {
            $value = (string) ($data[$field] ?? '');
            if ($value !== '' && ! $this->isPem($value)) {
                return 'The ' . $label . ' does not look like a PEM file.';
            }
        }

        return null;
    }

    /**
     * Whether a server sends RADIUS accounting for its sessions.
     *
     * Accounting is meaningless without the RADIUS backend, so it can only be
     * on for a server that authenticates through RADIUS.
     */
    public function usesAccounting(array $server): bool
    {
        return $this->usesRadius($server) && ! empty($server['radius_accounting']);
    }

    /**
     * The authentication servers, as `host:port` entries for radiusclient.
     *
     * The radiusclient matches an entry here against the `servers` file by name,
     * so these must be the same hosts that file lists — a placeholder would send
     * every request to a server the secret is not registered for.
     *
     * @return list<string>
     */
    public function radiusAuthServers(array $server): array
    {
        return $this->radiusEndpoints($server, (int) ($server['radius_port'] ?? 1812), false);
    }

    /**
     * The accounting servers, as `host:port` entries, or an empty list when
     * accounting is off (which is what keeps the `acctserver` line out of the
     * configuration and therefore keeps accounting disabled).
     *
     * @return list<string>
     */
    public function radiusAcctServers(array $server): array
    {
        if (! $this->usesAccounting($server)) {
            return [];
        }

        $port = max(1, min(65535, (int) ($server['radius_acct_port'] ?? 1813)));

        // The accounting host is separate only when one is given; by default the
        // same servers are used, on the accounting port.
        return $this->radiusEndpoints($server, $port, true);
    }

    /**
     * Endpoint list shared by authentication and accounting.
     *
     * @return list<string>
     */
    private function radiusEndpoints(array $server, int $port, bool $allowOverride): array
    {
        $port = max(1, min(65535, $port));

        if ($allowOverride) {
            $override = $this->clean((string) ($server['radius_acct_server'] ?? ''));
            if ($override !== '') {
                return [$override . ':' . $port];
            }
        }

        $endpoints = [];
        foreach (['radius_server', 'radius_server2'] as $field) {
            $host = $this->clean((string) ($server[$field] ?? ''));
            if ($host !== '') {
                $endpoints[] = $host . ':' . $port;
            }
        }

        return array_values(array_unique($endpoints));
    }

    /** The RADIUS shared secret for a server, decrypted. */
    public function radiusSecret(array $server): string
    {
        return $this->decrypt((string) ($server['radius_secret'] ?? ''));
    }

    /**
     * Validates the RADIUS settings of a server, or null when they are usable.
     *
     * A server with the backend set to RADIUS but no reachable server or no
     * secret cannot authenticate anyone, so it is rejected at save time rather
     * than discovered when the first subscriber is refused.
     */
    public function validateRadius(array $data): ?string
    {
        if (strtolower(trim((string) ($data['auth_backend'] ?? 'local'))) !== 'radius') {
            return null;
        }

        $server = $this->clean((string) ($data['radius_server'] ?? ''));
        if (! $this->isIp($server) && ! $this->isHostname($server)) {
            return 'A RADIUS server address is required.';
        }

        $port = (int) ($data['radius_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            return 'The RADIUS port must be between 1 and 65535.';
        }

        $timeout = (int) ($data['radius_timeout'] ?? 0);
        if ($timeout < 1 || $timeout > 60) {
            return 'The RADIUS timeout must be between 1 and 60 seconds.';
        }

        $retries = (int) ($data['radius_retries'] ?? -1);
        if ($retries < 0 || $retries > 10) {
            return 'The RADIUS retry count must be between 0 and 10.';
        }

        $secondary = $this->clean((string) ($data['radius_server2'] ?? ''));
        if ($secondary !== '' && ! $this->isIp($secondary) && ! $this->isHostname($secondary)) {
            return 'The secondary RADIUS server address is not valid.';
        }

        // Accounting settings are only meaningful for a RADIUS server; when it is
        // enabled they must be usable, because a bad accounting target would be
        // discovered only as missing session records.
        if (! empty($data['radius_accounting'])) {
            $acctServer = $this->clean((string) ($data['radius_acct_server'] ?? ''));
            if ($acctServer !== '' && ! $this->isIp($acctServer) && ! $this->isHostname($acctServer)) {
                return 'The accounting server address is not valid.';
            }

            // Absent means "use the standard accounting port"; only a value that
            // is present and out of range is an error.
            $acctPort = (int) ($data['radius_acct_port'] ?? 1813);
            if ($acctPort < 1 || $acctPort > 65535) {
                return 'The accounting port must be between 1 and 65535.';
            }
        }

        // A secret is required for a new or changed server, but an existing
        // server that already holds one is allowed to keep it.
        $secret = (string) ($data['radius_secret'] ?? '');
        if ($secret === '' && empty($data['radius_secret_set'])) {
            return 'A RADIUS shared secret is required.';
        }

        return null;
    }

    /** Whether a value is a plausible hostname (single label or dotted). */
    public function isHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $value);
    }

    /**
     * The radiusclient `servers` file for a server.
     *
     * One line per server: hostname, optional `:auth-port`, secret, timeout and
     * retries. This is the file the plugin reads to find and authenticate to
     * the RADIUS server, so the secret lives here and not on the command line.
     */
    public function compileRadiusServers(array $server): string
    {
        $lines = [];
        $lines[] = '# MicronOS RADIUS servers — ' . $this->clean((string) ($server['name'] ?? ''));
        $lines[] = "# hostname[:auth-port]\tsecret\ttimeout\tretries";

        $secret  = $this->radiusSecret($server);
        $port    = max(1, min(65535, (int) ($server['radius_port'] ?? 1812)));
        $timeout = max(1, min(60, (int) ($server['radius_timeout'] ?? 5)));
        $retries = max(0, min(10, (int) ($server['radius_retries'] ?? 3)));

        $primary = $this->clean((string) ($server['radius_server'] ?? ''));
        if ($primary !== '') {
            $lines[] = sprintf("%s:%d\t%s\t%d\t%d", $primary, $port, $secret, $timeout, $retries);
        }

        $secondary = $this->clean((string) ($server['radius_server2'] ?? ''));
        if ($secondary !== '') {
            $lines[] = sprintf("%s:%d\t%s\t%d\t%d", $secondary, $port, $secret, $timeout, $retries);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The radiusclient configuration file.
     *
     * Three things matter here:
     *
     *  - `authserver` names the hosts the client will authenticate against. The
     *    client matches these against the `servers` file by name, so they must be
     *    the hosts that file lists; a placeholder sends every request to a server
     *    the shared secret is not registered for.
     *  - `acctserver` is what enables accounting. Emitting it is the switch, and
     *    leaving it out is what disables Accounting-Start/Stop/Interim records.
     *  - The dictionaries, without which the plugin cannot decode the reply
     *    attributes, so the paths are emitted explicitly rather than relying on
     *    the package default.
     */
    public function compileRadiusClientConf(array $server): string
    {
        $nasId = $this->clean((string) ($server['radius_nas_id'] ?? ''));
        if ($nasId === '') {
            $nasId = $this->clean((string) ($server['name'] ?? 'MicronOS'));
        }

        $auth = $this->radiusAuthServers($server);
        $acct = $this->radiusAcctServers($server);

        $lines = [];
        $lines[] = '# MicronOS RADIUS client configuration';
        $lines[] = '# Generated by MicronOS. Edits are overwritten on the next apply.';
        $lines[] = '';
        $lines[] = 'auth_order radius';
        $lines[] = 'login_tries 4';
        $lines[] = 'login_timeout 60';
        $lines[] = 'nologin /etc/nologin';
        $lines[] = '';
        $lines[] = 'issue /etc/radiusclient/issue';
        $lines[] = '';
        $lines[] = 'authserver ' . ($auth !== [] ? implode(',', $auth) : 'localhost:1812');
        if ($acct !== []) {
            $lines[] = 'acctserver ' . implode(',', $acct);
        } else {
            $lines[] = '# accounting disabled for this server';
        }
        $lines[] = '';
        $lines[] = 'servers /etc/radiusclient/servers';
        $lines[] = '';
        $lines[] = 'dictionary ' . self::RADIUS_DICT_DIR . '/dictionary';
        $lines[] = 'dictionary ' . self::RADIUS_DICT_DIR . '/dictionary.rfc2865';
        $lines[] = 'dictionary ' . self::RADIUS_DICT_DIR . '/dictionary.rfc2866';
        $lines[] = 'dictionary ' . self::RADIUS_DICT_DIR . '/dictionary.rfc2869';
        $lines[] = 'dictionary ' . self::RADIUS_DICT_DIR . '/dictionary.microsoft';
        $lines[] = '';
        $lines[] = 'nas_identifier "' . $nasId . '"';
        $lines[] = 'default_realm "' . $nasId . '"';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The pppd options the radius plugin needs, appended to the server options.
     *
     * `plugin radius.so` loads the authenticator and `radius-config-file` points
     * it at the configuration above. `plugin radattr.so` then records the reply
     * attributes for each session, which is how the account's timeout, framed
     * address and rate limit reach the session monitor.
     *
     * The NAS identifier is set in `radiusclient.conf`, not here: the plugin's
     * only options are `radius-config-file`, `avpair` and `map-to-ifname`, and
     * an unrecognized option makes pppd refuse to start the session.
     *
     * @return list<string>
     */
    public function radiusServerOptions(array $server): array
    {
        if (! $this->usesRadius($server)) {
            return [];
        }

        return [
            'plugin radius.so',
            // Loaded after radius.so, which it depends on: this is what records
            // the reply attributes to /var/run/radattr.<iface> so the session
            // monitor can show the account's timeout, address and rate limit.
            'plugin radattr.so',
            'radius-config-file /etc/radiusclient/radiusclient.conf',
            'ipcp-accept-local',
            'ipcp-accept-remote',
            // Session timeout and idle timeout are attributes, so pppd must
            // accept them from the reply rather than from this file.
            'idle 0',
        ];
    }

    /**
     * The `radclient` command that tests a server's credentials.
     *
     * Returned as an argv list so the daemon can exec it without a shell.
     *
     * The secret is passed with `-S <file>`, never as the positional argument:
     * argv is world-readable through /proc, and a shared secret exposed there is
     * worse than no test at all. The daemon writes the file, runs this, and
     * removes it again.
     *
     * @param string $secretFile a file holding only the shared secret
     * @return list<string>
     */
    public function radclientArgv(array $server, string $secretFile): array
    {
        $host    = $this->clean((string) ($server['radius_server'] ?? ''));
        $port    = max(1, min(65535, (int) ($server['radius_port'] ?? 1812)));
        $timeout = max(1, min(60, (int) ($server['radius_timeout'] ?? 5)));
        $retries = max(0, min(10, (int) ($server['radius_retries'] ?? 3)));

        return [
            'radclient',
            '-t', (string) $timeout,
            '-r', (string) $retries,
            '-S', $secretFile,
            '-D', self::RADIUS_DICT_DIR,
            $host . ':' . $port,
            'auth',
        ];
    }

    /**
     * The Access-Request radclient sends for a test, as its stdin.
     *
     * A User-Password attribute is used rather than a CHAP challenge: a test
     * needs to prove the secret and reachability, and PAP is what radclient can
     * generate from a plaintext password. The real subscriber path stays CHAP.
     */
    public function radclientRequest(array $server, string $username, string $password): string
    {
        $nasId = $this->clean((string) ($server['radius_nas_id'] ?? '')) ?: 'MicronOS';

        return implode("\n", [
            'User-Name = "' . addcslashes($this->clean($username), '"\\') . '"',
            'User-Password = "' . addcslashes($this->clean($password), '"\\') . '"',
            'NAS-Identifier = "' . addcslashes($nasId, '"\\') . '"',
            'Service-Type = Framed-User',
            'Framed-Protocol = PPP',
        ]) . "\n";
    }

    /**
     * The `pppoe-server` command line for a server.
     *
     * Returned as an argv list rather than a string so the daemon can exec it
     * without a shell.
     *
     * @return list<string>
     */
    public function serverArgv(array $server): array
    {
        $argv = [
            'pppoe-server',
            '-I', $this->clean((string) ($server['interface'] ?? '')),
            '-L', $this->clean((string) ($server['local_ip'] ?? '')),
            '-R', $this->clean((string) ($server['remote_start'] ?? '')),
            '-N', (string) max(1, (int) ($server['max_sessions'] ?? 0) ?: 64),
            '-S', $this->clean((string) ($server['service_name'] ?? 'MicronOS')),
            '-O', self::SERVER_OPTIONS,
        ];

        $acName = $this->clean((string) ($server['ac_name'] ?? ''));
        if ($acName !== '') {
            $argv[] = '-C';
            $argv[] = $acName;
        }

        return $argv;
    }
}
