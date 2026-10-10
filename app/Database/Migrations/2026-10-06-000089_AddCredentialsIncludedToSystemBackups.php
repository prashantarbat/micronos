<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Backup metadata — connector credentials marker.
 *
 * Implements the connector backup policy (docs/MICRONOS_CONNECTOR_ABSTRACTION_
 * SPECIFICATION.md §7.6): a password-encrypted backup may carry the connector
 * master key and the `connector_secrets` rows inside its AES-256-GCM envelope,
 * while a plaintext backup must carry neither. This column records which kind
 * an archive is on `system_backups`, so the UI and the restore path can reason
 * about it without opening the archive:
 *
 *  - is_encrypted        = 1 and credentials_included = 1 → full recovery set
 *  - is_encrypted        = 1 and credentials_included = 0 → config only
 *  - is_encrypted        = 0 and credentials_included = 0 → never holds a credential
 *
 * Idempotent: the column is checked before it is added.
 */
class AddCredentialsIncludedToSystemBackups extends Migration
{
    public function up(): void
    {
        $db = \Config\Database::connect();

        if (! $db->tableExists('system_backups') || $db->fieldExists('credentials_included', 'system_backups')) {
            return;
        }

        $this->forge->addColumn('system_backups', [
            'credentials_included' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
        ]);
    }

    public function down(): void
    {
        $db = \Config\Database::connect();

        if ($db->tableExists('system_backups') && $db->fieldExists('credentials_included', 'system_backups')) {
            $this->forge->dropColumn('system_backups', 'credentials_included');
        }
    }
}
