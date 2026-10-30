<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 121: permissions for the browser terminal.
 *
 *   terminal.access — open a standard shell (unprivileged `micron-term` account)
 *   terminal.admin  — open a root shell (also requires re-entering the password)
 *
 * Standard terminals are granted to the administrative roles; root terminals
 * to nobody by default — the built-in super-administrator has every
 * permission, and any other role must be given it explicitly.
 */
class TerminalPermissions extends Migration
{
    private const PERMISSIONS = [
        ['id' => 'perm_terminal_access', 'key' => 'terminal.access', 'module' => 'Terminal', 'label' => 'Open Terminal',
            'description' => 'Open a standard browser terminal (unprivileged shell) on the appliance'],
        ['id' => 'perm_terminal_admin', 'key' => 'terminal.admin', 'module' => 'Terminal', 'label' => 'Open Root Terminal',
            'description' => 'Open an administrative (root) browser terminal after re-authenticating'],
    ];

    private const GRANTS = [
        'NetworkAdmin'  => ['terminal.access'],
        'SecurityAdmin' => ['terminal.access'],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('permissions')) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $ids = [];
        $fields = $this->db->getFieldNames('permissions');
        foreach (self::PERMISSIONS as $p) {
            $row = $this->db->table('permissions')->where('key', $p['key'])->get()->getRowArray();
            if ($row) {
                $ids[$p['key']] = $row['id'];
                continue;
            }
            $this->db->table('permissions')->insert(array_intersect_key($p + ['created_at' => $now], array_flip($fields)));
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
                if ($this->db->table('role_permissions')->where('role_id', $role['id'])->where('permission_id', $ids[$key])->countAllResults() === 0) {
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
