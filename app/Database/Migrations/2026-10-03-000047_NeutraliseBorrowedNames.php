<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Scrubs borrowed third-party product names out of rows that earlier seeders
 * and mock paths had already written.
 *
 * Editing a seeder only affects fresh installs, so an appliance seeded before
 * the rename keeps the old strings in its database. This rewrites the handful
 * of affected rows so the appliance converges on its own vocabulary without a
 * re-seed (which would discard live edits).
 *
 *   system_services.dpinger             → gateway-monitor
 *   firewall_addresses.comment          → drop "(FortiGate default)"
 *   frr_ospf_neighbors.description      → drop "(Cisco ASR)"
 *   dhcp_server_settings.custom_options → drop "UniFi" / "Cisco"
 *
 * Only those named rows/columns are touched — no blanket text sweep, so a
 * certificate, comment or description elsewhere can never be rewritten by
 * accident. Every step is idempotent.
 */
class NeutraliseBorrowedNames extends Migration
{
    /**
     * table => column => [ exact-current-value => replacement ].
     *
     * Matched with LIKE '%value%' so it also catches the value embedded in a
     * larger string (the JSON blob in dhcp_server_settings, for example).
     */
    private const FIXES = [
        'firewall_addresses' => [
            'comment' => [
                'Matches any IPv4 address (FortiGate default)' => 'Matches any IPv4 address',
            ],
        ],
        'frr_ospf_neighbors' => [
            'description' => [
                'Branch Edge Gateway (Cisco ASR)' => 'Branch Edge Gateway',
            ],
        ],
        'dhcp_server_settings' => [
            'custom_options' => [
                'Vendor Specific (UniFi Inform IP)' => 'Vendor Specific (Controller Inform IP)',
                'UniFi Controller Inform IP'        => 'Controller Inform IP',
                'Cisco VoIP TFTP Server IP'         => 'VoIP TFTP Server IP',
            ],
        ],
    ];

    public function up(): void
    {
        $db = \Config\Database::connect();

        // 1. The gateway-monitor service (seeded under a borrowed daemon name).
        if ($db->tableExists('system_services')) {
            $db->table('system_services')
                ->where('id', 'dpinger')
                ->update(['id' => 'gateway-monitor', 'name' => 'gateway-monitor']);
            $db->table('system_services')
                ->where('id', 'gateway-monitor')
                ->where('name', 'dpinger')
                ->update(['name' => 'gateway-monitor']);
        }

        // 2. Free-text fields whose seed text named a product.
        foreach (self::FIXES as $table => $columns) {
            if (! $db->tableExists($table)) {
                continue;
            }

            foreach ($columns as $column => $map) {
                foreach ($map as $from => $to) {
                    $rows = $db->table($table)
                        ->select($column)
                        ->like($column, $from)
                        ->get()->getResultArray();

                    foreach ($rows as $row) {
                        $value = (string) ($row[$column] ?? '');
                        $clean = str_replace($from, $to, $value);

                        if ($clean !== $value) {
                            $db->table($table)
                                ->where($column, $value)
                                ->update([$column => $clean]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reversible: the previous strings were placeholders,
        // and restoring a borrowed product name has no value.
    }
}
