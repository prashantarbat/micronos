<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Offline prefix cache for address objects.
 *
 * Address objects whose value is not an address (fqdn, internet-service,
 * geoip) are resolved into a CIDR list by `spark micron:resolve-objects` so a
 * firewall keeps matching the right networks when DNS or the catalogue feed is
 * unreachable. The resolved list is written both to the per-set feed file the
 * compiler already inlines and, for diagnostics, to this column.
 */
class AddResolvedPrefixesToAddresses extends Migration
{
    public function up(): void
    {
        $existing = array_map(fn ($c) => $c->name, $this->db->getFieldData('firewall_addresses'));

        $toAdd = [];
        if (! in_array('resolved_prefixes', $existing, true)) {
            $toAdd['resolved_prefixes'] = [
                'type' => 'TEXT',
                'null' => true,
            ];
        }
        if (! in_array('resolved_at', $existing, true)) {
            $toAdd['resolved_at'] = [
                'type' => 'DATETIME',
                'null' => true,
            ];
        }

        if ($toAdd !== []) {
            $this->forge->addColumn('firewall_addresses', $toAdd);
        }
    }

    public function down(): void
    {
        foreach (['resolved_prefixes', 'resolved_at'] as $column) {
            if ($this->db->fieldExists($column, 'firewall_addresses')) {
                $this->forge->dropColumn('firewall_addresses', $column);
            }
        }
    }
}
