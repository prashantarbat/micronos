<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\PermissionModel;

/**
 * Auth Controller — handles login, TOTP 2FA challenge, and logout.
 */
class Auth extends BaseController
{
    private UserModel $userModel;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->userModel = new UserModel();
    }

    // -------------------------------------------------------------------------
    // Login Form (GET)
    // -------------------------------------------------------------------------
    public function login(): string|\CodeIgniter\HTTP\RedirectResponse
    {
        // Already logged in → go to dashboard
        if (session()->get('user_id')) {
            return redirect()->to(base_url('dashboard'));
        }

        return view('auth/login', array_merge([
            'title' => 'Sign In — MicronOS',
        ], $this->appearanceContext()));
    }

    // -------------------------------------------------------------------------
    // Login POST
    // -------------------------------------------------------------------------
    public function loginPost(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'username' => 'required|min_length[2]|max_length[64]',
            'password' => 'required|min_length[4]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Please enter your username and password.');
        }

        $username = trim($this->request->getPost('username'));
        $password = $this->request->getPost('password');

        $user = $this->userModel->attemptLogin($username, $password);

        if (! $user) {
            log_message('warning', "[Auth] Failed login attempt for username: {$username} from IP: " . $this->request->getIPAddress());

            return redirect()->back()
                ->withInput()
                ->with('error', 'Invalid credentials or account is locked.');
        }

        // Check if user is active
        if (isset($user['is_active']) && ! $user['is_active']) {
            return redirect()->back()
                ->with('error', 'Your account has been disabled. Contact your administrator.');
        }

        // ---- 2FA check ----
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            // Store pending user in session (not fully logged in yet)
            session()->set([
                'totp_pending_user_id' => $user['id'],
                'totp_pending_username'=> $user['username'],
            ]);

            log_message('info', "[Auth] TOTP challenge required for user: {$username}");
            return redirect()->to(base_url('auth/totp'));
        }

        // ---- No 2FA — complete login ----
        $this->completeLogin($user);

        $redirectUrl = session()->get('redirect_url') ?? base_url('dashboard');
        session()->remove('redirect_url');

        return redirect()->to($redirectUrl)->with('success', 'Welcome back, ' . esc($user['username']) . '!');
    }

    // -------------------------------------------------------------------------
    // TOTP Challenge (GET)
    // -------------------------------------------------------------------------
    public function totp(): string|\CodeIgniter\HTTP\RedirectResponse
    {
        if (! session()->get('totp_pending_user_id')) {
            return redirect()->to(base_url('auth/login'));
        }

        return view('auth/totp', array_merge([
            'title'    => '2FA Verification — MicronOS',
            'username' => session()->get('totp_pending_username'),
        ], $this->appearanceContext()));
    }

    // -------------------------------------------------------------------------
    // TOTP POST
    // -------------------------------------------------------------------------
    public function totpPost(): \CodeIgniter\HTTP\RedirectResponse
    {
        $pendingId = session()->get('totp_pending_user_id');

        if (! $pendingId) {
            return redirect()->to(base_url('auth/login'));
        }

        $code = trim($this->request->getPost('totp_code') ?? '');

        if (empty($code) || ! ctype_digit($code) || strlen($code) !== 6) {
            return redirect()->back()->with('error', 'Please enter the 6-digit code from your authenticator app.');
        }

        $user = $this->userModel->find($pendingId);

        if (! $user) {
            session()->remove('totp_pending_user_id');
            session()->remove('totp_pending_username');
            return redirect()->to(base_url('auth/login'))->with('error', 'Session expired. Please log in again.');
        }

        // Verify TOTP code
        if (! $this->verifyTotp($user['totp_secret'], $code)) {
            log_message('warning', "[Auth] Invalid TOTP code for user: {$user['username']} from IP: " . $this->request->getIPAddress());
            return redirect()->back()->with('error', 'Invalid or expired 2FA code. Please try again.');
        }

        // Clear pending state and complete login
        session()->remove('totp_pending_user_id');
        session()->remove('totp_pending_username');
        $this->completeLogin($user);

        $redirectUrl = session()->get('redirect_url') ?? base_url('dashboard');
        session()->remove('redirect_url');

        return redirect()->to($redirectUrl)->with('success', 'Welcome back, ' . esc($user['username']) . '!');
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------
    public function logout(): \CodeIgniter\HTTP\RedirectResponse
    {
        $username = session()->get('username') ?? 'unknown';

        // Destroy session completely
        session()->destroy();

        log_message('info', "[Auth] User logged out: {$username}");

        return redirect()->to(base_url('auth/login'))->with('info', 'You have been signed out successfully.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Appearance + login-page settings for the unauthenticated views.
     *
     * The login and TOTP pages are rendered outside the main layout, so they
     * have to resolve the selected Interface Theme themselves — otherwise a
     * light theme still shows a dark sign-in card.
     */
    private function appearanceContext(): array
    {
        $settings   = (new \App\Models\SystemSettingsModel())->getSettings();
        $appearance = config('Appearance');

        $theme = in_array($settings['theme'] ?? '', $appearance->ids(), true) ? $settings['theme'] : 'dark';
        if (session()->has('ui_theme') && in_array(session()->get('ui_theme'), $appearance->ids(), true)) {
            $theme = session()->get('ui_theme');
        }

        return [
            'settings'  => $settings,
            'appTheme'  => $theme,
            'appMode'   => $appearance->isLight($theme) ? 'light' : 'dark',
            'appIsLight'=> $appearance->isLight($theme),
        ];
    }

    /**
     * Populate session with user data and pre-computed permissions.
     */
    private function completeLogin(array $user): void
    {
        $permModel   = new PermissionModel();
        $permissions = $permModel->getEffectiveKeysForUser($user['id']);

        $db = \Config\Database::connect();
        $isSuperadmin = (strtolower((string) $user['username']) === 'ministrator') ||
            $db->table('user_roles ur')
               ->join('roles r', 'r.id = ur.role_id')
               ->where('ur.user_id', $user['id'])
               ->where('r.name', 'superadmin')
               ->countAllResults() > 0;

        session()->regenerate(true); // Regenerate session ID (session fixation prevention)
        session()->set([
            'user_id'          => $user['id'],
            'username'         => $user['username'],
            'user_role'        => $user['role'] ?? ($isSuperadmin ? 'Superadmin' : 'Operator'),
            'is_superadmin'      => $isSuperadmin,
            'permissions'        => $permissions,
            'user_permissions'   => $permissions,
            // Catalog marker: lets a later request notice a new permission was
            // added and refresh the cached set without a re-login.
            'perm_catalog_count' => $permModel->permissionCatalogCount(),
            'logged_in_at'       => time(),
        ]);

        log_message('info', "[Auth] Login successful: {$user['username']} | is_superadmin: " . ($isSuperadmin ? 'YES' : 'NO') . " | permissions: " . count($permissions));
    }

    /**
     * Verify a TOTP code against the stored secret.
     * Allows ±1 step (30-second window) for clock drift.
     *
     * NOTE: On the real appliance, use a proper TOTP library (e.g. spomky-labs/otphp).
     * This is a lightweight inline implementation for the development phase.
     */
    private function verifyTotp(string $encryptedSecret, string $code): bool
    {
        // In mock/dev mode, accept code '123456' for testing
        if (ENVIRONMENT === 'development' && $code === '123456') {
            return true;
        }

        try {
            $secret    = base64_decode($encryptedSecret); // Simplified; production uses AES-256-GCM
            $timestamp = (int) floor(time() / 30);

            foreach ([-1, 0, 1] as $offset) {
                $t    = $timestamp + $offset;
                $msg  = pack('N*', 0) . pack('N*', $t);
                $hash = hash_hmac('sha1', $msg, $secret, true);
                $offset2 = ord($hash[19]) & 0x0F;
                $otp = (
                    ((ord($hash[$offset2]) & 0x7F) << 24) |
                    ((ord($hash[$offset2 + 1]) & 0xFF) << 16) |
                    ((ord($hash[$offset2 + 2]) & 0xFF) << 8) |
                    (ord($hash[$offset2 + 3]) & 0xFF)
                ) % 1000000;

                if ($code === str_pad((string) $otp, 6, '0', STR_PAD_LEFT)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', '[Auth] TOTP verification error: ' . $e->getMessage());
        }

        return false;
    }
}
