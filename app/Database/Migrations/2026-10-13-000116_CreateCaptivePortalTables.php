<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCaptivePortalTables extends Migration
{
    public function up()
    {
        // 1. captive_zones
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'auth_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 24,
                'default'    => 'clickthrough', // clickthrough, voucher, local, radius
            ],
            'portal_port' => [
                'type'       => 'INT',
                'default'    => 8088,
            ],
            'portal_ssl_port' => [
                'type'       => 'INT',
                'default'    => 8443,
            ],
            'session_timeout' => [
                'type'       => 'INT',
                'default'    => 60, // in minutes
            ],
            'idle_timeout' => [
                'type'       => 'INT',
                'default'    => 900, // in seconds
            ],
            'default_redirect_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
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
        $this->forge->addKey('slug');
        $this->forge->addKey('interface_id');
        $this->forge->createTable('captive_zones', true);

        // 2. captive_vouchers
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'auto_increment' => true,
            ],
            'zone_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'roll_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'duration_minutes' => [
                'type'    => 'INT',
                'default' => 60,
            ],
            'quota_bytes' => [
                'type'    => 'BIGINT',
                'default' => 0, // 0 = unlimited
            ],
            'bandwidth_down_kbps' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'bandwidth_up_kbps' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'max_devices' => [
                'type'    => 'INT',
                'default' => 1,
            ],
            'used_count' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'active', // active, used, depleted, expired, revoked
            ],
            'first_used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('zone_id');
        $this->forge->addKey('roll_id');
        $this->forge->addKey('code');
        $this->forge->createTable('captive_vouchers', true);

        // 3. captive_sessions
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'zone_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'mac_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 17,
            ],
            'auth_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'bytes_in' => [
                'type'    => 'BIGINT',
                'default' => 0,
            ],
            'bytes_out' => [
                'type'    => 'BIGINT',
                'default' => 0,
            ],
            'connected_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_seen_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'authorized', // authorized, expired, disconnected
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('zone_id');
        $this->forge->addKey('ip_address');
        $this->forge->addKey('mac_address');
        $this->forge->addKey('status');
        $this->forge->createTable('captive_sessions', true);

        // 4. captive_walled_garden
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'auto_increment' => true,
            ],
            'zone_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'target' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('zone_id');
        $this->forge->createTable('captive_walled_garden', true);

        // 5. captive_customizations
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'auto_increment' => true,
            ],
            'zone_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'venue_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'Guest Network Access',
            ],
            'welcome_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'terms_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'primary_color' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => '#4f46e5',
            ],
            'bg_color' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => '#0f172a',
            ],
            'logo_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'custom_css' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addKey('zone_id');
        $this->forge->createTable('captive_customizations', true);

        // Seed initial permissions if permissions table exists
        if ($this->db->tableExists('permissions')) {
            $perms = [
                ['key' => 'captive.view', 'label' => 'View Captive Portal', 'module' => 'Captive Portal', 'description' => 'View captive zones and telemetry'],
                ['key' => 'captive.edit', 'label' => 'Manage Captive Portal', 'module' => 'Captive Portal', 'description' => 'Create and modify captive zones, walled gardens, and branding'],
                ['key' => 'captive.vouchers.view', 'label' => 'View Vouchers', 'module' => 'Captive Portal', 'description' => 'View voucher rolls and vouchers'],
                ['key' => 'captive.vouchers.generate', 'label' => 'Generate Vouchers', 'module' => 'Captive Portal', 'description' => 'Batch generate hotspot vouchers'],
                ['key' => 'captive.vouchers.print', 'label' => 'Print Vouchers', 'module' => 'Captive Portal', 'description' => 'Print voucher slips and thermal tickets'],
                ['key' => 'captive.sessions.view', 'label' => 'View Captive Sessions', 'module' => 'Captive Portal', 'description' => 'Monitor live connected guest sessions'],
                ['key' => 'captive.sessions.disconnect', 'label' => 'Disconnect Sessions', 'module' => 'Captive Portal', 'description' => 'Manually disconnect guest sessions'],
            ];

            $now = date('Y-m-d H:i:s');
            $superAdminRole = $this->db->tableExists('roles')
                ? $this->db->table('roles')->where('name', 'SuperAdmin')->get()->getRowArray()
                : null;

            foreach ($perms as $p) {
                $existing = $this->db->table('permissions')->where('key', $p['key'])->get()->getRowArray();
                $permId = null;

                if ($existing) {
                    $permId = $existing['id'];
                } else {
                    $permId = sprintf(
                        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );

                    $this->db->table('permissions')->insert([
                        'id'          => $permId,
                        'key'         => $p['key'],
                        'label'       => $p['label'],
                        'module'      => $p['module'],
                        'description' => $p['description'],
                        'created_at'  => $now,
                    ]);
                }

                // Grant all to SuperAdmin
                if ($superAdminRole && $permId && $this->db->tableExists('role_permissions')) {
                    $hasRolePerm = $this->db->table('role_permissions')
                        ->where('role_id', $superAdminRole['id'])
                        ->where('permission_id', $permId)
                        ->countAllResults();

                    if ($hasRolePerm === 0) {
                        $this->db->table('role_permissions')->insert([
                            'role_id'       => $superAdminRole['id'],
                            'permission_id' => $permId,
                            'granted'       => 1,
                            'created_at'    => $now,
                        ]);
                    }
                }
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('captive_customizations', true);
        $this->forge->dropTable('captive_walled_garden', true);
        $this->forge->dropTable('captive_sessions', true);
        $this->forge->dropTable('captive_vouchers', true);
        $this->forge->dropTable('captive_zones', true);
    }
}
