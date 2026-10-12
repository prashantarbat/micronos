<?php

namespace App\Libraries;

use App\Models\ThreatFeedModel;
use App\Models\ThreatIndicatorModel;
use App\Models\ThreatIntelSettingsModel;
use App\Models\ThreatIntelWhitelistModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Exception;

/**
 * ThreatIntelEngine — High-performance threat feed ingest, indicator normalization,
 * RFC 1918 private IP protection, and nftables set compiler.
 */
class ThreatIntelEngine
{
    protected BaseConnection $db;
    protected ThreatFeedModel $feedModel;
    protected ThreatIndicatorModel $indicatorModel;
    protected ThreatIntelSettingsModel $settingsModel;
    protected ThreatIntelWhitelistModel $whitelistModel;

    public function __construct(
        ?BaseConnection $db = null,
        ?ThreatFeedModel $feedModel = null,
        ?ThreatIndicatorModel $indicatorModel = null,
        ?ThreatIntelSettingsModel $settingsModel = null,
        ?ThreatIntelWhitelistModel $whitelistModel = null
    ) {
        $this->db             = $db ?? Database::connect();
        $this->feedModel      = $feedModel ?? new ThreatFeedModel();
        $this->indicatorModel = $indicatorModel ?? new ThreatIndicatorModel();
        $this->settingsModel  = $settingsModel ?? new ThreatIntelSettingsModel();
        $this->whitelistModel = $whitelistModel ?? new ThreatIntelWhitelistModel();
    }

    // =========================================================================
    // RFC 1918 / RFC 6598 Private & Bogon IP Protection
    // =========================================================================

    /**
     * Check if an IP or CIDR is within a private, loopback, or reserved network.
     * Guaranteed to prevent accidental blacklisting of appliance/LAN subnets.
     */
    public function isPrivateOrReservedIp(string $ipOrCidr): bool
    {
        $ip = trim(explode('/', $ipOrCidr)[0]);
        $long = ip2long($ip);
        if ($long === false) {
            return true; // Malformed IP treated as reserved to prevent injection
        }

        // Unsigned representation
        $uLong = sprintf('%u', $long);

        $reservedRanges = [
            ['0.0.0.0', '0.255.255.255'],       // Current network (RFC 1122)
            ['10.0.0.0', '10.255.255.255'],     // Private-Use (RFC 1918)
            ['100.64.0.0', '100.127.255.255'],  // Carrier-Grade NAT (RFC 6598)
            ['127.0.0.0', '127.255.255.255'],   // Loopback (RFC 1122)
            ['169.254.0.0', '169.254.255.255'], // Link-Local (RFC 3927)
            ['172.16.0.0', '172.31.255.255'],   // Private-Use (RFC 1918)
            ['192.0.0.0', '192.0.0.255'],       // IETF Protocol Assignments
            ['192.168.0.0', '192.168.255.255'], // Private-Use (RFC 1918)
            ['198.18.0.0', '198.19.255.255'],   // Benchmarking (RFC 2544)
            ['224.0.0.0', '239.255.255.255'],   // Multicast (RFC 5771)
            ['240.0.0.0', '255.255.255.254'],   // Reserved (RFC 1112)
            ['255.255.255.255', '255.255.255.255'], // Limited Broadcast
        ];

        foreach ($reservedRanges as [$start, $end]) {
            $startLong = sprintf('%u', ip2long($start));
            $endLong   = sprintf('%u', ip2long($end));

            if ($uLong >= $startLong && $uLong <= $endLong) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate an IPv4 address or CIDR notation.
     */
    public function isValidIpOrCidr(string $ipOrCidr): bool
    {
        $parts = explode('/', trim($ipOrCidr));
        if (! filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (isset($parts[1])) {
            $prefix = (int) $parts[1];
            return $prefix >= 1 && $prefix <= 32;
        }

        return true;
    }

    /**
     * Validate a Fully Qualified Domain Name (FQDN) or wildcard domain.
     */
    public function isValidFqdn(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if (str_contains($domain, '/') || str_contains($domain, ':') || str_contains($domain, ' ')) {
            return false;
        }

        $clean = ltrim($domain, '*.');
        if (empty($clean) || strlen($clean) > 253 || ! str_contains($clean, '.')) {
            return false;
        }

        // Must not start or end with a hyphen or dot, or contain consecutive dots
        if (str_starts_with($clean, '-') || str_ends_with($clean, '-') || str_contains($clean, '..')) {
            return false;
        }

        return (bool) filter_var($clean, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    /**
     * Detect indicator type: 'ipv4', 'ipv4_cidr', 'fqdn', or 'invalid'.
     */
    public function detectIndicatorType(string $val): string
    {
        $val = trim($val);
        if ($this->isValidIpOrCidr($val)) {
            return str_contains($val, '/') ? 'ipv4_cidr' : 'ipv4';
        }
        if ($this->isValidFqdn($val)) {
            return 'fqdn';
        }
        return 'invalid';
    }

    // =========================================================================
    // Feed Parsers
    // =========================================================================

    /**
     * Parse AbuseIPDB Blacklist JSON or plaintext response.
     *
     * @return list<array{ip: string, score: int, type: string}>
     */
    public function parseAbuseIpDbResponse(string $body, int $minConfidence = 80): array
    {
        $indicators = [];
        $data = json_decode($body, true);

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $item) {
                $ip = $item['ipAddress'] ?? null;
                $score = (int) ($item['abuseConfidenceScore'] ?? 100);
                if ($ip && $score >= $minConfidence) {
                    $indicators[] = [
                        'ip'    => $ip,
                        'score' => $score,
                        'type'  => 'malicious_ip',
                    ];
                }
            }
        } else {
            // Fallback plain newline list
            $indicators = $this->parseGenericTextList($body, $minConfidence, 'malicious_ip');
        }

        return $indicators;
    }

    /**
     * Parse AlienVault OTX Pulse JSON response.
     *
     * @return list<array{ip: string, score: int, type: string}>
     */
    public function parseAlienVaultOtxResponse(string $body, int $minConfidence = 85): array
    {
        $indicators = [];
        $data = json_decode($body, true);

        if (is_array($data)) {
            $pulses = $data['results'] ?? ($data['pulses'] ?? [$data]);
            foreach ($pulses as $pulse) {
                $items = $pulse['indicators'] ?? [];
                foreach ($items as $ind) {
                    $type = strtolower($ind['type'] ?? '');
                    if (in_array($type, ['ipv4', 'ipv4_addr', 'ip'], true)) {
                        $ip = trim($ind['indicator'] ?? '');
                        if ($ip) {
                            $indicators[] = [
                                'ip'    => $ip,
                                'score' => $minConfidence,
                                'type'  => 'alienvault_otx_pulse',
                            ];
                        }
                    }
                }
            }
        }

        return $indicators;
    }

    /**
     * Parse MISP CSV or JSON export.
     *
     * @return list<array{ip: string, score: int, type: string}>
     */
    public function parseMispResponse(string $body, int $minConfidence = 80): array
    {
        $indicators = [];
        $trimmed = trim($body);

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $data = json_decode($trimmed, true);
            $attribs = $data['response']['Attribute'] ?? ($data['attributes'] ?? []);
            foreach ($attribs as $attr) {
                $type = $attr['type'] ?? '';
                if (str_contains($type, 'ip')) {
                    $val = trim($attr['value'] ?? '');
                    if ($val) {
                        $indicators[] = [
                            'ip'    => $val,
                            'score' => $minConfidence,
                            'type'  => 'misp_ioc',
                        ];
                    }
                }
            }
        } else {
            // CSV lines
            $lines = explode("\n", $trimmed);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $cols = str_getcsv($line);
                $ip = trim($cols[0] ?? '');
                if ($ip && $this->isValidIpOrCidr($ip)) {
                    $indicators[] = [
                        'ip'    => $ip,
                        'score' => $minConfidence,
                        'type'  => 'misp_ioc',
                    ];
                }
            }
        }

        return $indicators;
    }

    /**
     * Parse Spamhaus DROP / EDROP text file.
     * Example line: "198.51.100.0/24 ; SBL123456"
     *
     * @return list<array{ip: string, score: int, type: string}>
     */
    public function parseSpamhausDrop(string $body, int $minConfidence = 100): array
    {
        $indicators = [];
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }

            // Strip comment after semicolon
            $parts = explode(';', $line);
            $cidr = trim($parts[0]);

            if ($cidr && $this->isValidIpOrCidr($cidr)) {
                $indicators[] = [
                    'ip'    => $cidr,
                    'score' => $minConfidence,
                    'type'  => 'spamhaus_drop',
                ];
            }
        }

        return $indicators;
    }

    /**
     * Generic line-by-line IP/CIDR parser.
     *
     * @return list<array{ip: string, score: int, type: string}>
     */
    public function parseGenericTextList(string $body, int $score = 90, string $type = 'blocklist'): array
    {
        $indicators = [];
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//') || str_starts_with($line, ';')) {
                continue;
            }

            // In case of inline comments or whitespace separated columns
            $cols = preg_split('/\s+/', $line);
            $ip = $cols[0] ?? '';

            if ($ip && $this->isValidIpOrCidr($ip)) {
                $indicators[] = [
                    'ip'    => $ip,
                    'score' => $score,
                    'type'  => $type,
                ];
            }
        }

        return $indicators;
    }

    // =========================================================================
    // Sync & Ingest Workflow
    // =========================================================================

    /**
     * Sync a single threat intelligence feed.
     */
    public function syncFeed(array $feed): int
    {
        $settings = $this->settingsModel->getSettings();
        $safeguard = ! empty($settings['safeguard_rfc1918']);
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + (($settings['auto_prune_days'] ?? 7) * 86400));

        $url = $feed['url'] ?? '';
        if (empty($url)) {
            throw new Exception("Feed '{$feed['name']}' has no URL specified.");
        }

        // Prepare request headers
        $headers = [
            'User-Agent: MicronOS-ThreatIntel/1.0 (Enterprise Firewall)',
            'Accept: application/json, text/plain, */*',
        ];

        if (! empty($feed['api_key'])) {
            if ($feed['feed_type'] === 'abuseipdb') {
                $headers[] = 'Key: ' . $feed['api_key'];
            } elseif ($feed['feed_type'] === 'alienvault_otx') {
                $headers[] = 'X-OTX-API-KEY: ' . $feed['api_key'];
            } else {
                $headers[] = 'Authorization: ' . $feed['api_key'];
            }
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers) . "\r\n",
                'timeout' => 15,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->feedModel->update($feed['id'], [
                'last_sync_at' => $now,
                'last_status'  => 'failed',
                'last_error'   => "Unable to connect or download from feed URL: {$url}",
            ]);
            throw new Exception("Unable to download from {$url}");
        }

        // Parse according to feed type
        $minConfidence = (int) ($feed['min_confidence'] ?? 80);
        $rawItems = match ($feed['feed_type']) {
            'abuseipdb'        => $this->parseAbuseIpDbResponse($response, $minConfidence),
            'alienvault_otx'   => $this->parseAlienVaultOtxResponse($response, $minConfidence),
            'misp'             => $this->parseMispResponse($response, $minConfidence),
            'spamhaus_drop'    => $this->parseSpamhausDrop($response, $minConfidence),
            'emerging_threats' => $this->parseGenericTextList($response, $minConfidence, 'emerging_threats'),
            default            => $this->parseGenericTextList($response, $minConfidence, 'custom_feed'),
        };

        // Filter and sanitize items
        $sanitized = [];
        foreach ($rawItems as $item) {
            $val = trim($item['ip']);
            if (! $this->isValidIpOrCidr($val)) {
                continue;
            }

            // Hard security guard: never block RFC 1918 / bogons
            if ($safeguard && $this->isPrivateOrReservedIp($val)) {
                continue;
            }

            // Skip if whitelisted
            if ($this->whitelistModel->isWhitelisted($val)) {
                continue;
            }

            $sanitized[$val] = [
                'ip'    => $val,
                'score' => $item['score'] ?? $minConfidence,
                'type'  => $item['type'] ?? 'malicious_ip',
            ];
        }

        // Clear existing indicators for this feed and bulk insert
        $this->db->table('threat_indicators')->where('feed_id', $feed['id'])->delete();

        $insertedCount = 0;
        $batch = [];
        foreach ($sanitized as $item) {
            $batch[] = [
                'id'               => 'ti_' . bin2hex(random_bytes(8)),
                'feed_id'          => $feed['id'],
                'indicator_value'  => $item['ip'],
                'indicator_type'   => str_contains($item['ip'], '/') ? 'ipv4_cidr' : 'ipv4',
                'threat_type'      => $item['type'],
                'confidence_score' => $item['score'],
                'first_seen'       => $now,
                'last_seen'        => $now,
                'expires_at'       => $expiresAt,
                'created_at'       => $now,
            ];

            if (count($batch) >= 200) {
                $this->db->table('threat_indicators')->insertBatch($batch);
                $insertedCount += count($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->db->table('threat_indicators')->insertBatch($batch);
            $insertedCount += count($batch);
        }

        // Update feed metadata
        $this->feedModel->update($feed['id'], [
            'last_sync_at'    => $now,
            'last_status'     => 'success',
            'last_error'      => null,
            'indicator_count' => $insertedCount,
        ]);

        return $insertedCount;
    }

    /**
     * Sync all enabled threat feeds.
     */
    public function syncAllFeeds(): array
    {
        $feeds = $this->feedModel->getEnabledFeeds();
        $results = [
            'total_feeds'      => count($feeds),
            'successful_feeds' => 0,
            'failed_feeds'     => 0,
            'total_indicators' => 0,
            'errors'           => [],
        ];

        foreach ($feeds as $f) {
            try {
                $count = $this->syncFeed($f);
                $results['successful_feeds']++;
                $results['total_indicators'] += $count;
            } catch (Exception $e) {
                $results['failed_feeds']++;
                $results['errors'][$f['slug']] = $e->getMessage();
            }
        }

        // Compile updated nftables set after sync
        $this->compileNftablesConfig();

        return $results;
    }

    // =========================================================================
    // nftables Ruleset Compilation
    // =========================================================================

    /**
     * Compile active indicators into an atomic nftables ruleset string.
     */
    public function compileNftablesConfig(): string
    {
        $settings = $this->settingsModel->getSettings();
        $setName  = $settings['nft_set_name'] ?? 'threat_intel_ipv4';
        $action   = $settings['action'] ?? 'drop';
        $logPkt   = ! empty($settings['log_blocked_packets']);

        $indicators = $this->indicatorModel->getActiveIpIndicators();
        $elements = [];
        foreach ($indicators as $ind) {
            $val = trim($ind['indicator_value']);
            if ($val !== '' && ! in_array($val, $elements, true)) {
                $elements[] = $val;
            }
        }

        $elementsFormatted = empty($elements)
            ? '        # No active indicators'
            : implode(",\n        ", $elements);

        $logRuleSrc = $logPkt ? "        ip saddr @{$setName} counter log prefix \"[TI-BLOCK-SRC] \" {$action}\n" : "        ip saddr @{$setName} counter {$action}\n";
        $logRuleDst = $logPkt ? "        ip daddr @{$setName} counter log prefix \"[TI-BLOCK-DST] \" {$action}\n" : "        ip daddr @{$setName} counter {$action}\n";
        $elemCount = count($elements);

        return <<<NFT
# ==============================================================================
# Micron OS Dynamic Threat Intelligence Engine — nftables Rule Set
# Generated: auto-compiled by ThreatIntelEngine
# Action: {$action} | Elements: {$elemCount}
# ==============================================================================

table inet micronos {
    set {$setName} {
        type ipv4_addr
        flags interval
        elements = {
        {$elementsFormatted}
        }
    }

    chain threat_intel_prerouting {
        type filter hook prerouting priority -150; policy accept;
{$logRuleSrc}
    }

    chain threat_intel_forward {
        type filter hook forward priority -5; policy accept;
{$logRuleSrc}{$logRuleDst}
    }
}
NFT;
    }

    /**
     * Write compiled nftables config to filesystem.
     */
    public function writeNftablesConfigFile(?string $customPath = null): string
    {
        $path = $customPath ?? '/etc/micronos/nftables/threat_intel.nft';
        $content = $this->compileNftablesConfig();

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($path, $content);

        return $path;
    }

    // =========================================================================
    // Manual IoCs & FQDN DNSBL Sinkholing
    // =========================================================================

    /**
     * Manually add an IoC / blacklisted IP, CIDR, or FQDN.
     */
    public function addManualIndicator(
        string $value,
        string $threatType = 'custom_blacklist',
        int $confidence = 100,
        ?string $comment = null,
        ?string $expiresAt = null
    ): array {
        $value = trim($value);
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $parsed = parse_url($value, PHP_URL_HOST);
            if ($parsed) {
                $value = $parsed;
            }
        }
        $value = strtolower($value);

        $type = $this->detectIndicatorType($value);
        if ($type === 'invalid') {
            throw new \InvalidArgumentException("Invalid indicator format: '{$value}'. Must be a valid IPv4 address, CIDR block, or FQDN/domain.");
        }

        $settings = $this->settingsModel->getSettings();
        if (! empty($settings['safeguard_rfc1918']) && in_array($type, ['ipv4', 'ipv4_cidr'], true)) {
            if ($this->isPrivateOrReservedIp($value)) {
                throw new \InvalidArgumentException("IP or CIDR '{$value}' is in a private RFC 1918 / reserved range and cannot be blacklisted.");
            }
        }

        if ($this->whitelistModel->isWhitelisted($value)) {
            throw new \InvalidArgumentException("Indicator '{$value}' is currently on the whitelist. Remove from whitelist first.");
        }

        $now = date('Y-m-d H:i:s');
        $feedId = 'feed_manual_ioc';

        // Check if manual feed exists, create if not
        if ($this->feedModel->where('id', $feedId)->countAllResults() === 0) {
            $this->feedModel->insert([
                'id'                    => $feedId,
                'name'                  => 'Manual Administrator IoC Blacklist',
                'slug'                  => 'manual-ioc',
                'feed_type'             => 'manual',
                'url'                   => null,
                'api_key'               => null,
                'min_confidence'        => 100,
                'update_interval_hours' => 0,
                'enabled'               => 1,
                'last_sync_at'          => $now,
                'last_status'           => 'success',
                'indicator_count'       => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }

        // Check if already exists in threat_indicators
        $existing = $this->indicatorModel->where('indicator_value', $value)->first();
        if ($existing) {
            $this->indicatorModel->update($existing['id'], [
                'threat_type'      => $threatType,
                'confidence_score' => $confidence,
                'comment'          => $comment,
                'last_seen'        => $now,
                'expires_at'       => $expiresAt,
            ]);
            $record = array_merge($existing, [
                'threat_type'      => $threatType,
                'confidence_score' => $confidence,
                'comment'          => $comment,
                'last_seen'        => $now,
                'expires_at'       => $expiresAt,
            ]);
        } else {
            $record = [
                'id'               => 'ti_man_' . bin2hex(random_bytes(6)),
                'feed_id'          => $feedId,
                'indicator_value'  => $value,
                'indicator_type'   => $type,
                'threat_type'      => $threatType,
                'confidence_score' => $confidence,
                'comment'          => $comment,
                'first_seen'       => $now,
                'last_seen'        => $now,
                'expires_at'       => $expiresAt,
                'created_at'       => $now,
            ];
            $this->indicatorModel->insert($record);
        }

        // Recompile firewall sets & DNSBL
        $this->compileNftablesConfig();
        $this->compileDnsblConfig();

        // Update indicator count on manual feed
        $manualCount = $this->indicatorModel->where('feed_id', $feedId)->countAllResults();
        $this->feedModel->update($feedId, [
            'indicator_count' => $manualCount,
            'last_sync_at'    => $now,
        ]);

        return $record;
    }

    /**
     * Delete a manual IoC indicator.
     */
    public function deleteManualIndicator(string $id): bool
    {
        $item = $this->indicatorModel->find($id);
        if (! $item) {
            return false;
        }

        $this->indicatorModel->delete($id);

        $this->compileNftablesConfig();
        $this->compileDnsblConfig();

        $manualCount = $this->indicatorModel->where('feed_id', 'feed_manual_ioc')->countAllResults();
        $this->feedModel->update('feed_manual_ioc', [
            'indicator_count' => $manualCount,
            'last_sync_at'    => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Compile active FQDN/domain indicators into Unbound DNSBL sinkhole configuration.
     */
    public function compileDnsblConfig(): string
    {
        $domains = $this->indicatorModel->getActiveFqdnIndicators();
        $lines = [
            '# ============================================================================== ',
            '# Micron OS Threat Intelligence — DNSBL FQDN Sinkhole Rules',
            '# Generated by ThreatIntelEngine (Unbound local-zone definitions)',
            '# Total Domains: ' . count($domains),
            '# ============================================================================== ',
            '',
        ];

        foreach ($domains as $d) {
            $dom = trim($d['indicator_value']);
            if ($dom !== '') {
                $lines[] = 'local-zone: "' . $dom . '" static';
                $lines[] = 'local-data: "' . $dom . ' A 0.0.0.0"';
                $lines[] = 'local-data: "' . $dom . ' AAAA ::"';
            }
        }

        $content = implode("\n", $lines) . "\n";
        $path = '/etc/unbound/threat_intel_domains.conf';
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $content);

        return $content;
    }
}
