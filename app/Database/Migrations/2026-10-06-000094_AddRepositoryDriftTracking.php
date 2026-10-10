<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Repository drift tracking (Plugin Spec §5.2, §9.5).
 *
 * A curated repository can be perfectly configured and still be quietly dead:
 * the vendor stops publishing, its signing key reaches the end of its validity,
 * or it rotates the key and the metadata no longer verifies. None of that is
 * visible from a single `Test` click, because a single observation has nothing
 * to compare against. Seeing it needs *state carried between observations*:
 *
 *  - `metadata_date` / `metadata_seen_at` — the `Date:` the vendor's release
 *    metadata last declared, and when that same date was first seen. A date that
 *    never advances is what "the repository stopped updating" actually means, and
 *    it cannot be detected from the current value alone.
 *  - `key_expires_at` / `key_created_at` — the signing key's *own* dates from
 *    gpg. Distinct from the metadata's `Valid-Until`: one says the trust anchor
 *    is about to stop working, the other that the vendor stopped publishing.
 *  - `last_check_at` — when drift was last evaluated. Deliberately separate from
 *    `last_refresh` (apt metadata) and the apply outcome, so a page can say
 *    "checked 2 hours ago" without conflating three different activities.
 *  - `drift_kinds` / `drift_since` / `drift_detail` — the current verdict, when
 *    it began (so "flagged 6 days ago" stays stable rather than resetting on
 *    every sweep), and one line of explanation per active kind.
 *
 * Idempotent: every column is checked before it is added.
 */
class AddRepositoryDriftTracking extends Migration
{
    /**
     * column => forge definition.
     *
     * @var array<string, array<string, mixed>>
     */
    private const COLUMNS = [
        'metadata_date' => [
            'type'    => 'DATETIME',
            'null'    => true,
            'comment' => 'The Date: the vendor release metadata last declared',
        ],
        'metadata_seen_at' => [
            'type'    => 'DATETIME',
            'null'    => true,
            'comment' => 'When that same metadata Date: was first observed',
        ],
        'key_created_at' => [
            'type'    => 'DATETIME',
            'null'    => true,
            'comment' => 'Signing key creation date, from gpg',
        ],
        'key_expires_at' => [
            'type'    => 'DATETIME',
            'null'    => true,
            'comment' => 'Signing key expiry date, from gpg; NULL when it never expires',
        ],
        'last_check_at' => [
            'type'    => 'DATETIME',
            'null'    => true,
            'comment' => 'When the drift sweep last evaluated this repository',
        ],
        'drift_kinds' => [
            'type'       => 'VARCHAR',
            'constraint' => 255,
            'null'       => true,
            'comment'    => 'Comma-separated active drift kinds, empty when healthy',
        ],
        'drift_since' => [
            'type'    => 'DATETIME',
            'null'    => true,
            'comment' => 'When the current drift state began',
        ],
        'drift_detail' => [
            'type' => 'TEXT',
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
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugin_repos')) {
            return;
        }

        $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('plugin_repos'));

        foreach (array_keys(self::COLUMNS) as $column) {
            if (in_array($column, $existing, true)) {
                $this->forge->dropColumn('plugin_repos', $column);
            }
        }
    }
}
