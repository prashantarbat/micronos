<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Install check-in switch and its last outcome.
 *
 * On by default: the installer and System > Networking say what is sent, and
 * one switch turns it off. The last-run columns are written by
 * `php spark micron:checkin` without touching `updated_at`, so a check-in never
 * changes the "Last saved" time it reports.
 */
class AddInstallCheckinToSystemSettings extends Migration
{
    public function up(): void
    {
        $fields = [];
        if (! $this->db->fieldExists('telemetry_enabled', 'system_settings')) {
            $fields['telemetry_enabled'] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1];
        }
        if (! $this->db->fieldExists('telemetry_last_at', 'system_settings')) {
            $fields['telemetry_last_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! $this->db->fieldExists('telemetry_last_status', 'system_settings')) {
            $fields['telemetry_last_status'] = ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''];
        }
        if ($fields !== []) {
            $this->forge->addColumn('system_settings', $fields);
        }
    }

    public function down(): void
    {
        foreach (['telemetry_enabled', 'telemetry_last_at', 'telemetry_last_status'] as $f) {
            if ($this->db->fieldExists($f, 'system_settings')) {
                $this->forge->dropColumn('system_settings', $f);
            }
        }
    }
}
