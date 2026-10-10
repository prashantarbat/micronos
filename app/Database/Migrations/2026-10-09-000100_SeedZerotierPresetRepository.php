<?php

namespace App\Database\Migrations;

use App\Libraries\RepoPresets;
use App\Libraries\RepoRegistry;
use CodeIgniter\Database\Migration;

/**
 * Seed the ZeroTier preset repository (Plugin Spec §5.2, P3).
 *
 * §11's integration catalogue names two integrations that install from a
 * *vendor* apt repository: NetBird (seeded by
 * `2026-10-06-000093_SeedNetbirdPresetRepository`) and ZeroTier One. This is the
 * second of the two, so a fresh appliance can satisfy either Class C manifest's
 * `requires.repos` without an operator transcribing a URL, a suite and a
 * 40-character fingerprint from a vendor page.
 *
 * **It is seeded disabled.** Shipping trust for a vendor is not the same as
 * fetching from that vendor: nothing reaches `download.zerotier.com` until an
 * operator enables the repository and applies it. That is the Class C consent
 * step, and it happens before any key material or package metadata is requested.
 *
 * The row is written through `RepoRegistry::normalize()`, the same validator the
 * Repositories screen uses, so a preset cannot introduce a field — or a
 * combination of fields — that a hand-entered repository would be refused for.
 *
 * Idempotent: an existing `zerotier` row is left untouched, so an operator's
 * edits (including a changed fingerprint) are never overwritten by a re-run.
 */
class SeedZerotierPresetRepository extends Migration
{
    private const PRESET = 'zerotier';

    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugin_repos')) {
            return;
        }

        $preset = (new RepoPresets())->get(self::PRESET);
        if ($preset === null) {
            return;
        }

        // Never overwrite an existing definition — including one the operator
        // registered by hand before this migration ran.
        if ($db->table('plugin_repos')->where('id', (string) $preset['id'])->countAllResults() > 0) {
            return;
        }

        $normalised = RepoRegistry::normalize(RepoPresets::input($preset));
        if ($normalised['ok'] !== true || $normalised['repo'] === null) {
            return;
        }

        $repo = $normalised['repo'];

        $db->table('plugin_repos')->insert([
            'id'              => (string) $repo['id'],
            'name'            => (string) $repo['name'],
            'url'             => (string) $repo['url'],
            'suite'           => (string) $repo['suite'],
            'components'      => implode(',', (array) $repo['components']),
            'architectures'   => implode(',', (array) $repo['architectures']),
            'key_fingerprint' => (string) $repo['fingerprint'],
            'key_url'         => '',
            'key_armor'       => (string) $repo['key_armor'],
            // Disabled: the operator enables and applies it, which is the point
            // at which the appliance first talks to the vendor.
            'enabled'         => 0,
            'last_outcome'    => '',
            'last_detail'     => '',
            'added_by'        => 'preset',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('plugin_repos')) {
            return;
        }

        // Only the seeded row, and only while it still looks seeded: a
        // repository the operator has since taken ownership of is left alone.
        $db->table('plugin_repos')
            ->where('id', self::PRESET)
            ->where('added_by', 'preset')
            ->delete();
    }
}
