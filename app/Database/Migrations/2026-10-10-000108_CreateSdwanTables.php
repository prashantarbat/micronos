<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSdwanTables extends Migration
{
    public function up()
    {
        // 1. sdwan_members
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'gateway_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'cost' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10,
            ],
            'weight' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'priority' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'ping_target' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '8.8.8.8',
            ],
            'current_loss' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0.00,
            ],
            'current_latency' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 15.00,
            ],
            'current_jitter' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 2.00,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'alive', // 'alive', 'degraded', 'dead'
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
        $this->forge->addKey('interface');
        $this->forge->createTable('sdwan_members', true);

        // 2. sdwan_profiles
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sla_max_loss_pct' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 1.00,
            ],
            'sla_max_latency_ms' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 100.00,
            ],
            'sla_max_jitter_ms' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 15.00,
            ],
            'preferred_member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'backup_member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'assigned_applications' => [
                'type' => 'TEXT',
                'null' => true, // JSON array of application names
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('sdwan_profiles', true);

        // 3. sdwan_rules
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'priority' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'source_subnet' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '0.0.0.0/0',
            ],
            'destination_subnet' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '0.0.0.0/0',
            ],
            'sdwan_profile_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'strategy' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'best_quality', // 'best_quality', 'lowest_cost', 'failover', 'round_robin'
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
        $this->forge->addKey('priority');
        $this->forge->createTable('sdwan_rules', true);

        // 4. sdwan_settings
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
            'probe_interval_seconds' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 5,
            ],
            'probe_packet_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10,
            ],
            'jitter_smoothing_factor' => [
                'type'       => 'DECIMAL',
                'constraint' => '3,2',
                'default'    => 0.85,
            ],
            'default_strategy' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'best_quality',
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('sdwan_settings', true);

        // Seed initial SD-WAN members, profiles, rules, settings
        $this->seedInitialData();
    }

    public function down()
    {
        $this->forge->dropTable('sdwan_settings', true);
        $this->forge->dropTable('sdwan_rules', true);
        $this->forge->dropTable('sdwan_profiles', true);
        $this->forge->dropTable('sdwan_members', true);
    }

    private function seedInitialData(): void
    {
        $now = date('Y-m-d H:i:s');

        // Settings singleton
        $this->db->table('sdwan_settings')->insert([
            'id'                      => 1,
            'enabled'                 => 1,
            'probe_interval_seconds'  => 5,
            'probe_packet_count'      => 10,
            'jitter_smoothing_factor' => 0.85,
            'default_strategy'        => 'best_quality',
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        // Uplink Members
        $this->db->table('sdwan_members')->insertBatch([
            [
                'id'              => 'mem_wan1',
                'name'            => 'Primary Fiber WAN (Tier-1 ISP)',
                'interface'       => 'wan1',
                'gateway_ip'      => '198.51.100.1',
                'cost'            => 10,
                'weight'          => 100,
                'priority'        => 1,
                'ping_target'     => '1.1.1.1',
                'current_loss'    => 0.00,
                'current_latency' => 8.40,
                'current_jitter'  => 1.20,
                'status'          => 'alive',
                'enabled'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [
                'id'              => 'mem_wan2',
                'name'            => 'Secondary Broadband WAN',
                'interface'       => 'wan2',
                'gateway_ip'      => '203.0.113.1',
                'cost'            => 25,
                'weight'          => 50,
                'priority'        => 2,
                'ping_target'     => '8.8.8.8',
                'current_loss'    => 0.20,
                'current_latency' => 24.10,
                'current_jitter'  => 3.60,
                'status'          => 'alive',
                'enabled'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [
                'id'              => 'mem_lte',
                'name'            => '5G/LTE Cellular Emergency Uplink',
                'interface'       => 'wwan0',
                'gateway_ip'      => '100.64.1.1',
                'cost'            => 80,
                'weight'          => 10,
                'priority'        => 3,
                'ping_target'     => '9.9.9.9',
                'current_loss'    => 1.10,
                'current_latency' => 52.30,
                'current_jitter'  => 8.40,
                'status'          => 'degraded',
                'enabled'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
        ]);

        // Performance SLA Profiles
        $this->db->table('sdwan_profiles')->insertBatch([
            [
                'id'                    => 'prof_voip_collab',
                'name'                  => 'VoIP & Real-Time Collaboration (Strict SLA)',
                'description'           => 'Zero-jitter low-latency path steering for Microsoft Teams, Zoom, SIP, WebRTC, and Discord.',
                'sla_max_loss_pct'      => 0.50,
                'sla_max_latency_ms'    => 80.00,
                'sla_max_jitter_ms'     => 10.00,
                'preferred_member_id'   => 'mem_wan1',
                'backup_member_id'      => 'mem_wan2',
                'assigned_applications' => json_encode(['Zoom', 'Microsoft Teams', 'WebRTC', 'SIP', 'RTP', 'Discord']),
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'prof_cloud_erp',
                'name'                  => 'Mission-Critical Cloud ERP & SaaS',
                'description'           => 'Loss-sensitive steering for Salesforce, SAP, AWS, Azure, Microsoft 365, and GitHub.',
                'sla_max_loss_pct'      => 1.00,
                'sla_max_latency_ms'    => 120.00,
                'sla_max_jitter_ms'     => 20.00,
                'preferred_member_id'   => 'mem_wan1',
                'backup_member_id'      => 'mem_wan2',
                'assigned_applications' => json_encode(['Office365', 'Salesforce', 'AWS', 'Google Cloud', 'GitHub']),
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'prof_bulk_media',
                'name'                  => 'General Web & Bulk Media Transfers',
                'description'           => 'Lowest-cost steering for YouTube, Netflix, Windows Updates, BitTorrent, and Steam.',
                'sla_max_loss_pct'      => 5.00,
                'sla_max_latency_ms'    => 300.00,
                'sla_max_jitter_ms'     => 50.00,
                'preferred_member_id'   => 'mem_wan2',
                'backup_member_id'      => 'mem_wan1',
                'assigned_applications' => json_encode(['YouTube', 'Netflix', 'BitTorrent', 'Steam']),
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
        ]);

        // Path Steering Rules
        $this->db->table('sdwan_rules')->insertBatch([
            [
                'id'                 => 'rule_voip_steering',
                'priority'           => 1,
                'name'               => 'Steer Collaboration via Best Quality Link',
                'source_subnet'      => '192.168.0.0/16',
                'destination_subnet' => '0.0.0.0/0',
                'sdwan_profile_id'   => 'prof_voip_collab',
                'strategy'           => 'best_quality',
                'enabled'            => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'id'                 => 'rule_cloud_steering',
                'priority'           => 2,
                'name'               => 'Enterprise Cloud Traffic Lowest Cost Fallback',
                'source_subnet'      => '0.0.0.0/0',
                'destination_subnet' => '0.0.0.0/0',
                'sdwan_profile_id'   => 'prof_cloud_erp',
                'strategy'           => 'lowest_cost',
                'enabled'            => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ]);
    }
}
