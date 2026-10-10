<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates `dos_policies` table for Layer 4 DDoS / DoS mitigation,
 * SYN flood protection, connection limits, and protocol flood thresholds.
 */
class CreateDosPoliciesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'syn_flood_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'syn_flood_rate' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 100,
            ],
            'syn_flood_burst' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 200,
            ],
            'conn_limit_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'conn_limit_max' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 50,
            ],
            'conn_rate_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'conn_rate_max' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 60,
            ],
            'udp_flood_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'udp_flood_rate' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 500,
            ],
            'icmp_flood_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'icmp_flood_rate' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 20,
            ],
            'port_scan_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'drop',
            ],
            'ban_duration' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 600,
            ],
            'exempt_addresses' => [
                'type'       => 'TEXT',
                'null'       => true,
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
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
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
        $this->forge->addKey('interface_id');
        $this->forge->addKey('enabled');
        $this->forge->createTable('dos_policies', true);

        // Seed default WAN DDoS protection policy
        $db = \Config\Database::connect();
        $exists = $db->table('dos_policies')->where('id', 'dos_wan_default')->countAllResults();
        if ($exists === 0) {
            $db->table('dos_policies')->insert([
                'id'                 => 'dos_wan_default',
                'name'               => 'WAN Default DDoS Defense',
                'interface_id'       => 'wan',
                'syn_flood_enabled'  => 1,
                'syn_flood_rate'     => 100,
                'syn_flood_burst'    => 200,
                'conn_limit_enabled' => 1,
                'conn_limit_max'     => 50,
                'conn_rate_enabled'  => 1,
                'conn_rate_max'      => 60,
                'udp_flood_enabled'  => 1,
                'udp_flood_rate'     => 500,
                'icmp_flood_enabled' => 1,
                'icmp_flood_rate'    => 20,
                'port_scan_enabled'  => 1,
                'action'             => 'drop',
                'ban_duration'       => 600,
                'exempt_addresses'   => '127.0.0.1, 10.0.0.0/8, 192.168.0.0/16',
                'log_enabled'        => 1,
                'enabled'            => 1,
                'description'        => 'Stateful flood protection & dynamic host blacklisting on WAN gateway',
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('dos_policies', true);
    }
}
