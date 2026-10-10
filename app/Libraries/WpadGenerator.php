<?php

namespace App\Libraries;

/**
 * WpadGenerator — Dynamic WPAD (Web Proxy Auto-Discovery) & PAC Script Compiler
 * ============================================================================
 * Implements and turnkey-automates the proxy auto-config architecture described in
 * the Nguvu proxy guide:
 *  - Automatic serving of wpad.dat, wpad.da, proxy.pac, and wpad.pac
 *  - RFC1918 internal subnets & plain hostname direct connection bypasses
 *  - Dynamic proxy failover chains and strict egress lockouts
 *  - In-engine PAC evaluation simulator for real-time testing
 */
class WpadGenerator
{
    /**
     * Generate standard compliant PAC JavaScript
     */
    public function generatePac(array $settings, ?string $requestHost = null): string
    {
        $proxyHost = !empty($settings['wpad_proxy_host']) ? trim($settings['wpad_proxy_host']) : ($requestHost ?: '192.168.1.1');
        $proxyPort = (int) ($settings['wpad_proxy_port'] ?? 3128);
        $failoverHost = !empty($settings['wpad_failover_proxy']) ? trim($settings['wpad_failover_proxy']) : '';
        $fallbackPolicy = ($settings['wpad_fallback_policy'] ?? 'DIRECT') === 'FAIL_SECURE' ? '' : '; DIRECT';

        $proxyChain = "PROXY {$proxyHost}:{$proxyPort}";
        if ($failoverHost !== '') {
            $proxyChain .= "; PROXY {$failoverHost}";
        }
        $proxyChain .= $fallbackPolicy;

        $bypassPlain = !empty($settings['wpad_bypass_plain_hosts']);
        $bypassRfc1918 = !empty($settings['wpad_bypass_rfc1918']);

        $customDomains = $this->parseLines($settings['wpad_bypass_domains'] ?? '');
        $customNetworks = $this->parseLines($settings['wpad_bypass_networks'] ?? '');

        $lines = [];
        $lines[] = '/**';
        $lines[] = ' * MicronOS Gateway — Web Proxy Auto-Discovery (WPAD / PAC)';
        $lines[] = ' * Generated: ' . date('Y-m-d H:i:s T');
        $lines[] = ' * Primary Proxy: ' . "{$proxyHost}:{$proxyPort}";
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'function FindProxyForURL(url, host) {';
        $lines[] = '    // 0. Normalize hostname to lower-case';
        $lines[] = '    host = host.toLowerCase();';
        $lines[] = '';

        // 1. Plain hostname bypass
        if ($bypassPlain) {
            $lines[] = '    // 1. Bypass Proxy for Plain Hostnames (e.g. http://intranet/)';
            $lines[] = '    if (isPlainHostName(host)) {';
            $lines[] = '        return "DIRECT";';
            $lines[] = '    }';
            $lines[] = '';
        }

        // 2. Custom Domain Bypasses
        if (!empty($customDomains)) {
            $lines[] = '    // 2. Custom Domain & FQDN Bypasses';
            $domainChecks = [];
            foreach ($customDomains as $domain) {
                $domain = strtolower(trim($domain));
                if ($domain === '') continue;
                if (str_starts_with($domain, '*.')) {
                    $domainChecks[] = 'shExpMatch(host, "' . $domain . '")';
                    $domainChecks[] = 'host === "' . substr($domain, 2) . '"';
                } elseif (str_starts_with($domain, '.')) {
                    $domainChecks[] = 'dnsDomainIs(host, "' . $domain . '")';
                } else {
                    $domainChecks[] = 'host === "' . $domain . '" || dnsDomainIs(host, ".' . $domain . '")';
                }
            }
            if (!empty($domainChecks)) {
                $lines[] = '    if (' . implode(" ||\n        ", $domainChecks) . ') {';
                $lines[] = '        return "DIRECT";';
                $lines[] = '    }';
                $lines[] = '';
            }
        }

        // 3. RFC 1918 Private & Local Subnet Bypasses
        if ($bypassRfc1918 || !empty($customNetworks)) {
            $lines[] = '    // 3. Resolve host to check against internal IP subnet ranges';
            $lines[] = '    var resolved_ip = dnsResolve(host);';
            $lines[] = '    if (resolved_ip) {';

            $subnetChecks = [];
            if ($bypassRfc1918) {
                $subnetChecks[] = 'isInNet(resolved_ip, "10.0.0.0", "255.0.0.0")';
                $subnetChecks[] = 'isInNet(resolved_ip, "172.16.0.0", "255.240.0.0")';
                $subnetChecks[] = 'isInNet(resolved_ip, "192.168.0.0", "255.255.0.0")';
                $subnetChecks[] = 'isInNet(resolved_ip, "127.0.0.0", "255.0.0.0")';
                $subnetChecks[] = 'isInNet(resolved_ip, "169.254.0.0", "255.255.0.0")';
            }

            foreach ($customNetworks as $net) {
                $cidrParts = explode('/', trim($net));
                if (count($cidrParts) === 2 && filter_var($cidrParts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = $this->cidrToNetmask((int) $cidrParts[1]);
                    $subnetChecks[] = 'isInNet(resolved_ip, "' . $cidrParts[0] . '", "' . $mask . '")';
                }
            }

            if (!empty($subnetChecks)) {
                $lines[] = '        if (' . implode(" ||\n            ", $subnetChecks) . ') {';
                $lines[] = '            return "DIRECT";';
                $lines[] = '        }';
            }

            $lines[] = '    }';
            $lines[] = '';
        }

        // 4. Fallback Default Proxy
        $lines[] = '    // 4. Default: Route Web Traffic via Enterprise Proxy Gateway';
        $lines[] = '    return "' . $proxyChain . '";';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Simulate PAC script evaluation for administrative testing and validation
     */
    public function simulate(string $testUrl, array $settings, ?string $requestHost = null): array
    {
        $testUrl = trim($testUrl);
        if ($testUrl === '') {
            return [
                'success'     => false,
                'error'       => 'Please enter a valid URL or hostname to test.',
            ];
        }

        if (!preg_match('#^https?://#i', $testUrl)) {
            $parsed = parse_url('http://' . $testUrl);
        } else {
            $parsed = parse_url($testUrl);
        }

        $host = strtolower($parsed['host'] ?? $testUrl);
        $proxyHost = !empty($settings['wpad_proxy_host']) ? trim($settings['wpad_proxy_host']) : ($requestHost ?: '192.168.1.1');
        $proxyPort = (int) ($settings['wpad_proxy_port'] ?? 3128);
        $failoverHost = !empty($settings['wpad_failover_proxy']) ? trim($settings['wpad_failover_proxy']) : '';
        $fallbackPolicy = ($settings['wpad_fallback_policy'] ?? 'DIRECT') === 'FAIL_SECURE' ? '' : '; DIRECT';

        $proxyChain = "PROXY {$proxyHost}:{$proxyPort}";
        if ($failoverHost !== '') {
            $proxyChain .= "; PROXY {$failoverHost}";
        }
        $proxyChain .= $fallbackPolicy;

        // Step 1: Plain Hostname check
        if (!empty($settings['wpad_bypass_plain_hosts']) && !str_contains($host, '.')) {
            return [
                'success'     => true,
                'host'        => $host,
                'matched_rule'=> 'Rule 1: Plain Hostname Bypass',
                'decision'    => 'DIRECT',
                'description' => "Hostname '{$host}' contains no dots, indicating a local single-label intranet service. Traffic bypasses proxy directly.",
            ];
        }

        // Step 2: Custom domain bypasses
        $customDomains = $this->parseLines($settings['wpad_bypass_domains'] ?? '');
        foreach ($customDomains as $pattern) {
            $cleanPattern = strtolower(trim($pattern));
            if ($cleanPattern === '') continue;

            if ($this->matchDomain($host, $cleanPattern)) {
                return [
                    'success'     => true,
                    'host'        => $host,
                    'matched_rule'=> "Rule 2: Domain Bypass Match ({$pattern})",
                    'decision'    => 'DIRECT',
                    'description' => "Destination '{$host}' matched configured direct bypass pattern '{$pattern}'. Client connects directly.",
                ];
            }
        }

        // Step 3: IP / RFC1918 check
        $resolvedIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : null;
        if (!empty($settings['wpad_bypass_rfc1918'])) {
            $rfc1918Subnets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16'];
            if ($resolvedIp) {
                foreach ($rfc1918Subnets as $subnet) {
                    if ($this->ipInSubnet($resolvedIp, $subnet)) {
                        return [
                            'success'     => true,
                            'host'        => $host,
                            'matched_rule'=> "Rule 3: RFC 1918 Private Subnet ({$subnet})",
                            'decision'    => 'DIRECT',
                            'description' => "Target IP {$resolvedIp} is inside private network range {$subnet}. Traffic connects directly without proxying.",
                        ];
                    }
                }
            }
        }

        // Custom Subnets
        $customNetworks = $this->parseLines($settings['wpad_bypass_networks'] ?? '');
        if ($resolvedIp) {
            foreach ($customNetworks as $subnet) {
                if ($this->ipInSubnet($resolvedIp, $subnet)) {
                    return [
                        'success'     => true,
                        'host'        => $host,
                        'matched_rule'=> "Rule 3: Custom Bypass Subnet ({$subnet})",
                        'decision'    => 'DIRECT',
                        'description' => "Target IP {$resolvedIp} is inside configured bypass subnet {$subnet}. Traffic connects directly.",
                    ];
                }
            }
        }

        // Step 4: Default Proxy Routing
        return [
            'success'     => true,
            'host'        => $host,
            'matched_rule'=> 'Rule 4: Default Proxy Routing',
            'decision'    => $proxyChain,
            'description' => "Destination '{$host}' is an external or public internet destination. Routing through Enterprise Web Proxy.",
        ];
    }

    private function matchDomain(string $host, string $pattern): bool
    {
        if ($pattern === $host) {
            return true;
        }

        if (str_starts_with($pattern, '*.')) {
            $baseDomain = substr($pattern, 2);
            return $host === $baseDomain || str_ends_with($host, '.' . $baseDomain);
        }

        if (str_starts_with($pattern, '.')) {
            return str_ends_with($host, $pattern) || $host === ltrim($pattern, '.');
        }

        return fnmatch($pattern, $host);
    }

    private function ipInSubnet(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            $range .= '/32';
        }
        [$subnet, $bits] = explode('/', $range);
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) return false;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;

        $mask = -1 << (32 - $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }

    private function cidrToNetmask(int $bits): string
    {
        $bits = max(0, min(32, $bits));
        $long = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return long2ip($long);
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
