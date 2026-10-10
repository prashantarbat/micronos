<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * WebfilterSettingsModel — Enterprise Web Proxy & Content Filtering engine configuration
 */
class WebfilterSettingsModel extends Model
{
    protected $table         = 'webfilter_settings';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'enabled',
        'mode',
        'http_port',
        'https_port',
        'ssl_port',
        'interfaces',
        'ipv6_enabled',
        'visible_hostname',
        'admin_email',
        'dns_nameservers',
        'forwarded_for',
        'suppress_headers',
        'log_client_ip',
        'log_user_agent',
        'log_query_terms',
        'log_retention_days',
        'max_upload_size_mb',
        'max_download_size_mb',
        'max_client_connections',
        'allowed_subnets',
        'unrestricted_ips',
        'banned_ips',
        'banned_user_agents',
        'banned_extensions',
        'banned_mime_types',
        'custom_whitelist',
        'custom_blacklist',
        'custom_regex_blacklist',
        'safesearch_enabled',
        'youtube_restricted_mode',
        'block_page_title',
        'block_page_message',
        'block_page_redirect_url',
        'ssl_bump_enabled',
        'ssl_inspection_mode',
        'ca_cert_path',
        'ca_key_path',
        'ssl_bypass_domains',
        'ssl_min_tls_version',
        'cache_enabled',
        'cache_mem_mb',
        'cache_size_mb',
        'cache_max_obj_kb',
        'cache_replacement_policy',
        'cache_ignore_queries',
        // Squid refresh_pattern freshness controls
        'cache_refresh_enabled',
        'cache_refresh_min_minutes',
        'cache_refresh_percent',
        'cache_refresh_max_minutes',
        'cache_refresh_ignore_reload',
        'cache_refresh_ignore_no_store',
        'cache_refresh_ignore_private',
        'cache_refresh_reload_into_ims',
        'cache_refresh_custom',
        'cache_update_preset_enabled',
        'cache_do_not_cache',
        // ICAP antivirus inspection (C-ICAP + ClamAV)
        'icap_antivirus_enabled',
        'icap_host',
        'icap_port',
        'icap_service',
        'icap_scan_mode',
        'icap_preview_kb',
        'icap_max_file_mb',
        'icap_failure_mode',
        'icap_scan_https',
        'icap_bypass_domains',
        'clamav_update_schedule',
        'clamav_last_update_at',
        'clamav_signatures',
        'clamav_defs_version',
        'clamav_last_update_status',
        // WPAD / PAC
        'wpad_enabled',
        'wpad_proxy_host',
        'wpad_proxy_port',
        'wpad_failover_proxy',
        'wpad_fallback_policy',
        'wpad_bypass_plain_hosts',
        'wpad_bypass_rfc1918',
        'wpad_bypass_networks',
        'wpad_bypass_domains',
        'wpad_dhcp_option252',
        'wpad_dns_override',
        'wpad_anti_bypass_firewall',
        'updated_at',
    ];
    protected $useTimestamps = false;

    public function defaultSettings(): array
    {
        return [
            'id'                        => 1,
            'enabled'                   => 1,
            'mode'                      => 'transparent_sni',
            'http_port'                 => 3128,
            'https_port'                => 3129,
            'ssl_port'                  => 3130,
            'interfaces'                => ['lan'],
            'ipv6_enabled'              => 0,
            'visible_hostname'          => 'micronos-proxy',
            'admin_email'               => 'admin@micronos.local',
            'dns_nameservers'           => '',
            'forwarded_for'             => 'on',
            'suppress_headers'          => 0,
            'log_client_ip'             => 1,
            'log_user_agent'            => 1,
            'log_query_terms'           => 0,
            'log_retention_days'        => 30,
            'max_upload_size_mb'        => 50,
            'max_download_size_mb'      => 2048,
            'max_client_connections'    => 150,
            'allowed_subnets'           => "192.168.0.0/16\n10.0.0.0/8\n172.16.0.0/12\n127.0.0.1/32",
            'unrestricted_ips'          => "192.168.1.50\n192.168.1.51",
            'banned_ips'                => "192.168.1.240",
            'banned_user_agents'        => "curl*\nwget*\n*BitTorrent*\n*libwww-perl*\n*python-requests*",
            'banned_extensions'         => ".exe\n.msi\n.bat\n.iso\n.torrent\n.scr\n.vbs",
            'banned_mime_types'         => "application/x-bittorrent\napplication/x-dosexec\napplication/x-executable",
            'custom_whitelist'          => "micronos.test\ngithub.com\n*.microsoft.com\n*.google.com\n*.apple.com",
            'custom_blacklist'          => "*.doubleclick.net\n*.ads-tracker.xyz\n*.cryptominer-pool.online\n*.torrent-tracker.org",
            'custom_regex_blacklist'    => "^https?://.*(\\.onion|\\.ru/badware).*\n^https?://.*\\/torrent\\/.*",
            'safesearch_enabled'        => 1,
            'youtube_restricted_mode'   => 'moderate',
            'block_page_title'          => 'Access Restricted by Corporate Policy',
            'block_page_message'        => 'This web destination has been restricted in compliance with corporate security, acceptable use, and threat prevention policies.',
            'block_page_redirect_url'   => '',
            'ssl_bump_enabled'          => 0,
            'ssl_inspection_mode'       => 'transparent_sni',
            'ca_cert_path'              => '/etc/squid/certs/micron_ca.pem',
            'ca_key_path'               => '/etc/squid/certs/micron_ca.key',
            'ssl_bypass_domains'        => "*.paypal.com\n*.chase.com\n*.bankofamerica.com\n*.wellsfargo.com\n*.apple.com\n*.windowsupdate.com\n*.office365.com\n*.login.microsoftonline.com",
            'ssl_min_tls_version'       => '1.2',
            'cache_enabled'             => 1,
            'cache_mem_mb'              => 256,
            'cache_size_mb'             => 4096,
            'cache_max_obj_kb'          => 4096,
            'cache_replacement_policy'  => 'heap LFUDA',
            'cache_ignore_queries'      => 1,
            'cache_refresh_enabled'         => 1,
            'cache_refresh_min_minutes'     => 0,
            'cache_refresh_percent'         => 20,
            'cache_refresh_max_minutes'     => 4320,
            'cache_refresh_ignore_reload'   => 0,
            'cache_refresh_ignore_no_store' => 1,
            'cache_refresh_ignore_private'  => 0,
            'cache_refresh_reload_into_ims' => 0,
            'cache_refresh_custom'          => "refresh_pattern ^ftp:             1440  20%  10080\nrefresh_pattern ^gopher:          1440  0%   1440\nrefresh_pattern -i (/cgi-bin/|\\?) 0    0%   0",
            'cache_update_preset_enabled'   => 0,
            'cache_do_not_cache'            => "",
            'icap_antivirus_enabled'    => 1,
            'icap_host'                 => '127.0.0.1',
            'icap_port'                 => 1344,
            'icap_service'              => 'squidclamav',
            'icap_scan_mode'            => 'response',
            'icap_preview_kb'           => 1024,
            'icap_max_file_mb'          => 25,
            'icap_failure_mode'         => 'allow',
            'icap_scan_https'           => 1,
            'icap_bypass_domains'       => "*.windowsupdate.com\n*.apple.com\n*.bankofamerica.com",
            'clamav_update_schedule'    => 'daily',
            'clamav_last_update_at'     => null,
            'clamav_signatures'         => 8796412,
            'clamav_defs_version'       => '20261005',
            'clamav_last_update_status' => 'Definitions up to date (simulated)',
            'wpad_enabled'              => 1,
            'wpad_proxy_host'           => '192.168.1.1',
            'wpad_proxy_port'           => 3128,
            'wpad_failover_proxy'       => '',
            'wpad_fallback_policy'      => 'DIRECT',
            'wpad_bypass_plain_hosts'   => 1,
            'wpad_bypass_rfc1918'       => 1,
            'wpad_bypass_networks'      => "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16\n127.0.0.1/32\n169.254.0.0/16",
            'wpad_bypass_domains'       => "*.local\n*.corp\n*.internal\n*.lan\n*.micronos.test",
            'wpad_dhcp_option252'       => 1,
            'wpad_dns_override'         => 1,
            'wpad_anti_bypass_firewall' => 0,
        ];
    }

    public function getSettings(): array
    {
        $row = $this->find(1);
        if (!$row) {
            $defaults = $this->defaultSettings();
            $dbData = $defaults;
            $dbData['interfaces'] = json_encode($defaults['interfaces']);
            $dbData['updated_at'] = date('Y-m-d H:i:s');
            $this->insert($dbData);
            return $defaults;
        }

        $row['interfaces'] = is_string($row['interfaces'] ?? null)
            ? (json_decode($row['interfaces'], true) ?: ['lan'])
            : ($row['interfaces'] ?? ['lan']);

        return $row;
    }

    public function saveSettings(array $data): bool
    {
        if (isset($data['interfaces']) && is_array($data['interfaces'])) {
            $data['interfaces'] = json_encode(array_values(array_unique(array_filter($data['interfaces']))));
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($this->find(1)) {
            return (bool) $this->update(1, $data);
        }

        $data['id'] = 1;
        return (bool) $this->insert($data);
    }
}
