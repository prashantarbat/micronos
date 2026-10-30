<?php

namespace App\Database\Migrations;

use App\Libraries\IpcClient;
use CodeIgniter\Database\Migration;

/**
 * Migration 119: no development sample data on an appliance.
 *
 * Several earlier migrations seeded demonstration records so the Web UI had
 * something to show on a developer's machine. On an installed or live-booted
 * MicronOS appliance those rows are not harmless: some are live configuration
 * (an enabled 1:1 NAT for 198.51.100.25, an NPTv6 mapping, a PPPoE server on
 * eth1, enabled LDAP/RADIUS servers whose groups map to Superadmin, an SNMPv3
 * user with a published passphrase, SNMP "public" with a trap receiver, SIEM
 * forwarding to a made-up collector, SD-WAN members on non-existent links) and
 * the rest is invented history (IDS alerts, proxy access logs, PPPoE sessions,
 * threat indicators, service rows marked "running" with invented pids).
 *
 * On an appliance (ID=micronos in /etc/os-release, or MICRON_APPLIANCE=1 as the
 * ISO build and installer set it) this removes exactly those seeded rows — each
 * matched on the values it was seeded with, so anything an operator created or
 * edited is left alone — and returns the seeded service settings to "off".
 * A developer's database is not touched unless MICRON_PURGE_SAMPLE_DATA=1.
 */
class RemoveSampleDataFromAppliance extends Migration
{
    public function up()
    {
        if (! self::shouldRun()) {
            return;
        }

        $db  = $this->db;
        $now = date('Y-m-d H:i:s');
        $del = static function (string $table, array $where, array $whereIn = []) use ($db): void {
            if (! $db->tableExists($table)) {
                return;
            }
            $b = $db->table($table)->where($where);
            foreach ($whereIn as $col => $values) {
                $b->whereIn($col, $values);
            }
            $b->delete();
        };
        $set = static function (string $table, array $where, array $values) use ($db): void {
            if (! $db->tableExists($table)) {
                return;
            }
            $fields = $db->getFieldNames($table);
            $values = array_intersect_key($values, array_flip($fields));
            if ($values !== []) {
                $db->table($table)->where($where)->update($values);
            }
        };

        // NAT: the sample 1:1 mapping (now a DNAT/VIP object) and NPTv6 prefix.
        foreach (['nat_dnat', 'nat_vips'] as $t) {
            $del($t, ['external_ip' => '198.51.100.25', 'mapped_ip' => '192.168.1.50']);
        }
        $del('nat_nptv6', ['internal_prefix' => 'fd00:abcd:1::/64', 'external_prefix' => '2001:db8:acad:1::/64']);

        // PPPoE access concentrator demo.
        $del('pppoe_session_history', ['username' => 'demo_subscriber']);
        $del('pppoe_server_users', ['id' => 'pppuser_demo01', 'username' => 'demo_subscriber']);
        $del('pppoe_servers', ['id' => 'pppsrv_demo01', 'radius_server' => '10.20.30.40']);

        // External authentication servers and their group → role mappings.
        $del('auth_group_mappings', [], ['id' => ['map_ldap_admins', 'map_ldap_netops', 'map_radius_admins', 'map_radius_operators']]);
        $del('ldap_servers', ['id' => 'ldap_default', 'hostname' => 'ad.corp.local']);
        $del('radius_servers', ['id' => 'radius_default', 'hostname' => '10.10.10.25']);

        // SNMP: the v3 user with a published passphrase; the agent back to off.
        $del('snmp_v3_users', ['id' => 'snmp3_admin', 'auth_passphrase' => 'MicronOS#AuthPass2026']);
        $set('snmp_settings', ['id' => 1, 'system_location' => 'Primary Data Center, Rack 04'], [
            'enabled' => 0, 'v1_v2c_enabled' => 0, 'v3_enabled' => 0, 'traps_enabled' => 0,
            'listen_interfaces' => '', 'system_location' => '', 'system_contact' => '',
            'read_community' => '', 'trap_community' => '', 'allowed_subnets' => '',
            'trap_receiver_ip' => '', 'updated_at' => $now,
        ]);

        // SIEM forwarding to a collector that does not exist.
        $set('siem_settings', ['id' => 1, 'server_host' => 'splunk-collector.internal.network'], [
            'enabled' => 0, 'server_host' => '', 'updated_at' => $now,
        ]);

        // SD-WAN members on links the machine does not have, and rules using them.
        $del('sdwan_rules', [], ['id' => ['rule_voip_steering', 'rule_cloud_steering']]);
        $del('sdwan_members', [], ['id' => ['mem_wan1', 'mem_wan2', 'mem_lte']]);
        if ($db->tableExists('sdwan_members') && $db->table('sdwan_members')->countAllResults() === 0) {
            $set('sdwan_settings', ['id' => 1], ['enabled' => 0, 'updated_at' => $now]);
        }

        // ZTNA published applications that point at invented hosts.
        $del('ztna_resources', [], ['id' => ['res_erp', 'res_win_dc', 'res_bastion_ssh', 'res_scada_vnc']]);
        $set('ztna_settings', ['id' => 1, 'external_domain' => 'gateway.micronos.local'], [
            'enabled' => 0, 'external_domain' => '', 'updated_at' => $now,
        ]);

        // Invented history and indicators.
        $del('ids_alerts', [], ['src_ip' => ['198.51.100.44', '203.0.113.88', '45.155.205.233', '192.168.1.105']]);
        $del('ids_ip_rules', [], ['ip_address' => ['192.168.1.0/24', '1.1.1.1', '198.51.100.44', '203.0.113.88', '45.155.205.233']]);
        $del('threat_indicators', [], ['id' => ['ti_1', 'ti_2', 'ti_3', 'ti_4']]);
        $del('webfilter_access_logs', [], ['url' => [
            'https://github.com/torvalds/linux', 'https://malware-drop-site.xyz:443', 'https://online-poker-betting.net/',
            'https://www.chase.com/secure/login', 'http://unauthorized-tools.org/payload.exe', 'https://cdn.jsdelivr.net/npm/chart.js',
        ]]);
        $del('dhcp_reservations', ['mac_address' => '00:11:22:33:44:55', 'hostname' => 'core-printer.lan']);

        // Service run-state rows were seeded "running" with made-up pids; the
        // first status poll fills in what systemd actually reports.
        if ($db->tableExists('system_services')) {
            $db->table('system_services')->where('status', 'running')->update(['status' => 'stopped', 'pid' => null]);
        }
    }

    public function down()
    {
        // Removing sample data is not reversed: the rows were never configuration.
    }

    private static function shouldRun(): bool
    {
        return getenv('MICRON_PURGE_SAMPLE_DATA') === '1' || IpcClient::isAppliance();
    }
}
