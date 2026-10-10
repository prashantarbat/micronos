<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds an enable/disable switch to schedule objects.
 *
 * A disabled schedule pauses every Firewall policy, Destination NAT rule and
 * QoS classification rule that references it (they are omitted from the
 * compiled nftables ruleset until the schedule is re-enabled).
 */
class AddEnabledToFirewallSchedules extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('firewall_schedules')) {
            return;
        }

        $cols = array_map(fn ($c) => $c->name, $this->db->getFieldData('firewall_schedules'));
        if (! in_array('enabled', $cols, true)) {
            $this->forge->addColumn('firewall_schedules', [
                'enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                    'after'      => 'is_system',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('firewall_schedules')) {
            return;
        }

        $cols = array_map(fn ($c) => $c->name, $this->db->getFieldData('firewall_schedules'));
        if (in_array('enabled', $cols, true)) {
            $this->forge->dropColumn('firewall_schedules', 'enabled');
        }
    }
}
