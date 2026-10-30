<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TLS certificate selection and Let's Encrypt state for the Clientless Gateway.
 *
 *  - cert_id         : pki_certificates.id the gateway presents (0 = self-signed)
 *  - acme_enabled    : the operator asked for a Let's Encrypt certificate, so WAN
 *                      port 80 is answered by the gateway's ACME listener
 *  - acme_email      : contact address registered with Let's Encrypt
 *  - acme_staging    : issue from the Let's Encrypt staging directory (testing)
 *  - acme_last_error : why the last issuance failed, shown on the settings page
 */
class AddGatewayCertificateToZtnaSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ztna_settings', [
            'cert_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'acme_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'acme_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'default'    => '',
            ],
            'acme_staging' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'acme_last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ztna_settings', ['cert_id', 'acme_enabled', 'acme_email', 'acme_staging', 'acme_last_error']);
    }
}
