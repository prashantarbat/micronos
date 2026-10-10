<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Suricata Inline IPS — NFQUEUE Controls
 *
 * Suricata's compiled `nfq:` block tells the engine which Netfilter queue to
 * read, but the queue is only populated if the firewall ruleset diverts packets
 * into it. These columns parameterise that diversion, emitted by
 * NftablesCompiler as `queue num <n>` inside the `micron_ids` table:
 *
 *  - nfqueue_num     : the Netfilter queue number (Suricata default 0)
 *  - queue_fail_mode : 'open' adds the `bypass` flag so forwarding continues if
 *                      no queue reader is attached (a crashed IPS must never
 *                      blackhole the network); 'closed' omits it so traffic is
 *                      dropped when the engine is unavailable.
 *
 * Idempotent: each column is checked before it is added.
 */
class AddNfqueueControlsToIdsSettings extends Migration
{
    private const COLUMNS = [
        'nfqueue_num'     => ['INT', null, 0],
        'queue_fail_mode' => ['VARCHAR', 16, 'open'],
    ];

    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('ids_settings')) {
            return;
        }

        foreach (self::COLUMNS as $name => [$type, $constraint, $default]) {
            if ($db->fieldExists($name, 'ids_settings')) {
                continue;
            }

            $field = ['type' => $type, 'default' => $default];
            if ($constraint !== null) {
                $field['constraint'] = $constraint;
            }

            $this->forge->addColumn('ids_settings', [$name => $field]);
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('ids_settings')) {
            return;
        }

        foreach (array_keys(self::COLUMNS) as $name) {
            if ($db->fieldExists($name, 'ids_settings')) {
                $this->forge->dropColumn('ids_settings', $name);
            }
        }
    }
}
