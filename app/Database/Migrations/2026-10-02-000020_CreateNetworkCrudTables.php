<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates `static_routes`, `dhcp_reservations`, and `dns_hosts` tables for network CRUD
 */
class CreateNetworkCrudTables extends Migration
{
    public function up(): void
    {
        // 1. Static Routes Table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'destination' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'eth0',
            ],
            'metric' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10,
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
        $this->forge->createTable('static_routes', true);

        // 2. DHCP Static Reservations Table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'mac_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'hostname' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
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
        $this->forge->createTable('dhcp_reservations', true);

        // 3. DNS Host Overrides Table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'hostname' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
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
        $this->forge->createTable('dns_hosts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('static_routes', true);
        $this->forge->dropTable('dhcp_reservations', true);
        $this->forge->dropTable('dns_hosts', true);
    }
}
