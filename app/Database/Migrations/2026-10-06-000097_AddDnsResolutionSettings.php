<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Appliance DNS resolution settings (Architecture §13 DNS Resolver).
 *
 * The DNS page already controls how the appliance answers DNS for LAN clients
 * (Unbound, DoT, DNSBL). It had no way to express how the **firewall itself**
 * resolves DNS for its own needs, which pfSense exposes as two controls:
 *
 *  - `dns_server_override` — when set, DNS servers assigned by a DHCP/PPP server
 *    on WAN or by a remote OpenVPN peer (Pull DNS) may override the manually
 *    configured list. When cleared, only the servers on the DNS page are used.
 *    Defaults to **set**, matching pfSense, so an existing install keeps its
 *    current behaviour. It never applies to Unbound, which resolves/forwards
 *    for clients regardless of what the WAN hands out.
 *
 *  - `dns_resolution_behavior` — `local-fallback` (default), `local-only` or
 *    `remote-only`, selecting whether the appliance consults 127.0.0.1 and/or
 *    the configured remote servers. Stored as text rather than an integer enum
 *    so the stored value stays readable in a backup and a dump.
 *
 * Compiled by `App\Libraries\SystemResolverCompiler` into the appliance stub
 * resolver file; see that class for the exact semantics.
 *
 * Idempotent: each column is checked before it is added, and rows that predate
 * this migration keep behaving the same way (override on, local-fallback).
 */
class AddDnsResolutionSettings extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('dns_settings')) {
            return;
        }

        $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('dns_settings'));

        if (! in_array('dns_server_override', $existing, true)) {
            $this->forge->addColumn('dns_settings', [
                'dns_server_override' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 1,
                    'comment'    => 'Allow DHCP/PPP on WAN or a remote OpenVPN server to override the DNS server list',
                ],
            ]);
        }

        if (! in_array('dns_resolution_behavior', $existing, true)) {
            $this->forge->addColumn('dns_settings', [
                'dns_resolution_behavior' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'local-fallback', // local-fallback | local-only | remote-only
                    'comment'    => 'How the firewall itself resolves DNS',
                ],
            ]);
        }

        // Backfill the single settings row so an install that already had one
        // gets the documented defaults rather than NULLs.
        $db->table('dns_settings')
            ->where('id', 'global')
            ->where('dns_resolution_behavior IS NULL', null, false)
            ->update(['dns_resolution_behavior' => 'local-fallback']);

        $db->table('dns_settings')
            ->where('id', 'global')
            ->where('dns_server_override IS NULL', null, false)
            ->update(['dns_server_override' => 1]);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('dns_settings')) {
            return;
        }

        $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('dns_settings'));

        foreach (['dns_server_override', 'dns_resolution_behavior'] as $column) {
            if (in_array($column, $existing, true)) {
                $this->forge->dropColumn('dns_settings', $column);
            }
        }
    }
}
