<?php

namespace App\Database\Migrations;

use App\Libraries\Network\AddressChangeSync;
use CodeIgniter\Database\Migration;

/**
 * Migration 120: network transactions and new factory defaults.
 *
 *  1. `network_transactions` — network changes waiting for confirmation, with
 *     the database rows to restore if the appliance rolls them back.
 *  2. Factory defaults for a settings row nobody has saved yet (updated_at is
 *     NULL): Solar Amber theme, Top nav layout, and IPv6 off unless an
 *     interface is already configured for IPv6. A row an administrator has
 *     saved keeps every preference.
 *  3. English only: `language` is pinned to 'en'; serial console speed is
 *     pinned to 115200 (no longer configurable).
 *  4. The factory LAN rules and DHCP options seeded with literal 10.10.10.x
 *     values become dynamic references ({lan:network}, {lan:address}) when
 *     they still match the LAN, so they follow the LAN address from now on.
 *     Idempotent; administrator-written rules are untouched.
 */
class NetworkDefaultsAndTransactions extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('network_transactions')) {
            $this->forge->addField([
                'id'          => ['type' => 'INTEGER', 'auto_increment' => true],
                'tx'          => ['type' => 'VARCHAR', 'constraint' => 32],
                'status'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
                'actor'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'reason'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'before_json' => ['type' => 'TEXT', 'null' => true],
                'created_at'  => ['type' => 'DATETIME', 'null' => true],
                'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tx');
            $this->forge->createTable('network_transactions', true);
        }

        if ($this->db->tableExists('system_settings')) {
            $row = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
            if ($row !== null) {
                $set = ['language' => 'en', 'console_speed' => '115200'];
                if (empty($row['updated_at'])) {
                    $set['theme']  = 'micronos';
                    $set['layout'] = 'topnav';
                    if (! $this->ipv6InUse()) {
                        $set['ipv6_enabled'] = 0;
                    }
                }
                $fields = $this->db->getFieldNames('system_settings');
                $set    = array_intersect_key($set, array_flip($fields));
                if ($set !== []) {
                    $this->db->table('system_settings')->where('id', 1)->update($set);
                }
            }
        }

        try {
            (new AddressChangeSync($this->db))->reconcileFactoryDefaults();
        } catch (\Throwable $e) {
            log_message('warning', 'migration 120: factory default reconciliation skipped: ' . $e->getMessage());
        }
    }

    public function down()
    {
        $this->forge->dropTable('network_transactions', true);
    }

    /** Whether any interface already carries an IPv6 configuration. */
    private function ipv6InUse(): bool
    {
        if (! $this->db->tableExists('interfaces')) {
            return false;
        }
        $fields = $this->db->getFieldNames('interfaces');
        if (! in_array('ipv6_assignment_type', $fields, true)) {
            return false;
        }

        return $this->db->table('interfaces')
            ->whereNotIn('ipv6_assignment_type', ['disabled', 'none', ''])
            ->where('ipv6_assignment_type IS NOT NULL')
            ->countAllResults() > 0;
    }
}
