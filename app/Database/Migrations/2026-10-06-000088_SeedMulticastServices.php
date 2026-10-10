<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the daemons that back the Multicast & IGMP page to the system_services
 * inventory so the shared page-header service-control widget
 * (ServiceStatus::widget) can render their run-state, exactly like the HA,
 * routing and firewall pages. Inserts are idempotent (id is checked first).
 *
 * FRR (the daemon that runs PIM) is already present in the inventory, so it is
 * referenced directly by the page rather than seeded again here.
 */
class SeedMulticastServices extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        $services = [
            [
                'id'                 => 'igmpproxy',
                'name'               => 'igmpproxy',
                'subsystem'          => 'network',
                'description'        => 'IGMP Proxy — forwards upstream multicast (IPTV) onto downstream LAN segments',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1024,
                'memory_mb'          => 6.8,
            ],
            [
                'id'                 => 'mdns-repeater',
                'name'               => 'mdns-repeater',
                'subsystem'          => 'network',
                'description'        => 'mDNS / Bonjour Reflector — bridges multicast DNS queries between VLANs',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'stopped',
                'pid'                => null,
                'memory_mb'          => 4.2,
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
        $db->table('system_services')->whereIn('id', ['igmpproxy', 'mdns-repeater'])->delete();
    }
}
