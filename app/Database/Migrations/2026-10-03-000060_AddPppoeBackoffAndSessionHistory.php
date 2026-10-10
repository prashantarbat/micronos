<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 60: PPPoE escalating backoff states and per-subscriber session history.
 *
 * 1. `pppoe_backoff_states`:
 *    Tracks consecutive redial failures, timestamps, and next allowable retry times
 *    for PPPoE client interfaces so failing WAN links are retried with escalating
 *    backoff and alert events are raised.
 *
 * 2. `pppoe_session_history`:
 *    Captures historical sessions for PPPoE subscribers, recording the RADIUS
 *    reply attributes (rate limits, session/idle timeouts, framed IPs) received
 *    upon each authentication. This makes subscriber policy and rate limit
 *    changes over time visible and auditable.
 */
class AddPppoeBackoffAndSessionHistory extends Migration
{
    public function up(): void
    {
        // ── 1. pppoe_backoff_states ──
        if (! $this->db->tableExists('pppoe_backoff_states')) {
            $this->forge->addField([
                'interface' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                ],
                'failure_count' => [
                    'type'       => 'INT',
                    'default'    => 0,
                ],
                'last_failure_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'next_retry_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'last_reason' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'alert_count' => [
                    'type'       => 'INT',
                    'default'    => 0,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addPrimaryKey('interface');
            $this->forge->createTable('pppoe_backoff_states', true);
        }

        // ── 2. pppoe_session_history ──
        if (! $this->db->tableExists('pppoe_session_history')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                ],
                'subscriber_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => true,
                ],
                'username' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                ],
                'server_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => true,
                ],
                'server_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                ],
                'interface' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => true,
                ],
                'assigned_ip' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                'mac_address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => true,
                ],
                'session_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                ],
                'login_at' => [
                    'type' => 'DATETIME',
                ],
                'logout_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'uptime_seconds' => [
                    'type'       => 'INT',
                    'default'    => 0,
                ],
                'terminate_cause' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'default'    => 'User-Request',
                    'null'       => true,
                ],
                'rx_bytes' => [
                    'type'       => 'BIGINT',
                    'default'    => 0,
                ],
                'tx_bytes' => [
                    'type'       => 'BIGINT',
                    'default'    => 0,
                ],
                'rate_limit' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                ],
                'session_timeout' => [
                    'type'       => 'INT',
                    'null'       => true,
                ],
                'idle_timeout' => [
                    'type'       => 'INT',
                    'null'       => true,
                ],
                'interim_interval' => [
                    'type'       => 'INT',
                    'null'       => true,
                ],
                'framed_ip' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                'radius_attributes' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                ],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('subscriber_id');
            $this->forge->addKey('username');
            $this->forge->addKey('login_at');
            $this->forge->createTable('pppoe_session_history', true);
        }

        // Seed demo server & subscriber data if no servers currently exist,
        // giving the administrator immediate visibility into RADIUS session history & limit evolution.
        $this->seedDemoSubscriberAndHistory();
    }

    public function down(): void
    {
        if ($this->db->tableExists('pppoe_session_history')) {
            $this->forge->dropTable('pppoe_session_history', true);
        }

        if ($this->db->tableExists('pppoe_backoff_states')) {
            $this->forge->dropTable('pppoe_backoff_states', true);
        }
    }

    private function seedDemoSubscriberAndHistory(): void
    {
        if (! $this->db->tableExists('pppoe_servers') || ! $this->db->tableExists('pppoe_server_users')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Check if demo server exists
        $serverId = 'pppsrv_demo01';
        $server = $this->db->table('pppoe_servers')->where('id', $serverId)->get()->getRowArray();
        if (! $server && $this->db->table('pppoe_servers')->countAllResults() === 0) {
            $this->db->table('pppoe_servers')->insert([
                'id'            => $serverId,
                'name'          => 'PPPoE_Access_LAN',
                'interface'     => 'eth1',
                'service_name'  => 'MicronOS',
                'local_ip'      => '10.0.0.1',
                'remote_start'  => '10.0.0.10',
                'remote_end'    => '10.0.0.100',
                'dns1'          => '1.1.1.1',
                'dns2'          => '8.8.8.8',
                'auth_required' => 1,
                'idle_timeout'  => 300,
                'max_sessions'  => 64,
                'description'   => 'Subscriber Access Concentrator for LAN (eth1)',
                'enabled'       => 1,
                'auth_backend'  => 'radius',
                'radius_server' => '10.20.30.40',
                'radius_port'   => 1812,
                'radius_secret' => 'cGxhY2Vob2xkZXI=', // placeholder encrypted
                'radius_timeout'=> 5,
                'radius_retries'=> 3,
                'radius_nas_id' => 'micronos-bras01',
                'radius_accounting'  => 1,
                'radius_acct_port'   => 1813,
                'radius_acct_server' => '10.20.30.40',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        // Demo subscriber
        $subId = 'pppuser_demo01';
        $sub = $this->db->table('pppoe_server_users')->where('id', $subId)->get()->getRowArray();
        if (! $sub && $this->db->table('pppoe_server_users')->countAllResults() === 0) {
            $this->db->table('pppoe_server_users')->insert([
                'id'          => $subId,
                'server_id'   => $serverId,
                'username'    => 'demo_subscriber',
                'password'    => '',
                'static_ip'   => '10.0.0.42',
                'rate_limit'  => '2048/1024',
                'description' => 'Residential Fiber 20M Plan',
                'enabled'     => 1,
                'created_at'  => date('Y-m-d H:i:s', strtotime('-4 days')),
                'updated_at'  => $now,
            ]);
        }

        // Seed sample historical sessions showing limit changes over time
        if ($this->db->tableExists('pppoe_session_history') && $this->db->table('pppoe_session_history')->countAllResults() === 0) {
            $sessions = [
                [
                    'id'               => 'pppsess_hist_01',
                    'subscriber_id'    => $subId,
                    'username'         => 'demo_subscriber',
                    'server_id'        => $serverId,
                    'server_name'      => 'PPPoE_Access_LAN',
                    'interface'        => 'ppp1',
                    'assigned_ip'      => '10.0.0.42',
                    'mac_address'      => '00:1a:2b:3c:4d:5e',
                    'session_id'       => 'RAD-SESS-9001',
                    'login_at'         => date('Y-m-d H:i:s', strtotime('-3 days 08:30:00')),
                    'logout_at'        => date('Y-m-d H:i:s', strtotime('-3 days 12:30:00')),
                    'uptime_seconds'   => 14400,
                    'terminate_cause'  => 'User-Request',
                    'rx_bytes'         => 34215000,
                    'tx_bytes'         => 6842000,
                    'rate_limit'       => '1024/512',
                    'session_timeout'  => 1800,
                    'idle_timeout'     => 120,
                    'interim_interval' => 300,
                    'framed_ip'        => '10.0.0.42',
                    'radius_attributes'=> json_encode([
                        'Framed-IP-Address'     => '10.0.0.42',
                        'Session-Timeout'       => '1800',
                        'Idle-Timeout'          => '120',
                        'Acct-Interim-Interval' => '300',
                        'Filter-Id'             => '1024/512',
                        'Class'                 => '0x4261736963',
                    ]),
                    'created_at'       => date('Y-m-d H:i:s', strtotime('-3 days 08:30:00')),
                ],
                [
                    'id'               => 'pppsess_hist_02',
                    'subscriber_id'    => $subId,
                    'username'         => 'demo_subscriber',
                    'server_id'        => $serverId,
                    'server_name'      => 'PPPoE_Access_LAN',
                    'interface'        => 'ppp1',
                    'assigned_ip'      => '10.0.0.42',
                    'mac_address'      => '00:1a:2b:3c:4d:5e',
                    'session_id'       => 'RAD-SESS-9002',
                    'login_at'         => date('Y-m-d H:i:s', strtotime('-2 days 09:15:00')),
                    'logout_at'        => date('Y-m-d H:i:s', strtotime('-2 days 17:15:00')),
                    'uptime_seconds'   => 28800,
                    'terminate_cause'  => 'User-Request',
                    'rx_bytes'         => 184500000,
                    'tx_bytes'         => 24600000,
                    'rate_limit'       => '2048/1024', // Limit increased!
                    'session_timeout'  => 3600,        // Timeout increased!
                    'idle_timeout'     => 300,
                    'interim_interval' => 300,
                    'framed_ip'        => '10.0.0.42',
                    'radius_attributes'=> json_encode([
                        'Framed-IP-Address'     => '10.0.0.42',
                        'Session-Timeout'       => '3600',
                        'Idle-Timeout'          => '300',
                        'Acct-Interim-Interval' => '300',
                        'Filter-Id'             => '2048/1024',
                        'Class'                 => '0x5374616e64617264',
                    ]),
                    'created_at'       => date('Y-m-d H:i:s', strtotime('-2 days 09:15:00')),
                ],
                [
                    'id'               => 'pppsess_hist_03',
                    'subscriber_id'    => $subId,
                    'username'         => 'demo_subscriber',
                    'server_id'        => $serverId,
                    'server_name'      => 'PPPoE_Access_LAN',
                    'interface'        => 'ppp1',
                    'assigned_ip'      => '10.0.0.42',
                    'mac_address'      => '00:1a:2b:3c:4d:5e',
                    'session_id'       => 'RAD-SESS-9003',
                    'login_at'         => date('Y-m-d H:i:s', strtotime('-1 days 10:00:00')),
                    'logout_at'        => date('Y-m-d H:i:s', strtotime('-1 days 12:00:00')),
                    'uptime_seconds'   => 7200,
                    'terminate_cause'  => 'Session-Timeout',
                    'rx_bytes'         => 394200000,
                    'tx_bytes'         => 42100000,
                    'rate_limit'       => '5120/2048', // Turbo Boost Promo Limit!
                    'session_timeout'  => 7200,
                    'idle_timeout'     => 600,
                    'interim_interval' => 300,
                    'framed_ip'        => '10.0.0.42',
                    'radius_attributes'=> json_encode([
                        'Framed-IP-Address'     => '10.0.0.42',
                        'Session-Timeout'       => '7200',
                        'Idle-Timeout'          => '600',
                        'Acct-Interim-Interval' => '300',
                        'Filter-Id'             => '5120/2048',
                        'Class'                 => '0x547572626f',
                    ]),
                    'created_at'       => date('Y-m-d H:i:s', strtotime('-1 days 10:00:00')),
                ],
                [
                    'id'               => 'pppsess_hist_04',
                    'subscriber_id'    => $subId,
                    'username'         => 'demo_subscriber',
                    'server_id'        => $serverId,
                    'server_name'      => 'PPPoE_Access_LAN',
                    'interface'        => 'ppp1',
                    'assigned_ip'      => '10.0.0.42',
                    'mac_address'      => '00:1a:2b:3c:4d:5e',
                    'session_id'       => 'RAD-SESS-9004',
                    'login_at'         => date('Y-m-d H:i:s', strtotime('-31 minutes')),
                    'logout_at'        => null, // Current active session
                    'uptime_seconds'   => 1860,
                    'terminate_cause'  => 'Active',
                    'rx_bytes'         => 4210944,
                    'tx_bytes'         => 88920117,
                    'rate_limit'       => '2048/1024', // Standard Plan restored
                    'session_timeout'  => 3600,
                    'idle_timeout'     => 300,
                    'interim_interval' => 300,
                    'framed_ip'        => '10.0.0.42',
                    'radius_attributes'=> json_encode([
                        'Framed-IP-Address'     => '10.0.0.42',
                        'Session-Timeout'       => '3600',
                        'Idle-Timeout'          => '300',
                        'Acct-Interim-Interval' => '300',
                        'Filter-Id'             => '2048/1024',
                        'Class'                 => '0x4d6963726f6e',
                    ]),
                    'created_at'       => date('Y-m-d H:i:s', strtotime('-31 minutes')),
                ],
            ];

            foreach ($sessions as $record) {
                $this->db->table('pppoe_session_history')->insert($record);
            }
        }
    }
}
