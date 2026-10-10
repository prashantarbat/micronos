<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration — comprehensive system settings table
 * Stores all appliance-wide settings in a key/value flat row (single record, id=1).
 */
class CreateSystemSettingsTable extends Migration
{
    public function up(): void
    {
        $this->db->disableForeignKeyChecks();

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],

            // ── Identity ─────────────────────────────────────────────
            'hostname'  => ['type' => 'VARCHAR', 'constraint' => 253, 'default' => 'micronos-fw01'],
            'domain'    => ['type' => 'VARCHAR', 'constraint' => 253, 'default' => 'firewall.local'],
            'micron_id' => ['type' => 'VARCHAR', 'constraint' => 64,  'default' => ''],

            // ── Organisation ─────────────────────────────────────────
            'org_name'         => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'org_contact_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'org_email'        => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'org_phone'        => ['type' => 'VARCHAR', 'constraint' => 64,  'default' => ''],
            'org_address'      => ['type' => 'TEXT',    'null' => true,      'default' => null],
            'org_city'         => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'org_state'        => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'org_country'      => ['type' => 'VARCHAR', 'constraint' => 3,   'default' => ''],
            'org_latitude'     => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true, 'default' => null],
            'org_longitude'    => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true, 'default' => null],
            'org_notes'        => ['type' => 'TEXT',    'null' => true, 'default' => null],

            // ── Time & NTP ───────────────────────────────────────────
            'timezone'          => ['type' => 'VARCHAR', 'constraint' => 64,  'default' => 'UTC'],
            'ntp_servers'       => ['type' => 'VARCHAR', 'constraint' => 512, 'default' => 'pool.ntp.org, time.google.com'],
            'ntp_enabled'       => ['type' => 'TINYINT', 'constraint' => 1,   'default' => 1],
            'ntp_sync_interval' => ['type' => 'VARCHAR', 'constraint' => 16,  'default' => '3600'],

            // ── Web GUI ──────────────────────────────────────────────
            'webgui_protocol'        => ['type' => 'ENUM', 'constraint' => ['http','https'], 'default' => 'https'],
            'webgui_port'            => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 443],
            'webgui_redirect_http'   => ['type' => 'TINYINT',  'constraint' => 1, 'default' => 1],
            'webgui_session_timeout' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 30],
            'webgui_login_banner'    => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'webgui_max_login_attempts' => ['type' => 'TINYINT',  'constraint' => 3, 'unsigned' => true, 'default' => 5],
            'webgui_lockout_duration'   => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 15],

            // ── SSH ──────────────────────────────────────────────────
            'ssh_enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'ssh_port'          => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 22],
            'ssh_auth_methods'  => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'key,password'],
            'ssh_root_login'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'ssh_max_auth_tries'=> ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 3],

            // ── Notifications ────────────────────────────────────────
            'notif_email_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'notif_smtp_host'     => ['type' => 'VARCHAR', 'constraint' => 253, 'default' => ''],
            'notif_smtp_port'     => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 587],
            'notif_smtp_tls'      => ['type' => 'ENUM', 'constraint' => ['none','starttls','tls'], 'default' => 'starttls'],
            'notif_smtp_user'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'notif_smtp_pass'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'notif_smtp_from'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'notif_smtp_to'       => ['type' => 'TEXT', 'null' => true, 'default' => null],

            // ── Console / Hardware ───────────────────────────────────
            'console_speed' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => '115200'],

            // ── Misc ─────────────────────────────────────────────────
            'dns_default_servers' => ['type' => 'VARCHAR', 'constraint' => 512, 'default' => '1.1.1.1, 8.8.8.8'],
            'theme'               => ['type' => 'ENUM', 'constraint' => ['dark','light'], 'default' => 'dark'],
            'language'            => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'en'],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('system_settings', true);

        // Insert the default singleton row
        $uid = strtoupper(
            substr(md5(gethostname()), 0, 8) . '-' .
            substr(md5(uniqid('a', true)), 0, 4) . '-4' .
            substr(md5(uniqid('b', true)), 1, 3) . '-' .
            substr(md5(uniqid('c', true)), 0, 4) . '-' .
            substr(md5(uniqid('d', true)), 0, 12)
        );

        $this->db->table('system_settings')->insert([
            'id'        => 1,
            'hostname'  => 'micronos-fw01',
            'domain'    => 'firewall.local',
            'micron_id' => $uid,
        ]);

        $this->db->enableForeignKeyChecks();
    }

    public function down(): void
    {
        $this->forge->dropTable('system_settings', true);
    }
}
