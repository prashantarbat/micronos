<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateThreatIntelTables extends Migration
{
    public function up()
    {
        // 1. threat_feeds table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'feed_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32, // 'abuseipdb', 'alienvault_otx', 'misp', 'spamhaus_drop', 'emerging_threats', 'custom_url'
            ],
            'url' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
            ],
            'api_key' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'min_confidence' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 80,
            ],
            'update_interval_hours' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 6,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'last_sync_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'pending',
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'indicator_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('threat_feeds', true);

        // 2. threat_indicators table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'feed_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'indicator_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'indicator_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'ipv4',
            ],
            'threat_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'malicious_ip',
            ],
            'confidence_score' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 100,
            ],
            'first_seen' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_seen' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('indicator_value');
        $this->forge->addKey('feed_id');
        $this->forge->createTable('threat_indicators', true);

        // 3. threat_intel_settings table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'drop',
            ],
            'safeguard_rfc1918' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'log_blocked_packets' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'auto_prune_days' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 7,
            ],
            'nft_set_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'threat_intel_ipv4',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('threat_intel_settings', true);

        // 4. threat_intel_whitelist table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'indicator_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'admin',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('indicator_value');
        $this->forge->createTable('threat_intel_whitelist', true);

        // Seed initial threat_intel_settings
        $now = date('Y-m-d H:i:s');
        $this->db->table('threat_intel_settings')->insert([
            'id'                  => 'global_ti_settings',
            'enabled'             => 1,
            'action'              => 'drop',
            'safeguard_rfc1918'   => 1,
            'log_blocked_packets' => 1,
            'auto_prune_days'     => 7,
            'nft_set_name'        => 'threat_intel_ipv4',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        // Seed default threat feeds (Spamhaus, Emerging Threats, AbuseIPDB, AlienVault OTX, MISP)
        $this->db->table('threat_feeds')->insertBatch([
            [
                'id'                    => 'feed_spamhaus_drop',
                'name'                  => 'Spamhaus DROP & EDROP',
                'slug'                  => 'spamhaus-drop',
                'feed_type'             => 'spamhaus_drop',
                'url'                   => 'https://www.spamhaus.org/drop/drop.txt',
                'api_key'               => null,
                'min_confidence'        => 100,
                'update_interval_hours' => 12,
                'enabled'               => 1,
                'last_sync_at'          => $now,
                'last_status'           => 'success',
                'last_error'            => null,
                'indicator_count'       => 4,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'feed_emerging_threats',
                'name'                  => 'Emerging Threats (ET Open Blocklist)',
                'slug'                  => 'emerging-threats',
                'feed_type'             => 'emerging_threats',
                'url'                   => 'https://rules.emergingthreats.net/fwrules/emerging-Block-IPs.txt',
                'api_key'               => null,
                'min_confidence'        => 90,
                'update_interval_hours' => 6,
                'enabled'               => 1,
                'last_sync_at'          => $now,
                'last_status'           => 'success',
                'last_error'            => null,
                'indicator_count'       => 3,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'feed_abuseipdb',
                'name'                  => 'AbuseIPDB Global Blacklist',
                'slug'                  => 'abuseipdb-blacklist',
                'feed_type'             => 'abuseipdb',
                'url'                   => 'https://api.abuseipdb.com/api/v2/blacklist',
                'api_key'               => '',
                'min_confidence'        => 100,
                'update_interval_hours' => 4,
                'enabled'               => 0, // Disabled until API key entered
                'last_sync_at'          => null,
                'last_status'           => 'pending',
                'last_error'            => null,
                'indicator_count'       => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'feed_alienvault_otx',
                'name'                  => 'AlienVault OTX (Open Threat Exchange)',
                'slug'                  => 'alienvault-otx',
                'feed_type'             => 'alienvault_otx',
                'url'                   => 'https://otx.alienvault.com/api/v1/pulses/subscribed',
                'api_key'               => '',
                'min_confidence'        => 85,
                'update_interval_hours' => 6,
                'enabled'               => 0, // Disabled until API key entered
                'last_sync_at'          => null,
                'last_status'           => 'pending',
                'last_error'            => null,
                'indicator_count'       => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'feed_misp_export',
                'name'                  => 'MISP Threat Sharing Platform Export',
                'slug'                  => 'misp-platform',
                'feed_type'             => 'misp',
                'url'                   => 'https://misp.circl.lu/events/csv/download/false/false/ip-dst',
                'api_key'               => '',
                'min_confidence'        => 80,
                'update_interval_hours' => 6,
                'enabled'               => 0,
                'last_sync_at'          => null,
                'last_status'           => 'pending',
                'last_error'            => null,
                'indicator_count'       => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
        ]);

        // Seed initial known threat indicators for instant protection
        $this->db->table('threat_indicators')->insertBatch([
            [
                'id'               => 'ti_1',
                'feed_id'          => 'feed_spamhaus_drop',
                'indicator_value'  => '198.51.100.22',
                'indicator_type'   => 'ipv4',
                'threat_type'      => 'malicious_ip',
                'confidence_score' => 100,
                'first_seen'       => $now,
                'last_seen'        => $now,
                'expires_at'       => date('Y-m-d H:i:s', time() + 86400 * 7),
                'created_at'       => $now,
            ],
            [
                'id'               => 'ti_2',
                'feed_id'          => 'feed_spamhaus_drop',
                'indicator_value'  => '203.0.113.88',
                'indicator_type'   => 'ipv4',
                'threat_type'      => 'botnet_c2',
                'confidence_score' => 100,
                'first_seen'       => $now,
                'last_seen'        => $now,
                'expires_at'       => date('Y-m-d H:i:s', time() + 86400 * 7),
                'created_at'       => $now,
            ],
            [
                'id'               => 'ti_3',
                'feed_id'          => 'feed_spamhaus_drop',
                'indicator_value'  => '192.0.2.140',
                'indicator_type'   => 'ipv4',
                'threat_type'      => 'scanner',
                'confidence_score' => 95,
                'first_seen'       => $now,
                'last_seen'        => $now,
                'expires_at'       => date('Y-m-d H:i:s', time() + 86400 * 7),
                'created_at'       => $now,
            ],
            [
                'id'               => 'ti_4',
                'feed_id'          => 'feed_emerging_threats',
                'indicator_value'  => '185.220.101.5',
                'indicator_type'   => 'ipv4',
                'threat_type'      => 'tor_exit_relay',
                'confidence_score' => 90,
                'first_seen'       => $now,
                'last_seen'        => $now,
                'expires_at'       => date('Y-m-d H:i:s', time() + 86400 * 7),
                'created_at'       => $now,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('threat_intel_whitelist', true);
        $this->forge->dropTable('threat_intel_settings', true);
        $this->forge->dropTable('threat_indicators', true);
        $this->forge->dropTable('threat_feeds', true);
    }
}
