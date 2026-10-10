<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\RoleModel;
use App\Libraries\IpcClient;

/**
 * Users Controller — Identity & Access Management (IAM)
 * Unified Web GUI and Debian Linux OS User Management
 */
class Users extends BaseController
{
    private UserModel $userModel;
    private RoleModel $roleModel;
    private IpcClient $ipcClient;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->userModel = new UserModel();
        $this->roleModel = new RoleModel();
        $this->ipcClient = new IpcClient();
    }

    /**
     * List all system users with their assigned roles and Micron OS sync status.
     */
    public function index(): string
    {
        $users = $this->userModel->getUsersWithRoles();
        $roles = $this->roleModel->getAllWithCounts();
        $osResult = $this->ipcClient->userListOs();
        $osUsers = $osResult['users'] ?? [];

        $osMap = [];
        foreach ($osUsers as $osU) {
            $osMap[strtolower((string)($osU['username'] ?? ''))] = $osU;
        }

        foreach ($users as &$u) {
            $lower = strtolower($u['username']);
            if (isset($osMap[$lower])) {
                $u['os_synced']   = true;
                $u['os_uid']      = $osMap[$lower]['uid'] ?? null;
                $u['os_shell']    = $osMap[$lower]['shell'] ?? null;
                $u['os_is_admin'] = (bool)($osMap[$lower]['is_admin'] ?? false);
            } else {
                $u['os_synced']   = false;
                $u['os_uid']      = null;
                $u['os_shell']    = null;
                $u['os_is_admin'] = false;
            }
        }
        unset($u);

        return view('users/index', [
            'title'      => 'User Management — MicronOS',
            'breadcrumb' => 'Users',
            'users'      => $users,
            'roles'      => $roles,
            'osUsers'    => $osUsers,
        ]);
    }

    /**
     * Show create user form.
     */
    public function create(): string
    {
        $roles = $this->roleModel->getAllWithCounts();
        $shells = ['/bin/bash', '/bin/sh', '/usr/local/bin/microctl', '/usr/sbin/nologin'];

        return view('users/create', [
            'title'      => 'Create User — MicronOS',
            'breadcrumb' => 'Create User',
            'roles'      => $roles,
            'shells'     => $shells,
        ]);
    }

    /**
     * Store new user in DB and provision matching Micron OS user account.
     */
    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $authSource = trim((string)$this->request->getPost('auth_source') ?: 'local');
        if (! in_array($authSource, ['local', 'ldap', 'radius'], true)) {
            $authSource = 'local';
        }

        $rules = [
            'username'  => 'required|min_length[3]|max_length[32]|alpha_dash',
            'full_name' => 'required|min_length[2]|max_length[128]',
            'email'     => 'permit_empty|valid_email|max_length[128]',
            'shell'     => 'permit_empty|in_list[/bin/bash,/bin/sh,/usr/local/bin/microctl,/usr/sbin/nologin]',
            'ssh_keys'  => 'permit_empty|max_length[8192]',
        ];
        if ($authSource === 'local') {
            $rules['password'] = 'required|min_length[6]';
        } else {
            $rules['password'] = 'permit_empty|min_length[6]';
        }

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $username = trim((string)$this->request->getPost('username'));

        if ($this->userModel->where('username', $username)->first()) {
            return redirect()->back()->withInput()
                ->with('error', "Username '{$username}' is already in use.");
        }

        $userId = bin2hex(random_bytes(16));
        $now    = date('Y-m-d H:i:s');
        $pwd    = (string)$this->request->getPost('password');
        $pwdHash = !empty($pwd) ? UserModel::hashPassword($pwd) : UserModel::hashPassword(bin2hex(random_bytes(16)));
        $shell   = trim((string)$this->request->getPost('shell')) ?: '/bin/bash';
        $sshKeys = trim((string)$this->request->getPost('ssh_keys') ?? '');

        $this->userModel->insert([
            'id'             => $userId,
            'username'       => $username,
            'password_hash'  => $pwdHash,
            'full_name'      => trim((string)$this->request->getPost('full_name')),
            'email'          => trim((string)$this->request->getPost('email') ?? ''),
            'shell'          => $shell,
            'ssh_keys'       => $sshKeys ?: null,
            'role'           => 'operator', // legacy compatibility
            'auth_source'    => $authSource,
            'ldap_dn'        => trim((string)$this->request->getPost('ldap_dn')) ?: null,
            'external_group' => trim((string)$this->request->getPost('external_group')) ?: null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // Sync roles
        $roleIds = (array) ($this->request->getPost('roles') ?? []);
        $this->userModel->syncRoles($userId, $roleIds, session('user_id'));

        // Provision Micron OS user account via micrond
        if ($authSource === 'local') {
            $isAdmin = $this->isUserAdmin($roleIds);
            try {
                $this->ipcClient->userCreate([
                    'username'  => $username,
                    'password'  => $pwd,
                    'full_name' => trim((string)$this->request->getPost('full_name')),
                    'shell'     => $shell,
                    'is_admin'  => $isAdmin,
                    'ssh_keys'  => $sshKeys,
                ]);
            } catch (\Throwable $e) {
                log_message('warning', "[Users::store] Micron OS user provision warning: " . $e->getMessage());
            }
        }

        $this->logAudit('users.create', "Created user: {$username} (Auth: {$authSource})");

        return redirect()->to(base_url('users'))
            ->with('success', "User '{$username}' created and synchronized with Micron OS.");
    }

    /**
     * Show edit user form.
     */
    public function edit(string $userId): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->find($userId);
        if (! $user) {
            return redirect()->to(base_url('users'))->with('error', 'User not found.');
        }

        $userRoles     = $this->userModel->getUserRoles($userId);
        $selectedRoles = array_column($userRoles, 'id');
        $allRoles      = $this->roleModel->getAllWithCounts();
        $shells        = ['/bin/bash', '/bin/sh', '/usr/local/bin/microctl', '/usr/sbin/nologin'];

        return view('users/edit', [
            'title'         => "Edit User: {$user['username']} — MicronOS",
            'breadcrumb'    => 'Edit User',
            'user'          => $user,
            'allRoles'      => $allRoles,
            'selectedRoles' => $selectedRoles,
            'shells'        => $shells,
        ]);
    }

    /**
     * Update user details and roles, synchronizing changes to Micron OS.
     */
    public function update(string $userId): \CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->find($userId);
        if (! $user) {
            return redirect()->to(base_url('users'))->with('error', 'User not found.');
        }

        $rules = [
            'full_name' => 'required|min_length[2]|max_length[128]',
            'email'     => 'permit_empty|valid_email|max_length[128]',
            'password'  => 'permit_empty|min_length[6]',
            'shell'     => 'permit_empty|in_list[/bin/bash,/bin/sh,/usr/local/bin/microctl,/usr/sbin/nologin]',
            'ssh_keys'  => 'permit_empty|max_length[8192]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $shell   = trim((string)$this->request->getPost('shell')) ?: ($user['shell'] ?? '/bin/bash');
        $sshKeys = trim((string)$this->request->getPost('ssh_keys') ?? '');

        $updateData = [
            'full_name'  => trim((string)$this->request->getPost('full_name')),
            'email'      => trim((string)$this->request->getPost('email') ?? ''),
            'shell'      => $shell,
            'ssh_keys'   => $sshKeys ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->request->getPost('auth_source')) {
            $authSource = trim((string)$this->request->getPost('auth_source'));
            if (in_array($authSource, ['local', 'ldap', 'radius'], true)) {
                $updateData['auth_source'] = $authSource;
            }
        }
        if ($this->request->getPost('ldap_dn') !== null) {
            $updateData['ldap_dn'] = trim((string)$this->request->getPost('ldap_dn')) ?: null;
        }
        if ($this->request->getPost('external_group') !== null) {
            $updateData['external_group'] = trim((string)$this->request->getPost('external_group')) ?: null;
        }

        $newPassword = $this->request->getPost('password');
        if (! empty($newPassword)) {
            $updateData['password_hash'] = UserModel::hashPassword($newPassword);
            $updateData['locked_until']  = null;
            $updateData['failed_logins'] = 0;
        }

        // Toggle TOTP reset if requested
        if ($this->request->getPost('reset_totp') === '1') {
            $updateData['totp_enabled'] = 0;
            $updateData['totp_secret']  = null;
        }

        $this->userModel->update($userId, $updateData);

        // Sync roles (protect default Ministrator from having zero roles)
        $roleIds = (array) ($this->request->getPost('roles') ?? []);
        if (strtolower($user['username']) === 'ministrator' && empty($roleIds)) {
            $superRole = $this->roleModel->where('name', 'superadmin')->first();
            if ($superRole) {
                $roleIds = [$superRole['id']];
            }
        }

        $this->userModel->syncRoles($userId, $roleIds, session('user_id'));
        \App\Models\PermissionModel::clearSessionCache();

        // Synchronize changes to Micron OS user account
        $isAdmin = (strtolower($user['username']) === 'ministrator') || $this->isUserAdmin($roleIds);
        $osUpdateData = [
            'username'  => $user['username'],
            'full_name' => $updateData['full_name'],
            'shell'     => $shell,
            'is_admin'  => $isAdmin,
            'ssh_keys'  => $sshKeys,
        ];
        if (! empty($newPassword)) {
            $osUpdateData['password'] = $newPassword;
        }

        try {
            $this->ipcClient->userUpdate($osUpdateData);
        } catch (\Throwable $e) {
            log_message('warning', "[Users::update] Micron OS user update warning: " . $e->getMessage());
        }

        $this->logAudit('users.edit', "Updated user: {$user['username']}");

        return redirect()->to(base_url('users'))
            ->with('success', "User '{$user['username']}' updated and synchronized with Micron OS.");
    }

    /**
     * Delete a user account from both Web GUI DB and Micron OS.
     */
    public function delete(string $userId): \CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->find($userId);
        if (! $user) {
            return redirect()->to(base_url('users'))->with('error', 'User not found.');
        }

        // Prevent self-deletion
        if ($userId === session('user_id')) {
            return redirect()->to(base_url('users'))
                ->with('error', 'You cannot delete your own active account.');
        }

        // Prevent deletion of primary default administrator
        if (in_array(strtolower($user['username']), ['ministrator', 'root'], true)) {
            return redirect()->to(base_url('users'))
                ->with('error', 'The primary Ministrator/root account is protected and cannot be deleted.');
        }

        // Clean user roles
        $db = \Config\Database::connect();
        $db->table('user_roles')->where('user_id', $userId)->delete();

        $this->userModel->delete($userId);

        // Delete Micron OS user account via micrond
        try {
            $this->ipcClient->userDelete($user['username']);
        } catch (\Throwable $e) {
            log_message('warning', "[Users::delete] Micron OS user delete warning: " . $e->getMessage());
        }

        $this->logAudit('users.delete', "Deleted user: {$user['username']}");

        return redirect()->to(base_url('users'))
            ->with('success', "User '{$user['username']}' has been deleted from Web GUI and Micron OS.");
    }

    /**
     * Reset password and unlock user account, synchronizing new password to Micron OS.
     */
    public function resetPassword(string $userId): \CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->find($userId);
        if (! $user) {
            return redirect()->to(base_url('users'))->with('error', 'User not found.');
        }

        $newPass = trim((string)($this->request->getPost('new_password') ?? ''));
        if (strlen($newPass) < 6) {
            return redirect()->to(base_url('users'))
                ->with('error', 'Password must be at least 6 characters.');
        }

        $this->userModel->update($userId, [
            'password_hash' => UserModel::hashPassword($newPass),
            'locked_until'  => null,
            'failed_logins' => 0,
        ]);

        // Sync new password to Micron OS
        try {
            $this->ipcClient->userUpdate([
                'username' => $user['username'],
                'password' => $newPass,
            ]);
        } catch (\Throwable $e) {
            log_message('warning', "[Users::resetPassword] Micron OS user password update warning: " . $e->getMessage());
        }

        $this->logAudit('users.password_reset', "Reset password for: {$user['username']}");

        return redirect()->to(base_url('users'))
            ->with('success', "Password for '{$user['username']}' has been reset in Web GUI and Micron OS.");
    }

    /**
     * Bulk reconcile Web GUI users with Micron OS accounts.
     */
    public function syncOs(): \CodeIgniter\HTTP\RedirectResponse
    {
        $dbUsers = $this->userModel->findAll();
        $payload = [];
        foreach ($dbUsers as $u) {
            $userRoles = $this->userModel->getUserRoles($u['id']);
            $roleIds = array_column($userRoles, 'id');
            $isAdmin = (strtolower($u['username']) === 'ministrator') || $this->isUserAdmin($roleIds);
            $payload[] = [
                'username'  => $u['username'],
                'full_name' => $u['full_name'] ?? $u['username'],
                'shell'     => $u['shell'] ?? '/bin/bash',
                'is_admin'  => $isAdmin,
                'ssh_keys'  => $u['ssh_keys'] ?? '',
            ];
        }

        try {
            $res = $this->ipcClient->userSync($payload);
            $count = $res['count'] ?? count($payload);
            $this->logAudit('users.sync_os', "Synchronized {$count} users with Micron OS");
            return redirect()->to(base_url('users'))
                ->with('success', "Successfully synchronized {$count} user account(s) with Micron OS.");
        } catch (\Throwable $e) {
            return redirect()->to(base_url('users'))
                ->with('error', 'OS user synchronization failed: ' . $e->getMessage());
        }
    }

    /**
     * Trigger directory synchronization from configured LDAP / Active Directory.
     */
    public function importLdap(): \CodeIgniter\HTTP\RedirectResponse
    {
        $ldapModel  = new \App\Models\LdapServerModel();
        $ldapServer = $ldapModel->getActiveServer();
        if (! $ldapServer || empty($ldapServer['enabled'])) {
            return redirect()->to(base_url('users'))->with('error', 'Active Directory / LDAP is not configured or disabled. Please enable it in System Settings → LDAP / AD first.');
        }

        $sys = new \App\Controllers\SystemSettings();
        $sys->initController($this->request, $this->response, service('logger'));
        $resp = $sys->syncLdapUsers();

        return redirect()->to(base_url('users'))->with('success', 'Synchronized directory users with MicronOS.');
    }

    private function isUserAdmin(array $roleIds): bool
    {
        if (empty($roleIds)) {
            return false;
        }
        $roles = $this->roleModel->whereIn('id', $roleIds)->findAll();
        foreach ($roles as $r) {
            $name = strtolower($r['name'] ?? '');
            if (in_array($name, ['superadmin', 'admin', 'administrator', 'networkadmin'], true)) {
                return true;
            }
        }
        return false;
    }

    private function logAudit(string $action, string $detail): void
    {
        try {
            \Config\Database::connect()->table('audit_logs')->insert([
                'admin_user'      => session('username') ?? 'admin',
                'client_ip'       => $this->request->getIPAddress(),
                'user_id'         => session('user_id'),
                'username'        => session('username'),
                'action_type'     => 'security',
                'target_resource' => 'Users',
                'action'          => $action,
                'target'          => $detail,
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {}
    }
}
