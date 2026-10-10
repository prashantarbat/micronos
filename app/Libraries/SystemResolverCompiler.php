<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Appliance Stub Resolver Compiler
 * =========================================
 * Compiles the configuration that governs how the **firewall itself** resolves
 * DNS — the queries it issues for its own needs (package installs, update
 * checks, NTP, plugin fetches) — as `/etc/resolv.conf`.
 *
 * This is deliberately separate from `UnboundDnsCompiler`, which compiles the
 * question "how does the appliance answer DNS for LAN clients". PF Sense draws
 * the same distinction: the DNS Resolver serves clients, while the stub
 * resolver in `resolv.conf` serves the firewall.
 *
 * Two `dns_settings` columns drive it:
 *
 *   * `dns_server_override` — when set, DNS servers assigned by a DHCP/PPP
 *     server on WAN or by a remote OpenVPN peer (Pull DNS) may override the
 *     manually configured list. When cleared, only the servers configured on
 *     the DNS page are used. It never applies to Unbound, which is always in
 *     resolver/forwarder mode and ignores WAN-supplied servers.
 *   * `dns_resolution_behavior` — one of `local-fallback` (default),
 *     `local-only`, `remote-only`, choosing whether the appliance consults the
 *     local resolver (127.0.0.1) and/or the configured remote servers.
 *
 * The compiled artifact is a plain `resolv.conf`: comment header, a
 * machine-readable `# dynamic-wan-override:` directive the interface hooks
 * honor, then the `nameserver` lines. `daemon`-free by design, so it can be
 * compiled on the management plane (and in tests) without an appliance.
 */
class SystemResolverCompiler
{
    /** Where the compiled artifact is applied on the appliance. */
    public const FILE = '/etc/resolv.conf';

    /** The local resolver address, as Unbound listens on the loopback. */
    public const LOCAL = '127.0.0.1';

    /** Consult 127.0.0.1 first, then the remote servers. Default. */
    public const LOCAL_FALLBACK = 'local-fallback';

    /** Consult 127.0.0.1 only. */
    public const LOCAL_ONLY = 'local-only';

    /** Consult the remote servers only; bypass the local resolver. */
    public const REMOTE_ONLY = 'remote-only';

    /** The behavior used when nothing is configured or the stored value is unknown. */
    public const DEFAULT_BEHAVIOR = self::LOCAL_FALLBACK;

    /** Every accepted behavior, in operator-facing order. */
    public const BEHAVIORS = [self::LOCAL_FALLBACK, self::LOCAL_ONLY, self::REMOTE_ONLY];

    /** Operator-facing labels, adapted from pfSense's wording. */
    private const LABELS = [
        self::LOCAL_FALLBACK => 'Use local DNS (127.0.0.1), fall back to remote DNS Servers (Default)',
        self::LOCAL_ONLY     => 'Use local DNS (127.0.0.1), ignore remote DNS Servers',
        self::REMOTE_ONLY    => 'Use remote DNS Servers, ignore local DNS',
    ];

    /** The help text shown beside each option, adapted from pfSense's wording. */
    private const HELP = [
        self::LOCAL_FALLBACK => 'The firewall consults the DNS Resolver or DNS Forwarder running on this firewall to resolve '
            . 'hostnames for itself, by listing localhost (127.0.0.1) as its first DNS server internally. If the local DNS '
            . 'server is unreachable, the firewall sends queries directly to the DNS servers configured on this page, or '
            . 'those received from dynamic WANs. This method gives the firewall the best chance of having working DNS.',
        self::LOCAL_ONLY => 'Like the option above, the firewall uses its own DNS Resolver or DNS Forwarder to resolve '
            . 'hostnames. However, it will not attempt to use any other server. This option is more secure as it forces DNS '
            . 'to be resolved using the configuration on the DNS Resolver or DNS Forwarder, which may have special '
            . 'requirements restricting or redirecting name resolution. For example, if the DNS Resolver is configured for '
            . 'DNS over TLS, using this option ensures that the firewall will not send queries to DNS servers without using TLS.',
        self::REMOTE_ONLY => 'The firewall uses the DNS servers configured on this page or received from dynamic WANs, and it '
            . 'will not utilize the local DNS Resolver or DNS Forwarder. This option is useful when the local DNS service is '
            . 'configured in a strict manner to control client behavior, but the firewall still needs unrestricted access to '
            . 'DNS for tasks such as updates and installing packages.',
    ];

    /** A resolv.conf never carries more than a handful of servers. */
    private const MAX_SERVERS = 8;

    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // ─────────────────────────────────────────────────────────────────
    // Behavior model
    // ─────────────────────────────────────────────────────────────────

    /** @return array<int, string> every accepted behavior value. */
    public static function behaviors(): array
    {
        return self::BEHAVIORS;
    }

    /** Operator-facing label for a behavior (falls back to the default label). */
    public static function label(string $behavior): string
    {
        return self::LABELS[$behavior] ?? self::LABELS[self::DEFAULT_BEHAVIOR];
    }

    /** Help text for a behavior (falls back to the default help). */
    public static function help(string $behavior): string
    {
        return self::HELP[$behavior] ?? self::HELP[self::DEFAULT_BEHAVIOR];
    }

    /** True when the behavior is one of the accepted values. */
    public static function isValid(string $behavior): bool
    {
        return in_array($behavior, self::BEHAVIORS, true);
    }

    /**
     * Maps a stored or submitted value onto the closed set.
     *
     * An empty/unknown value is *not* an error here — a missing setting predates
     * this feature, and the documented default is what it should behave as. Use
     * `isValid()` when a submitted value must be rejected rather than coerced.
     */
    public static function normalize(?string $behavior): string
    {
        $behavior = is_string($behavior) ? strtolower(trim($behavior)) : '';

        return self::isValid($behavior) ? $behavior : self::DEFAULT_BEHAVIOR;
    }

    /** True when the behavior consults the local resolver. */
    public static function usesLocal(string $behavior): bool
    {
        return self::normalize($behavior) !== self::REMOTE_ONLY;
    }

    /** True when the behavior may consult remote servers. */
    public static function usesRemote(string $behavior): bool
    {
        return self::normalize($behavior) !== self::LOCAL_ONLY;
    }

    // ─────────────────────────────────────────────────────────────────
    // Settings access
    // ─────────────────────────────────────────────────────────────────

    /**
     * Reads the single `dns_settings` row, tolerating an unmigrated database
     * so `verify:system` and the compiler stay usable on a fresh install.
     */
    public function settings(): array
    {
        if ($this->db === null || ! $this->db->tableExists('dns_settings')) {
            return [];
        }

        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();

        return is_array($row) ? $row : [];
    }

    /** True when dynamic WAN/OpenVPN-assigned DNS servers may override the list. */
    public function overrideEnabled(?array $settings = null): bool
    {
        $settings ??= $this->settings();

        // Absent (pre-migration) rows default to pfSense's checked default.
        return array_key_exists('dns_server_override', $settings)
            ? (int) $settings['dns_server_override'] === 1
            : true;
    }

    /** The configured behavior, normalized to the closed set. */
    public function behavior(?array $settings = null): string
    {
        $settings ??= $this->settings();

        return self::normalize(isset($settings['dns_resolution_behavior']) ? (string) $settings['dns_resolution_behavior'] : null);
    }

    /**
     * The remote DNS servers configured on the page, in order.
     *
     * `upstream_servers` is the JSON array of resolvers the DNS page manages
     * (name/ip/port/sni). Only the addresses matter here — `resolv.conf` names
     * servers by address, and the port/DoT settings are Unbound's business.
     * Duplicates and non-addresses are dropped.
     *
     * @return array<int, string>
     */
    public function remoteServers(?array $settings = null): array
    {
        $settings ??= $this->settings();
        $raw      = $settings['upstream_servers'] ?? '[]';

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $servers = [];
        foreach ($decoded as $entry) {
            $ip = is_array($entry) ? (string) ($entry['ip'] ?? '') : (string) $entry;
            $ip = trim($ip);
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (in_array($ip, $servers, true)) {
                continue;
            }
            $servers[] = $ip;
            if (count($servers) >= self::MAX_SERVERS) {
                break;
            }
        }

        return $servers;
    }

    /**
     * Every dynamically assigned server currently on record, regardless of the
     * override. Used by the DNS page to show what the links handed the
     * appliance even while the override is off.
     *
     * @return array<int, string>
     */
    public function storedDynamicServers(): array
    {
        if ($this->db === null || ! $this->db->tableExists('dynamic_dns_servers')) {
            return [];
        }

        return (new DynamicDnsRegistry($this->db))->servers();
    }

    /**
     * The dynamic servers the compiler actually merges.
     *
     * Empty unless the DNS Server Override is enabled *and* the behavior does
     * not ignore remote servers: an override can only matter when the appliance
     * is willing to query a server other than its own resolver. Dropping them
     * here is exactly how a cleared override removes them from `resolv.conf`.
     *
     * @return array<int, string>
     */
    public function dynamicServers(?array $settings = null): array
    {
        $settings ??= $this->settings();

        if (! $this->overrideEnabled($settings) || ! self::usesRemote($this->behavior($settings))) {
            return [];
        }

        return $this->storedDynamicServers();
    }

    /**
     * The ordered `nameserver` addresses for a behavior.
     *
     * Dynamically assigned servers (DHCP/PPP on WAN, remote OpenVPN with Pull
     * DNS) are merged **ahead** of the manually configured list while the
     * override is enabled, and dropped entirely when it is not. The result is
     * capped at `MAX_SERVERS`, the same ceiling the daemon enforces, so the
     * compiler can never produce a file `dns.resolver.apply` would reject.
     *
     * @return array<int, string>
     */
    public function nameservers(?array $settings = null): array
    {
        $behavior = $this->behavior($settings);
        $servers  = [];

        if (self::usesLocal($behavior)) {
            $servers[] = self::LOCAL;
        }

        if (self::usesRemote($behavior)) {
            foreach ($this->dynamicServers($settings) as $ip) {
                if (! in_array($ip, $servers, true)) {
                    $servers[] = $ip;
                }
            }

            foreach ($this->remoteServers($settings) as $ip) {
                if (! in_array($ip, $servers, true)) {
                    $servers[] = $ip;
                }
            }
        }

        return array_slice($servers, 0, self::MAX_SERVERS);
    }

    /**
     * True when the configured behavior would leave the appliance with no
     * nameserver at all — the one combination the operator must not save.
     */
    public function isUnresolvable(?array $settings = null): bool
    {
        return $this->nameservers($settings) === [];
    }

    // ─────────────────────────────────────────────────────────────────
    // Compilation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Compiles the appliance stub resolver file.
     *
     * The invariant the daemon also enforces: the artifact always carries at
     * least one `nameserver`. "Use remote DNS Servers, ignore local DNS" with
     * nothing configured would otherwise leave the firewall unable to resolve
     * anything, so it degrades to 127.0.0.1 with a loud comment instead of
     * emitting an empty file.
     */
    public function compile(?array $settings = null): string
    {
        $settings ??= $this->settings();
        $behavior = $this->behavior($settings);
        $override = $this->overrideEnabled($settings);
        $servers  = $this->nameservers($settings);
        $dynamic  = $this->dynamicServers($settings);
        $stored   = $this->storedDynamicServers();

        $lines   = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Appliance Stub Resolver';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '#';
        $lines[] = '# How the firewall resolves DNS for its own needs (updates, package and';
        $lines[] = '# plugin installs, NTP). DNS clients on the LAN are unaffected — they are';
        $lines[] = '# served by the Unbound resolver compiled into /etc/unbound/unbound.conf.';
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# DNS Resolution Behavior: ' . self::label($behavior);
        $lines[] = $override
            ? '# DNS Server Override: enabled — servers assigned by DHCP/PPP on WAN or a remote OpenVPN server may override this list'
            : '# DNS Server Override: disabled — servers assigned by DHCP/PPP on WAN or a remote OpenVPN server are ignored';
        $lines[] = '';

        // The directive the interface up/down hooks read. Commented, because
        // resolv.conf has no syntax of its own for it.
        $lines[] = '# dynamic-wan-override: ' . ($override ? 'enabled' : 'disabled');

        if ($dynamic !== []) {
            $lines[] = '# Dynamic WAN/OpenVPN-assigned servers merged ahead of the configured list: ' . implode(', ', $dynamic);
        } elseif ($override && self::usesRemote($behavior)) {
            $lines[] = '# No dynamically assigned WAN/OpenVPN DNS servers are currently present.';
        }

        if (self::usesLocal($behavior)) {
            $lines[] = '# 127.0.0.1 is the local DNS Resolver (Unbound), which applies DNSSEC, DNS-over-TLS and the DNSBL blocklist.';
        }

        if (! $override && self::usesRemote($behavior)) {
            $lines[] = '# Dynamic WAN/OpenVPN-assigned servers are ignored: only the servers below are used.';
        }

        // The override has no observable effect when remote servers are ignored
        // wholesale, so say so rather than letting the operator believe it does.
        if ($override && ! self::usesRemote($behavior)) {
            $lines[] = '# NOTE: the DNS Server Override has no effect while remote DNS Servers are ignored (local DNS only).';
            if ($stored !== []) {
                $lines[] = '# ' . count($stored) . ' dynamically assigned server(s) are on record but not used under this behavior.';
            }
        }

        if ($servers === []) {
            // Defensive: compile() must never emit a resolv.conf without a
            // server, even if it is called with an in-memory (unsaved) settings
            // array that bypassed the controller's validation.
            $lines[] = '# !!! No DNS servers were configured for this behavior. Falling back to';
            $lines[] = '# !!! 127.0.0.1 so the firewall itself stays resolvable.';
            $servers = [self::LOCAL];
        }

        if (self::usesRemote($behavior) && $this->remoteServers($settings) === [] && self::usesLocal($behavior)) {
            $lines[] = '# No remote DNS servers are configured on the DNS page; the local resolver is the only server.';
        }

        $lines[] = '';
        foreach ($servers as $ip) {
            $lines[] = 'nameserver ' . $ip;
        }
        $lines[] = '';

        return implode("\n", $lines);
    }
}
