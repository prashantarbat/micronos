<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSnmpAndSiemTables extends Migration
{
    public function up()
    {
        // 1. snmp_settings table
        $this->forge->addField([
            'id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'listen_port' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 161,
            ],
            'listen_interfaces' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'all',
            ],
            'system_location' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'Primary DC, Rack 04',
            ],
            'system_contact' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'noc@micronos.network',
            ],
            'v1_v2c_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'read_community' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'public',
            ],
            'write_community' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'allowed_subnets' => [
                'type' => 'TEXT',
                'null' => true, // CIDRs separated by comma, e.g. 192.168.1.0/24, 10.0.0.0/8
            ],
            'v3_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'traps_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'trap_receiver_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => '192.168.1.50',
            ],
            'trap_receiver_port' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 162,
            ],
            'trap_community' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'public',
            ],
            'trap_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => 'v2c', // v2c, v3
            ],
            'trap_link_events' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'trap_auth_events' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'trap_ha_events' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'trap_threshold_events' => [
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
        $this->forge->createTable('snmp_settings', true);

        // 2. snmp_v3_users table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'security_level' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'authPriv', // noAuthNoPriv, authNoPriv, authPriv
            ],
            'auth_protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'SHA-256', // MD5, SHA-1, SHA-256, SHA-512
            ],
            'auth_passphrase' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'priv_protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'AES-256', // DES, AES-128, AES-192, AES-256
            ],
            'priv_passphrase' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'access_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => 'ro', // ro, rw
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
        $this->forge->createTable('snmp_v3_users', true);

        // 3. siem_settings table
        $this->forge->addField([
            'id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'auto_increment' => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'server_host' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => 'siem.internal.network',
            ],
            'server_port' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 514,
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'default'    => 'UDP', // UDP, TCP, TLS
            ],
            'log_format' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'CEF', // CEF, LEEF, JSON_ECS, RFC5424, RFC3164
            ],
            'facility' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'local0',
            ],
            'severity_threshold' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'info', // debug, info, notice, warning, error, critical
            ],
            'client_cert_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'tls_ca_verify' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'forward_firewall' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'forward_ids' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'forward_vpn' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'forward_proxy' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'forward_system' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'forward_dhcp' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'disk_spool_mb' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 500,
            ],
            'rate_limit_per_sec' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 5000,
            ],
            'last_test_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_test_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'success',
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
        $this->forge->createTable('siem_settings', true);

        // Seed initial default configurations
        $db = \Config\Database::connect();
        $db->table('snmp_settings')->insert([
            'id'                    => 1,
            'enabled'               => 1,
            'listen_port'           => 161,
            'listen_interfaces'     => 'LAN (eth1)',
            'system_location'       => 'Primary Data Center, Rack 04',
            'system_contact'        => 'noc@micronos.network',
            'v1_v2c_enabled'        => 1,
            'read_community'        => 'public',
            'write_community'       => null,
            'allowed_subnets'       => '192.168.1.0/24, 10.10.0.0/16',
            'v3_enabled'            => 1,
            'traps_enabled'         => 1,
            'trap_receiver_ip'      => '192.168.1.50',
            'trap_receiver_port'    => 162,
            'trap_community'        => 'public',
            'trap_version'          => 'v2c',
            'trap_link_events'      => 1,
            'trap_auth_events'      => 1,
            'trap_ha_events'        => 1,
            'trap_threshold_events' => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        $db->table('snmp_v3_users')->insert([
            'id'              => 'snmp3_admin',
            'username'        => 'secops_monitor',
            'security_level'  => 'authPriv',
            'auth_protocol'   => 'SHA-256',
            'auth_passphrase' => 'MicronOS#AuthPass2026',
            'priv_protocol'   => 'AES-256',
            'priv_passphrase' => 'MicronOS#PrivKey2026',
            'access_mode'     => 'ro',
            'enabled'         => 1,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $db->table('siem_settings')->insert([
            'id'                 => 1,
            'enabled'            => 1,
            'server_host'        => 'splunk-collector.internal.network',
            'server_port'        => 514,
            'protocol'           => 'UDP',
            'log_format'         => 'CEF',
            'facility'           => 'local0',
            'severity_threshold' => 'info',
            'client_cert_id'     => null,
            'tls_ca_verify'      => 1,
            'forward_firewall'   => 1,
            'forward_ids'        => 1,
            'forward_vpn'        => 1,
            'forward_proxy'      => 1,
            'forward_system'     => 1,
            'forward_dhcp'       => 1,
            'disk_spool_mb'      => 500,
            'rate_limit_per_sec' => 5000,
            'last_test_at'       => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'last_test_status'   => 'success',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('siem_settings', true);
        $this->forge->dropTable('snmp_v3_users', true);
        $this->forge->dropTable('snmp_settings', true);
    }
}
