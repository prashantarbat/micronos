<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnhanceWebfilterComprehensiveTables extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        // 1. Enhance webfilter_settings table
        $settingsFields = [
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'ssl_port' => [
                'type'    => 'INT',
                'default' => 3130,
            ],
            'interfaces' => [
                'type' => 'TEXT',
                'null' => true, // JSON array of interfaces e.g. ["lan"]
            ],
            'ipv6_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'visible_hostname' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'micronos-proxy',
            ],
            'admin_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'admin@micronos.local',
            ],
            'dns_nameservers' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'forwarded_for' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'on', // 'on', 'off', 'transparent', 'delete', 'truncate'
            ],
            'suppress_headers' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'log_client_ip' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'log_user_agent' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'log_query_terms' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'log_retention_days' => [
                'type'    => 'INT',
                'default' => 30,
            ],
            'max_upload_size_mb' => [
                'type'    => 'INT',
                'default' => 50,
            ],
            'max_download_size_mb' => [
                'type'    => 'INT',
                'default' => 2048,
            ],
            'max_client_connections' => [
                'type'    => 'INT',
                'default' => 150,
            ],
            'allowed_subnets' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'unrestricted_ips' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'banned_ips' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'banned_user_agents' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'banned_extensions' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'banned_mime_types' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'custom_whitelist' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'custom_blacklist' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'custom_regex_blacklist' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'block_page_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'Access Restricted by Corporate Policy',
            ],
            'block_page_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'block_page_redirect_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'ssl_bump_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'ssl_inspection_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'transparent_sni', // 'disabled', 'transparent_sni', 'deep_inspection'
            ],
            'ca_cert_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '/etc/squid/certs/micron_ca.pem',
            ],
            'ca_key_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '/etc/squid/certs/micron_ca.key',
            ],
            'ssl_bypass_domains' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'ssl_min_tls_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => '1.2',
            ],
            'cache_mem_mb' => [
                'type'    => 'INT',
                'default' => 256,
            ],
            'cache_max_obj_kb' => [
                'type'    => 'INT',
                'default' => 4096,
            ],
            'cache_replacement_policy' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'heap LFUDA',
            ],
            'cache_ignore_queries' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            // WPAD / PAC Configuration (Nguvu Simplified)
            'wpad_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'wpad_proxy_host' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'wpad_proxy_port' => [
                'type'    => 'INT',
                'default' => 3128,
            ],
            'wpad_failover_proxy' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'wpad_fallback_policy' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'DIRECT', // 'DIRECT' or 'FAIL_SECURE'
            ],
            'wpad_bypass_plain_hosts' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'wpad_bypass_rfc1918' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'wpad_bypass_networks' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'wpad_bypass_domains' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'wpad_dhcp_option252' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'wpad_dns_override' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'wpad_anti_bypass_firewall' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
        ];

        foreach ($settingsFields as $colName => $colDef) {
            if (!$db->fieldExists($colName, 'webfilter_settings')) {
                $this->forge->addColumn('webfilter_settings', [$colName => $colDef]);
            }
        }

        // Add domain_count to webfilter_categories if missing
        if (!$db->fieldExists('domain_count', 'webfilter_categories')) {
            $this->forge->addColumn('webfilter_categories', [
                'domain_count' => [
                    'type'    => 'INT',
                    'default' => 0,
                ],
            ]);
        }

        // Seed initial values in webfilter_settings if empty
        $existing = $db->table('webfilter_settings')->where('id', 1)->get()->getRowArray();
        $defaultData = [
            'enabled'                   => 1,
            'mode'                      => 'transparent_sni',
            'http_port'                 => 3128,
            'https_port'                => 3129,
            'ssl_port'                  => 3130,
            'interfaces'                => json_encode(['lan']),
            'ipv6_enabled'              => 0,
            'visible_hostname'          => 'micronos-proxy',
            'admin_email'               => 'admin@micronos.local',
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
            'ssl_bump_enabled'          => 0,
            'ssl_inspection_mode'       => 'transparent_sni',
            'ssl_bypass_domains'        => "*.paypal.com\n*.chase.com\n*.bankofamerica.com\n*.wellsfargo.com\n*.apple.com\n*.windowsupdate.com\n*.office365.com\n*.login.microsoftonline.com",
            'ssl_min_tls_version'       => '1.2',
            'cache_enabled'             => 1,
            'cache_mem_mb'              => 256,
            'cache_size_mb'             => 4096,
            'cache_max_obj_kb'          => 4096,
            'cache_replacement_policy'  => 'heap LFUDA',
            'cache_ignore_queries'      => 1,
            'wpad_enabled'              => 1,
            'wpad_proxy_host'           => '192.168.1.1',
            'wpad_proxy_port'           => 3128,
            'wpad_fallback_policy'      => 'DIRECT',
            'wpad_bypass_plain_hosts'   => 1,
            'wpad_bypass_rfc1918'       => 1,
            'wpad_bypass_networks'      => "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16\n127.0.0.1/32\n169.254.0.0/16",
            'wpad_bypass_domains'       => "*.local\n*.corp\n*.internal\n*.lan\n*.micronos.test",
            'wpad_dhcp_option252'       => 1,
            'wpad_dns_override'         => 1,
            'wpad_anti_bypass_firewall' => 0,
            'updated_at'                => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->table('webfilter_settings')->where('id', 1)->update($defaultData);
        } else {
            $defaultData['id'] = 1;
            $db->table('webfilter_settings')->insert($defaultData);
        }

        // 2. Create webfilter_feeds table
        if (!$db->tableExists('webfilter_feeds')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                ],
                'url' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                ],
                'category' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                ],
                'entries_count' => [
                    'type'    => 'INT',
                    'default' => 0,
                ],
                'auto_update' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'last_updated' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'Active',
                ],
                'enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('webfilter_feeds', true);

            // Seed default threat feeds
            $db->table('webfilter_feeds')->insertBatch([
                [
                    'id'            => 'feed_ut1_phishing',
                    'name'          => 'UT1 Toulouse Phishing & Malware Feed',
                    'url'           => 'https://dsi.ut-capitole.fr/blacklists/download/phishing.tar.gz',
                    'category'      => 'cat_malware',
                    'entries_count' => 129402,
                    'auto_update'   => 1,
                    'last_updated'  => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'status'        => 'Synchronized',
                    'enabled'       => 1,
                ],
                [
                    'id'            => 'feed_oisd_big',
                    'name'          => 'OISD Comprehensive Telemetry Blocklist',
                    'url'           => 'https://big.oisd.nl/domainswild',
                    'category'      => 'cat_malware',
                    'entries_count' => 345810,
                    'auto_update'   => 1,
                    'last_updated'  => date('Y-m-d H:i:s', strtotime('-5 hours')),
                    'status'        => 'Synchronized',
                    'enabled'       => 1,
                ],
                [
                    'id'            => 'feed_steven_black',
                    'name'          => 'Unified Malware, Ads & Cryptominers Feed',
                    'url'           => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts',
                    'category'      => 'cat_crypto',
                    'entries_count' => 84210,
                    'auto_update'   => 1,
                    'last_updated'  => date('Y-m-d H:i:s', strtotime('-1 day')),
                    'status'        => 'Synchronized',
                    'enabled'       => 1,
                ],
                [
                    'id'            => 'feed_shallalist_gambling',
                    'name'          => 'Shallalist Online Gambling & Betting Repos',
                    'url'           => 'https://www.shallalist.de/Downloads/shallalist.tar.gz',
                    'category'      => 'cat_gambling',
                    'entries_count' => 45091,
                    'auto_update'   => 1,
                    'last_updated'  => date('Y-m-d H:i:s', strtotime('-3 days')),
                    'status'        => 'Synchronized',
                    'enabled'       => 1,
                ],
            ]);
        }

        // 3. Create webfilter_access_logs table
        if (!$db->tableExists('webfilter_access_logs')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'auto_increment' => true,
                ],
                'timestamp' => [
                    'type' => 'DATETIME',
                ],
                'client_ip' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                ],
                'method' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 10,
                    'default'    => 'GET',
                ],
                'url' => [
                    'type' => 'TEXT',
                ],
                'domain' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                ],
                'http_code' => [
                    'type'    => 'INT',
                    'default' => 200,
                ],
                'squid_action' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'default'    => 'TCP_HIT/200',
                ],
                'filter_action' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 24,
                    'default'    => 'ALLOW', // 'ALLOW', 'BLOCK', 'BYPASS_SSL', 'MIME_DENY'
                ],
                'category' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                ],
                'user_agent' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'bytes_sent' => [
                    'type'    => 'BIGINT',
                    'default' => 0,
                ],
                'duration_ms' => [
                    'type'    => 'INT',
                    'default' => 12,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('webfilter_access_logs', true);

            // Seed realistic sample access logs
            $now = date('Y-m-d H:i:s');
            $db->table('webfilter_access_logs')->insertBatch([
                [
                    'timestamp'     => date('Y-m-d H:i:s', strtotime('-1 minute')),
                    'client_ip'     => '192.168.1.105',
                    'method'        => 'GET',
                    'url'           => 'https://github.com/torvalds/linux',
                    'domain'        => 'github.com',
                    'http_code'     => 200,
                    'squid_action'  => 'TCP_MISS/200',
                    'filter_action' => 'ALLOW',
                    'category'      => 'Development',
                    'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'bytes_sent'    => 148200,
                    'duration_ms'   => 45,
                ],
                [
                    'timestamp'     => date('Y-m-d H:i:s', strtotime('-3 minutes')),
                    'client_ip'     => '192.168.1.142',
                    'method'        => 'CONNECT',
                    'url'           => 'https://malware-drop-site.xyz:443',
                    'domain'        => 'malware-drop-site.xyz',
                    'http_code'     => 403,
                    'squid_action'  => 'TCP_DENIED/403',
                    'filter_action' => 'BLOCK',
                    'category'      => 'Malware & Phishing',
                    'user_agent'    => 'curl/8.4.0',
                    'bytes_sent'    => 3520,
                    'duration_ms'   => 4,
                ],
                [
                    'timestamp'     => date('Y-m-d H:i:s', strtotime('-6 minutes')),
                    'client_ip'     => '192.168.1.88',
                    'method'        => 'GET',
                    'url'           => 'https://online-poker-betting.net/',
                    'domain'        => 'online-poker-betting.net',
                    'http_code'     => 403,
                    'squid_action'  => 'TCP_DENIED/403',
                    'filter_action' => 'BLOCK',
                    'category'      => 'Gambling',
                    'user_agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                    'bytes_sent'    => 3520,
                    'duration_ms'   => 6,
                ],
                [
                    'timestamp'     => date('Y-m-d H:i:s', strtotime('-10 minutes')),
                    'client_ip'     => '192.168.1.50',
                    'method'        => 'GET',
                    'url'           => 'https://www.chase.com/secure/login',
                    'domain'        => 'chase.com',
                    'http_code'     => 200,
                    'squid_action'  => 'TCP_TUNNEL/200',
                    'filter_action' => 'BYPASS_SSL',
                    'category'      => 'Financial Services',
                    'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'bytes_sent'    => 86400,
                    'duration_ms'   => 28,
                ],
                [
                    'timestamp'     => date('Y-m-d H:i:s', strtotime('-14 minutes')),
                    'client_ip'     => '192.168.1.112',
                    'method'        => 'GET',
                    'url'           => 'http://unauthorized-tools.org/payload.exe',
                    'domain'        => 'unauthorized-tools.org',
                    'http_code'     => 403,
                    'squid_action'  => 'TCP_DENIED/403',
                    'filter_action' => 'BLOCK',
                    'category'      => 'Banned Extension (.exe)',
                    'user_agent'    => 'Wget/1.21.4',
                    'bytes_sent'    => 2840,
                    'duration_ms'   => 3,
                ],
                [
                    'timestamp'     => date('Y-m-d H:i:s', strtotime('-20 minutes')),
                    'client_ip'     => '192.168.1.75',
                    'method'        => 'GET',
                    'url'           => 'https://cdn.jsdelivr.net/npm/chart.js',
                    'domain'        => 'cdn.jsdelivr.net',
                    'http_code'     => 200,
                    'squid_action'  => 'TCP_HIT/200',
                    'filter_action' => 'ALLOW',
                    'category'      => 'CDN & Web Assets',
                    'user_agent'    => 'Mozilla/5.0 (X11; Linux x86_64)',
                    'bytes_sent'    => 65200,
                    'duration_ms'   => 2,
                ],
            ]);
        }

        // 4. Update / expand webfilter_categories
        $extraCats = [
            ['id' => 'cat_malware',   'name' => 'Malware & Phishing',         'action' => 'block', 'domain_count' => 129402, 'description' => 'Command & control, malicious payloads, ransomware and phishing sites', 'enabled' => 1],
            ['id' => 'cat_adult',     'name' => 'Adult Content',              'action' => 'block', 'domain_count' => 840932, 'description' => 'Pornography, explicit content and adult entertainment', 'enabled' => 1],
            ['id' => 'cat_gambling',  'name' => 'Gambling & Betting',         'action' => 'block', 'domain_count' => 45091,  'description' => 'Online casinos, sports betting, lotteries and gambling portals', 'enabled' => 1],
            ['id' => 'cat_social',    'name' => 'Social Networks',            'action' => 'allow', 'domain_count' => 8320,   'description' => 'Social networking platforms including Facebook, Instagram, TikTok, X', 'enabled' => 1],
            ['id' => 'cat_streaming', 'name' => 'Video & Audio Streaming',    'action' => 'allow', 'domain_count' => 14210,  'description' => 'High-bandwidth streaming media: YouTube, Netflix, Twitch, Spotify', 'enabled' => 1],
            ['id' => 'cat_gaming',    'name' => 'Online Gaming',              'action' => 'allow', 'domain_count' => 28400,  'description' => 'Online multiplayer gaming portals, MMO servers and gaming marketplaces', 'enabled' => 1],
            ['id' => 'cat_p2p',       'name' => 'P2P & File Sharing',         'action' => 'block', 'domain_count' => 62100,  'description' => 'BitTorrent trackers, P2P network hubs, cyberlockers and illegal file dumps', 'enabled' => 1],
            ['id' => 'cat_crypto',    'name' => 'Cryptomining & Web Miners',  'action' => 'block', 'domain_count' => 19300,  'description' => 'Browser-based cryptomining scripts and blockchain mining pools', 'enabled' => 1],
            ['id' => 'cat_hate',      'name' => 'Hate Speech & Extremism',    'action' => 'block', 'domain_count' => 31200,  'description' => 'Terrorist propaganda, extremist forums and hate speech portals', 'enabled' => 1],
            ['id' => 'cat_weapons',   'name' => 'Weapons & Illicit Goods',    'action' => 'block', 'domain_count' => 24100,  'description' => 'Illegal arms sales, darknet marketplaces and illicit contraband', 'enabled' => 1],
        ];

        foreach ($extraCats as $c) {
            $catExists = $db->table('webfilter_categories')->where('id', $c['id'])->get()->getRowArray();
            if ($catExists) {
                $db->table('webfilter_categories')->where('id', $c['id'])->update([
                    'name'         => $c['name'],
                    'domain_count' => $c['domain_count'],
                    'description'  => $c['description'],
                ]);
            } else {
                $db->table('webfilter_categories')->insert($c);
            }
        }

        // 5. Seed extra permissions if missing
        $newPerms = [
            ['key' => 'webfilter.edit',        'label' => 'Edit Web Filter Settings',        'module' => 'WebFilter', 'description' => 'Configure core proxy settings, ports, and modes'],
            ['key' => 'webfilter.wpad.manage',  'label' => 'Manage WPAD & PAC Auto-Discovery', 'module' => 'WebFilter', 'description' => 'Configure PAC scripts, DHCP Option 252, and DNS WPAD'],
            ['key' => 'webfilter.cache.clear',  'label' => 'Purge Web Proxy Cache',           'module' => 'WebFilter', 'description' => 'Flush and rebuild proxy cache storage'],
        ];

        foreach ($newPerms as $perm) {
            $pExists = $db->table('permissions')->where('key', $perm['key'])->get()->getRowArray();
            if (!$pExists) {
                $db->table('permissions')->insert([
                    'id'          => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    'key'         => $perm['key'],
                    'label'       => $perm['label'],
                    'module'      => $perm['module'],
                    'description' => $perm['description'],
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('webfilter_feeds', true);
        $this->forge->dropTable('webfilter_access_logs', true);
    }
}
