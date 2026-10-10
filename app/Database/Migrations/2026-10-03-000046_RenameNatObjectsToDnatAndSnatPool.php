<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rename the NAT objects to MicronOS' vendor-neutral vocabulary.
 *
 *   nat_vips          → nat_dnat         (inbound destination-NAT rules)
 *   nat_ip_pools      → nat_snat_pools   (outbound source-NAT address ranges)
 *   firewall_rules.vip_name  → dnat_name
 *   firewall_rules.pool_name → snat_pool_name
 *
 * This is a pure rename: ids are opaque strings, policies reference the
 * objects by name, and the columns keep their existing values, so no data
 * has to be rewritten. Fresh installs are unaffected because migration
 * 2026-10-02-000042 creates the tables under their old names first.
 */
class RenameNatObjectsToDnatAndSnatPool extends Migration
{
    public function up(): void
    {
        $this->forge->renameTable('nat_vips', 'nat_dnat');
        $this->forge->renameTable('nat_ip_pools', 'nat_snat_pools');

        // SQLite has supported RENAME COLUMN since 3.25; the appliance ships a
        // far newer build, and MySQL 8 / MariaDB both understand it too.
        // prefixTable() keeps this working when a DB prefix is configured.
        $table = $this->db->prefixTable('firewall_rules');
        $this->db->query("ALTER TABLE {$table} RENAME COLUMN vip_name TO dnat_name");
        $this->db->query("ALTER TABLE {$table} RENAME COLUMN pool_name TO snat_pool_name");

        // Ids embedded the old vocabulary too ("vip_…", "pool_…"). They are
        // opaque strings, so this is cosmetic consistency, not a fix-up.
        $this->rePrefixIds('nat_dnat', 'vip_', 'dnat_');
        $this->rePrefixIds('nat_snat_pools', 'pool_', 'snat_');
    }

    /** Rewrites every id in $table that still starts with $from to use $to. */
    private function rePrefixIds(string $table, string $from, string $to): void
    {
        foreach ($this->db->table($table)->select('id')->like('id', $from . '%')->get()->getResultArray() as $row) {
            $this->db->table($table)
                ->where('id', $row['id'])
                ->update(['id' => $to . substr((string) $row['id'], strlen($from))]);
        }
    }

    public function down(): void
    {
        $this->rePrefixIds('nat_snat_pools', 'snat_', 'pool_');
        $this->rePrefixIds('nat_dnat', 'dnat_', 'vip_');

        $table = $this->db->prefixTable('firewall_rules');
        $this->db->query("ALTER TABLE {$table} RENAME COLUMN dnat_name TO vip_name");
        $this->db->query("ALTER TABLE {$table} RENAME COLUMN snat_pool_name TO pool_name");

        $this->forge->renameTable('nat_snat_pools', 'nat_ip_pools');
        $this->forge->renameTable('nat_dnat', 'nat_vips');
    }
}
