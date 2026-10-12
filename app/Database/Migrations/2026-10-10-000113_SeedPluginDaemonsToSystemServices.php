<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the daemons that back the plugin pages (darkstat, lldpd, ZeroTier,
 * NetBird, tayga NAT64) to the system_services inventory so their page-header
 * service controls have persistent run-state and control support.
 * Inserts are idempotent (id is checked first).
 */
class SeedPluginDaemonsToSystemServices extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        $services = [
            [
                'id'                 => 'darkstat',
                'name'               => 'darkstat',
                'subsystem'          => 'monitoring',
                'description'        => 'darkstat Network Traffic Analyser & Bandwidth Monitor',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 0,
                'status'             => 'running',
                'pid'                => 1840,
                'memory_mb'          => 8.5,
            ],
            [
                'id'                 => 'lldpd',
                'name'               => 'lldpd',
                'subsystem'          => 'system',
                'description'        => 'Link Layer Discovery Protocol Daemon (LLDP/CDP/EDP)',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 0,
                'status'             => 'running',
                'pid'                => 1912,
                'memory_mb'          => 6.2,
            ],
            [
                'id'                 => 'zerotier-one',
                'name'               => 'zerotier-one',
                'subsystem'          => 'vpn',
                'description'        => 'ZeroTier One Virtual Network Overlay Agent',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 0,
                'status'             => 'stopped',
                'pid'                => null,
                'memory_mb'          => 0,
            ],
            [
                'id'                 => 'netbird',
                'name'               => 'netbird',
                'subsystem'          => 'vpn',
                'description'        => 'NetBird WireGuard-Based Overlay Network Agent',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 0,
                'status'             => 'stopped',
                'pid'                => null,
                'memory_mb'          => 0,
            ],
            [
                'id'                 => 'tayga',
                'name'               => 'tayga',
                'subsystem'          => 'network',
                'description'        => 'tayga Userspace NAT64 Translation Daemon',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 0,
                'status'             => 'stopped',
                'pid'                => null,
                'memory_mb'          => 0,
            ],
        ];

        foreach ($services as $srv) {
            if ($db->table('system_services')->where('id', $srv['id'])->countAllResults() === 0) {
                $srv['created_at'] = date('Y-m-d H:i:s');
                $srv['updated_at'] = date('Y-m-d H:i:s');
                $db->table('system_services')->insert($srv);
            }
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        $db->table('system_services')->whereIn('id', ['darkstat', 'lldpd', 'zerotier-one', 'netbird', 'tayga'])->delete();
    }
}
