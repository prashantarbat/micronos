<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAppIdAndDpiTables extends Migration
{
    public function up()
    {
        // 1. dpi_categories table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'icon' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'grid',
            ],
            'color' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => '#6366f1',
            ],
            'default_action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'allow',
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('dpi_categories', true);

        // 2. dpi_applications table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'category_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'proto_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'mark_hex' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'risk_level' => [
                'type'       => 'INT',
                'constraint' => 2,
                'default'    => 1, // 1: Low, 2: Moderate, 3: Medium, 4: High, 5: Critical
            ],
            'ports_hint' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'is_system' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('category_id');
        $this->forge->addKey('proto_id');
        $this->forge->createTable('dpi_applications', true);

        // 3. dpi_policies table (Application Control Policy Rulebase)
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'sequence' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'src_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'lan',
            ],
            'dst_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'app_ids' => [
                'type' => 'TEXT',
                'null' => true, // JSON array of application IDs
            ],
            'category_ids' => [
                'type' => 'TEXT',
                'null' => true, // JSON array of category IDs
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'block', // 'block', 'allow', 'shape', 'log'
            ],
            'bandwidth_limit_kbps' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true, // for traffic shaping
            ],
            'schedule' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'always',
            ],
            'log_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('sequence');
        $this->forge->createTable('dpi_policies', true);

        // 4. dpi_custom_signatures table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'category_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'custom',
            ],
            'pattern_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'regex', // 'regex', 'host', 'payload_hex'
            ],
            'pattern' => [
                'type' => 'TEXT',
            ],
            'port' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'tcp',
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('dpi_custom_signatures', true);

        // 5. dpi_settings table
        $this->forge->addField([
            'id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'engine_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'inline_block', // 'inline_block', 'monitor_only'
            ],
            'inspection_depth_packets' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10,
            ],
            'log_level' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'matched',
            ],
            'flow_table_max' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 262144,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('dpi_settings', true);

        // Seed default settings and initial categories/applications
        $this->seedInitialData();
    }

    public function down()
    {
        $this->forge->dropTable('dpi_settings', true);
        $this->forge->dropTable('dpi_custom_signatures', true);
        $this->forge->dropTable('dpi_policies', true);
        $this->forge->dropTable('dpi_applications', true);
        $this->forge->dropTable('dpi_categories', true);
    }

    private function seedInitialData(): void
    {
        $now = date('Y-m-d H:i:s');

        // Settings singleton
        $this->db->table('dpi_settings')->insert([
            'id'                       => 1,
            'enabled'                  => 1,
            'engine_mode'              => 'inline_block',
            'inspection_depth_packets' => 10,
            'log_level'                => 'matched',
            'flow_table_max'           => 262144,
            'updated_at'               => $now,
        ]);

        // Categories
        $categories = [
            ['id' => 'streaming',      'name' => 'Streaming & Media',      'description' => 'Video, audio, live broadcasts and streaming CDNs',   'icon' => 'film',        'color' => '#f43f5e'],
            ['id' => 'social',         'name' => 'Social Networking',      'description' => 'Social platforms, messaging, microblogs and communities', 'icon' => 'users',       'color' => '#3b82f6'],
            ['id' => 'collaboration',  'name' => 'Collaboration & VoIP',   'description' => 'Enterprise conferencing, screen sharing, VoIP calls', 'icon' => 'video',       'color' => '#10b981'],
            ['id' => 'p2p',            'name' => 'P2P & File Sharing',     'description' => 'Peer-to-peer file transfer, torrents, bulk file exchange', 'icon' => 'download',    'color' => '#f59e0b'],
            ['id' => 'gaming',         'name' => 'Gaming & Esports',       'description' => 'Online multiplayer games, gaming platforms and launchers', 'icon' => 'play',        'color' => '#8b5cf6'],
            ['id' => 'ai',             'name' => 'Artificial Intelligence', 'description' => 'Generative AI chatbots, LLM inference endpoints and APIs', 'icon' => 'cpu',         'color' => '#06b6d4'],
            ['id' => 'crypto',         'name' => 'Cryptomining & Risk',    'description' => 'Cryptocurrency mining pools, Stratum protocol, darknet nodes', 'icon' => 'alert-triangle', 'color' => '#ef4444'],
            ['id' => 'remote_access',  'name' => 'Remote Access & Admin',  'description' => 'Remote desktop, RDP, SSH, TeamViewer, AnyDesk utilities', 'icon' => 'terminal',   'color' => '#64748b'],
            ['id' => 'business',       'name' => 'Enterprise & Cloud',     'description' => 'SaaS applications, cloud storage and corporate services', 'icon' => 'briefcase',   'color' => '#0ea5e9'],
        ];

        foreach ($categories as $cat) {
            $cat['default_action'] = 'allow';
            $cat['created_at'] = $now;
            $cat['updated_at'] = $now;
            $this->db->table('dpi_categories')->insert($cat);
        }

        // Applications
        $apps = [
            // Streaming
            ['id' => 'youtube',       'category_id' => 'streaming',     'name' => 'YouTube',          'proto_id' => 91,  'mark_hex' => '0x1001', 'risk_level' => 2, 'ports_hint' => '443, 80'],
            ['id' => 'netflix',       'category_id' => 'streaming',     'name' => 'Netflix',          'proto_id' => 124, 'mark_hex' => '0x1002', 'risk_level' => 1, 'ports_hint' => '443'],
            ['id' => 'spotify',       'category_id' => 'streaming',     'name' => 'Spotify',          'proto_id' => 147, 'mark_hex' => '0x1003', 'risk_level' => 1, 'ports_hint' => '443, 4070'],
            ['id' => 'twitch',        'category_id' => 'streaming',     'name' => 'Twitch',           'proto_id' => 155, 'mark_hex' => '0x1004', 'risk_level' => 2, 'ports_hint' => '443, 1935'],
            
            // Social
            ['id' => 'facebook',      'category_id' => 'social',        'name' => 'Facebook',         'proto_id' => 119, 'mark_hex' => '0x1011', 'risk_level' => 2, 'ports_hint' => '443'],
            ['id' => 'instagram',     'category_id' => 'social',        'name' => 'Instagram',        'proto_id' => 212, 'mark_hex' => '0x1012', 'risk_level' => 2, 'ports_hint' => '443'],
            ['id' => 'tiktok',        'category_id' => 'social',        'name' => 'TikTok',           'proto_id' => 236, 'mark_hex' => '0x1013', 'risk_level' => 3, 'ports_hint' => '443'],
            ['id' => 'twitter',       'category_id' => 'social',        'name' => 'X (Twitter)',      'proto_id' => 120, 'mark_hex' => '0x1014', 'risk_level' => 2, 'ports_hint' => '443'],
            ['id' => 'telegram',      'category_id' => 'social',        'name' => 'Telegram',         'proto_id' => 168, 'mark_hex' => '0x1015', 'risk_level' => 3, 'ports_hint' => '443, 80, 5222'],
            ['id' => 'whatsapp',      'category_id' => 'social',        'name' => 'WhatsApp',         'proto_id' => 165, 'mark_hex' => '0x1016', 'risk_level' => 2, 'ports_hint' => '443, 5222'],
            
            // Collaboration
            ['id' => 'zoom',          'category_id' => 'collaboration', 'name' => 'Zoom',             'proto_id' => 231, 'mark_hex' => '0x1021', 'risk_level' => 1, 'ports_hint' => '443, 8801, 8802'],
            ['id' => 'ms_teams',      'category_id' => 'collaboration', 'name' => 'Microsoft Teams',  'proto_id' => 240, 'mark_hex' => '0x1022', 'risk_level' => 1, 'ports_hint' => '443, 3478-3481'],
            ['id' => 'slack',         'category_id' => 'collaboration', 'name' => 'Slack',            'proto_id' => 220, 'mark_hex' => '0x1023', 'risk_level' => 1, 'ports_hint' => '443'],
            
            // P2P
            ['id' => 'bittorrent',    'category_id' => 'p2p',           'name' => 'BitTorrent',       'proto_id' => 7,   'mark_hex' => '0x1031', 'risk_level' => 4, 'ports_hint' => '6881-6889, UDP DHT'],
            
            // Gaming
            ['id' => 'steam',         'category_id' => 'gaming',        'name' => 'Steam',            'proto_id' => 175, 'mark_hex' => '0x1041', 'risk_level' => 2, 'ports_hint' => '27015-27030, 443'],
            ['id' => 'roblox',        'category_id' => 'gaming',        'name' => 'Roblox',           'proto_id' => 252, 'mark_hex' => '0x1042', 'risk_level' => 2, 'ports_hint' => '443, 49152-65535'],
            
            // AI
            ['id' => 'chatgpt',       'category_id' => 'ai',            'name' => 'OpenAI ChatGPT',   'proto_id' => 260, 'mark_hex' => '0x1051', 'risk_level' => 2, 'ports_hint' => '443'],
            ['id' => 'claude_ai',     'category_id' => 'ai',            'name' => 'Anthropic Claude', 'proto_id' => 261, 'mark_hex' => '0x1052', 'risk_level' => 2, 'ports_hint' => '443'],
            
            // Cryptomining
            ['id' => 'stratum',       'category_id' => 'crypto',        'name' => 'Stratum (Mining)', 'proto_id' => 218, 'mark_hex' => '0x1061', 'risk_level' => 5, 'ports_hint' => '3333, 4444, 8332'],
            
            // Remote Access
            ['id' => 'anydesk',       'category_id' => 'remote_access', 'name' => 'AnyDesk',          'proto_id' => 244, 'mark_hex' => '0x1071', 'risk_level' => 3, 'ports_hint' => '7070, 443'],
            ['id' => 'teamviewer',    'category_id' => 'remote_access', 'name' => 'TeamViewer',       'proto_id' => 129, 'mark_hex' => '0x1072', 'risk_level' => 3, 'ports_hint' => '5938, 443'],
        ];

        foreach ($apps as $app) {
            $app['is_system'] = 1;
            $app['description'] = "Protocol definition for {$app['name']}";
            $app['created_at'] = $now;
            $app['updated_at'] = $now;
            $this->db->table('dpi_applications')->insert($app);
        }

        // Seed default high-risk block policy: Block Cryptomining
        $this->db->table('dpi_policies')->insert([
            'id'            => 'pol_dpi_crypto_block',
            'name'          => 'Default Block Cryptomining Pools',
            'sequence'      => 1,
            'src_interface' => 'lan',
            'dst_interface' => 'wan',
            'app_ids'       => json_encode(['stratum']),
            'category_ids'  => json_encode(['crypto']),
            'action'        => 'block',
            'schedule'      => 'always',
            'log_enabled'   => 1,
            'enabled'       => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
}
