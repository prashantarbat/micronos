<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Catalogue permission keys that routes already required but no migration or
 * seeder ever created. A route guarded by an uncatalogued key denies every
 * user except the built-in super-administrator, and the role editor cannot
 * offer it — so the IPsec pages and three captive-portal actions were
 * unreachable for every delegated role.
 *
 * Found by CompletionRegressionTest::testEveryRoutePermissionExistsInTheCatalogue.
 */
class CatalogueMissingRoutePermissions extends Migration
{
    private const PERMISSIONS = [
        ['id' => 'perm_vpn_ipsec_view',          'key' => 'vpn.ipsec.view',          'module' => 'VPN',            'label' => 'View IPsec Tunnels',       'description' => 'View strongSwan IPsec tunnels and SA status'],
        ['id' => 'perm_vpn_ipsec_manage',        'key' => 'vpn.ipsec.manage',        'module' => 'VPN',            'label' => 'Manage IPsec Tunnels',     'description' => 'Create, edit, delete and apply IPsec tunnels'],
        ['id' => 'perm_captive_vouchers_revoke', 'key' => 'captive.vouchers.revoke', 'module' => 'Captive Portal', 'label' => 'Revoke Vouchers',          'description' => 'Revoke unused or active hotspot vouchers'],
        ['id' => 'perm_captive_apply',           'key' => 'captive.apply',           'module' => 'Captive Portal', 'label' => 'Apply Captive Portal',     'description' => 'Compile and apply captive portal firewall redirection'],
    ];

    private const GRANTS = [
        'NetworkAdmin'  => ['vpn.ipsec.view', 'vpn.ipsec.manage', 'captive.vouchers.revoke', 'captive.apply'],
        'SecurityAdmin' => ['vpn.ipsec.view', 'vpn.ipsec.manage'],
        'Operator'      => ['vpn.ipsec.view'],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach (self::PERMISSIONS as $p) {
            $row = $this->db->table('permissions')->where('key', $p['key'])->get()->getRowArray();
            if ($row) {
                $ids[$p['key']] = $row['id'];
                continue;
            }
            $this->db->table('permissions')->insert($p + ['created_at' => $now]);
            $ids[$p['key']] = $p['id'];
        }
        if (! $this->db->tableExists('roles') || ! $this->db->tableExists('role_permissions')) {
            return;
        }
        foreach (self::GRANTS as $roleName => $keys) {
            $role = $this->db->table('roles')->where('name', $roleName)->get()->getRowArray();
            if (! $role) {
                continue;
            }
            foreach ($keys as $key) {
                $exists = $this->db->table('role_permissions')
                    ->where('role_id', $role['id'])->where('permission_id', $ids[$key])->countAllResults();
                if ($exists === 0) {
                    $this->db->table('role_permissions')->insert([
                        'role_id' => $role['id'], 'permission_id' => $ids[$key], 'granted' => 1, 'created_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $keys = array_column(self::PERMISSIONS, 'key');
        $ids  = array_column($this->db->table('permissions')->whereIn('key', $keys)->get()->getResultArray(), 'id');
        if ($ids !== [] && $this->db->tableExists('role_permissions')) {
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        $this->db->table('permissions')->whereIn('key', $keys)->delete();
    }
}
