<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the daemons that back the IPsec, SNMP and SIEM pages to the
 * system_services inventory so their page-header service controls have a
 * run-state to render. Inserts are idempotent (id is checked first).
 */
class SeedAdditionalPageServices extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        $services = [
            [
                'id'                 => 'strongswan',
                'name'               => 'strongswan',
                'subsystem'          => 'vpn',
                'description'        => 'strongSwan IKEv2/IPsec Site-to-Site and Road Warrior Daemon',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 908,
                'memory_mb'          => 18.4,
            ],
            [
                'id'                 => 'snmpd',
                'name'               => 'snmpd',
                'subsystem'          => 'system',
                'description'        => 'Net-SNMP Monitoring Agent (v2c/v3) and Trap Sender',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1344,
                'memory_mb'          => 9.6,
            ],
            [
                'id'                 => 'rsyslog',
                'name'               => 'rsyslog',
                'subsystem'          => 'system',
                'description'        => 'rsyslog Remote Syslog & SIEM Event Forwarder',
                'enabled_at_boot'    => 1,
                'watchdog_monitored' => 1,
                'status'             => 'running',
                'pid'                => 1120,
                'memory_mb'          => 14.2,
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
        $db->table('system_services')->whereIn('id', ['strongswan', 'snmpd', 'rsyslog'])->delete();
    }
}
