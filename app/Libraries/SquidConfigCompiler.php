<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Web Proxy & Content Filtering Configuration Compiler
 * =============================================================
 * Compiles database-defined proxy parameters, ACL lists, category policies,
 * SSL peek & splice inspection, threat intelligence, and caching controls.
 */
class SquidConfigCompiler
{
    /**
     * Curated refresh_pattern rules for software/OS update endpoints.
     *
     * Update archives are large and origin servers frequently force full
     * reloads, so without pinning them Squid re-fetches the same payload for
     * every client. Values follow the Squid project's Windows Update guidance:
     * 4320 min minimum freshness, 80% heuristic, 43200 min maximum, and
     * `reload-into-ims` so a forced reload becomes a cheap If-Modified-Since
     * check instead of a fresh download.
     */
    private const UPDATE_SITE_REFRESH_PATTERNS = [
        'refresh_pattern -i microsoft\.com/.*\.(cab|exe|ms[i|u|f]|[ap]sf|wm[v|a]|dat|zip) 4320 80% 43200 reload-into-ims',
        'refresh_pattern -i windowsupdate\.com/.*\.(cab|exe|ms[i|u|f]|[ap]sf|wm[v|a]|dat|zip) 4320 80% 43200 reload-into-ims',
        'refresh_pattern -i windows\.com/.*\.(cab|exe|ms[i|u|f]|[ap]sf|wm[v|a]|dat|zip) 4320 80% 43200 reload-into-ims',
        'refresh_pattern -i apple\.com/.*\.(dmg|pkg|ipsw|zip) 4320 80% 43200 reload-into-ims',
        'refresh_pattern -i (ubuntu|debian|centos|fedoraproject|archlinux|linuxmint|opensuse)\.(com|org|net)/ 4320 80% 43200 reload-into-ims',
    ];

    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Compiles /etc/squid/squid.conf configuration file content.
     */
    public function compile(): string
    {
        $settings = $this->fetchSettings();
        $categories = $this->fetchCategories();

        $httpPort       = (int) ($settings['http_port'] ?? 3128);
        $tproxyPort     = (int) ($settings['https_port'] ?? 3129);
        $sslPort        = (int) ($settings['ssl_port'] ?? 3130);
        $mode           = $settings['mode'] ?? 'transparent_sni';
        $enableSslBump  = !empty($settings['ssl_bump_enabled']) || $mode === 'deep_inspection';
        $caCertPath     = $settings['ca_cert_path'] ?? '/etc/squid/certs/micron_ca.pem';
        $caKeyPath      = $settings['ca_key_path'] ?? '/etc/squid/certs/micron_ca.key';
        $visibleHost    = !empty($settings['visible_hostname']) ? $settings['visible_hostname'] : 'micronos-proxy';
        $adminEmail     = !empty($settings['admin_email']) ? $settings['admin_email'] : 'admin@micronos.local';

        $lines = [];
        $lines[] = '# ======================================================================';
        $lines[] = '# Micron Firewall OS (MicronOS) — Enterprise Web Proxy & Content Filter';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ======================================================================';
        $lines[] = '';

        // 1. Listening Ports & Modes
        $lines[] = '# 1. Proxy Ports & Interception Engines';
        $lines[] = "http_port {$httpPort}";
        if ($mode === 'transparent_sni' || $mode === 'deep_inspection' || $mode === 'hybrid') {
            if ($enableSslBump) {
                $lines[] = "http_port {$tproxyPort} tproxy ssl-bump generate-host-certificates=on dynamic_cert_mem_cache_size=16MB cert={$caCertPath} key={$caKeyPath}";
                $lines[] = "https_port {$sslPort} tproxy ssl-bump generate-host-certificates=on dynamic_cert_mem_cache_size=16MB cert={$caCertPath} key={$caKeyPath}";
            } else {
                $lines[] = "http_port {$tproxyPort} tproxy";
            }
        }
        $lines[] = '';

        // 2. SSL/TLS Dynamic Peek & Splice
        $lines[] = '# 2. SSL/TLS SNI Peek & Splice Rules';
        if ($enableSslBump || $mode === 'transparent_sni') {
            $lines[] = 'acl step1 at_step SslBump1';
            $lines[] = 'acl step2 at_step SslBump2';
            $lines[] = 'acl step3 at_step SslBump3';

            $sslBypass = $this->parseLines($settings['ssl_bypass_domains'] ?? '');
            if (!empty($sslBypass)) {
                $lines[] = 'acl ssl_bypass_domains ssl::server_name ' . implode(' ', $sslBypass);
                $lines[] = 'ssl_bump peek step1';
                $lines[] = 'ssl_bump splice ssl_bypass_domains';
            } else {
                $lines[] = 'ssl_bump peek step1';
            }

            if ($mode === 'deep_inspection' || !empty($settings['ssl_bump_enabled'])) {
                $lines[] = 'ssl_bump bump all';
            } else {
                $lines[] = 'ssl_bump splice all';
            }
        }
        $lines[] = '';

        // 3. Access Control Lists (ACLs)
        $lines[] = '# 3. Access Control Lists (ACLs)';
        $lines[] = 'acl SSL_ports port 443 8443';
        $lines[] = 'acl Safe_ports port 80 443 21 8080 8443 1025-65535';
        $lines[] = 'acl CONNECT method CONNECT';
        $lines[] = 'acl localhost src 127.0.0.1/32 ::1';

        // Allowed client subnets
        $subnets = $this->parseLines($settings['allowed_subnets'] ?? "192.168.0.0/16\n10.0.0.0/8\n172.16.0.0/12");
        foreach ($subnets as $subnet) {
            $lines[] = "acl localnet src {$subnet}";
        }

        // Unrestricted / privileged source IPs
        $unrestrictedIps = $this->parseLines($settings['unrestricted_ips'] ?? '');
        if (!empty($unrestrictedIps)) {
            $lines[] = 'acl unrestricted_src src ' . implode(' ', $unrestrictedIps);
        }

        // Banned Client IPs
        $bannedIps = $this->parseLines($settings['banned_ips'] ?? '');
        if (!empty($bannedIps)) {
            $lines[] = 'acl banned_src src ' . implode(' ', $bannedIps);
        }

        // Banned User-Agents
        $bannedAgents = $this->parseLines($settings['banned_user_agents'] ?? '');
        if (!empty($bannedAgents)) {
            $lines[] = 'acl banned_user_agents browser -i ' . implode(' ', $bannedAgents);
        }

        // Banned File Extensions
        $bannedExts = $this->parseLines($settings['banned_extensions'] ?? '');
        if (!empty($bannedExts)) {
            $escapedExts = array_map(fn($e) => '\\' . ltrim($e, '.') . '($|\\?)', $bannedExts);
            $lines[] = 'acl banned_extensions urlpath_regex -i ' . implode(' ', $escapedExts);
        }

        // Banned MIME Types
        $bannedMimes = $this->parseLines($settings['banned_mime_types'] ?? '');
        if (!empty($bannedMimes)) {
            $lines[] = 'acl banned_mime_types req_mime_type -i ' . implode(' ', $bannedMimes);
        }

        // Custom Whitelist Domains
        $whitelists = $this->parseLines($settings['custom_whitelist'] ?? '');
        if (!empty($whitelists)) {
            $lines[] = 'acl custom_whitelist dstdomain ' . implode(' ', $whitelists);
        }

        // Custom Blacklist Domains
        $blacklists = $this->parseLines($settings['custom_blacklist'] ?? '');
        if (!empty($blacklists)) {
            $lines[] = 'acl custom_blacklist dstdomain ' . implode(' ', $blacklists);
        }

        // Custom Regex Blacklist
        $regexBlacklists = $this->parseLines($settings['custom_regex_blacklist'] ?? '');
        if (!empty($regexBlacklists)) {
            $lines[] = 'acl custom_regex_blacklist url_regex -i ' . implode(' ', array_map('escapeshellarg', $regexBlacklists));
        }

        // Category ACLs
        $lines[] = '';
        $lines[] = '# 4. Category-Based Content Filtering';
        foreach ($categories as $cat) {
            $slug = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cat['name']));
            $action = $cat['action'] ?? 'allow';
            if ($action === 'block') {
                $lines[] = "acl cat_{$slug} dstdomain \"/etc/squid/acls/cat_{$slug}.txt\"";
            }
        }
        $lines[] = '';

        // 5. Access Permission Rules & Priorities
        $lines[] = '# 5. Policy Access Evaluation Hierarchy';
        $lines[] = 'http_access deny !Safe_ports';
        $lines[] = 'http_access deny CONNECT !SSL_ports';
        $lines[] = 'http_access allow localhost manager';
        $lines[] = 'http_access deny manager';

        if (!empty($bannedIps)) {
            $lines[] = 'http_access deny banned_src';
        }
        if (!empty($bannedAgents)) {
            $lines[] = 'http_access deny banned_user_agents';
        }
        if (!empty($bannedExts)) {
            $lines[] = 'http_access deny banned_extensions';
        }
        if (!empty($bannedMimes)) {
            $lines[] = 'http_access deny banned_mime_types';
        }

        // Unrestricted hosts bypass further inspection
        if (!empty($unrestrictedIps)) {
            $lines[] = 'http_access allow unrestricted_src';
        }

        // Custom Whitelist takes precedence over category blocks
        if (!empty($whitelists)) {
            $lines[] = 'http_access allow custom_whitelist';
        }

        // Custom Blacklists
        if (!empty($blacklists)) {
            $lines[] = 'http_access deny custom_blacklist';
        }
        if (!empty($regexBlacklists)) {
            $lines[] = 'http_access deny custom_regex_blacklist';
        }

        // Enforce blocked categories
        foreach ($categories as $cat) {
            $slug = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($cat['name']));
            $action = $cat['action'] ?? 'allow';
            if ($action === 'block') {
                $lines[] = "http_access deny cat_{$slug}";
            }
        }

        // Default local networks policy
        $lines[] = 'http_access allow localnet';
        $lines[] = 'http_access allow localhost';
        $lines[] = 'http_access deny all';
        $lines[] = '';

        // 6. SafeSearch & Media Restrictions
        $lines[] = '# 6. Content Integrity & SafeSearch Restrictions';
        if (!empty($settings['safesearch_enabled'])) {
            $lines[] = '# SafeSearch enforcement headers';
            $lines[] = 'request_header_add X-YouTube-Edu-Filter "ABCD1234567890" all';
        }

        $ytMode = $settings['youtube_restricted_mode'] ?? 'disabled';
        if ($ytMode === 'strict') {
            $lines[] = 'request_header_add YouTube-Restrict "Strict" all';
        } elseif ($ytMode === 'moderate') {
            $lines[] = 'request_header_add YouTube-Restrict "Moderate" all';
        }
        $lines[] = '';

        // 7. Privacy, Headers & Anonymization
        $lines[] = '# 7. Headers & Privacy Controls';
        $forwardedFor = $settings['forwarded_for'] ?? 'on';
        $lines[] = "forwarded_for {$forwardedFor}";
        if (!empty($settings['suppress_headers'])) {
            $lines[] = 'request_header_access Via deny all';
            $lines[] = 'request_header_access X-Forwarded-For deny all';
            $lines[] = 'request_header_access From deny all';
        }
        $lines[] = '';

        // 8. Caching & Performance Acceleration
        $lines[] = '# 8. Cache & Storage Engine';
        if (!empty($settings['cache_enabled'])) {
            $cacheMem = (int) ($settings['cache_mem_mb'] ?? 256);
            $cacheSize = (int) ($settings['cache_size_mb'] ?? 4096);
            $maxObj = (int) ($settings['cache_max_obj_kb'] ?? 4096);
            $policy = $settings['cache_replacement_policy'] ?? 'heap LFUDA';

            $lines[] = "cache_mem {$cacheMem} MB";
            $lines[] = "maximum_object_size_in_memory {$maxObj} KB";
            $lines[] = "cache_dir ufs /var/spool/squid {$cacheSize} 16 256";
            $lines[] = "cache_replacement_policy {$policy}";
            if (!empty($settings['cache_ignore_queries'])) {
                $lines[] = 'acl query_url urlpath_regex cgi-bin \\?';
                $lines[] = 'cache deny query_url';
            }

            // 8b. Freshness & refresh_pattern rules
            $refreshEnabled = ! empty($settings['cache_refresh_enabled']);
            $updatePreset   = ! empty($settings['cache_update_preset_enabled']);

            if ($refreshEnabled || $updatePreset) {
                $lines[] = '';
                $lines[] = '# 8b. Freshness & Cache Refresh Patterns';
                $lines[] = '# Squid applies the FIRST matching refresh_pattern, so specific rules';
                $lines[] = '# are emitted ahead of the catch-all default below.';

                // Operator rules win over the built-in update-site presets.
                if ($refreshEnabled) {
                    foreach ($this->parseRefreshPatterns($settings['cache_refresh_custom'] ?? '') as $pattern) {
                        $lines[] = $pattern;
                    }
                }

                // Curated OS/software update-site rules (must precede the catch-all).
                if ($updatePreset) {
                    $lines[] = '# OS & software update sites (Windows Update, Apple, Linux mirrors),'
                        . ' pinned so large downloads stay cached (values per squid-cache.org).';
                    foreach (self::UPDATE_SITE_REFRESH_PATTERNS as $pattern) {
                        $lines[] = $pattern;
                    }
                }

                if ($refreshEnabled) {
                    $refreshMin = max(0, (int) ($settings['cache_refresh_min_minutes'] ?? 0));
                    $refreshPct = min(100, max(0, (int) ($settings['cache_refresh_percent'] ?? 20)));
                    $refreshMax = max(0, (int) ($settings['cache_refresh_max_minutes'] ?? 4320));

                    $refreshOptions = [];
                    if (!empty($settings['cache_refresh_ignore_reload'])) {
                        $refreshOptions[] = 'ignore-reload';
                    }
                    if (!empty($settings['cache_refresh_ignore_no_store'])) {
                        $refreshOptions[] = 'ignore-no-store';
                    }
                    if (!empty($settings['cache_refresh_ignore_private'])) {
                        $refreshOptions[] = 'ignore-private';
                    }
                    if (!empty($settings['cache_refresh_reload_into_ims'])) {
                        $refreshOptions[] = 'reload-into-ims';
                    }

                    $catchAll = "refresh_pattern . {$refreshMin} {$refreshPct}% {$refreshMax}";
                    if (!empty($refreshOptions)) {
                        $catchAll .= ' ' . implode(' ', $refreshOptions);
                    }
                    $lines[] = $catchAll;
                }
            }

            // 8c. Cache bypass destinations ("Do Not Cache" list)
            $bypass = $this->parseCacheBypass($settings['cache_do_not_cache'] ?? '');
            if (!empty($bypass['domains']) || !empty($bypass['ips'])) {
                $lines[] = '';
                $lines[] = '# 8c. Do Not Cache Destinations';
                if (!empty($bypass['domains'])) {
                    $lines[] = 'acl cache_bypass_domains dstdomain ' . implode(' ', $bypass['domains']);
                    $lines[] = 'cache deny cache_bypass_domains';
                }
                if (!empty($bypass['ips'])) {
                    $lines[] = 'acl cache_bypass_ips dst ' . implode(' ', $bypass['ips']);
                    $lines[] = 'cache deny cache_bypass_ips';
                }
            }
        } else {
            $lines[] = 'cache deny all';
        }
        $lines[] = '';

        // 9. ICAP Antivirus & Malware Inspection (C-ICAP + ClamAV)
        $lines[] = '# 9. ICAP Antivirus & Malware Inspection (C-ICAP + ClamAV)';
        if (!empty($settings['icap_antivirus_enabled'])) {
            $icapHost     = !empty($settings['icap_host']) ? $settings['icap_host'] : '127.0.0.1';
            $icapPort     = (int) ($settings['icap_port'] ?? 1344);
            $icapService  = !empty($settings['icap_service']) ? $settings['icap_service'] : 'squidclamav';
            $scanMode     = $settings['icap_scan_mode'] ?? 'response'; // request|response|both
            $previewKb    = max(1, (int) ($settings['icap_preview_kb'] ?? 1024));
            // bypass=1 tells Squid to pass traffic through unchecked when the
            // ICAP server is unreachable; bypass=0 fails closed (block).
            $bypassOnFail = ($settings['icap_failure_mode'] ?? 'allow') === 'allow' ? 1 : 0;
            $scanRequest  = $scanMode === 'request' || $scanMode === 'both';
            $scanResponse = $scanMode === 'response' || $scanMode === 'both';

            $lines[] = 'icap_enable on';
            $lines[] = 'icap_send_client_ip on';
            $lines[] = 'icap_send_client_username on';
            $lines[] = "icap_preview_size {$previewKb}";
            $lines[] = 'icap_service_failure_limit 5 in 10 seconds';

            if ($scanRequest) {
                $lines[] = "icap_service svc_reqmod reqmod_precache bypass={$bypassOnFail} icap://{$icapHost}:{$icapPort}/{$icapService}";
            }
            if ($scanResponse) {
                $lines[] = "icap_service svc_respmod respmod_precache bypass={$bypassOnFail} icap://{$icapHost}:{$icapPort}/{$icapService}";
            }

            // HTTPS bodies only reach the scanner when SSL-Bump has decrypted
            // them; SNI peek & splice forwards ciphertext untouched.
            $tlsInspected = !empty($settings['icap_scan_https']) && $enableSslBump;
            $lines[] = $tlsInspected
                ? '# HTTPS payloads are scanned through the SSL-Bump decrypted stream.'
                : '# NOTE: HTTPS bodies are NOT inspected unless SSL-Bump deep inspection is enabled.';

            // Domains whose payloads must never be handed to the scanner.
            $avBypass = $this->parseLines($settings['icap_bypass_domains'] ?? '');
            if (!empty($avBypass)) {
                $lines[] = 'acl av_scan_bypass dstdomain ' . implode(' ', $avBypass);
            }

            foreach (['svc_reqmod' => $scanRequest, 'svc_respmod' => $scanResponse] as $svc => $active) {
                if (! $active) {
                    continue;
                }
                if (!empty($avBypass)) {
                    $lines[] = "adaptation_access {$svc} deny av_scan_bypass";
                }
                $lines[] = "adaptation_access {$svc} allow all";
            }
        } else {
            $lines[] = '# ICAP antivirus inspection disabled.';
        }
        $lines[] = '';

        // 10. Error Pages & Identity
        $lines[] = '# 10. System Identity & Error Display';
        $lines[] = "visible_hostname {$visibleHost}";
        $lines[] = "cache_mgr {$adminEmail}";
        $lines[] = 'deny_info http://micronos.test/webfilter/blocked custom_blacklist';
        $lines[] = '';

        return implode("\n", $lines);
    }

    protected function fetchSettings(): array
    {
        try {
            return $this->db->table('webfilter_settings')->where('id', 1)->get()->getRowArray() ?: [];
        } catch (\Throwable $e) {
            return ['mode' => 'transparent_sni', 'http_port' => 3128, 'https_port' => 3129, 'ssl_bump_enabled' => 0];
        }
    }

    protected function fetchCategories(): array
    {
        try {
            return $this->db->table('webfilter_categories')->where('enabled', 1)->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Extracts operator-supplied refresh_pattern directives.
     *
     * Only lines beginning with the `refresh_pattern` keyword are emitted, so
     * a stray entry can never inject an unrelated directive into squid.conf.
     * Comment lines and blanks are dropped. Squid applies the first matching
     * rule, so callers must emit the result before the catch-all pattern.
     */
    private function parseRefreshPatterns(string $text): array
    {
        $clean = [];
        foreach (preg_split('/[\r\n]+/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^refresh_pattern\s+\S/', $line)) {
                $clean[] = $line;
            }
        }
        return $clean;
    }

    /**
     * Splits "Do Not Cache" entries into destination domains and IP/CIDR
     * addresses.
     *
     * Only entries that validate as an IP, a CIDR block, or a hostname are
     * returned, so a stray line can never inject an unrelated directive into
     * squid.conf. Domains are normalised to a single leading dot, which Squid
     * matches against the domain itself and every subdomain.
     *
     * @return array{domains: array<int, string>, ips: array<int, string>}
     */
    private function parseCacheBypass(string $text): array
    {
        $domains = [];
        $ips = [];

        foreach (preg_split('/[\r\n]+/', trim($text)) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            $host = $entry;
            if (str_contains($entry, '/')) {
                [$host, $prefix] = explode('/', $entry, 2);
                if ($this->isIp($host) && ctype_digit($prefix)) {
                    $max = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
                    if ((int) $prefix <= $max) {
                        $ips[] = $entry;
                        continue;
                    }
                }
            } elseif ($this->isIp($host)) {
                $ips[] = $host;
                continue;
            }

            $domain = strtolower(ltrim(preg_replace('/^\*\.?/', '', $host), '.'));
            // All-numeric dotted strings (e.g. a malformed IP) are rejected so
            // they are never emitted as a destination domain.
            if ($domain !== '' && ! preg_match('/^[\d.]+$/', $domain)
                && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain)) {
                $domains[] = '.' . $domain;
            }
        }

        return [
            'domains' => array_values(array_unique($domains)),
            'ips'     => array_values(array_unique($ips)),
        ];
    }

    private function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function parseLines(string $text): array
    {
        $lines = preg_split('/[\r\n]+/', trim($text));
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $clean[] = $line;
            }
        }
        return array_values(array_unique($clean));
    }
}
