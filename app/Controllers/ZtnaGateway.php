<?php

namespace App\Controllers;

use App\Libraries\Acme;
use App\Libraries\AcmeManager;
use App\Libraries\ConfigApplier;
use App\Libraries\ConfigRevisionEngine;
use App\Libraries\DefaultCredentials;
use App\Libraries\GatewayAccessPolicy;
use App\Libraries\GatewayCertificate;
use App\Libraries\GatewayIdentity;
use App\Libraries\GuacamoleTokenGenerator;
use App\Libraries\IpcClient;
use App\Libraries\ZtnaCompiler;
use App\Models\RoleModel;
use App\Models\UserModel;
use App\Models\ZtnaResourceModel;
use App\Models\ZtnaSessionModel;
use App\Models\ZtnaSettingsModel;

/**
 * ZtnaGateway Controller — In-Browser Clientless Web Application & Desktop Gateway.
 * Zero-install HTML5 RDP, SSH, VNC, and Identity-Aware Web Proxy.
 */
class ZtnaGateway extends BaseController
{
    protected IpcClient $ipc;
    protected ZtnaResourceModel $resourceModel;
    protected ZtnaSessionModel $sessionModel;
    protected ZtnaSettingsModel $settingsModel;
    protected ZtnaCompiler $compiler;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->resourceModel = new ZtnaResourceModel();
        $this->sessionModel  = new ZtnaSessionModel();
        $this->settingsModel = new ZtnaSettingsModel();
        $this->compiler      = new ZtnaCompiler($this->resourceModel, $this->settingsModel);
        $this->revEngine     = new ConfigRevisionEngine();
    }

    /**
     * Admin Management Console.
     */
    public function index()
    {
        $resources = $this->resourceModel->orderBy('name', 'ASC')->findAll();
        $sessions  = $this->sessionModel->getActiveSessions();
        $settings  = $this->settingsModel->getSettings();

        $roleModel = new RoleModel();
        $allRoles  = $roleModel->findAll();

        $gatewayCert = new GatewayCertificate($this->ipc);

        return view('ztna/index', [
            'resources' => $resources,
            'sessions'  => $sessions,
            'settings'  => $settings,
            'allRoles'  => $allRoles,
            'certStatus'     => $gatewayCert->status((int) ($settings['cert_id'] ?? 0), (string) ($settings['external_domain'] ?? '')),
            'certCandidates' => $gatewayCert->candidates(),
            'title'     => 'In-Browser Clientless Web & Desktop Gateway (ZTNA)',
        ]);
    }

    /**
     * End-User Clientless Application Launcher Portal.
     */
    public function portal()
    {
        // ZtnaAuthFilter guarantees an identity on this route.
        $user = GatewayIdentity::current() ?? ['username' => '', 'roles' => [], 'is_superadmin' => false, 'via' => 'gateway'];

        $accessible = $this->resourceModel->getAccessibleForRoles($user['roles'], $user['is_superadmin']);
        $settings   = $this->settingsModel->getSettings();

        return view('ztna/portal', [
            'resources'   => $accessible,
            'settings'    => $settings,
            'username'    => $user['username'],
            // A portal user gets the gateway's own page chrome, not the
            // appliance's admin layout (whose menu they cannot use).
            'gatewayMode' => $user['via'] === 'gateway',
            'title'       => esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Gateway sign-in (separate from the appliance login)
    // -------------------------------------------------------------------------

    /** Pending second-factor step lasts this long. */
    private const MFA_PENDING_TTL = 300;

    public function login()
    {
        $user = GatewayIdentity::current();
        if ($user !== null && $user['via'] === 'gateway') {
            return redirect()->to(base_url('vpn/clientless-gateway/portal'));
        }

        return $this->loginView('password');
    }

    public function loginPost()
    {
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        if ($username === '' || strlen($username) > 64 || $password === '') {
            return redirect()->to(base_url('vpn/clientless-gateway/login'))
                ->withInput()->with('error', 'Enter your username and password.');
        }

        $users = new UserModel();
        $user  = $users->attemptLogin($username, $password);
        $ip    = $this->request->getIPAddress();

        if (! $user) {
            log_message('warning', "[ZTNA] Failed gateway sign-in for '{$username}' from {$ip}");

            return redirect()->to(base_url('vpn/clientless-gateway/login'))
                ->withInput()->with('error', 'Invalid credentials or the account is locked.');
        }

        if (isset($user['is_active']) && ! $user['is_active']) {
            return redirect()->to(base_url('vpn/clientless-gateway/login'))
                ->with('error', 'Your account has been disabled. Contact your administrator.');
        }

        // The factory password is public; it never opens the internet-facing portal.
        if (DefaultCredentials::isFactoryPassword((string) $password)) {
            log_message('warning', "[ZTNA] Refused gateway sign-in for '{$username}' from {$ip}: factory default password");

            return redirect()->to(base_url('vpn/clientless-gateway/login'))
                ->with('error', 'This account still uses the factory default password. Change it in the management interface first.');
        }

        $settings = $this->settingsModel->getSettings();
        $hasTotp  = (int) ($user['totp_enabled'] ?? 0) === 1 && (string) ($user['totp_secret'] ?? '') !== '';

        if ($hasTotp) {
            session()->set('ztna_pending', [
                'user_id'  => (string) $user['id'],
                'username' => (string) $user['username'],
                'at'       => time(),
            ]);

            return redirect()->to(base_url('vpn/clientless-gateway/login/verify'));
        }

        if ((int) ($settings['require_mfa'] ?? 0) === 1) {
            log_message('warning', "[ZTNA] Gateway sign-in refused for '{$username}': two-factor authentication is required but not set up");

            return redirect()->to(base_url('vpn/clientless-gateway/login'))
                ->with('error', 'This gateway requires two-factor authentication. Set up an authenticator app on your account first, then sign in again.');
        }

        GatewayIdentity::signIn($user);
        log_message('info', "[ZTNA] Gateway sign-in: {$user['username']} from {$ip}");

        return redirect()->to($this->takeIntended());
    }

    public function totp()
    {
        if ($this->pendingMfa() === null) {
            return redirect()->to(base_url('vpn/clientless-gateway/login'));
        }

        return $this->loginView('totp');
    }

    public function totpPost()
    {
        $pending = $this->pendingMfa();
        if ($pending === null) {
            return redirect()->to(base_url('vpn/clientless-gateway/login'))
                ->with('error', 'Your sign-in expired. Please sign in again.');
        }

        $code = preg_replace('/\s+/', '', (string) $this->request->getPost('totp_code'));
        if (! ctype_digit($code) || strlen($code) !== 6) {
            return redirect()->to(base_url('vpn/clientless-gateway/login/verify'))
                ->with('error', 'Enter the 6-digit code from your authenticator app.');
        }

        $user = (new UserModel())->find($pending['user_id']);
        if (! is_array($user) || ! Auth::verifyTotp((string) ($user['totp_secret'] ?? ''), $code)) {
            log_message('warning', "[ZTNA] Invalid gateway 2FA code for '{$pending['username']}' from " . $this->request->getIPAddress());

            return redirect()->to(base_url('vpn/clientless-gateway/login/verify'))
                ->with('error', 'That code is not valid. Try the current code from your app.');
        }

        session()->remove('ztna_pending');
        GatewayIdentity::signIn($user);
        log_message('info', "[ZTNA] Gateway sign-in (2FA): {$user['username']} from " . $this->request->getIPAddress());

        return redirect()->to($this->takeIntended());
    }

    public function logout()
    {
        GatewayIdentity::signOut();

        return redirect()->to(base_url('vpn/clientless-gateway/login'))
            ->with('info', 'You have been signed out.');
    }

    private function loginView(string $step)
    {
        $settings = $this->settingsModel->getSettings();
        $pending  = $this->pendingMfa();

        return view('ztna/login', [
            'settings' => $settings,
            'step'     => $step,
            'pendingUser' => $pending['username'] ?? '',
            'title'    => 'Sign in — ' . ($settings['portal_title'] ?? 'Clientless Gateway'),
        ]);
    }

    private function pendingMfa(): ?array
    {
        $pending = session()->get('ztna_pending');
        if (! is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::MFA_PENDING_TTL) {
            session()->remove('ztna_pending');

            return null;
        }

        return $pending;
    }

    /** Where to go after sign-in: only a gateway page, never an arbitrary URL. */
    private function takeIntended(): string
    {
        $intended = (string) session()->get('ztna_intended');
        session()->remove('ztna_intended');

        if (preg_match('#^vpn/clientless-gateway/(portal|launch/[A-Za-z0-9_-]+|connect/[A-Za-z0-9._-]+)$#', $intended) === 1) {
            return base_url($intended);
        }

        return base_url('vpn/clientless-gateway/portal');
    }

    /**
     * Save or update published resource.
     */
    public function saveResource()
    {
        $id   = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Resource name is required.']);
        }

        $type  = $this->request->getPost('type') ?: 'web';
        $slug  = strtolower(trim((string) $this->request->getPost('slug')));
        if ($slug === '') {
            $slug = url_title($name, '-', true);
        }

        $roles = $this->request->getPost('allowed_roles');

        $data = [
            'name'          => $name,
            'slug'          => $slug,
            'type'          => $type,
            'internal_host' => trim((string) $this->request->getPost('internal_host')),
            'internal_port' => (int) ($this->request->getPost('internal_port') ?: ($type === 'rdp' ? 3389 : ($type === 'ssh' ? 22 : 80))),
            'description'   => trim((string) $this->request->getPost('description')),
            'icon'          => $this->request->getPost('icon') ?: ($type === 'rdp' ? 'desktop' : ($type === 'ssh' ? 'terminal' : 'globe')),
            'require_auth'  => (int) ($this->request->getPost('require_auth') ?? 1),
            'allowed_roles' => is_array($roles) ? json_encode(array_values($roles)) : json_encode([]),
            'status'        => $this->request->getPost('status') ?: 'online',
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->resourceModel->update($id, $data);
            $action = 'Updated';
        } else {
            $data['id'] = 'res_' . bin2hex(random_bytes(4));
            $this->resourceModel->insert($data);
            $action = 'Published';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$action} Clientless Gateway resource '{$name}' ({$type})",
            'vpn'
        );

        return $this->response->setJSON([
            'success'   => true,
            'message'   => "Resource '{$name}' saved.",
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * Delete a published resource.
     */
    public function deleteResource(string $id)
    {
        $resource = $this->resourceModel->find($id);
        if (!$resource) {
            return $this->response->setJSON(['success' => false, 'message' => 'Resource not found.', 'csrf_hash' => csrf_hash()]);
        }

        $this->resourceModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted Clientless Gateway resource '{$resource['name']}'",
            'vpn'
        );

        return $this->response->setJSON([
            'success'   => true,
            'message'   => "Resource '{$resource['name']}' deleted.",
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * Launch in-browser session for an authorized resource.
     */
    public function launch(string $id)
    {
        $resource = $this->resourceModel->find($id);
        if (!$resource) {
            return redirect()->to(base_url('vpn/clientless-gateway/portal'))->with('error', 'Requested resource was not found.');
        }

        $user = GatewayIdentity::current();
        if ($user === null) {
            return redirect()->to(base_url('vpn/clientless-gateway/login'));
        }

        // A portal user may only open what the portal offered them.
        $allowed = array_column($this->resourceModel->getAccessibleForRoles($user['roles'], $user['is_superadmin']), 'id');
        if (! in_array($resource['id'], $allowed, true)) {
            return redirect()->to(base_url('vpn/clientless-gateway/portal'))->with('error', 'You are not authorised to open that resource.');
        }

        $username = $user['username'];
        $userId   = $user['user_id'];

        // Provision token. The guacd address is signed into it so the tunnel
        // bridge connects where the operator configured, not where the browser says.
        $settings = $this->settingsModel->getSettings();
        try {
            $token = GuacamoleTokenGenerator::generateToken($resource, $username, 3600, [
                'guacd_host' => $settings['guacd_host'] ?? '',
                'guacd_port' => (int) ($settings['guacd_port'] ?? 0),
            ]);
        } catch (\RuntimeException $e) {
            log_message('error', $e->getMessage());
            return redirect()->to(base_url('vpn/clientless-gateway/portal'))->with('error', 'The remote access gateway is not ready yet. Try again in a moment.');
        }

        // Record session
        $this->sessionModel->insert([
            'id'             => 'sess_' . bin2hex(random_bytes(6)),
            'session_token'  => $token,
            'user_id'        => $userId,
            'username'       => $username,
            'resource_id'    => $resource['id'],
            'client_ip'      => $this->request->getIPAddress(),
            'user_agent'     => (string) $this->request->getUserAgent(),
            'started_at'     => date('Y-m-d H:i:s'),
            'last_active_at' => date('Y-m-d H:i:s'),
            'status'         => 'active',
        ]);

        // Increment active sessions counter
        $this->resourceModel->update($resource['id'], [
            'active_sessions_count' => (int)$resource['active_sessions_count'] + 1,
        ]);

        if ($resource['type'] === 'web') {
            // Forward to web app subpath
            return redirect()->to(base_url("apps/{$resource['slug']}/"));
        }

        // Forward to in-browser canvas viewer
        return redirect()->to(base_url("vpn/clientless-gateway/connect/{$token}"));
    }

    /**
     * In-Browser HTML5 Canvas / Terminal Viewer.
     */
    public function connect(string $token)
    {
        $payload = GuacamoleTokenGenerator::verifyToken($token);
        if (!$payload) {
            return redirect()->to(base_url('vpn/clientless-gateway/portal'))->with('error', 'Session authorization expired or invalid.');
        }

        $settings = $this->settingsModel->getSettings();

        return view('ztna/viewer', [
            'token'     => $token,
            'tunnelUrl' => base_url('vpn/clientless-gateway/tunnel'),
            'session'   => $payload,
            'settings'  => $settings,
            'title'     => "Remote {$payload['type']}: {$payload['name']}",
        ]);
    }

    /**
     * Terminate an active session.
     */
    public function terminateSession(string $id)
    {
        $session = $this->sessionModel->find($id);
        if ($session) {
            $this->sessionModel->update($id, ['status' => 'terminated']);

            // Revoke the tunnel token so micron-guacd-bridge drops a live session.
            try {
                $this->ipc->call('ztna.session_kill', [
                    'session_id' => $id,
                    'token'      => (string) ($session['session_token'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'ZTNA session revoke failed: ' . $e->getMessage());
            }
            $res = $this->resourceModel->find($session['resource_id']);
            if ($res && (int)$res['active_sessions_count'] > 0) {
                $this->resourceModel->update($res['id'], [
                    'active_sessions_count' => (int)$res['active_sessions_count'] - 1,
                ]);
            }
        }

        return $this->response->setJSON([
            'success'   => true,
            'message'   => 'Session terminated.',
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * Subrequest Nginx auth verification endpoint (`auth_request /api/ztna/verify-auth`).
     */
    public function verifyAuth()
    {
        $user = GatewayIdentity::current();
        if ($user === null) {
            return $this->response->setStatusCode(401)->setBody('Authentication Required');
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('X-Remote-User', $user['username'])
            ->setHeader('X-Remote-Email', $user['email'])
            ->setHeader('X-Remote-Groups', implode(',', $user['roles']))
            ->setBody('OK');
    }

    /**
     * Save Clientless Gateway settings and company branding portal configuration.
     */
    public function saveSettings()
    {
        $current = $this->settingsModel->getSettings();

        // Process logo upload if present
        $logoUrl = trim((string) $this->request->getPost('logo_url'));
        $file = $this->request->getFile('logo_file');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'], true)) {
                $targetDir = FCPATH . 'uploads/branding';
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                $newName = 'ztna_logo_' . time() . '.' . $ext;
                $file->move($targetDir, $newName);
                $logoUrl = base_url('uploads/branding/' . $newName);
            }
        }

        $faviconUrl = trim((string) $this->request->getPost('favicon_url'));
        $favFile = $this->request->getFile('favicon_file');
        if ($favFile && $favFile->isValid() && !$favFile->hasMoved()) {
            $ext = strtolower($favFile->getExtension());
            if (in_array($ext, ['ico', 'png', 'svg', 'webp'], true)) {
                $targetDir = FCPATH . 'uploads/branding';
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                $newName = 'ztna_fav_' . time() . '.' . $ext;
                $favFile->move($targetDir, $newName);
                $faviconUrl = base_url('uploads/branding/' . $newName);
            }
        }

        $externalPort = (int) ($this->request->getPost('external_port') ?: 443);
        if ($externalPort <= 0 || $externalPort > 65535) {
            $externalPort = 443;
        }

        $brandColor = trim((string) $this->request->getPost('brand_color')) ?: '#6366f1';
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $brandColor)) {
            $brandColor = '#6366f1';
        }

        $brandHover = trim((string) $this->request->getPost('brand_color_hover')) ?: '#4f46e5';
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $brandHover)) {
            $brandHover = '#4f46e5';
        }

        $brandAccent = trim((string) $this->request->getPost('brand_accent_color')) ?: '#10b981';
        $portalTheme = trim((string) $this->request->getPost('portal_theme')) ?: 'light';
        if (!in_array($portalTheme, ['light', 'gray', 'dark'], true)) {
            $portalTheme = 'light';
        }

        $data = [
            'id'                   => 1,
            'enabled'              => (int) ($this->request->getPost('enabled') ?? 0),
            'company_name'         => trim((string) $this->request->getPost('company_name')) ?: 'Micron Security',
            'portal_title'         => trim((string) $this->request->getPost('portal_title')) ?: 'Micron OS Clientless Gateway',
            'logo_url'             => $logoUrl,
            'favicon_url'          => $faviconUrl,
            'brand_color'          => $brandColor,
            'brand_color_hover'    => $brandHover,
            'brand_accent_color'   => $brandAccent,
            'portal_theme'         => $portalTheme,
            'welcome_heading'      => trim((string) $this->request->getPost('welcome_heading')) ?: 'Zero-Trust Clientless Access Portal',
            'welcome_subtitle'     => trim((string) $this->request->getPost('welcome_subtitle')) ?: 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.',
            'notice_banner'        => trim((string) $this->request->getPost('notice_banner')),
            'notice_banner_type'   => trim((string) $this->request->getPost('notice_banner_type')) ?: 'info',
            'help_link'            => trim((string) $this->request->getPost('help_link')),
            'help_link_text'       => trim((string) $this->request->getPost('help_link_text')) ?: 'Contact Helpdesk',
            'footer_text'          => trim((string) $this->request->getPost('footer_text')) ?: '© ' . date('Y') . ' Micron OS Zero-Trust Network Access. All rights reserved.',
            'custom_css'           => (string) $this->request->getPost('custom_css'),
            'external_domain'      => trim((string) $this->request->getPost('external_domain')) ?: 'gateway.micronos.local',
            'external_port'        => $externalPort,
            'guacd_host'           => trim((string) $this->request->getPost('guacd_host')) ?: '127.0.0.1',
            'guacd_port'           => (int) ($this->request->getPost('guacd_port') ?: 4822),
            'session_timeout_mins' => max(5, (int) ($this->request->getPost('session_timeout_mins') ?: 60)),
            'require_mfa'          => (int) ($this->request->getPost('require_mfa') ?? 0),
            // Only the core settings form posts this; the branding form keeps it.
            'access_mode'          => in_array($this->request->getPost('access_mode'), ['open', 'firewall'], true)
                ? (string) $this->request->getPost('access_mode')
                : (($current['access_mode'] ?? 'open') === 'firewall' ? 'firewall' : 'open'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ] + self::certificateFields($current);

        $this->settingsModel->replace($data);

        // Gateway access mode, enabled state or port changed: the firewall has
        // to be recompiled for it to take effect. Switching to firewall
        // control first seeds an allow-all + log policy so nobody is cut off.
        $accessNote = '';
        $firewallAffected = $data['access_mode'] !== ($current['access_mode'] ?? 'open')
            || (int) $data['enabled'] !== (int) ($current['enabled'] ?? 1)
            || (int) $data['external_port'] !== (int) ($current['external_port'] ?? 443);
        if ($data['access_mode'] === 'firewall' && $firewallAffected) {
            $seeded = (new GatewayAccessPolicy())->ensure((int) $data['external_port']);
            if ($seeded['policy_created']) {
                $accessNote = ' Added the policy "' . GatewayAccessPolicy::STARTER_NAME . '" in Firewall → Rules; tighten access from there.';
            }
        }
        if ($firewallAffected) {
            try {
                (new ConfigApplier($this->ipc))->firewall();
            } catch (\Throwable $e) {
                log_message('error', '[ZTNA] firewall re-apply after settings change failed: ' . $e->getMessage());
            }
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Updated Clientless Gateway company branding & guacd daemon settings',
            'vpn'
        );

        return $this->response->setJSON([
            'success'   => true,
            'message'   => 'Clientless Gateway & company branding settings updated successfully.' . $accessNote,
            'csrf_hash' => csrf_hash(),
            'settings'  => $data,
        ]);
    }

    /**
     * Reset company branding back to default theme.
     */
    public function resetBranding()
    {
        $current = $this->settingsModel->getSettings();
        $defaults = [
            'id'                   => 1,
            'enabled'              => $current['enabled'] ?? 1,
            'company_name'         => 'Micron Security',
            'portal_title'         => 'Micron OS Clientless Gateway',
            'logo_url'             => '',
            'favicon_url'          => '',
            'brand_color'          => '#6366f1',
            'brand_color_hover'    => '#4f46e5',
            'brand_accent_color'   => '#10b981',
            'portal_theme'         => 'light',
            'welcome_heading'      => 'Zero-Trust Clientless Access Portal',
            'welcome_subtitle'     => 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.',
            'notice_banner'        => '',
            'notice_banner_type'   => 'info',
            'help_link'            => '',
            'help_link_text'       => 'Contact Helpdesk',
            'footer_text'          => '© ' . date('Y') . ' Micron OS Zero-Trust Network Access. All rights reserved.',
            'custom_css'           => '',
            'external_domain'      => $current['external_domain'] ?? 'gateway.micronos.local',
            'external_port'        => $current['external_port'] ?? 443,
            'guacd_host'           => $current['guacd_host'] ?? '127.0.0.1',
            'guacd_port'           => $current['guacd_port'] ?? 4822,
            'session_timeout_mins' => $current['session_timeout_mins'] ?? 60,
            'require_mfa'          => $current['require_mfa'] ?? 1,
            'access_mode'          => $current['access_mode'] ?? 'open',
            'updated_at'           => date('Y-m-d H:i:s'),
        ] + self::certificateFields($current);

        $this->settingsModel->replace($defaults);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Reset Clientless Gateway branding to defaults',
            'vpn'
        );

        return $this->response->setJSON([
            'success'   => true,
            'message'   => 'Company branding reset to defaults.',
            'csrf_hash' => csrf_hash(),
            'settings'  => $defaults,
        ]);
    }

    /**
     * Diagnostic probe to test guacd daemon TCP socket connectivity.
     */
    public function testGuacd()
    {
        $host = trim((string) ($this->request->getPost('guacd_host') ?: '127.0.0.1'));
        $port = (int) ($this->request->getPost('guacd_port') ?: 4822);

        // First attempt via micrond IPC
        try {
            $ipcRes = $this->ipc->call('ztna.test_guacd', ['host' => $host, 'port' => $port]);
            if (isset($ipcRes['result']['success'])) {
                return $this->response->setJSON(array_merge($ipcRes['result'], [
                    'csrf_hash' => csrf_hash(),
                ]));
            }
        } catch (\Throwable $e) {
            // IPC client may not be reachable in CLI/testing environment; fallback to direct socket
        }

        // Direct fallback socket test
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        if (is_resource($fp)) {
            fclose($fp);
            return $this->response->setJSON([
                'success'    => true,
                'host'       => $host,
                'port'       => $port,
                'latency_ms' => $latencyMs,
                'message'    => "Successfully connected to guacd daemon at {$host}:{$port} ({$latencyMs}ms).",
                'csrf_hash'  => csrf_hash(),
            ]);
        }

        return $this->response->setJSON([
            'success'   => false,
            'host'      => $host,
            'port'      => $port,
            'error'     => $errstr ?: "Connection refused (errno {$errno})",
            'message'   => "Could not connect to guacd daemon at {$host}:{$port} ({$errstr})",
            'csrf_hash' => csrf_hash(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Gateway HTTPS certificate
    // -------------------------------------------------------------------------

    /** Use an existing PKI certificate, or 0 for the self-signed one. */
    public function certificateSelect()
    {
        $certId   = (int) $this->request->getPost('cert_id');
        $settings = $this->settingsModel->getSettings();

        if ($certId > 0 && ! in_array($certId, array_map('intval', array_column((new GatewayCertificate($this->ipc))->candidates(), 'id')), true)) {
            return $this->certJson(false, 'Pick a server certificate that has a private key.');
        }

        return $this->bindCertificate($certId, $settings, false);
    }

    /** Upload a certificate + key (and optional chain) and use it. */
    public function certificateUpload()
    {
        $cert  = $this->postedPem('cert_pem', 'cert_file');
        $key   = $this->postedPem('key_pem', 'key_file');
        $chain = $this->postedPem('chain_pem', 'chain_file');

        if ($cert === '' || $key === '') {
            return $this->certJson(false, 'Provide both the certificate and its private key (PEM).');
        }

        $gatewayCert = new GatewayCertificate($this->ipc);
        $imported = $gatewayCert->import($cert, $key, $chain, (string) $this->request->getPost('name'));
        if (! $imported['ok']) {
            return $this->certJson(false, $imported['message']);
        }

        $settings = $this->settingsModel->getSettings();
        $status   = $gatewayCert->status((int) $imported['cert_id'], (string) $settings['external_domain']);
        $note     = $status['covers_domain'] ? '' : ' Note: it does not list ' . $settings['external_domain'] . ', so browsers will warn.';

        return $this->bindCertificate((int) $imported['cert_id'], $settings, false, $note);
    }

    /**
     * Issue (or re-issue) a Let's Encrypt certificate for the gateway hostname.
     * Renewal afterwards is automatic: the daily micron:acme-renew job renews
     * it in place and GatewayCertificate::redeployBindings() installs it.
     */
    public function certificateAcme()
    {
        @set_time_limit(240);

        $settings = $this->settingsModel->getSettings();
        $domain   = strtolower(trim((string) ($settings['external_domain'] ?? '')));
        $email    = trim((string) $this->request->getPost('acme_email'));
        $staging  = (int) ($this->request->getPost('acme_staging') ?? 0) === 1;

        if ($problem = self::publicHostnameProblem($domain)) {
            return $this->certJson(false, $problem);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->certJson(false, 'Enter a valid email address for Let\'s Encrypt expiry notices.');
        }

        // Open WAN tcp/80 to the gateway's ACME listener before asking the CA.
        $this->saveSettingFields([
            'acme_enabled' => 1,
            'acme_email'   => $email,
            'acme_staging' => $staging ? 1 : 0,
        ]);
        $this->reapply(true);

        // Re-issue into the same PKI row when the gateway already uses a
        // Let's Encrypt certificate for this name, so bindings stay intact.
        $gatewayCert = new GatewayCertificate($this->ipc);
        $current     = $gatewayCert->status((int) ($settings['cert_id'] ?? 0), $domain);
        $reuseId     = ($current['source'] === 'acme' && $current['covers_domain']) ? $current['cert_id'] : null;

        $result = (new AcmeManager(null, $this->ipc))->request([
            'directory'      => $staging ? Acme::LETSENCRYPT_STAGING : Acme::LETSENCRYPT,
            'email'          => $email,
            'domains'        => [$domain],
            'challenge_type' => Acme::CHALLENGE_HTTP,
            'cert_type'      => 'server',
            'name'           => 'Clientless Gateway: ' . $domain,
            'cert_id'        => $reuseId,
        ]);

        if (($result['ok'] ?? false) !== true || empty($result['cert_id'])) {
            $message = (string) ($result['message'] ?? 'the certificate was not issued');
            $this->saveSettingFields(['acme_last_error' => mb_substr($message, 0, 2000)]);

            return $this->certJson(false, 'Let\'s Encrypt did not issue a certificate: ' . $message
                . ' Check that ' . $domain . ' resolves to this appliance\'s public address and that TCP port 80 reaches it from the internet.');
        }

        $this->saveSettingFields(['acme_last_error' => null]);

        return $this->bindCertificate((int) $result['cert_id'], $this->settingsModel->getSettings(), true,
            ' It renews automatically about 30 days before it expires.');
    }

    /** Install, record and (when Let's Encrypt is turned off) close WAN port 80. */
    private function bindCertificate(int $certId, array $settings, bool $acme, string $note = '')
    {
        $deployed = (new GatewayCertificate($this->ipc))->deploy($certId, (string) ($settings['external_domain'] ?? ''));
        if (! $deployed['ok']) {
            return $this->certJson(false, $deployed['message']);
        }

        $wasAcme = (int) ($settings['acme_enabled'] ?? 0) === 1;
        $this->saveSettingFields(['cert_id' => $certId, 'acme_enabled' => $acme ? 1 : 0]);
        if ($wasAcme !== $acme) {
            $this->reapply(true);
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Changed the Clientless Gateway TLS certificate',
            'vpn'
        );

        return $this->certJson(true, $deployed['message'] . $note);
    }

    /** Recompile nginx (and optionally the firewall) after a certificate change. */
    private function reapply(bool $firewall): void
    {
        try {
            if ($firewall) {
                (new ConfigApplier($this->ipc))->firewall();
            }
            $this->ipc->call('ztna.apply', ['config' => (new ZtnaCompiler($this->resourceModel, $this->settingsModel))->compile()]);
        } catch (\Throwable $e) {
            log_message('error', '[ZTNA] re-applying gateway configuration failed: ' . $e->getMessage());
        }
    }

    private function postedPem(string $field, string $fileField): string
    {
        $file = $this->request->getFile($fileField);
        if ($file && $file->isValid() && ! $file->hasMoved() && $file->getSize() <= 65536) {
            return (string) file_get_contents($file->getTempName());
        }

        return trim((string) $this->request->getPost($field));
    }

    private function certJson(bool $ok, string $message)
    {
        return $this->response->setJSON([
            'success'   => $ok,
            'message'   => $message,
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /** Why Let's Encrypt cannot issue for this name, or null when it can. */
    private static function publicHostnameProblem(string $domain): ?string
    {
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return 'Set the External Gateway FQDN to a public DNS name (not an IP address) and save it first.';
        }
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            return "'{$domain}' is not a valid public hostname.";
        }
        foreach (['.local', '.lan', '.internal', '.localdomain', '.home.arpa', '.corp', '.test', '.example', '.invalid', '.localhost'] as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return "Let's Encrypt only issues certificates for public DNS names, and '{$domain}' is a private name. Use a public hostname, or upload a certificate from your internal CA instead.";
            }
        }

        return null;
    }

    /** Update a few settings columns, creating the settings row on first use. */
    private function saveSettingFields(array $fields): void
    {
        if ($this->settingsModel->find(1) === null) {
            $this->settingsModel->insert(array_merge($this->settingsModel->getSettings(), $fields, ['id' => 1]));

            return;
        }

        $this->settingsModel->update(1, $fields);
    }

    /** Certificate settings carried across the settings form's full-row replace. */
    private static function certificateFields(array $current): array
    {
        return [
            'cert_id'         => (int) ($current['cert_id'] ?? 0),
            'acme_enabled'    => (int) ($current['acme_enabled'] ?? 0),
            'acme_email'      => (string) ($current['acme_email'] ?? ''),
            'acme_staging'    => (int) ($current['acme_staging'] ?? 0),
            'acme_last_error' => ($current['acme_last_error'] ?? '') !== '' ? (string) $current['acme_last_error'] : null,
        ];
    }

    public function apply()
    {
        $compiled = $this->compiler->compile();
        $this->ipc->call('ztna.apply', ['config' => $compiled]);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Applied Clientless ZTNA Gateway Nginx reverse proxy configuration',
            'vpn'
        );

        return $this->response->setJSON([
            'success'   => true,
            'message'   => 'Clientless Gateway reverse proxy reloaded successfully.',
            'compiled'  => $compiled,
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * Get runtime daemon and session status.
     */
    public function status()
    {
        $ipcRes = $this->ipc->call('ztna.status', []);
        $activeSessions = $this->sessionModel->where('status', 'active')->countAllResults();

        return $this->response->setJSON([
            'success'         => true,
            'guacd_active'    => $ipcRes['result']['guacd_active'] ?? false,
            'nginx_active'    => $ipcRes['result']['nginx_active'] ?? false,
            'active_sessions' => $activeSessions,
            'state'           => $ipcRes['result']['state'] ?? [],
            'csrf_hash'       => csrf_hash(),
        ]);
    }
}

