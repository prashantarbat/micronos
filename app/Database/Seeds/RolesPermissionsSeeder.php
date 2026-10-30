<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * RolesPermissionsSeeder
 *
 * Seeds the complete MicronOS RBAC system:
 *   - All granular permissions (grouped by module)
 *   - 5 built-in system roles with their permission sets
 *   - Assigns the SuperAdmin role to the initial admin user
 *
 * Built-in Roles (cannot be deleted, but CAN have permissions adjusted):
 *   1. SuperAdmin    — Full access to everything, including role management
 *   2. NetworkAdmin  — Full network config; no user/role management
 *   3. SecurityAdmin — Firewall, IDS/IPS, VPN, WebFilter; read-only network
 *   4. Operator      — Read-only across all panels; can view logs & dashboards
 *   5. VpnUser       — Self-service VPN portal only (road warrior access)
 */
class RolesPermissionsSeeder extends Seeder
{
    // -------------------------------------------------------------------------
    // Complete Permission Catalog
    // -------------------------------------------------------------------------
    private array $permissions = [

        // --- Dashboard ---
        ['module' => 'Dashboard', 'key' => 'dashboard.view',         'label' => 'View Dashboard'],
        ['module' => 'Dashboard', 'key' => 'dashboard.widgets.edit', 'label' => 'Customise Dashboard Widgets'],

        // --- Interfaces ---
        ['module' => 'Interfaces', 'key' => 'interfaces.view',       'label' => 'View Interfaces'],
        ['module' => 'Interfaces', 'key' => 'interfaces.edit',       'label' => 'Edit Interface Settings'],
        ['module' => 'Interfaces', 'key' => 'interfaces.apply',      'label' => 'Apply Interface Changes'],
        ['module' => 'Interfaces', 'key' => 'interfaces.vlan.manage','label' => 'Manage VLANs'],

        // --- Firewall ---
        ['module' => 'Firewall', 'key' => 'firewall.rules.view',      'label' => 'View Firewall Rules'],
        ['module' => 'Firewall', 'key' => 'firewall.rules.edit',      'label' => 'Edit Firewall Rules'],
        ['module' => 'Firewall', 'key' => 'firewall.rules.delete',    'label' => 'Delete Firewall Rules'],
        ['module' => 'Firewall', 'key' => 'firewall.rules.apply',     'label' => 'Apply / Commit Firewall Rules'],
        ['module' => 'Firewall', 'key' => 'firewall.rules.reorder',   'label' => 'Reorder Firewall Rules'],
        ['module' => 'Firewall', 'key' => 'firewall.schedules.manage','label' => 'Manage Firewall Schedules'],
        ['module' => 'Firewall', 'key' => 'firewall.aliases.manage',  'label' => 'Manage Firewall Aliases'],

        // --- NAT ---
        ['module' => 'NAT', 'key' => 'nat.view',    'label' => 'View NAT Rules'],
        ['module' => 'NAT', 'key' => 'nat.edit',    'label' => 'Edit NAT Rules'],
        ['module' => 'NAT', 'key' => 'nat.delete',  'label' => 'Delete NAT Rules'],
        ['module' => 'NAT', 'key' => 'nat.apply',   'label' => 'Apply NAT Rules'],

        // --- Multicast & IGMP ---
        ['module' => 'Multicast', 'key' => 'multicast.view',  'label' => 'View Multicast & IGMP Configuration'],
        ['module' => 'Multicast', 'key' => 'multicast.edit',  'label' => 'Edit Multicast & IGMP Configuration'],
        ['module' => 'Multicast', 'key' => 'multicast.apply', 'label' => 'Apply Multicast & IGMP Configuration'],

        // --- Routing ---
        ['module' => 'Routing', 'key' => 'routing.table.view',       'label' => 'View Routing Table'],
        ['module' => 'Routing', 'key' => 'routing.static.manage',    'label' => 'Manage Static Routes'],
        ['module' => 'Routing', 'key' => 'routing.frr.view',         'label' => 'View FRRouting Status'],
        ['module' => 'Routing', 'key' => 'routing.frr.edit',         'label' => 'Edit FRRouting Config (BGP/OSPF)'],
        ['module' => 'Routing', 'key' => 'routing.multiwan.manage',  'label' => 'Manage Multi-WAN & Load Balancing'],
        ['module' => 'Routing', 'key' => 'routing.pbr.manage',       'label' => 'Manage Policy-Based Routing'],

        // --- VPN — WireGuard ---
        ['module' => 'VPN', 'key' => 'vpn.ipsec.view',             'label' => 'View IPsec Tunnels'],
        ['module' => 'VPN', 'key' => 'vpn.ipsec.manage',           'label' => 'Manage IPsec Tunnels'],
        ['module' => 'VPN', 'key' => 'vpn.wireguard.view',         'label' => 'View WireGuard Status'],
        ['module' => 'VPN', 'key' => 'vpn.wireguard.manage',       'label' => 'Manage WireGuard Tunnels & Peers'],
        ['module' => 'VPN', 'key' => 'vpn.wireguard.keys.export',  'label' => 'Export WireGuard Key Configs'],
        ['module' => 'VPN', 'key' => 'vpn.wireguard.peer.selfservice', 'label' => 'Self-Service WireGuard Peer (Road Warrior)'],

        // --- VPN — OpenVPN ---
        ['module' => 'VPN', 'key' => 'vpn.openvpn.view',           'label' => 'View OpenVPN Status'],
        ['module' => 'VPN', 'key' => 'vpn.openvpn.manage',         'label' => 'Manage OpenVPN Server & Clients'],
        ['module' => 'VPN', 'key' => 'vpn.openvpn.cert.export',    'label' => 'Export OpenVPN Client Bundles (.ovpn)'],
        ['module' => 'VPN', 'key' => 'vpn.openvpn.selfservice',    'label' => 'Self-Service OpenVPN Enrollment (Road Warrior)'],

        // --- DHCP ---
        ['module' => 'DHCP', 'key' => 'dhcp.view',           'label' => 'View DHCP Leases'],
        ['module' => 'DHCP', 'key' => 'dhcp.edit',           'label' => 'Edit DHCP Server Settings'],
        ['module' => 'DHCP', 'key' => 'dhcp.leases.manage',  'label' => 'Manage Static DHCP Leases'],
        ['module' => 'DHCP', 'key' => 'dhcp.restart',        'label' => 'Restart DHCP Server'],

        // --- DNS ---
        ['module' => 'DNS', 'key' => 'dns.view',            'label' => 'View DNS Settings'],
        ['module' => 'DNS', 'key' => 'dns.edit',            'label' => 'Edit DNS Resolver Settings'],
        ['module' => 'DNS', 'key' => 'dns.override.manage', 'label' => 'Manage DNS Host Overrides'],
        ['module' => 'DNS', 'key' => 'dns.flush',           'label' => 'Flush DNS Cache'],

        // --- Web Filter (Squid) ---
        ['module' => 'WebFilter', 'key' => 'webfilter.view',             'label' => 'View Web Filter Status'],
        ['module' => 'WebFilter', 'key' => 'webfilter.policy.edit',      'label' => 'Edit Web Filter Policies'],
        ['module' => 'WebFilter', 'key' => 'webfilter.categories.manage','label' => 'Manage URL Categories & Blocklists'],
        ['module' => 'WebFilter', 'key' => 'webfilter.bypass.manage',    'label' => 'Manage Bypass Exceptions'],
        ['module' => 'WebFilter', 'key' => 'webfilter.logs.view',        'label' => 'View Web Filter Logs'],
        ['module' => 'WebFilter', 'key' => 'webfilter.reload',           'label' => 'Reload Squid / Apply Filter Changes'],

        // --- IDS/IPS (Suricata) ---
        ['module' => 'IDS/IPS', 'key' => 'ids.view',             'label' => 'View IDS/IPS Alerts & Status'],
        ['module' => 'IDS/IPS', 'key' => 'ids.rules.manage',     'label' => 'Manage Suricata Rules & Rulesets'],
        ['module' => 'IDS/IPS', 'key' => 'ids.suppress.manage',  'label' => 'Manage Alert Suppressions'],
        ['module' => 'IDS/IPS', 'key' => 'ids.restart',          'label' => 'Restart Suricata Engine'],

        // --- QoS / Traffic Shaping ---
        ['module' => 'QoS', 'key' => 'qos.view',    'label' => 'View QoS / Traffic Shaper'],
        ['module' => 'QoS', 'key' => 'qos.edit',    'label' => 'Edit QoS Policies'],
        ['module' => 'QoS', 'key' => 'qos.apply',   'label' => 'Apply QoS Changes'],

        // --- High Availability ---
        ['module' => 'HighAvailability', 'key' => 'ha.view',    'label' => 'View HA Cluster Status'],
        ['module' => 'HighAvailability', 'key' => 'ha.edit',    'label' => 'Edit HA Cluster Settings'],
        ['module' => 'HighAvailability', 'key' => 'ha.failover','label' => 'Trigger Manual Failover'],

        // --- Certificates & PKI ---
        ['module' => 'Certificates', 'key' => 'cert.view',        'label' => 'View Certificates'],
        ['module' => 'Certificates', 'key' => 'cert.create',      'label' => 'Create / Import Certificates'],
        ['module' => 'Certificates', 'key' => 'cert.delete',      'label' => 'Delete Certificates'],
        ['module' => 'Certificates', 'key' => 'cert.ca.manage',   'label' => 'Manage Certificate Authorities'],

        // --- Identity & Access (Users / Roles / LDAP) ---
        ['module' => 'Identity', 'key' => 'users.view',         'label' => 'View Users'],
        ['module' => 'Identity', 'key' => 'users.create',       'label' => 'Create Users'],
        ['module' => 'Identity', 'key' => 'users.edit',         'label' => 'Edit Users'],
        ['module' => 'Identity', 'key' => 'users.delete',       'label' => 'Delete Users'],
        ['module' => 'Identity', 'key' => 'users.password.reset','label' => 'Reset User Passwords'],
        ['module' => 'Identity', 'key' => 'roles.view',         'label' => 'View Roles & Permissions'],
        ['module' => 'Identity', 'key' => 'roles.create',       'label' => 'Create Custom Roles'],
        ['module' => 'Identity', 'key' => 'roles.edit',         'label' => 'Edit Role Permissions'],
        ['module' => 'Identity', 'key' => 'roles.delete',       'label' => 'Delete Custom Roles'],
        ['module' => 'Identity', 'key' => 'roles.assign',       'label' => 'Assign Roles to Users'],
        ['module' => 'Identity', 'key' => 'ldap.view',          'label' => 'View LDAP / AD Configuration'],
        ['module' => 'Identity', 'key' => 'ldap.edit',          'label' => 'Edit LDAP / AD Servers'],
        ['module' => 'Identity', 'key' => 'totp.manage',        'label' => 'Manage User 2FA (TOTP)'],

        // --- Backup & Restore ---
        ['module' => 'Backup', 'key' => 'backup.view',    'label' => 'View Backup History'],
        ['module' => 'Backup', 'key' => 'backup.create',  'label' => 'Create Configuration Backup'],
        ['module' => 'Backup', 'key' => 'backup.restore', 'label' => 'Restore Configuration Backup'],
        ['module' => 'Backup', 'key' => 'backup.delete',  'label' => 'Delete Backup Files'],
        ['module' => 'Backup', 'key' => 'backup.export',  'label' => 'Download / Export Backup File'],

        // --- System ---
        ['module' => 'System', 'key' => 'system.info.view',      'label' => 'View System Information'],
        ['module' => 'System', 'key' => 'system.settings.edit',  'label' => 'Edit System Settings'],
        ['module' => 'System', 'key' => 'system.reboot',         'label' => 'Reboot Appliance'],
        ['module' => 'System', 'key' => 'system.halt',           'label' => 'Halt / Shutdown Appliance'],
        ['module' => 'System', 'key' => 'system.update',         'label' => 'Apply System Updates'],
        ['module' => 'System', 'key' => 'system.factory_reset',  'label' => 'Factory Reset Appliance'],
        ['module' => 'System', 'key' => 'system.noc.register',   'label' => 'Register with Central NOC'],

        // --- Terminal / Console ---
        ['module' => 'Terminal', 'key' => 'terminal.access',      'label' => 'Open Terminal'],
        ['module' => 'Terminal', 'key' => 'terminal.admin',       'label' => 'Open Root Terminal'],

        // --- Diagnostics ---
        ['module' => 'Diagnostics', 'key' => 'diag.ping',        'label' => 'Run Ping / Traceroute'],
        ['module' => 'Diagnostics', 'key' => 'diag.packet_capture','label' => 'Run Packet Capture (tcpdump)'],
        ['module' => 'Diagnostics', 'key' => 'diag.logs.view',   'label' => 'View System & Service Logs'],
        ['module' => 'Diagnostics', 'key' => 'diag.audit.view',  'label' => 'View Audit / Security Logs'],
        ['module' => 'Diagnostics', 'key' => 'diag.arp.view',    'label' => 'View ARP Table'],
        ['module' => 'Diagnostics', 'key' => 'diag.states.view', 'label' => 'View Connection State Table'],
    ];

    // -------------------------------------------------------------------------
    // Built-in Role Definitions
    // -------------------------------------------------------------------------
    private array $roles = [
        [
            'name'         => 'SuperAdmin',
            'display_name' => 'Super Administrator',
            'description'  => 'Unrestricted access to all system functions including role and user management. Equivalent to root for the web interface.',
            'is_system'    => 1,
            'permissions'  => '*', // All permissions
        ],
        [
            'name'         => 'NetworkAdmin',
            'display_name' => 'Network Administrator',
            'description'  => 'Full control over network configuration: interfaces, firewall, NAT, routing, VPN, DHCP, DNS, and QoS. Cannot manage users, roles, or perform system operations.',
            'is_system'    => 1,
            'permissions'  => [
                'dashboard.view',
                'interfaces.*', 'firewall.*', 'nat.*', 'routing.*',
                'vpn.wireguard.*', 'vpn.openvpn.*',
                'dhcp.*', 'dns.*', 'qos.*',
                'cert.view', 'cert.create',
                'diag.*',
                'system.info.view',
                'terminal.access',
            ],
        ],
        [
            'name'         => 'SecurityAdmin',
            'display_name' => 'Security Administrator',
            'description'  => 'Manages security posture: firewall rules, IDS/IPS, web filtering, VPN, certificates, and audit logs. Read-only on network topology.',
            'is_system'    => 1,
            'permissions'  => [
                'dashboard.view',
                'interfaces.view',
                'firewall.*', 'nat.view', 'nat.edit', 'nat.apply',
                'vpn.wireguard.*', 'vpn.openvpn.*',
                'webfilter.*', 'ids.*', 'cert.*',
                'users.view', 'totp.manage',
                'diag.logs.view', 'diag.audit.view', 'diag.ping',
                'system.info.view', 'backup.view',
                'terminal.access',
            ],
        ],
        [
            'name'         => 'Operator',
            'display_name' => 'Operator (Read-Only)',
            'description'  => 'View-only access to dashboards, logs, and status panels. Cannot modify any configuration.',
            'is_system'    => 1,
            'permissions'  => [
                'dashboard.view',
                'interfaces.view',
                'firewall.rules.view', 'nat.view', 'routing.table.view', 'routing.frr.view',
                'vpn.wireguard.view', 'vpn.openvpn.view',
                'dhcp.view', 'dns.view',
                'webfilter.view', 'webfilter.logs.view',
                'ids.view', 'qos.view', 'ha.view', 'cert.view',
                'users.view', 'roles.view',
                'backup.view',
                'system.info.view',
                'diag.ping', 'diag.logs.view', 'diag.audit.view', 'diag.arp.view', 'diag.states.view',
            ],
        ],
        [
            'name'         => 'VpnUser',
            'display_name' => 'VPN Road Warrior',
            'description'  => 'Self-service VPN portal access only. Can view their own VPN status, download their own client configuration, and manage their own 2FA enrollment.',
            'is_system'    => 1,
            'permissions'  => [
                'vpn.wireguard.peer.selfservice',
                'vpn.openvpn.selfservice',
            ],
        ],
    ];

    // -------------------------------------------------------------------------
    public function run(): void
    {
        $db = \Config\Database::connect();

        $now = date('Y-m-d H:i:s');

        // ------------------------------------------------------------------
        // 1. Seed Permissions
        // ------------------------------------------------------------------
        $permIdMap = []; // key => uuid

        foreach ($this->permissions as $perm) {
            $existing = $db->table('permissions')->where('key', $perm['key'])->get()->getRowArray();

            if ($existing) {
                $permIdMap[$perm['key']] = $existing['id'];
                continue;
            }

            $id = $this->uuid();
            $db->table('permissions')->insert([
                'id'          => $id,
                'key'         => $perm['key'],
                'label'       => $perm['label'],
                'module'      => $perm['module'],
                'description' => null,
                'created_at'  => $now,
            ]);
            $permIdMap[$perm['key']] = $id;
        }

        // ------------------------------------------------------------------
        // 2. Seed Roles
        // ------------------------------------------------------------------
        foreach ($this->roles as $roleDef) {
            $existing = $db->table('roles')->where('name', $roleDef['name'])->get()->getRowArray();

            if ($existing) {
                $roleId = $existing['id'];
            } else {
                $roleId = $this->uuid();
                $db->table('roles')->insert([
                    'id'           => $roleId,
                    'name'         => $roleDef['name'],
                    'display_name' => $roleDef['display_name'],
                    'description'  => $roleDef['description'],
                    'is_system'    => $roleDef['is_system'],
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }

            // ------------------------------------------------------------------
            // 3. Assign Permissions to Role
            // ------------------------------------------------------------------
            $grantedPermKeys = $roleDef['permissions'];

            if ($grantedPermKeys === '*') {
                // SuperAdmin gets everything
                $grantedPermKeys = array_column($this->permissions, 'key');
            } else {
                // Expand glob patterns like 'interfaces.*'
                $grantedPermKeys = $this->expandGlobs($grantedPermKeys, array_column($this->permissions, 'key'));
            }

            foreach ($grantedPermKeys as $permKey) {
                if (! isset($permIdMap[$permKey])) {
                    continue;
                }

                $permId = $permIdMap[$permKey];

                $pivotExists = $db->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->countAllResults();

                if (! $pivotExists) {
                    $db->table('role_permissions')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permId,
                        'granted'       => 1,
                        'created_at'    => $now,
                    ]);
                }
            }
        }

        // ------------------------------------------------------------------
        // 4. Assign SuperAdmin role to the initial admin user
        // ------------------------------------------------------------------
        $adminUser = $db->table('users')->where('username', 'Ministrator')->get()->getRowArray();
        $superAdminRole = $db->table('roles')->where('name', 'SuperAdmin')->get()->getRowArray();

        if ($adminUser && $superAdminRole) {
            $alreadyAssigned = $db->table('user_roles')
                ->where('user_id', $adminUser['id'])
                ->where('role_id', $superAdminRole['id'])
                ->countAllResults();

            if (! $alreadyAssigned) {
                $db->table('user_roles')->insert([
                    'user_id'     => $adminUser['id'],
                    'role_id'     => $superAdminRole['id'],
                    'assigned_at' => $now,
                    'assigned_by' => null,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Expand glob patterns (e.g. 'interfaces.*') against the full permission list.
     */
    private function expandGlobs(array $patterns, array $allKeys): array
    {
        $resolved = [];

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                $prefix = rtrim(str_replace('*', '', $pattern), '.');
                foreach ($allKeys as $key) {
                    if ($prefix === '' || str_starts_with($key, $prefix . '.') || $key === $prefix) {
                        $resolved[] = $key;
                    }
                }
            } else {
                $resolved[] = $pattern;
            }
        }

        return array_unique($resolved);
    }

    /**
     * Generate a UUID v4.
     */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
