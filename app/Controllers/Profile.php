<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\RoleModel;

/**
 * Profile Controller — self-service user settings (password, details, 2FA enrollment)
 */
class Profile extends BaseController
{
    private UserModel $userModel;
    private RoleModel $roleModel;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->userModel = new UserModel();
        $this->roleModel = new RoleModel();
    }

    public function index(): string
    {
        $userId = session('user_id');
        $user   = $this->userModel->find($userId);
        $roles  = $this->userModel->getUserRoles($userId);

        return view('profile/index', [
            'title'      => 'Account Settings — MicronOS',
            'breadcrumb' => 'My Profile',
            'user'       => $user,
            'roles'      => $roles,
        ]);
    }

    public function save(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = session('user_id');

        $rules = [
            'full_name' => 'required|min_length[2]|max_length[128]',
            'email'     => 'permit_empty|valid_email|max_length[128]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $this->userModel->update($userId, [
            'full_name'  => trim($this->request->getPost('full_name')),
            'email'      => trim($this->request->getPost('email') ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        session()->set('full_name', trim($this->request->getPost('full_name')));

        return redirect()->to(base_url('profile'))
            ->with('success', 'Profile details updated successfully.');
    }

    public function changePassword(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = session('user_id');
        $user   = $this->userModel->find($userId);

        $current = $this->request->getPost('current_password');
        $newPass = $this->request->getPost('new_password');
        $confirm = $this->request->getPost('confirm_password');

        if (! password_verify($current, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Current password is incorrect.');
        }

        if (strlen($newPass) < 6) {
            return redirect()->back()->with('error', 'New password must be at least 6 characters.');
        }

        if ($newPass !== $confirm) {
            return redirect()->back()->with('error', 'New password and confirmation do not match.');
        }

        $this->userModel->update($userId, [
            'password_hash' => UserModel::hashPassword($newPass),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url('profile'))
            ->with('success', 'Your password has been changed.');
    }

    public function setupTotp(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = session('user_id');
        $action = $this->request->getPost('action');

        if ($action === 'disable') {
            $this->userModel->update($userId, [
                'totp_enabled' => 0,
                'totp_secret'  => null,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            return redirect()->to(base_url('profile'))
                ->with('success', 'Two-factor authentication disabled.');
        }

        // Demo enrollment generator for dev
        $demoSecret = strtoupper(bin2hex(random_bytes(10)));
        $this->userModel->update($userId, [
            'totp_enabled' => 1,
            'totp_secret'  => $demoSecret,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url('profile'))
            ->with('success', 'Two-factor authentication enabled (Secret: ' . $demoSecret . ').');
    }
}
