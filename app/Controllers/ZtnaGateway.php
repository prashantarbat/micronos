<?php

namespace App\Controllers;

use App\Libraries\ConfigRevisionEngine;
use App\Libraries\GuacamoleTokenGenerator;
use App\Libraries\IpcClient;
use App\Libraries\ZtnaCompiler;
use App\Models\RoleModel;
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

        return view('ztna/index', [
            'resources' => $resources,
            'sessions'  => $sessions,
            'settings'  => $settings,
            'allRoles'  => $allRoles,
            'title'     => 'In-Browser Clientless Web & Desktop Gateway (ZTNA)',
        ]);
    }

    /**
     * End-User Clientless Application Launcher Portal.
     */
    public function portal()
    {
        $username      = session()->get('username') ?? 'ministrator';
        $isSuperadmin  = (bool) (session()->get('is_superadmin') ?? false);
        $userRoles     = session()->get('roles') ?? ['SuperAdmin'];

        $accessible = $this->resourceModel->getAccessibleForRoles($userRoles, $isSuperadmin);
        $settings   = $this->settingsModel->getSettings();

        return view('ztna/portal', [
            'resources' => $accessible,
            'settings'  => $settings,
            'username'  => $username,
            'title'     => esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway'),
        ]);
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
            'success' => true,
            'message' => "Resource '{$name}' saved.",
        ]);
    }

    /**
     * Delete a published resource.
     */
    public function deleteResource(string $id)
    {
        $resource = $this->resourceModel->find($id);
        if (!$resource) {
            return $this->response->setJSON(['success' => false, 'message' => 'Resource not found.']);
        }

        $this->resourceModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted Clientless Gateway resource '{$resource['name']}'",
            'vpn'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Resource '{$resource['name']}' deleted.",
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

        $username = session()->get('username') ?? 'ministrator';
        $userId   = session()->get('user_id') ?? 'usr_superadmin';

        // Provision token
        $token = GuacamoleTokenGenerator::generateToken($resource, $username);

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
            'token'    => $token,
            'session'  => $payload,
            'settings' => $settings,
            'title'    => "Remote {$payload['type']}: {$payload['name']}",
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
            $res = $this->resourceModel->find($session['resource_id']);
            if ($res && (int)$res['active_sessions_count'] > 0) {
                $this->resourceModel->update($res['id'], [
                    'active_sessions_count' => (int)$res['active_sessions_count'] - 1,
                ]);
            }
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Session terminated.',
        ]);
    }

    /**
     * Subrequest Nginx auth verification endpoint (`auth_request /api/ztna/verify-auth`).
     */
    public function verifyAuth()
    {
        $username = session()->get('username');
        if (!$username) {
            return $this->response->setStatusCode(401)->setBody('Authentication Required');
        }

        $roles = session()->get('roles') ?? ['SuperAdmin'];
        $email = session()->get('email') ?? "{$username}@micronos.local";

        return $this->response
            ->setStatusCode(200)
            ->setHeader('X-Remote-User', $username)
            ->setHeader('X-Remote-Email', $email)
            ->setHeader('X-Remote-Groups', implode(',', $roles))
            ->setBody('OK');
    }

    public function saveSettings()
    {
        $data = [
            'id'                   => 1,
            'enabled'              => (int) ($this->request->getPost('enabled') ?? 1),
            'portal_title'         => trim((string) $this->request->getPost('portal_title')) ?: 'Micron OS Clientless Gateway',
            'external_domain'      => trim((string) $this->request->getPost('external_domain')) ?: 'gateway.micronos.local',
            'guacd_host'           => trim((string) $this->request->getPost('guacd_host')) ?: '127.0.0.1',
            'guacd_port'           => (int) ($this->request->getPost('guacd_port') ?: 4822),
            'session_timeout_mins' => max(5, (int) ($this->request->getPost('session_timeout_mins') ?: 60)),
            'require_mfa'          => (int) ($this->request->getPost('require_mfa') ?? 1),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];

        $this->settingsModel->replace($data);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Updated Clientless Gateway settings',
            'vpn'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Gateway settings updated.',
        ]);
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
            'success'  => true,
            'message'  => 'Clientless Gateway reverse proxy reloaded successfully.',
            'compiled' => $compiled,
        ]);
    }
}
