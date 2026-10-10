<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Master IPv6 switch for the appliance: when off, the kernel IPv6 stack is
 * disabled, IPv6 routes are withdrawn and all IPv6 traffic is dropped.
 *
 * When set to 0 the whole IPv6 stack is meant to be off: no IPv6 interface
 * configuration, no IPv6 default route, no IPv6 listeners in the services, and
 * the firewall drops every IPv6 packet. The web UI records the intent and asks
 * micrond to apply it; the value also gates IPv6 output in the compiled
 * configurations.
 */
class AddIpv6EnabledToSystemSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('system_settings', [
            'ipv6_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'dns_default_servers',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('system_settings', 'ipv6_enabled');
    }
}
