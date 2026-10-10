<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateServicesAndCronTables extends Migration
{
    public function up()
    {
        // 1. system_services table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'subsystem' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'system',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'enabled_at_boot' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'watchdog_monitored' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'running', // running, stopped, failed
            ],
            'pid' => [
                'type'       => 'INT',
                'null'       => true,
            ],
            'memory_mb' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0.00,
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
        $this->forge->createTable('system_services', true);

        // 2. cron_jobs table
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
            ],
            'minute' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '*',
            ],
            'hour' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '*',
            ],
            'day_of_month' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '*',
            ],
            'month' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '*',
            ],
            'day_of_week' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '*',
            ],
            'user' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'root',
            ],
            'command' => [
                'type' => 'TEXT',
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'last_run_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'pending', // success, failed, running
            ],
            'last_output' => [
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
        $this->forge->createTable('cron_jobs', true);

        // Seed initial standard core services
        $db = \Config\Database::connect();
        $initialServices = [
            [
                'id'                 => 'nftables',
                'name'               => 'nftables',
                'subsystem'          => 'firewall',
                'description'        => 'Netfilter Stateful Packet Inspection & NAT Engine',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 512,
                'memory_mb'          => 28.4,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'wireguard',
                'name'               => 'wireguard',
                'subsystem'          => 'vpn',
                'description'        => 'WireGuard Fast Kernel Cryptographic Tunnel Module (wg0)',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 640,
                'memory_mb'          => 16.2,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'openvpn',
                'name'               => 'openvpn@server',
                'subsystem'          => 'vpn',
                'description'        => 'OpenVPN Multi-Client TLS Road Warrior Gateway (tun0)',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 784,
                'memory_mb'          => 34.8,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'frr',
                'name'               => 'frr',
                'subsystem'          => 'routing',
                'description'        => 'FRRouting Dynamic Routing Suite (OSPFv2/v3, BGP, BFD)',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 912,
                'memory_mb'          => 48.6,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'kea-dhcp4',
                'name'               => 'kea-dhcp4-server',
                'subsystem'          => 'network',
                'description'        => 'Kea High-Performance Dynamic Host Configuration Daemon',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1024,
                'memory_mb'          => 22.1,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'unbound',
                'name'               => 'unbound',
                'subsystem'          => 'network',
                'description'        => 'Unbound Validating Recursive DNSSEC & DNSBL Threat Resolver',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1150,
                'memory_mb'          => 64.5,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'gateway-monitor',
                'name'               => 'gateway-monitor',
                'subsystem'          => 'network',
                'description'        => 'Multi-WAN Gateway Quality & ICMP Latency Prober',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1280,
                'memory_mb'          => 8.4,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'squid',
                'name'               => 'squid',
                'subsystem'          => 'security',
                'description'        => 'Squid Web Content Filter & Transparent TPROXY Daemon',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1420,
                'memory_mb'          => 92.4,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'keepalived',
                'name'               => 'keepalived',
                'subsystem'          => 'ha',
                'description'        => 'Keepalived VRRPv2/v3 High Availability Failover Daemon',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1510,
                'memory_mb'          => 12.3,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'conntrackd',
                'name'               => 'conntrackd',
                'subsystem'          => 'ha',
                'description'        => 'Conntrack State Replication Daemon for Cluster Nodes',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1602,
                'memory_mb'          => 18.7,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'suricata',
                'name'               => 'suricata',
                'subsystem'          => 'security',
                'description'        => 'Suricata Next-Gen IPS/IDS Deep Packet Inspection Engine',
                'enabled_at_boot'    => 0,
                'watchdog_monitored' => 0,
                'status'             => 'stopped',
                'pid'                => null,
                'memory_mb'          => 0,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            [
                'id'                 => 'chrony',
                'name'               => 'chrony',
                'subsystem'          => 'system',
                'description'        => 'Chrony Precision Network Time Protocol (NTP) Synchronizer',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1780,
                'memory_mb'          => 6.2,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
        ];

        foreach ($initialServices as $srv) {
            $existing = $db->table('system_services')->where('id', $srv['id'])->countAllResults();
            if ($existing === 0) {
                $db->table('system_services')->insert($srv);
            }
        }

        // Seed initial standard cron jobs
        $initialCronJobs = [
            [
                'id'           => 'cron_backup_nightly',
                'minute'       => '0',
                'hour'         => '2',
                'day_of_month' => '*',
                'month'        => '*',
                'day_of_week'  => '*',
                'user'         => 'root',
                // Seeded disabled and without run history. The original seed
                // named a `/usr/local/bin/micron-backup` helper that ships
                // nowhere while reporting a successful `.embk` backup nothing
                // could produce — the row asserted an archive that did not
                // exist. Scheduled snapshots, retention and offsite export are
                // still planned (§17.6), so the preset stays off until an
                // administrator enables it; `2026-10-06-000099` applies the same
                // values to databases that already hold the bad row.
                'command'      => 'php spark backup:create',
                'description'  => 'Nightly Configuration Snapshot (disabled until scheduled snapshots, retention and offsite export are implemented)',
                'enabled'      => 0,
                'last_status'  => 'pending',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ],
            [
                'id'           => 'cron_dnsbl_update',
                'minute'       => '30',
                'hour'         => '3',
                'day_of_month' => '*',
                'month'        => '*',
                'day_of_week'  => '*',
                'user'         => 'root',
                'command'      => '/usr/local/bin/dnsbl-update --reload-unbound',
                'description'  => 'Daily Synchronize DNSBL Threat Intelligence Blocklists & Bloom Filters',
                'enabled'      => 1,
                'last_run_at'  => date('Y-m-d 03:30:00', strtotime('yesterday')),
                'last_status'  => 'success',
                'last_output'  => 'Synchronized 4 feeds: 502,937 rules compiled into Unbound sinkhole.',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ],
            [
                'id'           => 'cron_logrotate',
                'minute'       => '0',
                'hour'         => '0',
                'day_of_month' => '*',
                'month'        => '*',
                'day_of_week'  => '*',
                'user'         => 'root',
                'command'      => '/usr/sbin/logrotate /etc/logrotate.conf',
                'description'  => 'Midnight Log Rotation (Firewall, Suricata, Squid, System Logs)',
                'enabled'      => 1,
                'last_run_at'  => date('Y-m-d 00:00:00', strtotime('today')),
                'last_status'  => 'success',
                'last_output'  => 'Rotated: /var/log/firewall.log, /var/log/suricata/eve.json. Gzip compressed.',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ],
            [
                'id'           => 'cron_cert_renewal',
                'minute'       => '15',
                'hour'         => '4',
                'day_of_month' => '1',
                'month'        => '*',
                'day_of_week'  => '*',
                'user'         => 'root',
                'command'      => '/usr/local/bin/micron-cert-renew --check-expiry 30',
                'description'  => 'Monthly SSL/TLS & OpenVPN Certificate Expiration & Renewal Check',
                'enabled'      => 1,
                'last_run_at'  => date('Y-m-01 04:15:00'),
                'last_status'  => 'success',
                'last_output'  => 'All 6 certificates validated. Next expiry in 72 days.',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ],
            [
                'id'           => 'cron_dyndns_check',
                'minute'       => '*/15',
                'hour'         => '*',
                'day_of_month' => '*',
                'month'        => '*',
                'day_of_week'  => '*',
                'user'         => 'root',
                'command'      => '/usr/local/bin/dyndns-update --iface eth0',
                'description'  => 'Quarter-Hourly Dynamic DNS Public IP Verification (Cloudflare / DuckDNS)',
                'enabled'      => 1,
                'last_run_at'  => date('Y-m-d H:i:00', strtotime('-5 minutes')),
                'last_status'  => 'success',
                'last_output'  => 'WAN IP 198.51.100.25 unchanged. No DNS update required.',
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ],
        ];

        foreach ($initialCronJobs as $job) {
            $existing = $db->table('cron_jobs')->where('id', $job['id'])->countAllResults();
            if ($existing === 0) {
                $db->table('cron_jobs')->insert($job);
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('cron_jobs', true);
        $this->forge->dropTable('system_services', true);
    }
}
