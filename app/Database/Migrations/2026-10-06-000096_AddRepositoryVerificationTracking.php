<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Verification-regression tracking (Plugin Spec §5.2, §9.5).
 *
 * The drift sweep re-verifies every repository in service, so a source that was
 * trustworthy yesterday and is not today is the one event an operator most needs
 * to hear about. Detecting it needs one more piece of carried state:
 *
 *  - `last_verified_at` — the last time a verification **succeeded**. It is
 *    deliberately sticky: a failure leaves it in place rather than clearing it,
 *    so "this repository verified before and no longer does" stays distinguishable
 *    from "this repository has never verified". Without it, both look like the
 *    same failed check, and the regression — the interesting one — is invisible.
 *
 * Separate from `last_check_at` (when it was last *looked at*) and from
 * `last_refresh` (apt metadata): three different activities, three fields.
 *
 * Idempotent: the column is checked before it is added.
 */
class AddRepositoryVerificationTracking extends Migration
{
    private const COLUMN = 'last_verified_at';

    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugin_repos')) {
            return;
        }

        $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('plugin_repos'));
        if (in_array(self::COLUMN, $existing, true)) {
            return;
        }

        $this->forge->addColumn('plugin_repos', [
            self::COLUMN => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => "When this repository's verification last succeeded",
            ],
        ]);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugin_repos')) {
            return;
        }

        $existing = array_map(static fn ($c) => $c->name, $db->getFieldData('plugin_repos'));
        if (in_array(self::COLUMN, $existing, true)) {
            $this->forge->dropColumn('plugin_repos', self::COLUMN);
        }
    }
}
