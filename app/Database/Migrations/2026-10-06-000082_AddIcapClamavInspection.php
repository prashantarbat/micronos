<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * C-ICAP + ClamAV Antivirus Inspection
 *
 * Adds the configuration surface for streaming HTTP payload malware scanning
 * through a C-ICAP bridge (`c-icap` offloading to the multi-threaded
 * `clamav-daemon` socket):
 *
 *  - webfilter_settings columns : ICAP endpoint, scan mode, preview/scan size,
 *                                 failure policy, bypass domains and the
 *                                 ClamAV definitions telemetry
 *  - webfilter_av_detections    : log of blocked/alerted payloads
 *  - system_services seed       : the `clamav` and `c-icap` daemons so the
 *                                 per-page service controls can render them
 *
 * Every step is idempotent (columns checked first, rows keyed by id) so the
 * migration is safe to re-run against an existing appliance.
 */
class AddIcapClamavInspection extends Migration
{
    /** Columns added to webfilter_settings: name => [type, constraint, default]. */
    private const SETTINGS_COLUMNS = [
        'icap_host'                => ['VARCHAR', 64,  '127.0.0.1'],
        'icap_port'                => ['INT',      null, 1344],
        'icap_service'             => ['VARCHAR', 64,  'squidclamav'],
        'icap_scan_mode'           => ['VARCHAR', 16,  'response'], // request|response|both
        'icap_preview_kb'          => ['INT',      null, 1024],
        'icap_max_file_mb'         => ['INT',      null, 25],
        'icap_failure_mode'        => ['VARCHAR', 16,  'allow'],    // allow|block
        'icap_scan_https'          => ['TINYINT',  1,   1],
        'icap_bypass_domains'      => ['TEXT',     null, null],
        'clamav_update_schedule'   => ['VARCHAR', 32,  'daily'],
        'clamav_last_update_at'    => ['DATETIME', null, null],
        'clamav_signatures'        => ['INT',      null, 0],
        'clamav_defs_version'      => ['VARCHAR', 64,  null],
        'clamav_last_update_status'=> ['VARCHAR', 255, null],
    ];

    public function up(): void
    {
        $db = \Config\Database::connect();

        $this->addSettingsColumns($db);
        $this->createDetectionsTable($db);
        $this->seedDaemons($db);
    }

    private function addSettingsColumns($db): void
    {
        foreach (self::SETTINGS_COLUMNS as $name => [$type, $constraint, $default]) {
            if ($db->fieldExists($name, 'webfilter_settings')) {
                continue;
            }

            $field = ['type' => $type, 'null' => $default === null];
            if ($constraint !== null) {
                $field['constraint'] = $constraint;
            }
            if ($default !== null) {
                $field['default'] = $default;
            }

            $this->forge->addColumn('webfilter_settings', [$name => $field]);
        }
    }

    private function createDetectionsTable($db): void
    {
        if ($db->tableExists('webfilter_av_detections')) {
            return;
        }

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'detected_at' => ['type' => 'DATETIME', 'null' => true],
            'client_ip'   => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'domain'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'url'         => ['type' => 'TEXT', 'null' => true],
            'filename'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'threat_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'file_size'   => ['type' => 'BIGINT', 'default' => 0],
            'sha256'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'action'      => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'block'], // block|alert
            'engine'      => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'clamav'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('detected_at');
        $this->forge->createTable('webfilter_av_detections', true);
    }

    private function seedDaemons($db): void
    {
        $now = date('Y-m-d H:i:s');

        $daemons = [
            [
                'id'                 => 'clamav',
                'name'               => 'clamav-daemon',
                'subsystem'          => 'security',
                'description'        => 'ClamAV Multi-Threaded Antivirus Signature Scanning Engine',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1512,
                'memory_mb'          => 412.6,
            ],
            [
                'id'                 => 'c-icap',
                'name'               => 'c-icap',
                'subsystem'          => 'security',
                'description'        => 'C-ICAP RFC 3507 Content Adaptation Bridge for Squid',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1498,
                'memory_mb'          => 38.2,
            ],
        ];

        foreach ($daemons as $srv) {
            if ($db->table('system_services')->where('id', $srv['id'])->countAllResults() === 0) {
                $srv['created_at'] = $now;
                $srv['updated_at'] = $now;
                $db->table('system_services')->insert($srv);
            }
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        foreach (array_keys(self::SETTINGS_COLUMNS) as $name) {
            if ($db->fieldExists($name, 'webfilter_settings')) {
                $this->forge->dropColumn('webfilter_settings', $name);
            }
        }

        $this->forge->dropTable('webfilter_av_detections', true);
        $db->table('system_services')->whereIn('id', ['clamav', 'c-icap'])->delete();
    }
}
