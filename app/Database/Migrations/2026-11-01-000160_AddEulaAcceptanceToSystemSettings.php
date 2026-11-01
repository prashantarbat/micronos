<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which version of the Micron OS licence agreement an administrator accepted
 * in the web interface, when, and who. Empty until the first acceptance, so a
 * fresh appliance asks.
 */
class AddEulaAcceptanceToSystemSettings extends Migration
{
    public function up(): void
    {
        $fields = [];
        if (! $this->db->fieldExists('eula_version', 'system_settings')) {
            $fields['eula_version'] = ['type' => 'VARCHAR', 'constraint' => 20, 'default' => ''];
        }
        if (! $this->db->fieldExists('eula_accepted_at', 'system_settings')) {
            $fields['eula_accepted_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! $this->db->fieldExists('eula_accepted_by', 'system_settings')) {
            $fields['eula_accepted_by'] = ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''];
        }
        if ($fields !== []) {
            $this->forge->addColumn('system_settings', $fields);
        }
    }

    public function down(): void
    {
        foreach (['eula_version', 'eula_accepted_at', 'eula_accepted_by'] as $f) {
            if ($this->db->fieldExists($f, 'system_settings')) {
                $this->forge->dropColumn('system_settings', $f);
            }
        }
    }
}
