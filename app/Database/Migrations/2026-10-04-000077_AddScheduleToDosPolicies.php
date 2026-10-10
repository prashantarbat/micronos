<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds schedule support to DoS / DDoS mitigation policies (/firewall/dos),
 * bringing them in line with firewall_rules, nat_dnat and qos_rules.
 */
class AddScheduleToDosPolicies extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('dos_policies')) {
            return;
        }

        $cols = array_map(fn ($c) => $c->name, $this->db->getFieldData('dos_policies'));
        if (! in_array('schedule', $cols, true)) {
            $this->forge->addColumn('dos_policies', [
                'schedule' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'default'    => 'always',
                    'after'      => 'interface_id',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('dos_policies')) {
            return;
        }

        $cols = array_map(fn ($c) => $c->name, $this->db->getFieldData('dos_policies'));
        if (in_array('schedule', $cols, true)) {
            $this->forge->dropColumn('dos_policies', 'schedule');
        }
    }
}
