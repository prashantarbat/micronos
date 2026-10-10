<?php

namespace App\Controllers;

use App\Models\RoleModel;
use App\Models\PermissionModel;

/**
 * Roles Controller — RBAC Role & Permission management UI.
 */
class Roles extends BaseController
{
    private RoleModel $roleModel;
    private PermissionModel $permModel;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->roleModel = new RoleModel();
        $this->permModel = new PermissionModel();
    }

    // -------------------------------------------------------------------------
    // List all roles
    // -------------------------------------------------------------------------
    public function index(): string
    {
        $roles = $this->roleModel->getAllWithCounts();
        $groupMappingModel = new \App\Models\AuthGroupMappingModel();
        $ldapMappings      = $groupMappingModel->getAllWithRoles('ldap');
        $radiusMappings    = $groupMappingModel->getAllWithRoles('radius');

        return view('roles/index', [
            'title'          => 'Roles & Permissions — MicronOS',
            'breadcrumb'     => 'Roles & Permissions',
            'roles'          => $roles,
            'ldapMappings'   => $ldapMappings,
            'radiusMappings' => $radiusMappings,
        ]);
    }

    // -------------------------------------------------------------------------
    // Create role form
    // -------------------------------------------------------------------------
    public function create(): string
    {
        $grouped = $this->permModel->getAllGrouped();

        return view('roles/create', [
            'title'           => 'Create Role — MicronOS',
            'breadcrumb'      => 'Create Role',
            'groupedPerms'    => $grouped,
            'selectedPerms'   => [],
        ]);
    }

    // -------------------------------------------------------------------------
    // Store new role
    // -------------------------------------------------------------------------
    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'name'         => 'required|min_length[2]|max_length[64]|alpha_dash',
            'display_name' => 'required|min_length[2]|max_length[128]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $name         = trim($this->request->getPost('name'));
        $displayName  = trim($this->request->getPost('display_name'));
        $description  = trim($this->request->getPost('description') ?? '');
        $permIds      = $this->request->getPost('permissions') ?? [];

        // Check name uniqueness
        if ($this->roleModel->where('name', $name)->first()) {
            return redirect()->back()->withInput()
                ->with('error', "A role named '{$name}' already exists.");
        }

        try {
            $roleId = $this->roleModel->createRole($name, $displayName, $description);
            $this->roleModel->syncPermissions($roleId, (array) $permIds);

            $this->logAudit('roles.create', "Created role: {$name}");

            return redirect()->to(base_url('roles'))
                ->with('success', "Role '{$displayName}' created successfully.");
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()
                ->with('error', 'Failed to create role: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Edit role permissions form
    // -------------------------------------------------------------------------
    public function edit(string $roleId): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $role = $this->roleModel->getWithPermissions($roleId);

        if (! $role) {
            return redirect()->to(base_url('roles'))->with('error', 'Role not found.');
        }

        $grouped       = $this->permModel->getAllGrouped();
        $selectedPerms = array_column($role['permissions'] ?? [], 'id');

        $groupMappingModel = new \App\Models\AuthGroupMappingModel();
        $mappedGroups      = $groupMappingModel->where('role_id', $roleId)->findAll();

        return view('roles/edit', [
            'title'         => "Edit Role: {$role['display_name']} — MicronOS",
            'breadcrumb'    => 'Edit Role',
            'role'          => $role,
            'groupedPerms'  => $grouped,
            'selectedPerms' => $selectedPerms,
            'mappedGroups'  => $mappedGroups,
        ]);
    }

    // -------------------------------------------------------------------------
    // Update role
    // -------------------------------------------------------------------------
    public function update(string $roleId): \CodeIgniter\HTTP\RedirectResponse
    {
        $role = $this->roleModel->find($roleId);

        if (! $role) {
            return redirect()->to(base_url('roles'))->with('error', 'Role not found.');
        }

        $displayName = trim($this->request->getPost('display_name') ?? '');
        $description = trim($this->request->getPost('description') ?? '');
        $permIds     = $this->request->getPost('permissions') ?? [];

        // Update display name and description (name slug cannot change for system roles)
        $updateData = ['display_name' => $displayName, 'description' => $description];
        if (! $role['is_system']) {
            $newName = trim($this->request->getPost('name') ?? $role['name']);
            $updateData['name'] = $newName;
        }

        $this->roleModel->update($roleId, $updateData);
        $this->roleModel->syncPermissions($roleId, (array) $permIds);
        \App\Models\PermissionModel::clearSessionCache();

        $this->logAudit('roles.edit', "Updated role: {$role['name']}");

        return redirect()->to(base_url('roles'))
            ->with('success', "Role '{$role['name']}' updated successfully.");
    }

    // -------------------------------------------------------------------------
    // Delete custom role
    // -------------------------------------------------------------------------
    public function delete(string $roleId): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $role = $this->roleModel->find($roleId);
            $this->roleModel->deleteCustomRole($roleId);

            $this->logAudit('roles.delete', "Deleted role: " . ($role['name'] ?? $roleId));

            return redirect()->to(base_url('roles'))
                ->with('success', 'Role deleted successfully.');
        } catch (\RuntimeException $e) {
            return redirect()->to(base_url('roles'))
                ->with('error', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Sync permissions (standalone AJAX endpoint)
    // -------------------------------------------------------------------------
    public function syncPermissions(string $roleId): \CodeIgniter\HTTP\ResponseInterface
    {
        $permIds = $this->request->getPost('permissions') ?? [];
        $this->roleModel->syncPermissions($roleId, (array) $permIds);
        \App\Models\PermissionModel::clearSessionCache();

        return $this->response->setJSON(['success' => true, 'count' => count($permIds)]);
    }

    // -------------------------------------------------------------------------
    // External Group to Role Mappings (LDAP & RADIUS)
    // -------------------------------------------------------------------------
    public function saveGroupMapping(): \CodeIgniter\HTTP\RedirectResponse
    {
        $groupMappingModel = new \App\Models\AuthGroupMappingModel();
        $sourceType = trim((string)($this->request->getPost('auth_type') ?: $this->request->getPost('source_type') ?: 'ldap'));
        $groupName  = trim((string)($this->request->getPost('external_group') ?: $this->request->getPost('external_group_name')));
        $roleId     = trim((string)$this->request->getPost('role_id'));
        $priority   = (int)($this->request->getPost('priority') ?: 100);

        if (empty($groupName) || empty($roleId)) {
            return redirect()->back()->with('error', 'External group name and target role are required.');
        }

        $groupMappingModel->insert([
            'id'             => 'map_' . bin2hex(random_bytes(6)),
            'auth_type'      => $sourceType,
            'external_group' => $groupName,
            'role_id'        => $roleId,
            'priority'       => $priority,
            'description'    => trim((string)$this->request->getPost('description') ?? ''),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit('roles.group_mapping.create', "Mapped {$sourceType} group '{$groupName}' to role ID '{$roleId}'");

        return redirect()->back()->with('success', 'External group mapping added successfully.');
    }

    public function deleteGroupMapping(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $groupMappingModel = new \App\Models\AuthGroupMappingModel();
        $mapping = $groupMappingModel->find($id);
        if ($mapping) {
            $groupMappingModel->delete($id);
            $src = $mapping['auth_type'] ?? $mapping['source_type'] ?? 'ldap';
            $grp = $mapping['external_group'] ?? $mapping['external_group_name'] ?? '';
            $this->logAudit('roles.group_mapping.delete', "Deleted {$src} group mapping '{$grp}'");
        }

        return redirect()->back()->with('success', 'Group mapping removed.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private function logAudit(string $action, string $detail): void
    {
        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                // No `id`: it is an INTEGER PRIMARY KEY, which rejects a hex
                // string, so this insert used to fail with "datatype mismatch".
                // The four legacy columns are NOT NULL on an appliance whose
                // database predates the migration file's later edits.
                'admin_user'      => session('username') ?? 'admin',
                'client_ip'       => $this->request->getIPAddress(),
                'user_id'         => session('user_id'),
                'username'        => session('username'),
                'action_type'     => 'security',
                'target_resource' => 'Roles',
                'action'          => $action,
                'target'          => $detail,
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) { /* non-critical */ }
    }
}
