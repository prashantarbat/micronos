<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates comprehensive NAT tables:
 * - nat_settings (Global Outbound Mode: Automatic/Hybrid/Manual + NAT Reflection / Hairpin)
 * - nat_one_to_one (Bi-directional 1:1 NAT host/subnet mappings)
 * - nat_outbound_rules (Manual/Hybrid Outbound SNAT mappings with Static Port)
 * - nat_nptv6 (IPv6 Network Prefix Translation - RFC 6296)
 */
class CreateComprehensiveNatTables extends Migration
{
    public function up(): void
    {
        // ── 1. Global NAT Settings ────────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'global',
            ],
            'outbound_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'hybrid', // 'automatic', 'hybrid', 'manual', 'disabled'
            ],
            'nat_reflection' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1, // Hairpin NAT loopback
            ],
            'reflection_timeout' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 30,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('nat_settings', true);

        // ── 2. 1:1 NAT (Bi-Directional VIP Mapping) ────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'external_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'internal_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'dest_network' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'any',
            ],
            'nat_reflection' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
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
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('nat_one_to_one', true);

        // ── 3. Advanced Outbound NAT Rules (SNAT) ─────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'any',
            ],
            'source_network' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '192.168.1.0/24',
            ],
            'source_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'dest_network' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'any',
            ],
            'dest_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'target_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'interface', // 'interface', 'vip', 'pool'
            ],
            'target_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'static_port' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0, // Set to 1 for VoIP SIP/RTP & Gaming consoles (prevents source port rewriting)
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
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('nat_outbound_rules', true);

        // ── 4. IPv6 Network Prefix Translation (NPTv6) ────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'internal_prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'external_prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
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
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('nat_nptv6', true);

        // ── Seed Defaults ─────────────────────────────────────────────
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // Global Settings
        $db->table('nat_settings')->insert([
            'id'                 => 'global',
            'outbound_mode'      => 'hybrid',
            'nat_reflection'     => 1,
            'reflection_timeout' => 30,
            'updated_at'         => $now,
        ]);

        // Seed Default 1:1 NAT sample
        $db->table('nat_one_to_one')->insert([
            'id'             => 'onetoone_' . bin2hex(random_bytes(6)),
            'interface_id'   => 'wan',
            'external_ip'    => '198.51.100.25',
            'internal_ip'    => '192.168.1.50',
            'dest_network'   => 'any',
            'nat_reflection' => 1,
            'description'    => 'Public DMZ Web/Mail Cluster bi-directional 1:1 translation',
            'enabled'        => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // Seed Default Outbound Mappings (VoIP Static Port & RFC1918)
        $db->table('nat_outbound_rules')->insertBatch([
            [
                'id'             => 'outbound_' . bin2hex(random_bytes(6)),
                'interface_id'   => 'wan',
                'protocol'       => 'udp',
                'source_network' => '192.168.1.0/24',
                'source_port'    => '5060',
                'dest_network'   => 'any',
                'dest_port'      => 'any',
                'target_type'    => 'interface',
                'target_ip'      => null,
                'static_port'    => 1, // Static port for SIP PBX phones
                'description'    => 'VoIP PBX SIP Signaling — Static Port (prevents audio dropouts)',
                'enabled'        => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id'             => 'outbound_' . bin2hex(random_bytes(6)),
                'interface_id'   => 'wan',
                'protocol'       => 'any',
                'source_network' => '10.8.0.0/24',
                'source_port'    => 'any',
                'dest_network'   => 'any',
                'dest_port'      => 'any',
                'target_type'    => 'interface',
                'target_ip'      => null,
                'static_port'    => 0,
                'description'    => 'OpenVPN Road Warrior Client egress to Internet via WAN',
                'enabled'        => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ]);

        // Seed Sample NPTv6 (ULA to GUA)
        $db->table('nat_nptv6')->insert([
            'id'              => 'npt_' . bin2hex(random_bytes(6)),
            'interface_id'    => 'wan',
            'internal_prefix' => 'fd00:abcd:1::/64',
            'external_prefix' => '2001:db8:acad:1::/64',
            'description'     => 'Stateless IPv6 Prefix Translation for Internal ULA Subnet',
            'enabled'         => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('nat_nptv6', true);
        $this->forge->dropTable('nat_outbound_rules', true);
        $this->forge->dropTable('nat_one_to_one', true);
        $this->forge->dropTable('nat_settings', true);
    }
}
