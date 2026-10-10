<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSslInspectionTables extends Migration
{
    public function up()
    {
        // 1. ssl_inspection_profiles table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'deep_inspection', // 'deep_inspection', 'certificate_inspection'
            ],
            'ca_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'min_tls_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => '1.2',
            ],
            'allow_invalid_certs' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'block_untrusted_issuers' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'exempt_categories' => [
                'type' => 'TEXT',
                'null' => true, // JSON array of categories (e.g. finance, healthcare, gov)
            ],
            'exempt_domains' => [
                'type' => 'TEXT',
                'null' => true, // Newline-delimited wildcard domain patterns
            ],
            'log_inspected_sessions' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'is_default' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('ssl_inspection_profiles', true);

        // 2. ssl_bypass_domains table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'domain_pattern' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'pinned_app', // 'pinned_app', 'banking', 'healthcare', 'custom'
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('domain_pattern');
        $this->forge->createTable('ssl_bypass_domains', true);

        // 3. ssl_mitm_settings table
        $this->forge->addField([
            'id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'active_profile_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'prof_deep_inspection',
            ],
            'dynamic_cert_cache_size' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10000,
            ],
            'cert_validity_days' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 365,
            ],
            'generate_missing_san' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('ssl_mitm_settings', true);

        // Seed initial profiles, bypass domains, and default settings
        $this->seedInitialData();
    }

    public function down()
    {
        $this->forge->dropTable('ssl_mitm_settings', true);
        $this->forge->dropTable('ssl_bypass_domains', true);
        $this->forge->dropTable('ssl_inspection_profiles', true);
    }

    private function seedInitialData(): void
    {
        $now = date('Y-m-d H:i:s');

        // Settings singleton
        $this->db->table('ssl_mitm_settings')->insert([
            'id'                      => 1,
            'enabled'                 => 1,
            'active_profile_id'       => 'prof_deep_inspection',
            'dynamic_cert_cache_size' => 10000,
            'cert_validity_days'      => 365,
            'generate_missing_san'    => 1,
            'updated_at'              => $now,
        ]);

        // Default Profiles
        $this->db->table('ssl_inspection_profiles')->insert([
            'id'                      => 'prof_deep_inspection',
            'name'                    => 'Deep Inspection (Full MITM Decryption)',
            'description'             => 'Forges dynamic synthetic certificates on the fly, decrypts HTTPS payloads for antivirus and content filtering, and re-encrypts to destination.',
            'mode'                    => 'deep_inspection',
            'ca_id'                   => null,
            'min_tls_version'         => '1.2',
            'allow_invalid_certs'     => 0,
            'block_untrusted_issuers' => 1,
            'exempt_categories'       => json_encode(['finance', 'healthcare', 'government']),
            'exempt_domains'          => "*.bank.com\n*.chase.com\n*.wellsfargo.com\n*.apple.com",
            'log_inspected_sessions'  => 1,
            'is_default'              => 1,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        $this->db->table('ssl_inspection_profiles')->insert([
            'id'                      => 'prof_cert_inspection',
            'name'                    => 'Certificate Inspection (SNI Only / No Decrypt)',
            'description'             => 'Inspects TLS Client Hello Server Name Indication (SNI) and server X.509 certificates without payload decryption or certificate substitution.',
            'mode'                    => 'certificate_inspection',
            'ca_id'                   => null,
            'min_tls_version'         => '1.2',
            'allow_invalid_certs'     => 0,
            'block_untrusted_issuers' => 1,
            'exempt_categories'       => json_encode([]),
            'exempt_domains'          => null,
            'log_inspected_sessions'  => 1,
            'is_default'              => 0,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        // Pre-seeded Certificate Pinned & Banking Bypass Domains
        $bypasses = [
            ['id' => 'byp_apple',     'domain_pattern' => '*.apple.com',         'category' => 'pinned_app', 'reason' => 'Apple Services TLS Certificate Pinning'],
            ['id' => 'byp_icloud',    'domain_pattern' => '*.icloud.com',        'category' => 'pinned_app', 'reason' => 'iCloud Keychains & App Store Pinning'],
            ['id' => 'byp_whatsapp',  'domain_pattern' => '*.whatsapp.net',      'category' => 'pinned_app', 'reason' => 'WhatsApp End-to-End Encryption Channels'],
            ['id' => 'byp_spotify',   'domain_pattern' => '*.spotify.com',       'category' => 'pinned_app', 'reason' => 'Spotify Media Token Authentication'],
            ['id' => 'byp_dropbox',   'domain_pattern' => '*.dropbox.com',       'category' => 'pinned_app', 'reason' => 'Dropbox Desktop Client Certificate Pinning'],
            ['id' => 'byp_microsoft', 'domain_pattern' => '*.login.microsoft.com','category' => 'pinned_app', 'reason' => 'Microsoft Identity & Authenticator App'],
            ['id' => 'byp_chase',     'domain_pattern' => '*.chase.com',         'category' => 'banking',    'reason' => 'JPMorgan Chase Online Banking Privacy'],
            ['id' => 'byp_bofa',      'domain_pattern' => '*.bankofamerica.com', 'category' => 'banking',    'reason' => 'Bank of America Financial Portal'],
            ['id' => 'byp_wells',     'domain_pattern' => '*.wellsfargo.com',    'category' => 'banking',    'reason' => 'Wells Fargo Financial Portal'],
            ['id' => 'byp_irs',       'domain_pattern' => '*.irs.gov',           'category' => 'healthcare', 'reason' => 'Government & Tax Regulatory Privacy'],
        ];

        foreach ($bypasses as $b) {
            $b['enabled']    = 1;
            $b['created_at'] = $now;
            $b['updated_at'] = $now;
            $this->db->table('ssl_bypass_domains')->insert($b);
        }
    }
}
