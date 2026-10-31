<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InitialApplianceSeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();

        // 1. Seed Default Administrative User (Ministrator / M1Cr0N)
        // Username: Ministrator | Password: M1Cr0N
        // ⚠ Change this password immediately after first login!
        if ($db->table('users')->where('username', 'Ministrator')->countAllResults() === 0) {
            $passwordHash = defined('PASSWORD_ARGON2ID')
                ? password_hash('M1Cr0N', PASSWORD_ARGON2ID)
                : password_hash('M1Cr0N', PASSWORD_BCRYPT, ['cost' => 12]);

            $userBuilder = $db->table('users');
            $userBuilder->insert([
                'id'                  => 'usr_root_ministrator_001',
                'username'            => 'Ministrator',
                'email'               => 'ministrator@firewall.local',
                'password_hash'       => $passwordHash,
                'auth_source'         => 'local',
                'role'                => 'SuperAdmin',
                'totp_enabled'        => 0,
                'totp_secret'         => null,
                'totp_scratch_codes'  => null,
                'webauthn_enabled'    => 0,
                'is_active'           => 1,
                'created_at'          => date('Y-m-d H:i:s'),
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);
        }

        // 2. Seed Default Network Interfaces (WAN and LAN)
        // The roles are created unassigned: a NIC name cannot be known before the
        // appliance sees its own hardware (eth0 on one box is enp1s0 or igc0 on
        // the next). The operator binds them from the console, pfSense style
        // (micron-menu option 1, `php spark network assign`), and micron-network
        // never guesses. The addressing below is what each role gets once bound.
        if ($db->table('interfaces')->countAllResults() === 0) {
            $ifBuilder = $db->table('interfaces');
            $ifBuilder->insertBatch([
                [
                    'id'                 => 'wan',
                    'name'               => 'WAN',
                    'physical_device'    => '',
                    'enabled'            => 1,
                    'ip_assignment_type' => 'dhcp',
                    'ipv4_address'       => null,
                    'ipv4_netmask'       => null,
                    'ipv4_gateway'       => null,
                    'mtu'                => 1500,
                    'created_at'         => date('Y-m-d H:i:s'),
                    'updated_at'         => date('Y-m-d H:i:s'),
                ],
                [
                    'id'                 => 'lan',
                    'name'               => 'LAN',
                    'physical_device'    => '',
                    'enabled'            => 1,
                    'ip_assignment_type' => 'static',
                    'ipv4_address'       => '10.10.10.10',
                    'ipv4_netmask'       => 24,
                    'ipv4_gateway'       => null,
                    'mtu'                => 1500,
                    'created_at'         => date('Y-m-d H:i:s'),
                    'updated_at'         => date('Y-m-d H:i:s'),
                ]
            ]);
        }

        // 3. Seed Default Base Firewall Rules
        if ($db->table('firewall_rules')->countAllResults() === 0) {
            $ruleBuilder = $db->table('firewall_rules');
            $ruleBuilder->insertBatch([
                [
                    'id'           => 'rule_lan_webgui_access',
                    'sequence'     => 1,
                    'interface_id' => 'lan',
                    'action'       => 'pass',
                    'direction'    => 'in',
                    'protocol'     => 'tcp',
                    // Dynamic references: these follow the LAN address when it
                    // changes (resolved by NftablesCompiler at compile time).
                    'source_type'  => 'network',
                    'source_value' => '{lan:network}',
                    'source_port'  => null,
                    'dest_type'    => 'ip',
                    'dest_value'   => '{lan:address}',
                    'dest_port'    => '443',
                    'log_enabled'  => 0,
                    'description'  => 'Allow LAN clients to access HTTPS Web Management GUI',
                    'enabled'      => 1,
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ],
                [
                    'id'           => 'rule_lan_dns_access',
                    'sequence'     => 2,
                    'interface_id' => 'lan',
                    'action'       => 'pass',
                    'direction'    => 'in',
                    'protocol'     => 'udp',
                    'source_type'  => 'network',
                    'source_value' => '{lan:network}',
                    'source_port'  => null,
                    'dest_type'    => 'any',
                    'dest_value'   => 'any',
                    'dest_port'    => '53',
                    'log_enabled'  => 0,
                    'description'  => 'Allow LAN clients to send DNS queries',
                    'enabled'      => 1,
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ],
                [
                    'id'           => 'rule_lan_default_outbound',
                    'sequence'     => 3,
                    'interface_id' => 'lan',
                    'action'       => 'pass',
                    'direction'    => 'in',
                    'protocol'     => 'any',
                    'source_type'  => 'any',
                    'source_value' => 'any',
                    'source_port'  => null,
                    'dest_type'    => 'any',
                    'dest_value'   => 'any',
                    'dest_port'    => null,
                    'log_enabled'  => 0,
                    'description'  => 'Default allow all outbound traffic from LAN to Internet',
                    'enabled'      => 1,
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]
            ]);
        }

        // 4. Seed Default Web Filtering Settings & Categories
        if ($db->table('webfilter_settings')->countAllResults() === 0) {
            $wfSettings = $db->table('webfilter_settings');
            $wfSettings->insert([
                'id'                      => 1,
                'mode'                    => 'transparent_sni',
                'http_port'               => 3128,
                'https_port'              => 3129,
                'icap_antivirus_enabled'  => 1,
                'safesearch_enabled'      => 1,
                'youtube_restricted_mode' => 'moderate',
                'cache_enabled'           => 0,
                'cache_size_mb'           => 4096,
                'updated_at'              => date('Y-m-d H:i:s'),
            ]);
        }

        if ($db->table('webfilter_categories')->countAllResults() === 0) {
            $wfCat = $db->table('webfilter_categories');
            $wfCat->insertBatch([
                ['id' => 'cat_malware', 'name' => 'Malware & Phishing', 'action' => 'block', 'description' => 'Known malicious sites, command and control, and trojan sources', 'enabled' => 1],
                ['id' => 'cat_gambling', 'name' => 'Gambling', 'action' => 'block', 'description' => 'Online betting and casino portals', 'enabled' => 1],
                ['id' => 'cat_adult', 'name' => 'Adult Content', 'action' => 'block', 'description' => 'Pornography and explicit material', 'enabled' => 1],
                ['id' => 'cat_social', 'name' => 'Social Media', 'action' => 'allow', 'description' => 'Social networking platforms', 'enabled' => 1],
            ]);
        }

        // 5. Seed Default DHCP Server Settings for LAN (Disabled by default on fresh install)
        if ($db->table('dhcp_server_settings')->where('interface_id', 'lan')->countAllResults() === 0) {
            $db->table('dhcp_server_settings')->insert([
                'interface_id' => 'lan',
                'enabled'      => 0,
                'range_from'   => '10.10.10.100',
                'range_to'     => '10.10.10.200',
                'gateway'      => '10.10.10.10',
                'dns_1'        => '10.10.10.10',
                'dns_2'        => '1.1.1.1',
                'default_lease_time' => 7200,
                'max_lease_time'     => 86400,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
