<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Brings the PKI store up to a full certificate-manager model.
 *
 * A CA or certificate can either be *generated* or *imported* — the latter
 * an existing one (paste PEM, or upload the file). A certificate can also start
 * life as a Certificate Signing Request (CSR) whose signed certificate is
 * pasted back later. Until now MicronOS could only generate, so third-party /
 * public CA material and externally-issued certificates could not be installed.
 *
 *  - pki_authorities.source  : 'generated' | 'imported'
 *  - pki_certificates.source : 'generated' | 'imported' | 'csr'
 *  - pki_certificates.csr    : the PEM CSR while it awaits signing
 *  - system_settings.webgui_cert_id : certificate bound to the Web GUI / portal
 *    (the certificate the Web GUI and other services present to clients).
 */
class AddPkiImportSupport extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pki_authorities', [
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'generated',
                'after'      => 'is_default',
            ],
        ]);

        $this->forge->addColumn('pki_certificates', [
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'generated',
                'after'      => 'description',
            ],
            'csr' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'private_key',
            ],
        ]);

        $this->forge->addColumn('system_settings', [
            'webgui_cert_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
                'after'      => 'webgui_lockout_duration',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('system_settings', 'webgui_cert_id');
        $this->forge->dropColumn('pki_certificates', ['source', 'csr']);
        $this->forge->dropColumn('pki_authorities', 'source');
    }
}
