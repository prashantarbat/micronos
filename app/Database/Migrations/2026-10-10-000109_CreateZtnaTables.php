<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateZtnaTables extends Migration
{
    public function up()
    {
        // 1. ztna_resources
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
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32, // 'web', 'rdp', 'ssh', 'vnc'
            ],
            'internal_host' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'internal_port' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'icon' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'globe', // 'globe', 'desktop', 'terminal', 'server'
            ],
            'require_auth' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'allowed_roles' => [
                'type' => 'TEXT',
                'null' => true, // JSON array of role names
            ],
            'active_sessions_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'online',
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('slug');
        $this->forge->addKey('type');
        $this->forge->createTable('ztna_resources', true);

        // 2. ztna_sessions
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'session_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'user_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'resource_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'client_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'user_agent' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
            ],
            'last_active_at' => [
                'type' => 'DATETIME',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'active', // 'active', 'terminated'
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('session_token');
        $this->forge->addKey('resource_id');
        $this->forge->createTable('ztna_sessions', true);

        // 3. ztna_settings
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
            'portal_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'Micron OS Clientless Gateway',
            ],
            'external_domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'gateway.micronos.local',
            ],
            'guacd_host' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '127.0.0.1',
            ],
            'guacd_port' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 4822,
            ],
            'session_timeout_mins' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 60,
            ],
            'require_mfa' => [
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
        $this->forge->createTable('ztna_settings', true);

        // Seed initial resources & settings
        $this->seedInitialData();
    }

    public function down()
    {
        $this->forge->dropTable('ztna_settings', true);
        $this->forge->dropTable('ztna_sessions', true);
        $this->forge->dropTable('ztna_resources', true);
    }

    private function seedInitialData(): void
    {
        $now = date('Y-m-d H:i:s');

        // Settings singleton
        $this->db->table('ztna_settings')->insert([
            'id'                   => 1,
            'enabled'              => 1,
            'portal_title'         => 'Micron OS Clientless Gateway',
            'external_domain'      => 'gateway.micronos.local',
            'guacd_host'           => '127.0.0.1',
            'guacd_port'           => 4822,
            'session_timeout_mins' => 60,
            'require_mfa'          => 1,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        // Published Applications & Desktops
        $this->db->table('ztna_resources')->insertBatch([
            [
                'id'                    => 'res_erp',
                'name'                  => 'Corporate Financial ERP',
                'slug'                  => 'erp',
                'type'                  => 'web',
                'internal_host'         => '192.168.10.50',
                'internal_port'         => 8080,
                'description'           => 'Internal SAP & Financial Accounting Web Application with SSO Identity Header Injection',
                'icon'                  => 'globe',
                'require_auth'          => 1,
                'allowed_roles'         => json_encode(['SuperAdmin', 'Administrator', 'Finance']),
                'active_sessions_count' => 0,
                'status'                => 'online',
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'res_win_dc',
                'name'                  => 'Domain Controller DC-01 (Windows RDP)',
                'slug'                  => 'dc01-rdp',
                'type'                  => 'rdp',
                'internal_host'         => '192.168.10.10',
                'internal_port'         => 3389,
                'description'           => 'Windows Server Active Directory Management Console in standard HTML5 Canvas',
                'icon'                  => 'desktop',
                'require_auth'          => 1,
                'allowed_roles'         => json_encode(['SuperAdmin', 'Administrator']),
                'active_sessions_count' => 0,
                'status'                => 'online',
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'res_bastion_ssh',
                'name'                  => 'Linux Production Bastion (SSH)',
                'slug'                  => 'bastion-ssh',
                'type'                  => 'ssh',
                'internal_host'         => '192.168.20.5',
                'internal_port'         => 22,
                'description'           => 'Hardened DMZ Secure Shell Bastion Host in browser xterm.js terminal emulator',
                'icon'                  => 'terminal',
                'require_auth'          => 1,
                'allowed_roles'         => json_encode(['SuperAdmin', 'Administrator', 'DevOps']),
                'active_sessions_count' => 0,
                'status'                => 'online',
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'                    => 'res_scada_vnc',
                'name'                  => 'SCADA Industrial Monitor (VNC)',
                'slug'                  => 'scada-vnc',
                'type'                  => 'vnc',
                'internal_host'         => '192.168.30.2',
                'internal_port'         => 5900,
                'description'           => 'OT / SCADA Plant Telemetry Virtual Framebuffer Console',
                'icon'                  => 'server',
                'require_auth'          => 1,
                'allowed_roles'         => json_encode(['SuperAdmin', 'Operator']),
                'active_sessions_count' => 0,
                'status'                => 'online',
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
        ]);
    }
}
