<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRadiusAndLdapAuthTables extends Migration
{
    public function up()
    {
        // 1. Create radius_servers table
        if (! $this->db->tableExists('radius_servers')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                ],
                'hostname' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                ],
                'auth_port' => [
                    'type'    => 'INT',
                    'default' => 1812,
                ],
                'acct_port' => [
                    'type'    => 'INT',
                    'default' => 1813,
                ],
                'secret' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                ],
                'auth_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'default'    => 'pap', // pap, chap, mschapv2
                ],
                'nas_identifier' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => true,
                ],
                'nas_ip' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                'timeout' => [
                    'type'    => 'INT',
                    'default' => 5,
                ],
                'retries' => [
                    'type'    => 'INT',
                    'default' => 3,
                ],
                'acct_enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
                'acct_server' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => true,
                ],
                'default_role_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                ],
                'group_attribute' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'Filter-Id', // Filter-Id, Class, Cisco-AVPair
                ],
                'is_primary' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
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
            $this->forge->createTable('radius_servers', true);
        }

        // 2. Create auth_group_mappings table (maps external LDAP/AD & RADIUS groups to MicronOS Roles)
        if (! $this->db->tableExists('auth_group_mappings')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                ],
                'auth_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'default'    => 'ldap', // 'ldap' or 'radius'
                ],
                'server_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                ],
                'external_group' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                ],
                'role_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                ],
                'priority' => [
                    'type'    => 'INT',
                    'default' => 10,
                ],
                'description' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
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
            $this->forge->createTable('auth_group_mappings', true);
        }

        // 3. Add missing columns to ldap_servers table if needed
        if ($this->db->tableExists('ldap_servers')) {
            $ldapFields = $this->db->getFieldNames('ldap_servers');
            $toAdd = [];
            if (! in_array('schema_type', $ldapFields, true)) {
                $toAdd['schema_type'] = ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'ad'];
            }
            if (! in_array('group_attribute', $ldapFields, true)) {
                $toAdd['group_attribute'] = ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'memberOf'];
            }
            if (! in_array('default_role_id', $ldapFields, true)) {
                $toAdd['default_role_id'] = ['type' => 'VARCHAR', 'constraint' => 36, 'null' => true];
            }
            if (! in_array('timeout', $ldapFields, true)) {
                $toAdd['timeout'] = ['type' => 'INT', 'default' => 5];
            }
            if (! in_array('fallback_local', $ldapFields, true)) {
                $toAdd['fallback_local'] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1];
            }
            if (! empty($toAdd)) {
                $this->forge->addColumn('ldap_servers', $toAdd);
            }
        }

        // 4. Add missing columns to users table
        if ($this->db->tableExists('users')) {
            $userFields = $this->db->getFieldNames('users');
            $toAddUsers = [];
            if (! in_array('ldap_dn', $userFields, true)) {
                $toAddUsers['ldap_dn'] = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
            }
            if (! in_array('external_group', $userFields, true)) {
                $toAddUsers['external_group'] = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
            }
            if (! empty($toAddUsers)) {
                $this->forge->addColumn('users', $toAddUsers);
            }
        }

        // 5. Seed initial default entries if empty
        $now = date('Y-m-d H:i:s');

        // Seed default LDAP server if none exists
        if ($this->db->table('ldap_servers')->countAllResults() === 0) {
            $this->db->table('ldap_servers')->insert([
                'id'                  => 'ldap_default',
                'name'                => 'Corporate Active Directory',
                'hostname'            => 'ad.corp.local',
                'port'                => 636,
                'encryption'          => 'ldaps',
                'base_dn'             => 'DC=corp,DC=local',
                'bind_dn'             => 'CN=MicronSvc,OU=ServiceAccounts,DC=corp,DC=local',
                'bind_password'       => 'P@ssword123',
                'user_search_base'    => 'OU=Users,DC=corp,DC=local',
                'user_search_filter'  => '(&(objectClass=user)(sAMAccountName={username}))',
                'group_search_base'   => 'OU=SecurityGroups,DC=corp,DC=local',
                'admin_group_mapping' => 'Domain Admins',
                'schema_type'         => 'ad',
                'group_attribute'     => 'memberOf',
                'timeout'             => 5,
                'enabled'             => 1,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }

        // Seed default RADIUS server if none exists
        if ($this->db->table('radius_servers')->countAllResults() === 0) {
            $this->db->table('radius_servers')->insert([
                'id'              => 'radius_default',
                'name'            => 'Primary Enterprise RADIUS (NPS)',
                'hostname'        => '10.10.10.25',
                'auth_port'       => 1812,
                'acct_port'       => 1813,
                'secret'          => 'testing123-secret',
                'auth_type'       => 'pap',
                'nas_identifier'  => 'micronos-gw01',
                'nas_ip'          => '10.10.10.1',
                'timeout'         => 5,
                'retries'         => 3,
                'acct_enabled'    => 1,
                'acct_server'     => '10.10.10.25',
                'group_attribute' => 'Filter-Id',
                'is_primary'      => 1,
                'enabled'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // Seed default group mappings
        if ($this->db->table('auth_group_mappings')->countAllResults() === 0) {
            // Find role IDs if available
            $superadminRole = $this->db->table('roles')->where('name', 'superadmin')->get()->getRowArray();
            $operatorRole   = $this->db->table('roles')->where('name', 'operator')->get()->getRowArray();

            $superadminId = $superadminRole['id'] ?? 'role_superadmin';
            $operatorId   = $operatorRole['id'] ?? 'role_operator';

            $this->db->table('auth_group_mappings')->insertBatch([
                [
                    'id'             => 'map_ldap_admins',
                    'auth_type'      => 'ldap',
                    'server_id'      => 'ldap_default',
                    'external_group' => 'Domain Admins',
                    'role_id'        => $superadminId,
                    'priority'       => 10,
                    'description'    => 'Active Directory Domain Admins map to Superadmin privilege',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                [
                    'id'             => 'map_ldap_netops',
                    'auth_type'      => 'ldap',
                    'server_id'      => 'ldap_default',
                    'external_group' => 'Network Operators',
                    'role_id'        => $operatorId,
                    'priority'       => 20,
                    'description'    => 'Active Directory Network Operators map to Operator role',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                [
                    'id'             => 'map_radius_admins',
                    'auth_type'      => 'radius',
                    'server_id'      => 'radius_default',
                    'external_group' => 'Admins',
                    'role_id'        => $superadminId,
                    'priority'       => 10,
                    'description'    => 'RADIUS Filter-Id Admins receives Superadmin privilege',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                [
                    'id'             => 'map_radius_operators',
                    'auth_type'      => 'radius',
                    'server_id'      => 'radius_default',
                    'external_group' => 'Operators',
                    'role_id'        => $operatorId,
                    'priority'       => 20,
                    'description'    => 'RADIUS Filter-Id Operators receives Operator role',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
            ]);
        }
    }

    public function down()
    {
        $this->forge->dropTable('auth_group_mappings', true);
        $this->forge->dropTable('radius_servers', true);
    }
}
