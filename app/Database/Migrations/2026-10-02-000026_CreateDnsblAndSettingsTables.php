<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates `dns_feeds`, `dns_overrides` and `dns_settings` for DNSBL
 */
class CreateDnsblAndSettingsTables extends Migration
{
    public function up(): void
    {
        // ── 1. DNSBL Feed Subscriptions ────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'url' => [
                'type'       => 'TEXT',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'Ads & Tracking',
            ],
            'entries_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'last_updated' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
            'created_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
            'updated_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('dns_feeds', true);

        // ── 2. DNS Whitelist / Blacklist Overrides ─────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'whitelist' or 'blacklist'
                'default'    => 'blacklist',
            ],
            'is_wildcard' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
            'updated_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('domain');
        $this->forge->createTable('dns_overrides', true);

        // ── 3. DNS Resolver & DoT Settings ────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'global',
            ],
            'enable_dnssec' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'enable_dnsbl' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'upstream_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'dot', // 'standard' or 'dot' (DNS-over-TLS)
            ],
            'upstream_servers' => [
                'type'       => 'TEXT', // JSON array of upstream resolvers
                'null'       => true,
            ],
            'cache_min_ttl' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 60,
            ],
            'cache_max_ttl' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 86400,
            ],
            'blocked_query_action' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'nxdomain', // 'nxdomain' or '0.0.0.0'
            ],
            'updated_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('dns_settings', true);

        // Seed default DNSBL feeds and initial settings
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $feeds = [
            [
                'id'            => 'feed_stevenblack',
                'name'          => 'StevenBlack Unified Hosts',
                'url'           => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts',
                'category'      => 'Adware & Malware',
                'entries_count' => 184512,
                'enabled'       => 1,
                'last_updated'  => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'id'            => 'feed_adguard',
                'name'          => 'AdGuard DNS Filter',
                'url'           => 'https://adguardteam.github.io/HostlistsRegistry/assets/filter_1.txt',
                'category'      => 'Tracking & Telemetry',
                'entries_count' => 62140,
                'enabled'       => 1,
                'last_updated'  => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'id'            => 'feed_oisd_basic',
                'name'          => 'OISD Blocklist Big',
                'url'           => 'https://big.oisd.nl',
                'category'      => 'General Adblock',
                'entries_count' => 241905,
                'enabled'       => 1,
                'last_updated'  => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'id'            => 'feed_phishing_urlhaus',
                'name'          => 'abuse.ch URLhaus Phishing & Malware',
                'url'           => 'https://urlhaus.abuse.ch/downloads/hostfile/',
                'category'      => 'High-Risk Cyber Threats',
                'entries_count' => 14380,
                'enabled'       => 1,
                'last_updated'  => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ];

        foreach ($feeds as $feed) {
            $db->table('dns_feeds')->insert($feed);
        }

        $defaultUpstreams = json_encode([
            ['name' => 'Cloudflare Primary (DoT)', 'ip' => '1.1.1.1', 'port' => 853, 'sni' => 'cloudflare-dns.com'],
            ['name' => 'Cloudflare Secondary (DoT)', 'ip' => '1.0.0.1', 'port' => 853, 'sni' => 'cloudflare-dns.com'],
            ['name' => 'Quad9 Secured (DoT)', 'ip' => '9.9.9.9', 'port' => 853, 'sni' => 'dns.quad9.net'],
        ]);

        $db->table('dns_settings')->insert([
            'id'                   => 'global',
            'enable_dnssec'        => 1,
            'enable_dnsbl'         => 1,
            'upstream_mode'        => 'dot',
            'upstream_servers'     => $defaultUpstreams,
            'cache_min_ttl'        => 60,
            'cache_max_ttl'        => 86400,
            'blocked_query_action' => '0.0.0.0',
            'updated_at'           => $now,
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('dns_settings', true);
        $this->forge->dropTable('dns_overrides', true);
        $this->forge->dropTable('dns_feeds', true);
    }
}
