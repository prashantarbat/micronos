<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates firewall_schedules table and adds schedule support to:
 *  - firewall_rules (already present in schema)
 *  - nat_dnat (Destination NAT)
 *  - qos_rules (Traffic Shaper priority rules)
 */
class CreateFirewallSchedulesAndAddScheduleColumns extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        // 1. Create firewall_schedules table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'recurring', // recurring or onetime
            ],
            'days_of_week' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'all', // all or mon,tue,wed,thu,fri,sat,sun
            ],
            'start_time' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => '00:00:00',
            ],
            'end_time' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => '23:59:59',
            ],
            'start_datetime' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'end_datetime' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'is_system' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->createTable('firewall_schedules', true);

        // 2. Pre-seed default schedules
        $now = date('Y-m-d H:i:s');
        $schedules = [
            [
                'id'           => 'sched_always',
                'name'         => 'always',
                'type'         => 'recurring',
                'days_of_week' => 'all',
                'start_time'   => '00:00:00',
                'end_time'     => '23:59:59',
                'is_system'    => 1,
                'description'  => 'Always active schedule (24/7/365 continuous matching)',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id'           => 'sched_workhours',
                'name'         => 'work_hours',
                'type'         => 'recurring',
                'days_of_week' => 'mon,tue,wed,thu,fri',
                'start_time'   => '08:00:00',
                'end_time'     => '18:00:00',
                'is_system'    => 0,
                'description'  => 'Standard business work hours (Mon–Fri 08:00 to 18:00)',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id'           => 'sched_afterhours',
                'name'         => 'after_hours',
                'type'         => 'recurring',
                'days_of_week' => 'mon,tue,wed,thu,fri',
                'start_time'   => '18:00:00',
                'end_time'     => '08:00:00',
                'is_system'    => 0,
                'description'  => 'After business hours (Mon–Fri 18:00 to 08:00 next day)',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id'           => 'sched_weekend',
                'name'         => 'weekend',
                'type'         => 'recurring',
                'days_of_week' => 'sat,sun',
                'start_time'   => '00:00:00',
                'end_time'     => '23:59:59',
                'is_system'    => 0,
                'description'  => 'Weekend days (Saturday & Sunday all day)',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ];

        foreach ($schedules as $s) {
            if ($db->table('firewall_schedules')->where('id', $s['id'])->countAllResults() === 0) {
                $db->table('firewall_schedules')->insert($s);
            }
        }

        // 3. Add schedule column to nat_dnat if not exists
        if ($db->tableExists('nat_dnat')) {
            $existingDnatCols = array_map(fn ($c) => $c->name, $db->getFieldData('nat_dnat'));
            if (! in_array('schedule', $existingDnatCols, true)) {
                $this->forge->addColumn('nat_dnat', [
                    'schedule' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 64,
                        'default'    => 'always',
                        'after'      => 'bidirectional',
                    ],
                ]);
            }
        }

        // 4. Add schedule column to qos_rules if not exists
        if ($db->tableExists('qos_rules')) {
            $existingQosCols = array_map(fn ($c) => $c->name, $db->getFieldData('qos_rules'));
            if (! in_array('schedule', $existingQosCols, true)) {
                $this->forge->addColumn('qos_rules', [
                    'schedule' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 64,
                        'default'    => 'always',
                        'after'      => 'bandwidth_share',
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('firewall_schedules', true);

        if ($this->db->tableExists('nat_dnat')) {
            $existingDnatCols = array_map(fn ($c) => $c->name, $this->db->getFieldData('nat_dnat'));
            if (in_array('schedule', $existingDnatCols, true)) {
                $this->forge->dropColumn('nat_dnat', 'schedule');
            }
        }

        if ($this->db->tableExists('qos_rules')) {
            $existingQosCols = array_map(fn ($c) => $c->name, $this->db->getFieldData('qos_rules'));
            if (in_array('schedule', $existingQosCols, true)) {
                $this->forge->dropColumn('qos_rules', 'schedule');
            }
        }
    }
}
