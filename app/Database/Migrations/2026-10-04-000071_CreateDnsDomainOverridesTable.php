<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Creates `dns_domain_overrides` for Domain Overrides
 * (per-domain forwarding of queries to a specific upstream resolver).
 */
class CreateDnsDomainOverridesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 253,
            ],
            'dns_server' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'port' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 53,
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
        $this->forge->addKey('domain');
        $this->forge->createTable('dns_domain_overrides', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('dns_domain_overrides', true);
    }
}
