<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateHaQosPkiTables extends Migration
{
    public function up()
    {
        // -------------------------------------------------------------
        // 1. HA Settings
        // -------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'node_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'node-01',
            ],
            'mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'master', // master or backup
            ],
            'vrrp_router_id' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 51,
            ],
            'vrrp_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'lan0',
            ],
            'vrrp_priority' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 110,
            ],
            'vrrp_auth_pass' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'VRRP_S3cur3Key',
            ],
            'preempt' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'conntrackd_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'conntrackd_interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'sync0',
            ],
            'peer_sync_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'default'    => '10.254.254.2',
            ],
            'peer_sync_port' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 3780,
            ],
            'config_sync_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'config_sync_peer_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'default'    => '10.254.254.2',
            ],
            'config_sync_api_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'sync_sec_key_'.bin2hex(random_bytes(16)),
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('ha_settings', true);

        // -------------------------------------------------------------
        // 2. HA Virtual IPs (VIPs)
        // -------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'lan0',
            ],
            'vip_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'vrrp', // vrrp, ipalias, proxyarp
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'subnet_mask' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'default'    => '255.255.255.0',
            ],
            'vrrp_router_id' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 51,
            ],
            'vrrp_priority' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 110,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'MASTER', // MASTER, BACKUP, FAULT
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('ha_virtual_ips', true);

        // -------------------------------------------------------------
        // 3. QoS Shapers (CAKE / FQ-CoDel Bufferbloat mitigation)
        // -------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'interface' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'bandwidth_down_mbps' => [
                'type'       => 'FLOAT',
                'default'    => 100.0,
            ],
            'bandwidth_up_mbps' => [
                'type'       => 'FLOAT',
                'default'    => 50.0,
            ],
            'scheduler' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'cake', // cake, fq_codel, htb
            ],
            'diffserv' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'diffserv4', // diffserv4, diffserv3, besteffort
            ],
            'flow_isolation' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'triple-isolate', // dual-srchost, dual-dsthost, triple-isolate, flowblind
            ],
            'ack_filter' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'rtt_ms' => [
                'type'       => 'INT',
                'constraint' => 6,
                'default'    => 100,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('qos_shapers', true);

        // -------------------------------------------------------------
        // 4. QoS Rules / Traffic Classes
        // -------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'shaper_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'priority' => [
                'type'       => 'INT',
                'constraint' => 3,
                'default'    => 1, // 1 (Highest/Voice) -> 8 (Bulk)
            ],
            'protocol' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'any',
            ],
            'src_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'any',
            ],
            'dst_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'any',
            ],
            'dst_port' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'any',
            ],
            'dscp_class' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'EF', // EF, AF41, CS3, BE, CS1
            ],
            'bandwidth_share' => [
                'type'       => 'INT',
                'constraint' => 5,
                'default'    => 20, // %
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('qos_rules', true);

        // -------------------------------------------------------------
        // 5. PKI Authorities (Certificate Authorities)
        // -------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'common_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'organization' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'MicronOS Enterprise',
            ],
            'country' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'default'    => 'US',
            ],
            'state' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'California',
            ],
            'city' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'San Jose',
            ],
            'certificate' => [
                'type' => 'TEXT',
            ],
            'private_key' => [
                'type' => 'TEXT',
            ],
            'serial_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '01',
            ],
            'valid_from' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'valid_until' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'key_length' => [
                'type'       => 'INT',
                'constraint' => 6,
                'default'    => 4096,
            ],
            'digest' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'sha256',
            ],
            'is_default' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('pki_authorities', true);

        // -------------------------------------------------------------
        // 6. PKI Certificates (Server, Client, VPN, SSL-Bump)
        // -------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'ca_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'cert_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'server', // server, client, user, vpn
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'common_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'organization' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => 'MicronOS Enterprise',
            ],
            'san_dns' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'san_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'certificate' => [
                'type' => 'TEXT',
            ],
            'private_key' => [
                'type' => 'TEXT',
            ],
            'serial_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '1001',
            ],
            'valid_from' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'valid_until' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'revoked' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('pki_certificates', true);
    }

    public function down()
    {
        $this->forge->dropTable('pki_certificates', true);
        $this->forge->dropTable('pki_authorities', true);
        $this->forge->dropTable('qos_rules', true);
        $this->forge->dropTable('qos_shapers', true);
        $this->forge->dropTable('ha_virtual_ips', true);
        $this->forge->dropTable('ha_settings', true);
    }
}
