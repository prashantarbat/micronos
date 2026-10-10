<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Policy-Based Routing — object-based matches.
 * -------------------------------------------
 * A PBR rule originally matched only raw CIDRs (`match_src` / `match_dst`).
 * That duplicates every address the firewall already knows about, and it goes
 * stale the moment a FQDN, an internet-service catalogue or a country feed is
 * refreshed.
 *
 * These two columns hold a JSON list of named address objects, address groups,
 * FQDNs, internet services and geography codes — the same references a firewall
 * policy uses. The compiler resolves them to the named sets it already emits
 * (`@fqdn_…`, `@isdb_…`, `@geo_…`) or to literal prefixes, so a PBR match stays
 * current alongside the rest of the ruleset.
 *
 * The raw CIDR columns are kept: a rule may still match a literal address that
 * is not modelled as an object.
 */
class AddMatchObjectsToPbrRules extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('match_src_objects', 'routing_pbr_rules')) {
            $this->forge->addColumn('routing_pbr_rules', [
                'match_src_objects' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'match_src',
                ],
            ]);
        }

        if (! $this->db->fieldExists('match_dst_objects', 'routing_pbr_rules')) {
            $this->forge->addColumn('routing_pbr_rules', [
                'match_dst_objects' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'match_dst',
                ],
            ]);
        }
    }

    public function down(): void
    {
        // CI4's SQLite table rebuild cannot drop an unindexed column through
        // the forge, so the raw statement is used where the driver needs it.
        foreach (['match_src_objects', 'match_dst_objects'] as $column) {
            if (! $this->db->fieldExists($column, 'routing_pbr_rules')) {
                continue;
            }

            if ($this->db->DBDriver === 'SQLite3') {
                $table = $this->db->prefixTable('routing_pbr_rules');
                $this->db->query("ALTER TABLE {$table} DROP COLUMN {$column}");
            } else {
                $this->forge->dropColumn('routing_pbr_rules', $column);
            }
        }
    }
}
