<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Unbound DNS Server Configuration Compiler
 * ==================================================
 * Compiles validating, recursive, caching DNS resolver configuration (/etc/unbound/unbound.conf)
 * with DNSSEC validation, DNS-over-TLS upstreams, and local host overrides.
 */
class UnboundDnsCompiler
{
    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Compiles /etc/unbound/unbound.conf file content.
     */
    public function compile(): string
    {
        $lines = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled Unbound DNS Resolver';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';
        $lines[] = 'server:';
        $lines[] = '    verbosity: 1';
        $lines[] = '    interface: 0.0.0.0';
        $lines[] = '    interface: ::0';
        $lines[] = '    port: 53';
        $lines[] = '    do-ip4: yes';
        $lines[] = '    do-ip6: yes';
        $lines[] = '    do-udp: yes';
        $lines[] = '    do-tcp: yes';
        $lines[] = '';
        $lines[] = '    # Access Control';
        $lines[] = '    access-control: 127.0.0.0/8 allow';
        $lines[] = '    access-control: 10.0.0.0/8 allow';
        $lines[] = '    access-control: 172.16.0.0/12 allow';
        $lines[] = '    access-control: 192.168.0.0/16 allow';
        $lines[] = '    access-control: 0.0.0.0/0 refuse';
        $lines[] = '';
        $lines[] = '    # Security & Hardening';
        $lines[] = '    hide-identity: yes';
        $lines[] = '    hide-version: yes';
        $lines[] = '    harden-glue: yes';
        $lines[] = '    harden-dnssec-stripped: yes';
        $lines[] = '    use-caps-for-id: yes';
        $lines[] = '    auto-trust-anchor-file: "/var/lib/unbound/root.key"';
        $lines[] = '';
        $lines[] = '    # Performance & Memory';
        $lines[] = '    num-threads: 2';
        $lines[] = '    msg-cache-slabs: 4';
        $lines[] = '    rrset-cache-slabs: 4';
        $lines[] = '    infra-cache-slabs: 4';
        $lines[] = '    key-cache-slabs: 4';
        $lines[] = '    msg-cache-size: 64m';
        $lines[] = '    rrset-cache-size: 128m';
        $lines[] = '';
        // Local DNS Host Overrides (split-horizon A records) — from `dns_hosts`.
        // Unbound creates an implicit transparent local-zone for local-data,
        // so unmatched names inside the same zone still resolve upstream.
        $lines[] = '    # Local DNS Host Overrides (dns_hosts)';
        $hosts = $this->rows('dns_hosts');
        if ($hosts === []) {
            $lines[] = '    # (none defined)';
        }
        foreach ($hosts as $host) {
            $fqdn = rtrim($this->quoteName(strtolower((string) ($host['hostname'] ?? ''))), '.');
            $ip   = str_replace(['"', ' '], '', (string) ($host['ip_address'] ?? ''));
            if ($fqdn === '' || $ip === '') {
                continue;
            }
            $lines[] = '    local-data: "' . $fqdn . '. IN A ' . $ip . '"';
        }
        $lines[] = '';

        $lines[] = 'forward-zone:';
        $lines[] = '    name: "."';
        $lines[] = '    forward-tls-upstream: yes';
        $lines[] = '    # Cloudflare & Quad9 DNS over TLS (DoT)';
        $lines[] = '    forward-addr: 1.1.1.1@853#cloudflare-dns.com';
        $lines[] = '    forward-addr: 1.0.0.1@853#cloudflare-dns.com';
        $lines[] = '    forward-addr: 9.9.9.9@853#dns.quad9.net';
        $lines[] = '';

        // Domain Overrides (per-domain forwarding) — from `dns_domain_overrides`.
        // Unbound answers from the closest matching forward-zone, so these
        // take precedence over the root "." forward zone above.
        $lines[] = '# Domain Overrides (dns_domain_overrides)';
        $domainOverrides = array_filter(
            $this->rows('dns_domain_overrides'),
            static fn (array $o): bool => (int) ($o['enabled'] ?? 0) === 1
        );
        if ($domainOverrides === []) {
            $lines[] = '# (none defined)';
        }
        foreach ($domainOverrides as $override) {
            $zone   = rtrim($this->quoteName(strtolower((string) ($override['domain'] ?? ''))), '.');
            $server = str_replace(['"', ' '], '', (string) ($override['dns_server'] ?? ''));
            $port   = (int) ($override['port'] ?? 53);
            if ($zone === '' || $server === '') {
                continue;
            }

            $lines[] = 'forward-zone:';
            $lines[] = '    name: "' . $zone . '."';
            $desc = trim((string) ($override['description'] ?? ''));
            if ($desc !== '') {
                $lines[] = '    # ' . str_replace(["\r", "\n"], ' ', $desc);
            }
            $lines[] = '    forward-addr: ' . $server . ($port > 0 && $port !== 53 ? '@' . $port : '');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Reads rows from an appliance table, tolerating a table that has not
     * been migrated yet (keeps `verify:system` usable on a fresh database).
     */
    protected function rows(string $table): array
    {
        if ($this->db === null || ! $this->db->tableExists($table)) {
            return [];
        }

        return $this->db->table($table)->get()->getResultArray();
    }

    /**
     * Strips everything that may not appear inside a quoted unbound value.
     */
    protected function quoteName(string $name): string
    {
        return preg_replace('/[^a-z0-9._-]/i', '', $name) ?? '';
    }
}
