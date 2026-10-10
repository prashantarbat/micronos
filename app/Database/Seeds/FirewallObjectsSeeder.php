<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class FirewallObjectsSeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // 1. Seed Firewall Addresses
        $addrTable = $db->table('firewall_addresses');
        $addresses = [
            [
                'id'         => 'addr_all_000',
                'name'       => 'all',
                'type'       => 'ipmask',
                'value'      => '0.0.0.0/0',
                'interface'  => 'any',
                'color'      => '#3b82f6',
                'comment'    => 'Matches any IPv4 address',
                'is_system'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_lan_sub_001',
                'name'       => 'LAN_Subnet',
                'type'       => 'ipmask',
                'value'      => '192.168.1.0/24',
                'interface'  => 'lan',
                'color'      => '#10b981',
                'comment'    => 'Default local LAN subnet',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_rfc1918_10',
                'name'       => 'RFC1918_10',
                'type'       => 'ipmask',
                'value'      => '10.0.0.0/8',
                'interface'  => 'any',
                'color'      => '#6366f1',
                'comment'    => 'Class A private IP space',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_rfc1918_172',
                'name'       => 'RFC1918_172',
                'type'       => 'ipmask',
                'value'      => '172.16.0.0/12',
                'interface'  => 'any',
                'color'      => '#6366f1',
                'comment'    => 'Class B private IP space',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_rfc1918_192',
                'name'       => 'RFC1918_192',
                'type'       => 'ipmask',
                'value'      => '192.168.0.0/16',
                'interface'  => 'any',
                'color'      => '#6366f1',
                'comment'    => 'Class C private IP space',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_dns_google',
                'name'       => 'Google_DNS',
                'type'       => 'iprange',
                'value'      => '8.8.8.8-8.8.4.4',
                'interface'  => 'wan',
                'color'      => '#f59e0b',
                'comment'    => 'Google Anycast DNS servers',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_dns_cf',
                'name'       => 'Cloudflare_DNS',
                'type'       => 'ipmask',
                'value'      => '1.1.1.1/32',
                'interface'  => 'wan',
                'color'      => '#f97316',
                'comment'    => 'Cloudflare 1.1.1.1 DNS',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_fqdn_msupdate',
                'name'       => 'Microsoft_Update_FQDN',
                'type'       => 'fqdn',
                'value'      => 'update.microsoft.com',
                'interface'  => 'wan',
                'color'      => '#06b6d4',
                'comment'    => 'Microsoft Windows Update FQDN',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'addr_fqdn_github',
                'name'       => 'GitHub_FQDN',
                'type'       => 'fqdn',
                'value'      => 'github.com',
                'interface'  => 'wan',
                'color'      => '#8b5cf6',
                'comment'    => 'GitHub website and API',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($addresses as $addr) {
            $exists = $addrTable->where('name', $addr['name'])->countAllResults();
            if ($exists === 0) {
                $addrTable->insert($addr);
            }
        }

        // 2. Seed Address Groups
        $agTable = $db->table('firewall_address_groups');
        $addrGroups = [
            [
                'id'         => 'ag_dns_public',
                'name'       => 'Public_DNS_Servers',
                'members'    => json_encode(['Google_DNS', 'Cloudflare_DNS']),
                'color'      => '#f59e0b',
                'comment'    => 'Trusted public DNS resolvers',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'ag_rfc1918',
                'name'       => 'RFC1918_Private_Nets',
                'members'    => json_encode(['RFC1918_10', 'RFC1918_172', 'RFC1918_192']),
                'color'      => '#6366f1',
                'comment'    => 'All RFC 1918 private IPv4 networks',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($addrGroups as $ag) {
            $exists = $agTable->where('name', $ag['name'])->countAllResults();
            if ($exists === 0) {
                $agTable->insert($ag);
            }
        }

        // 3. Seed All Known Ports & Services
        $svcTable = $db->table('firewall_services');
        $services = [
            // General / All
            ['name' => 'ALL', 'protocol' => 'IP', 'tcp_port' => null, 'udp_port' => null, 'category' => 'General', 'is_system' => 1, 'comment' => 'All IP Traffic'],
            // Web
            ['name' => 'HTTP', 'protocol' => 'TCP', 'tcp_port' => '80', 'udp_port' => null, 'category' => 'Web Access', 'is_system' => 1, 'comment' => 'World Wide Web HTTP'],
            ['name' => 'HTTPS', 'protocol' => 'TCP', 'tcp_port' => '443', 'udp_port' => null, 'category' => 'Web Access', 'is_system' => 1, 'comment' => 'Secure HTTP over TLS'],
            ['name' => 'HTTP_ALT', 'protocol' => 'TCP', 'tcp_port' => '8080', 'udp_port' => null, 'category' => 'Web Access', 'is_system' => 0, 'comment' => 'HTTP Alternate Port 8080'],
            // Remote Management
            ['name' => 'SSH', 'protocol' => 'TCP', 'tcp_port' => '22', 'udp_port' => null, 'category' => 'Remote Access', 'is_system' => 1, 'comment' => 'Secure Shell remote login'],
            ['name' => 'TELNET', 'protocol' => 'TCP', 'tcp_port' => '23', 'udp_port' => null, 'category' => 'Remote Access', 'is_system' => 1, 'comment' => 'Unencrypted Telnet'],
            ['name' => 'RDP', 'protocol' => 'TCP', 'tcp_port' => '3389', 'udp_port' => null, 'category' => 'Remote Access', 'is_system' => 1, 'comment' => 'Microsoft Remote Desktop Protocol'],
            ['name' => 'VNC', 'protocol' => 'TCP', 'tcp_port' => '5900', 'udp_port' => null, 'category' => 'Remote Access', 'is_system' => 0, 'comment' => 'Virtual Network Computing'],
            // Network Infrastructure
            ['name' => 'DNS', 'protocol' => 'TCP/UDP', 'tcp_port' => '53', 'udp_port' => '53', 'category' => 'Network Services', 'is_system' => 1, 'comment' => 'Domain Name System'],
            ['name' => 'DHCP', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '67-68', 'category' => 'Network Services', 'is_system' => 1, 'comment' => 'Dynamic Host Configuration Protocol'],
            ['name' => 'NTP', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '123', 'category' => 'Network Services', 'is_system' => 1, 'comment' => 'Network Time Protocol'],
            ['name' => 'PING', 'protocol' => 'ICMP', 'tcp_port' => null, 'udp_port' => null, 'icmp_type' => 8, 'icmp_code' => 0, 'category' => 'Network Services', 'is_system' => 1, 'comment' => 'ICMP Echo Request (Ping)'],
            ['name' => 'ALL_ICMP', 'protocol' => 'ICMP', 'tcp_port' => null, 'udp_port' => null, 'category' => 'Network Services', 'is_system' => 1, 'comment' => 'All ICMP Traffic'],
            ['name' => 'SNMP', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '161-162', 'category' => 'Network Services', 'is_system' => 1, 'comment' => 'Simple Network Management Protocol'],
            ['name' => 'SYSLOG', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '514', 'category' => 'Network Services', 'is_system' => 0, 'comment' => 'Standard Syslog daemon'],
            // Email
            ['name' => 'SMTP', 'protocol' => 'TCP', 'tcp_port' => '25', 'udp_port' => null, 'category' => 'Email', 'is_system' => 1, 'comment' => 'Simple Mail Transfer Protocol'],
            ['name' => 'SMTPS', 'protocol' => 'TCP', 'tcp_port' => '465', 'udp_port' => null, 'category' => 'Email', 'is_system' => 1, 'comment' => 'Secure SMTP over SSL/TLS'],
            ['name' => 'SMTP_SUBMISSION', 'protocol' => 'TCP', 'tcp_port' => '587', 'udp_port' => null, 'category' => 'Email', 'is_system' => 0, 'comment' => 'Email client message submission'],
            ['name' => 'IMAP', 'protocol' => 'TCP', 'tcp_port' => '143', 'udp_port' => null, 'category' => 'Email', 'is_system' => 1, 'comment' => 'Internet Message Access Protocol'],
            ['name' => 'IMAPS', 'protocol' => 'TCP', 'tcp_port' => '993', 'udp_port' => null, 'category' => 'Email', 'is_system' => 1, 'comment' => 'Secure IMAP over SSL/TLS'],
            ['name' => 'POP3', 'protocol' => 'TCP', 'tcp_port' => '110', 'udp_port' => null, 'category' => 'Email', 'is_system' => 1, 'comment' => 'Post Office Protocol v3'],
            ['name' => 'POP3S', 'protocol' => 'TCP', 'tcp_port' => '995', 'udp_port' => null, 'category' => 'Email', 'is_system' => 1, 'comment' => 'Secure POP3 over SSL/TLS'],
            // Authentication
            ['name' => 'LDAP', 'protocol' => 'TCP', 'tcp_port' => '389', 'udp_port' => null, 'category' => 'Authentication', 'is_system' => 1, 'comment' => 'Lightweight Directory Access Protocol'],
            ['name' => 'LDAPS', 'protocol' => 'TCP', 'tcp_port' => '636', 'udp_port' => null, 'category' => 'Authentication', 'is_system' => 1, 'comment' => 'Secure LDAP over SSL'],
            ['name' => 'KERBEROS', 'protocol' => 'TCP/UDP', 'tcp_port' => '88', 'udp_port' => '88', 'category' => 'Authentication', 'is_system' => 0, 'comment' => 'Kerberos authentication service'],
            ['name' => 'RADIUS', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '1812-1813', 'category' => 'Authentication', 'is_system' => 0, 'comment' => 'RADIUS authentication & accounting'],
            // File & Storage
            ['name' => 'FTP', 'protocol' => 'TCP', 'tcp_port' => '21', 'udp_port' => null, 'category' => 'File Transfer', 'is_system' => 1, 'comment' => 'File Transfer Protocol'],
            ['name' => 'SMB', 'protocol' => 'TCP', 'tcp_port' => '445', 'udp_port' => null, 'category' => 'File Transfer', 'is_system' => 1, 'comment' => 'Server Message Block / CIFS'],
            ['name' => 'NFS', 'protocol' => 'TCP/UDP', 'tcp_port' => '2049', 'udp_port' => '2049', 'category' => 'File Transfer', 'is_system' => 0, 'comment' => 'Network File System'],
            // Databases
            ['name' => 'MYSQL', 'protocol' => 'TCP', 'tcp_port' => '3306', 'udp_port' => null, 'category' => 'Database', 'is_system' => 1, 'comment' => 'MySQL / MariaDB database server'],
            ['name' => 'POSTGRESQL', 'protocol' => 'TCP', 'tcp_port' => '5432', 'udp_port' => null, 'category' => 'Database', 'is_system' => 1, 'comment' => 'PostgreSQL relational database'],
            ['name' => 'MSSQL', 'protocol' => 'TCP', 'tcp_port' => '1433', 'udp_port' => null, 'category' => 'Database', 'is_system' => 1, 'comment' => 'Microsoft SQL Server'],
            ['name' => 'ORACLE', 'protocol' => 'TCP', 'tcp_port' => '1521', 'udp_port' => null, 'category' => 'Database', 'is_system' => 0, 'comment' => 'Oracle Database Listener'],
            ['name' => 'REDIS', 'protocol' => 'TCP', 'tcp_port' => '6379', 'udp_port' => null, 'category' => 'Database', 'is_system' => 0, 'comment' => 'Redis key-value in-memory store'],
            ['name' => 'MONGODB', 'protocol' => 'TCP', 'tcp_port' => '27017', 'udp_port' => null, 'category' => 'Database', 'is_system' => 0, 'comment' => 'MongoDB NoSQL database'],
            // VPN & Tunneling
            ['name' => 'WIREGUARD', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '51820', 'category' => 'Tunneling', 'is_system' => 1, 'comment' => 'WireGuard secure VPN tunnel'],
            ['name' => 'OPENVPN', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '1194', 'category' => 'Tunneling', 'is_system' => 1, 'comment' => 'OpenVPN default UDP tunnel'],
            ['name' => 'IPSEC_IKE', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '500', 'category' => 'Tunneling', 'is_system' => 1, 'comment' => 'IPsec Internet Key Exchange (IKE)'],
            ['name' => 'IPSEC_NATT', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '4500', 'category' => 'Tunneling', 'is_system' => 1, 'comment' => 'IPsec NAT-Traversal'],
            // VoIP
            ['name' => 'SIP', 'protocol' => 'TCP/UDP', 'tcp_port' => '5060', 'udp_port' => '5060', 'category' => 'VoIP', 'is_system' => 0, 'comment' => 'Session Initiation Protocol for VoIP'],
            ['name' => 'RTP', 'protocol' => 'UDP', 'tcp_port' => null, 'udp_port' => '10000-20000', 'category' => 'VoIP', 'is_system' => 0, 'comment' => 'Real-time Transport Protocol media streams'],
        ];

        foreach ($services as $svc) {
            $exists = $svcTable->where('name', $svc['name'])->countAllResults();
            if ($exists === 0) {
                $svc['id'] = 'svc_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $svc['name']));
                $svc['created_at'] = $now;
                $svc['updated_at'] = $now;
                $svcTable->insert($svc);
            }
        }

        // 4. Seed Service Groups
        $sgTable = $db->table('firewall_service_groups');
        $serviceGroups = [
            [
                'id'         => 'sg_web_browsing',
                'name'       => 'Web_Browsing',
                'members'    => json_encode(['HTTP', 'HTTPS', 'DNS']),
                'comment'    => 'Standard outbound web surfing ports',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'sg_email_access',
                'name'       => 'Email_Access',
                'members'    => json_encode(['SMTP', 'SMTPS', 'SMTP_SUBMISSION', 'IMAP', 'IMAPS', 'POP3', 'POP3S']),
                'comment'    => 'Standard email transfer & retrieval protocols',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'sg_remote_mgmt',
                'name'       => 'Remote_Management',
                'members'    => json_encode(['SSH', 'RDP', 'HTTPS']),
                'comment'    => 'Secure remote administration services',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'sg_databases',
                'name'       => 'Database_Services',
                'members'    => json_encode(['MYSQL', 'POSTGRESQL', 'MSSQL', 'REDIS']),
                'comment'    => 'Common SQL and NoSQL database ports',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id'         => 'sg_vpn_protocols',
                'name'       => 'VPN_Protocols',
                'members'    => json_encode(['WIREGUARD', 'OPENVPN', 'IPSEC_IKE', 'IPSEC_NATT']),
                'comment'    => 'Standard encrypted VPN tunnels',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($serviceGroups as $sg) {
            $exists = $sgTable->where('name', $sg['name'])->countAllResults();
            if ($exists === 0) {
                $sgTable->insert($sg);
            }
        }

        // 5. Seed policies if none exist
        $ruleTable = $db->table('firewall_rules');
        $ruleCount = $ruleTable->countAllResults();

        // Update or insert policies
        if ($ruleCount === 0 || $ruleTable->where('name IS NOT NULL')->countAllResults() === 0) {
            $policies = [
                [
                    'id'                => 'pol_lan_to_wan_web',
                    'name'              => 'LAN to WAN Internet Access',
                    'interface_id'      => 'lan',
                    'src_interface'     => 'lan',
                    'dst_interface'     => 'wan',
                    'sources'           => json_encode(['LAN_Subnet']),
                    'destinations'      => json_encode(['all']),
                    'services'          => json_encode(['Web_Browsing']),
                    'action'            => 'accept',
                    'nat_enabled'       => 1,
                    'schedule'          => 'always',
                    'log_traffic'       => 'all',
                    'security_profiles' => json_encode(['ips']),
                    'sequence'          => 1,
                    'enabled'           => 1,
                    'direction'         => 'in',
                    'protocol'          => 'any',
                    'source_type'       => 'network',
                    'source_value'      => '192.168.1.0/24',
                    'dest_type'         => 'any',
                    'dest_value'        => 'any',
                    'dest_port'         => null,
                    'description'       => 'Default outbound web traffic from LAN to WAN with Source NAT',
                    'hit_count'         => 14205,
                    'bytes'             => 12845920,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
                [
                    'id'                => 'pol_lan_to_wan_vpn',
                    'name'              => 'Allow VPN Tunnels Outbound',
                    'interface_id'      => 'lan',
                    'src_interface'     => 'lan',
                    'dst_interface'     => 'wan',
                    'sources'           => json_encode(['LAN_Subnet']),
                    'destinations'      => json_encode(['all']),
                    'services'          => json_encode(['VPN_Protocols']),
                    'action'            => 'accept',
                    'nat_enabled'       => 1,
                    'schedule'          => 'always',
                    'log_traffic'       => 'all',
                    'security_profiles' => null,
                    'sequence'          => 2,
                    'enabled'           => 1,
                    'direction'         => 'in',
                    'protocol'          => 'any',
                    'source_type'       => 'network',
                    'source_value'      => '192.168.1.0/24',
                    'dest_type'         => 'any',
                    'dest_value'        => 'any',
                    'dest_port'         => null,
                    'description'       => 'Allow outbound VPN tunnel handshakes',
                    'hit_count'         => 342,
                    'bytes'             => 492040,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
                [
                    'id'                => 'pol_block_inbound_wan',
                    'name'              => 'Block Inbound WAN to LAN',
                    'interface_id'      => 'wan',
                    'src_interface'     => 'wan',
                    'dst_interface'     => 'lan',
                    'sources'           => json_encode(['all']),
                    'destinations'      => json_encode(['LAN_Subnet']),
                    'services'          => json_encode(['ALL']),
                    'action'            => 'deny',
                    'nat_enabled'       => 0,
                    'schedule'          => 'always',
                    'log_traffic'       => 'all',
                    'security_profiles' => null,
                    'sequence'          => 3,
                    'enabled'           => 1,
                    'direction'         => 'in',
                    'protocol'          => 'any',
                    'source_type'       => 'any',
                    'source_value'      => 'any',
                    'dest_type'         => 'network',
                    'dest_value'        => '192.168.1.0/24',
                    'dest_port'         => null,
                    'description'       => 'Drop unsolicited external traffic into private network',
                    'hit_count'         => 8921,
                    'bytes'             => 570944,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            ];

            foreach ($policies as $pol) {
                $exists = $ruleTable->where('id', $pol['id'])->countAllResults();
                if ($exists === 0) {
                    $ruleTable->insert($pol);
                }
            }
        }
    }
}
