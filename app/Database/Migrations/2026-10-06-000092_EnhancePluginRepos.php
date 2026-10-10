<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Curated plugin repositories (Plugin Spec §5.2, §10).
 *
 * `plugin_repos` shipped with the plugin tables as a four-column placeholder
 * (`id`, `url`, `key_fingerprint`, `enabled`, `last_refresh`) that nothing read.
 * Repository management turns it into the real registry: a repository is a
 * signed apt source with a *declared* signing-key fingerprint, so the fields
 * needed to generate `/etc/apt/sources.list.d/micron-<id>.sources`,
 * `/etc/apt/preferences.d/micron-<id>.pref` and a private keyring are stored
 * next to it.
 *
 * The two columns that carry the trust model are `key_fingerprint` (the value
 * the operator states *out of band*, before any key material is fetched) and
 * `key_armor` (the key material itself, public and therefore not a secret, kept
 * so a repository survives a restore without a network round-trip).
 *
 * Idempotent: each column is checked before it is added, and the permission row
 * before it is inserted.
 */
class EnhancePluginRepos extends Migration
{
    /**
     * Presets are rows seeded at image-build time; `repo.manage` is the
     * operator-facing CRUD grant, kept separate from the metadata-only
     * `plugins.repo.refresh`.
     */
    private const PERMISSION = [
        'plugins.repo.manage' => [
            'Manage Plugin Repos',
            'Plugins',
            'Add, edit, enable and remove curated plugin repositories',
        ],
    ];

    /**
     * column => forge definition.
     *
     * @var array<string, array<string, mixed>>
     */
    private const COLUMNS = [
        'name' => [
            'type'       => 'VARCHAR',
            'constraint' => 128,
            'null'       => true,
        ],
        'suite' => [
            'type'       => 'VARCHAR',
            'constraint' => 64,
            'null'       => true,
            'comment'    => 'Distribution suite (e.g. stable), or ./ for a flat repository',
        ],
        'components' => [
            'type'       => 'VARCHAR',
            'constraint' => 255,
            'null'       => true,
            'comment'    => 'Comma-separated component list; empty for a flat repository',
        ],
        'architectures' => [
            'type'       => 'VARCHAR',
            'constraint' => 64,
            'null'       => true,
            'comment'    => 'Comma-separated Debian architecture list',
        ],
        'key_url' => [
            'type'       => 'VARCHAR',
            'constraint' => 255,
            'null'       => true,
            'comment'    => 'HTTPS URL the signing key is fetched from, when not stored inline',
        ],
        'key_armor' => [
            'type'    => 'TEXT',
            'null'    => true,
            'comment' => 'ASCII-armored public signing key (public data, safe to back up)',
        ],
        'last_outcome' => [
            'type'       => 'VARCHAR',
            'constraint' => 16,
            'null'       => true,
        ],
        'last_detail' => [
            'type' => 'TEXT',
            'null' => true,
        ],
        'added_by' => [
            'type'       => 'VARCHAR',
            'constraint' => 64,
            'null'       => true,
        ],
        'created_at' => [
            'type' => 'DATETIME',
            'null' => true,
        ],
    ];

    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugin_repos')) {
            return;
        }

        $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('plugin_repos'));

        foreach (self::COLUMNS as $column => $definition) {
            if (in_array($column, $existing, true)) {
                continue;
            }

            $this->forge->addColumn('plugin_repos', [$column => $definition]);
        }

        $this->seedPermission($db);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if ($db->tableExists('plugin_repos')) {
            $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('plugin_repos'));

            foreach (array_keys(self::COLUMNS) as $column) {
                if (in_array($column, $existing, true)) {
                    $this->forge->dropColumn('plugin_repos', $column);
                }
            }
        }

        if ($db->tableExists('permissions')) {
            $db->table('permissions')->whereIn('key', array_keys(self::PERMISSION))->delete();
        }
    }

    private function seedPermission(\CodeIgniter\Database\BaseConnection $db): void
    {
        if (! $db->tableExists('permissions')) {
            return;
        }

        foreach (self::PERMISSION as $key => [$label, $module, $description]) {
            if ($db->table('permissions')->where('key', $key)->countAllResults() > 0) {
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

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
