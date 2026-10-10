<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Plugin & package manager schema (Plugin Spec §10).
 *
 * Adds the persistence for installed plugins, their capability grants, curated
 * repositories, settings and the install/rollback log, and seeds the static
 * `plugins.*` RBAC permissions. Permissions are created here, at migration
 * time, never registered at runtime by a bundle — a plugin manifest may only
 * *reference* a key that already exists (Plugin Spec §9, PL9).
 *
 * Idempotent: every table is created with IF NOT EXISTS and each permission
 * row is checked before it is inserted.
 */
class CreatePluginTables extends Migration
{
    /**
     * Static permission catalogue. Key => [label, module, description].
     */
    private const PERMISSIONS = [
        'plugins.view'          => ['View Plugins', 'Plugins', 'See installed and available plugins'],
        'plugins.install'       => ['Install Plugins', 'Plugins', 'Install, remove and upgrade plugins'],
        'plugins.configure'     => ['Configure Plugins', 'Plugins', 'Edit plugin settings and enable/disable'],
        'plugins.repo.refresh'  => ['Refresh Plugin Repos', 'Plugins', 'Refresh curated repository metadata'],
        'plugins.audit.view'    => ['View Plugin Audit', 'Plugins', 'Read the plugin install and rollback history'],
    ];

    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugins')) {
            $this->forge->addField([
                'id'            => ['type' => 'VARCHAR', 'constraint' => 64],
                'name'          => ['type' => 'VARCHAR', 'constraint' => 128],
                'version'       => ['type' => 'VARCHAR', 'constraint' => 32],
                'class'         => ['type' => 'VARCHAR', 'constraint' => 1],
                'state'         => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'installed'],
                'installed_at'  => ['type' => 'DATETIME', 'null' => true],
                'enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'data_policy'   => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'retain'],
                'manifest_json' => ['type' => 'TEXT', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('plugins', true);
        }

        if (! $db->tableExists('plugin_grants')) {
            $this->forge->addField([
                'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
                'plugin_id'  => ['type' => 'VARCHAR', 'constraint' => 64],
                'capability' => ['type' => 'VARCHAR', 'constraint' => 128],
                'granted'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'granted_by' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'granted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['plugin_id', 'capability'], false, true);
            $this->forge->createTable('plugin_grants', true);
        }

        if (! $db->tableExists('plugin_repos')) {
            $this->forge->addField([
                'id'              => ['type' => 'VARCHAR', 'constraint' => 64],
                'url'             => ['type' => 'VARCHAR', 'constraint' => 255],
                'key_fingerprint' => ['type' => 'VARCHAR', 'constraint' => 64],
                'enabled'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'last_refresh'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('plugin_repos', true);
        }

        if (! $db->tableExists('plugin_settings')) {
            $this->forge->addField([
                'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
                'plugin_id'  => ['type' => 'VARCHAR', 'constraint' => 64],
                'setting_key' => ['type' => 'VARCHAR', 'constraint' => 64],
                'value'      => ['type' => 'TEXT', 'null' => true],
                'is_secret'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['plugin_id', 'setting_key'], false, true);
            $this->forge->createTable('plugin_settings', true);
        }

        if (! $db->tableExists('plugin_install_log')) {
            $this->forge->addField([
                'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
                'plugin_id'  => ['type' => 'VARCHAR', 'constraint' => 64],
                'action'     => ['type' => 'VARCHAR', 'constraint' => 16],
                'outcome'    => ['type' => 'VARCHAR', 'constraint' => 16],
                'plan'       => ['type' => 'TEXT', 'null' => true],
                'detail'     => ['type' => 'TEXT', 'null' => true],
                'user_id'    => ['type' => 'VARCHAR', 'constraint' => 36, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('plugin_install_log', true);
        }

        $this->seedPermissions($db);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        $this->forge->dropTable('plugin_install_log', true);
        $this->forge->dropTable('plugin_settings', true);
        $this->forge->dropTable('plugin_repos', true);
        $this->forge->dropTable('plugin_grants', true);
        $this->forge->dropTable('plugins', true);

        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
        }
    }

    private function seedPermissions(\CodeIgniter\Database\BaseConnection $db): void
    {
        if (! $db->tableExists('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $key => [$label, $module, $description]) {
            $exists = $db->table('permissions')->where('key', $key)->countAllResults();
            if ($exists > 0) {
                continue;
            }

            $db->table('permissions')->insert([
                'id'          => $this->uuid(),
                'key'         => $key,
                'label'       => $label,
                'module'      => $module,
                'description' => $description,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Deterministic-enough UUIDv4 (no external dependency).
     */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
