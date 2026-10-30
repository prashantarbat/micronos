<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Browser terminal (Terminal / Console menu).
 *
 * The shells themselves are run by micron-terminald (system/micron-terminald);
 * these settings only govern how the Web UI reaches it. Limits, timeouts and
 * the unprivileged account live in /etc/micron/terminal.json, read by the
 * service.
 */
class Terminal extends BaseConfig
{
    /** Control socket of micron-terminald (0660 root:www-data). */
    public string $controlSocket = '/run/micron/terminal.sock';

    /**
     * WebSocket URL the browser opens. Empty = same origin `/terminal/ws`,
     * which Apache proxies to 127.0.0.1:7681 (mod_proxy_wstunnel). Set
     * TERMINAL_WS_URL only on a development host without that proxy.
     */
    public string $wsUrl = '';

    /**
     * Transport policy. HTTPS is always accepted. Plain HTTP is accepted only
     * from loopback / private (RFC 1918, CGNAT, ULA, link-local) client
     * addresses — the management networks the firewall already restricts
     * the GUI to — unless $requireTls is true, which refuses plain HTTP
     * outright.
     */
    public bool $requireTls = false;

    /** Seconds a re-entered password authorises opening admin (root) sessions. */
    public int $adminReauthSeconds = 300;

    public function __construct()
    {
        parent::__construct();
        $sock = getenv('TERMINAL_CONTROL_SOCKET');
        if (is_string($sock) && $sock !== '') {
            $this->controlSocket = $sock;
        }
        $ws = getenv('TERMINAL_WS_URL');
        if (is_string($ws) && $ws !== '') {
            $this->wsUrl = $ws;
        }
        if (getenv('TERMINAL_REQUIRE_TLS') === '1') {
            $this->requireTls = true;
        }
    }
}
