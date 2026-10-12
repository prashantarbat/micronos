<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSsoTables extends Migration
{
    public function up()
    {
        // 1. sso_providers table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'oidc', 'saml'
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'discovery_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'client_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'client_secret' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'auth_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'token_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'userinfo_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'scopes' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'openid profile email',
            ],
            'idp_entity_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'idp_sso_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'idp_x509_cert' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sp_entity_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'auto_provision' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'default_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'Operator',
            ],
            'role_mapping_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'allow_unmapped_users' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'icon' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'shield-lock',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('sso_providers', true);

        // 2. sso_states table (CSRF state & PKCE)
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'state_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'provider_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'nonce' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'code_verifier' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'relay_state' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('state_token');
        $this->forge->addKey('provider_id');
        $this->forge->createTable('sso_states', true);

        // 3. sso_settings table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'sso_enforced' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'allow_local_fallback' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'default_provider_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('sso_settings', true);

        // Add sso_sub to users if not present
        if ($this->db->tableExists('users')) {
            $userFields = $this->db->getFieldNames('users');
            if (! in_array('sso_sub', $userFields, true)) {
                $this->forge->addColumn('users', [
                    'sso_sub' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 255,
                        'null'       => true,
                    ],
                ]);
            }
        }

        // Seed initial sso_settings
        $now = date('Y-m-d H:i:s');
        $this->db->table('sso_settings')->insert([
            'id'                   => 'global_sso_settings',
            'sso_enforced'         => 0,
            'allow_local_fallback' => 1,
            'default_provider_id'  => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        // Seed initial preset templates (disabled until credentials supplied)
        $this->db->table('sso_providers')->insertBatch([
            [
                'id'                   => 'prov_entra',
                'name'                 => 'Microsoft Entra ID (Azure AD)',
                'slug'                 => 'azure-ad',
                'protocol'             => 'oidc',
                'enabled'              => 0,
                'discovery_url'        => 'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration',
                'client_id'            => '',
                'client_secret'        => '',
                'auth_url'             => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url'            => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'userinfo_url'         => 'https://graph.microsoft.com/oidc/userinfo',
                'scopes'               => 'openid profile email',
                'idp_entity_id'        => null,
                'idp_sso_url'          => null,
                'idp_x509_cert'        => null,
                'sp_entity_id'         => null,
                'auto_provision'       => 1,
                'default_role'         => 'Operator',
                'role_mapping_json'    => json_encode([
                    'Global Administrator' => 'SuperAdmin',
                    'Security Administrator'=> 'SuperAdmin',
                    'Network Administrator' => 'Administrator',
                    'Helpdesk Operator'    => 'Operator',
                ]),
                'allow_unmapped_users' => 1,
                'icon'                 => 'microsoft',
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'id'                   => 'prov_okta',
                'name'                 => 'Okta Workforce Identity',
                'slug'                 => 'okta',
                'protocol'             => 'saml',
                'enabled'              => 0,
                'discovery_url'        => null,
                'client_id'            => null,
                'client_secret'        => null,
                'auth_url'             => null,
                'token_url'            => null,
                'userinfo_url'         => null,
                'scopes'               => 'openid profile email groups',
                'idp_entity_id'        => 'http://www.okta.com/exk1exampleEntityId',
                'idp_sso_url'          => 'https://dev-company.okta.com/app/dev-company_micronos_1/sso/saml',
                'idp_x509_cert'        => "-----BEGIN CERTIFICATE-----\nMIIDpDCCAoygAwIBAgIGAXexampleCertificateOkta==\n-----END CERTIFICATE-----",
                'sp_entity_id'         => 'micronos-sp',
                'auto_provision'       => 1,
                'default_role'         => 'Operator',
                'role_mapping_json'    => json_encode([
                    'Okta-SuperAdmins' => 'SuperAdmin',
                    'Okta-NetOps'      => 'Administrator',
                    'Okta-Viewers'     => 'Viewer',
                ]),
                'allow_unmapped_users' => 1,
                'icon'                 => 'shield-check',
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'id'                   => 'prov_google',
                'name'                 => 'Google Workspace',
                'slug'                 => 'google',
                'protocol'             => 'oidc',
                'enabled'              => 0,
                'discovery_url'        => 'https://accounts.google.com/.well-known/openid-configuration',
                'client_id'            => '',
                'client_secret'        => '',
                'auth_url'             => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url'            => 'https://oauth2.googleapis.com/token',
                'userinfo_url'         => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scopes'               => 'openid profile email',
                'idp_entity_id'        => null,
                'idp_sso_url'          => null,
                'idp_x509_cert'        => null,
                'sp_entity_id'         => null,
                'auto_provision'       => 1,
                'default_role'         => 'Operator',
                'role_mapping_json'    => json_encode([]),
                'allow_unmapped_users' => 1,
                'icon'                 => 'google',
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [
                'id'                   => 'prov_keycloak',
                'name'                 => 'Keycloak IAM',
                'slug'                 => 'keycloak',
                'protocol'             => 'oidc',
                'enabled'              => 0,
                'discovery_url'        => 'https://auth.company.local/realms/master/.well-known/openid-configuration',
                'client_id'            => 'micronos-appliance',
                'client_secret'        => '',
                'auth_url'             => 'https://auth.company.local/realms/master/protocol/openid-connect/auth',
                'token_url'            => 'https://auth.company.local/realms/master/protocol/openid-connect/token',
                'userinfo_url'         => 'https://auth.company.local/realms/master/protocol/openid-connect/userinfo',
                'scopes'               => 'openid profile email roles',
                'idp_entity_id'        => null,
                'idp_sso_url'          => null,
                'idp_x509_cert'        => null,
                'sp_entity_id'         => null,
                'auto_provision'       => 1,
                'default_role'         => 'Operator',
                'role_mapping_json'    => json_encode([
                    'realm:admin' => 'SuperAdmin',
                    'realm:operator' => 'Operator',
                ]),
                'allow_unmapped_users' => 1,
                'icon'                 => 'key',
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('sso_settings', true);
        $this->forge->dropTable('sso_states', true);
        $this->forge->dropTable('sso_providers', true);

        if ($this->db->tableExists('users')) {
            $userFields = $this->db->getFieldNames('users');
            if (in_array('sso_sub', $userFields, true)) {
                $this->forge->dropColumn('users', ['sso_sub']);
            }
        }
    }
}
