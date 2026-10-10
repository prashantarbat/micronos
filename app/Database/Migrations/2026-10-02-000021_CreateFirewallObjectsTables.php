<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates Firewall Objects & Policies
 * - firewall_addresses (IP/Netmask, IP Range, FQDN)
 * - firewall_address_groups (Groups of addresses)
 * - firewall_services (All known standard ports & custom services)
 * - firewall_service_groups (Groups of services/ports)
 * - Updates firewall_rules with policy columns
 */
class CreateFirewallObjectsTables extends Migration
{
    public function up(): void
    {
        // 1. Firewall Addresses Table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'ipmask', 'iprange', 'fqdn', 'geography'
                'default'    => 'ipmask',
            ],
            'value' => [
                'type'       => 'VARCHAR',
                'constraint' => 255, // e.g. '192.168.1.0/24', '10.0.0.1-10.0.0.50', 'update.microsoft.com'
            ],
            'interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'color' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
            ],
            'comment' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'is_system' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
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
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('firewall_addresses', true);

        // 2. Firewall Address Groups Table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'members' => [
                'type' => 'TEXT', // JSON array of address names or IDs
            ],
            'color' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
            ],
            'comment' => [
                'type' => 'TEXT',
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
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('firewall_address_groups', true);

        // 3. Firewall Services (All Known Ports & Custom Ports)
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'TCP', 'UDP', 'TCP/UDP', 'ICMP', 'IP'
                'default'    => 'TCP',
            ],
            'tcp_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'udp_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'icmp_type' => [
                'type' => 'INT',
                'null' => true,
            ],
            'icmp_code' => [
                'type' => 'INT',
                'null' => true,
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'General',
            ],
            'is_system' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'comment' => [
                'type' => 'TEXT',
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
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('firewall_services', true);

        // 4. Firewall Service Groups Table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'members' => [
                'type' => 'TEXT', // JSON array of service names
            ],
            'comment' => [
                'type' => 'TEXT',
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
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('firewall_service_groups', true);

        // 5. Add policy fields to firewall_rules if not present
        $fields = [
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'src_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'dst_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'sources' => [
                'type' => 'TEXT',
                'null' => true, // JSON array: ["all"] or ["LAN_Subnet", "Admin_PC"]
            ],
            'destinations' => [
                'type' => 'TEXT',
                'null' => true, // JSON array: ["all"] or ["Google_DNS"]
            ],
            'services' => [
                'type' => 'TEXT',
                'null' => true, // JSON array: ["HTTP", "HTTPS", "DNS"]
            ],
            'nat_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'schedule' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'always',
            ],
            'log_traffic' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'all',
            ],
            'security_profiles' => [
                'type' => 'TEXT',
                'null' => true, // JSON array: ["ips", "av", "webfilter"]
            ],
            'hit_count' => [
                'type'    => 'BIGINT',
                'default' => 0,
            ],
            'bytes' => [
                'type'    => 'BIGINT',
                'default' => 0,
            ],
        ];

        // Safe column additions
        $db = \Config\Database::connect();
        $existingCols = array_map(fn($c) => $c->name, $db->getFieldData('firewall_rules'));

        $toAdd = [];
        foreach ($fields as $col => $def) {
            if (!in_array($col, $existingCols, true)) {
                $toAdd[$col] = $def;
            }
        }

        if (!empty($toAdd)) {
            $this->forge->addColumn('firewall_rules', $toAdd);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('firewall_addresses', true);
        $this->forge->dropTable('firewall_address_groups', true);
        $this->forge->dropTable('firewall_services', true);
        $this->forge->dropTable('firewall_service_groups', true);
    }
}
