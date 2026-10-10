<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: NAT objects
 * - nat_vips      : named Virtual IPs (DNAT mappings, optional port translation
 *                   and NAT reflection) — referenced from firewall policies.
 * - nat_ip_pools  : named IP Pool ranges used for outbound SNAT overload.
 * - firewall_rules gains vip_name / pool_name so a policy can reference them.
 *
 * These are a thin abstraction over the same nftables primitives
 * the compiler already emits (`dnat to` / `snat to`).
 */
class CreateNatObjectTables extends Migration
{
    public function up(): void
    {
        // ── 1. Virtual IPs (DNAT) ─────────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
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
            'external_port' => [
                'type' => 'INT',
                'null' => true,
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'tcp', // 'tcp', 'udp', 'tcp/udp', 'any'
            ],
            'mapped_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'mapped_port' => [
                'type' => 'INT',
                'null' => true,
            ],
            'nat_reflection' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'comment' => [
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
        $this->forge->addUniqueKey('name');
        // Never resurrect the old table name if this migration is re-executed
        // after the objects were renamed (2026-10-03-000046).
        if (! $this->db->tableExists('nat_dnat') && ! $this->db->tableExists('nat_vips')) {
            $this->forge->createTable('nat_vips', true);
        }

        // ── 2. IP Pools (outbound SNAT) ───────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'wan',
            ],
            'start_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'end_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'comment' => [
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
        $this->forge->addUniqueKey('name');
        // Same guard as above, for the renamed SNAT pool table.
        if (! $this->db->tableExists('nat_snat_pools') && ! $this->db->tableExists('nat_ip_pools')) {
            $this->forge->createTable('nat_ip_pools', true);
        }

        // ── 3. Policy references (safe column-add) ────────────────────
        $db = \Config\Database::connect();
        $existingCols = array_map(fn ($c) => $c->name, $db->getFieldData('firewall_rules'));

        $toAdd = [];
        if (! in_array('vip_name', $existingCols, true) && ! in_array('dnat_name', $existingCols, true)) {
            $toAdd['vip_name'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ];
        }
        if (! in_array('pool_name', $existingCols, true) && ! in_array('snat_pool_name', $existingCols, true)) {
            $toAdd['pool_name'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ];
        }

        if ($toAdd !== []) {
            $this->forge->addColumn('firewall_rules', $toAdd);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('nat_ip_pools', true);
        $this->forge->dropTable('nat_vips', true);
    }
}
