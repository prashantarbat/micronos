<?php

namespace App\Controllers;

use App\Libraries\ConfigRevisionEngine;
use App\Libraries\SsoClient;
use App\Models\PermissionModel;
use App\Models\SsoProviderModel;
use App\Models\SsoSettingsModel;
use App\Models\SsoStateModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Exception;

/**
 * Sso Controller — Enterprise Centralized Single Sign-On (OIDC & SAML 2.0).
 */
class Sso extends BaseController
{
    protected SsoProviderModel $providerModel;
    protected SsoStateModel $stateModel;
    protected SsoSettingsModel $settingsModel;
    protected SsoClient $ssoClient;
    protected UserModel $userModel;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->providerModel = new SsoProviderModel();
        $this->stateModel    = new SsoStateModel();
        $this->settingsModel = new SsoSettingsModel();
        $this->ssoClient     = new SsoClient();
        $this->userModel     = new UserModel();
        $this->revEngine     = new ConfigRevisionEngine();
    }

    // =========================================================================
    // Admin Management (Under /system/sso)
    // =========================================================================

    public function index(): string
    {
        $providers = $this->providerModel->orderBy('name', 'ASC')->findAll();
        $settings  = $this->settingsModel->getSettings();

        return view('sso/index', [
            'title'     => 'Centralized Single Sign-On (SSO) — Micron OS',
            'providers' => $providers,
            'settings'  => $settings,
        ]);
    }

    public function saveProvider(): RedirectResponse
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));
        $slug = trim((string) $this->request->getPost('slug'));
        $protocol = strtolower(trim((string) $this->request->getPost('protocol')));

        if (empty($name) || empty($protocol)) {
            return redirect()->back()->with('error', 'Provider name and protocol are required.');
        }

        if (empty($slug)) {
            $slug = url_title(strtolower($name));
        }

        $data = [
            'name'                 => $name,
            'slug'                 => $slug,
            'protocol'             => $protocol,
            'enabled'              => $this->request->getPost('enabled') ? 1 : 0,
            'discovery_url'        => trim((string) $this->request->getPost('discovery_url')),
            'client_id'            => trim((string) $this->request->getPost('client_id')),
            'client_secret'        => trim((string) $this->request->getPost('client_secret')),
            'auth_url'             => trim((string) $this->request->getPost('auth_url')),
            'token_url'            => trim((string) $this->request->getPost('token_url')),
            'userinfo_url'         => trim((string) $this->request->getPost('userinfo_url')),
            'scopes'               => trim((string) $this->request->getPost('scopes')) ?: 'openid profile email',
            'idp_entity_id'        => trim((string) $this->request->getPost('idp_entity_id')),
            'idp_sso_url'          => trim((string) $this->request->getPost('idp_sso_url')),
            'idp_x509_cert'        => trim((string) $this->request->getPost('idp_x509_cert')),
            'sp_entity_id'         => trim((string) $this->request->getPost('sp_entity_id')) ?: base_url('auth/sso/metadata/' . $slug),
            'auto_provision'       => $this->request->getPost('auto_provision') ? 1 : 0,
            'default_role'         => trim((string) $this->request->getPost('default_role')) ?: 'Operator',
            'role_mapping_json'    => trim((string) $this->request->getPost('role_mapping_json')),
            'allow_unmapped_users' => $this->request->getPost('allow_unmapped_users') ? 1 : 0,
            'icon'                 => trim((string) $this->request->getPost('icon')) ?: 'shield-lock',
        ];

        if (! empty($id)) {
            $this->providerModel->update($id, $data);
            $message = "SSO Provider '{$name}' updated successfully.";
        } else {
            $data['id'] = 'sso_' . bin2hex(random_bytes(8));
            $this->providerModel->insert($data);
            $message = "SSO Provider '{$name}' created successfully.";
        }

        // Git-backed revision commit
        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            $message,
            'system'
        );

        return redirect()->to(base_url('system/sso'))->with('success', $message);
    }

    public function toggleProvider(string $id): RedirectResponse
    {
        $provider = $this->providerModel->find($id);
        if (! $provider) {
            return redirect()->back()->with('error', 'Provider not found.');
        }

        $newState = $provider['enabled'] ? 0 : 1;
        $this->providerModel->update($id, ['enabled' => $newState]);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            ($newState ? 'Enabled' : 'Disabled') . " SSO Provider '{$provider['name']}'",
            'system'
        );

        return redirect()->to(base_url('system/sso'))->with(
            'success',
            "Provider '{$provider['name']}' " . ($newState ? 'enabled' : 'disabled') . '.'
        );
    }

    public function deleteProvider(string $id): RedirectResponse
    {
        $provider = $this->providerModel->find($id);
        if (! $provider) {
            return redirect()->back()->with('error', 'Provider not found.');
        }

        $this->providerModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            "Deleted SSO Provider '{$provider['name']}'",
            'system'
        );

        return redirect()->to(base_url('system/sso'))->with('success', "Provider '{$provider['name']}' deleted.");
    }

    public function saveSettings(): RedirectResponse
    {
        $settings = $this->settingsModel->getSettings();

        $data = [
            'sso_enforced'         => $this->request->getPost('sso_enforced') ? 1 : 0,
            'allow_local_fallback' => $this->request->getPost('allow_local_fallback') ? 1 : 0,
            'default_provider_id'  => $this->request->getPost('default_provider_id') ?: null,
        ];

        $this->settingsModel->update($settings['id'], $data);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            'Updated global Single Sign-On policies',
            'system'
        );

        return redirect()->to(base_url('system/sso'))->with('success', 'SSO settings saved.');
    }

    public function discoverEndpoints(): ResponseInterface
    {
        $url = trim((string) $this->request->getPost('discovery_url'));
        if (empty($url)) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Discovery URL is required.']);
        }

        try {
            $endpoints = $this->ssoClient->discoverOidcEndpoints($url);
            return $this->response->setJSON(['status' => 'success', 'data' => $endpoints]);
        } catch (Exception $e) {
            return $this->response->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Public Authentication Flow (Under /auth/sso)
    // =========================================================================

    /**
     * Initiate SSO Login (Redirect to IdP).
     */
    public function login(string $slug): RedirectResponse
    {
        $provider = $this->providerModel->getBySlug($slug);
        if (! $provider || ! $provider['enabled']) {
            return redirect()->to(base_url('auth/login'))->with('error', 'Selected SSO provider is unavailable.');
        }

        if ($provider['protocol'] === 'oidc') {
            $pkce = $this->ssoClient->generatePkce();
            $stateRecord = $this->stateModel->createState($provider['id'], $pkce['verifier']);
            $redirectUri = base_url('auth/sso/callback/' . $provider['slug']);

            try {
                $authUrl = $this->ssoClient->buildAuthorizationUrl(
                    $provider,
                    $redirectUri,
                    $stateRecord['state_token'],
                    $stateRecord['nonce'],
                    $pkce['challenge']
                );
                return redirect()->to($authUrl);
            } catch (Exception $e) {
                return redirect()->to(base_url('auth/login'))->with('error', 'OIDC Error: ' . $e->getMessage());
            }
        } elseif ($provider['protocol'] === 'saml') {
            $relayState = bin2hex(random_bytes(16));
            $this->stateModel->createState($provider['id'], null, $relayState);
            $acsUrl = base_url('auth/sso/saml/acs/' . $provider['slug']);

            try {
                $samlUrl = $this->ssoClient->buildSamlAuthnRequestUrl($provider, $acsUrl, $relayState);
                return redirect()->to($samlUrl);
            } catch (Exception $e) {
                return redirect()->to(base_url('auth/login'))->with('error', 'SAML Error: ' . $e->getMessage());
            }
        }

        return redirect()->to(base_url('auth/login'))->with('error', 'Unsupported SSO protocol.');
    }

    /**
     * OIDC Callback Endpoint.
     */
    public function callback(string $slug): RedirectResponse
    {
        $provider = $this->providerModel->getBySlug($slug);
        if (! $provider || ! $provider['enabled']) {
            return redirect()->to(base_url('auth/login'))->with('error', 'SSO Provider is disabled or not found.');
        }

        $code  = $this->request->getGet('code');
        $state = $this->request->getGet('state');
        $error = $this->request->getGet('error_description') ?? $this->request->getGet('error');

        if (! empty($error)) {
            return redirect()->to(base_url('auth/login'))->with('error', 'IdP returned error: ' . $error);
        }

        if (empty($code) || empty($state)) {
            return redirect()->to(base_url('auth/login'))->with('error', 'Missing authorization code or state.');
        }

        $stateRecord = $this->stateModel->consumeState($state);
        if (! $stateRecord || $stateRecord['provider_id'] !== $provider['id']) {
            return redirect()->to(base_url('auth/login'))->with('error', 'Invalid or expired SSO state session (CSRF protection).');
        }

        $redirectUri = base_url('auth/sso/callback/' . $provider['slug']);

        try {
            $tokenResult = $this->ssoClient->exchangeCodeForTokens(
                $provider,
                $code,
                $redirectUri,
                $stateRecord['code_verifier']
            );

            $claims = [];
            if (! empty($tokenResult['id_token'])) {
                $claims = $this->ssoClient->parseJwtClaims($tokenResult['id_token']);
            }

            if (! empty($tokenResult['access_token']) && ! empty($provider['userinfo_url'])) {
                $userinfoClaims = $this->ssoClient->fetchUserInfo($provider, $tokenResult['access_token']);
                $claims = array_merge($claims, $userinfoClaims);
            }

            $profile = $this->ssoClient->resolveProfileAndRole($provider, $claims);
            $user = $this->ssoClient->provisionOrUpdateUser($provider, $profile);

            $this->completeSsoLogin($user);

            return redirect()->to(base_url('dashboard'))->with(
                'success',
                "Signed in via {$provider['name']} as " . esc($user['username']) . '.'
            );
        } catch (Exception $e) {
            log_message('error', "[SSO Callback Error] " . $e->getMessage());
            return redirect()->to(base_url('auth/login'))->with('error', 'Authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * SAML 2.0 Assertion Consumer Service (ACS) Endpoint.
     */
    public function acs(string $slug): RedirectResponse
    {
        $provider = $this->providerModel->getBySlug($slug);
        if (! $provider || ! $provider['enabled']) {
            return redirect()->to(base_url('auth/login'))->with('error', 'SSO Provider is disabled or not found.');
        }

        $samlResponse = $this->request->getPost('SAMLResponse');
        if (empty($samlResponse)) {
            return redirect()->to(base_url('auth/login'))->with('error', 'No SAMLResponse received from Identity Provider.');
        }

        try {
            $parsed = $this->ssoClient->parseSamlResponse($samlResponse, $provider['idp_x509_cert']);

            $claims = array_merge($parsed['attributes'], [
                'sub'     => $parsed['name_id'],
                'name_id' => $parsed['name_id'],
            ]);

            $profile = $this->ssoClient->resolveProfileAndRole($provider, $claims);
            $user = $this->ssoClient->provisionOrUpdateUser($provider, $profile);

            $this->completeSsoLogin($user);

            return redirect()->to(base_url('dashboard'))->with(
                'success',
                "Signed in via {$provider['name']} as " . esc($user['username']) . '.'
            );
        } catch (Exception $e) {
            log_message('error', "[SAML ACS Error] " . $e->getMessage());
            return redirect()->to(base_url('auth/login'))->with('error', 'SAML Assertion verification failed: ' . $e->getMessage());
        }
    }

    /**
     * SAML 2.0 Service Provider Metadata Endpoint.
     */
    public function metadata(string $slug): ResponseInterface
    {
        $provider = $this->providerModel->getBySlug($slug);
        if (! $provider) {
            return $this->response->setStatusCode(404)->setBody('Provider not found');
        }

        $acsUrl = base_url('auth/sso/saml/acs/' . $provider['slug']);
        $xml = $this->ssoClient->generateSpMetadataXml($provider, $acsUrl);

        return $this->response
            ->setHeader('Content-Type', 'application/xml; charset=utf-8')
            ->setBody($xml);
    }

    /**
     * Finalize user session matching Auth::completeLogin.
     */
    protected function completeSsoLogin(array $user): void
    {
        $permModel = new PermissionModel();
        $permissions = $permModel->getEffectiveKeysForUser($user['id']);

        $db = \Config\Database::connect();
        $isSuperadmin = (strtolower((string) $user['username']) === 'administrator') ||
            (strtolower((string) ($user['role'] ?? '')) === 'superadmin') ||
            $db->table('user_roles ur')
               ->join('roles r', 'r.id = ur.role_id')
               ->where('ur.user_id', $user['id'])
               ->where('r.name', 'superadmin')
               ->countAllResults() > 0;

        session()->regenerate(true);
        session()->set([
            'user_id'            => $user['id'],
            'username'           => $user['username'],
            'user_role'          => $user['role'] ?? ($isSuperadmin ? 'Superadmin' : 'Operator'),
            'is_superadmin'      => $isSuperadmin,
            'permissions'        => $permissions,
            'user_permissions'   => $permissions,
            'perm_catalog_count' => $permModel->permissionCatalogCount(),
            'logged_in_at'       => time(),
            'auth_source'        => $user['auth_source'] ?? 'sso',
        ]);

        log_message('info', "[SSO Auth] Successfully signed in user {$user['username']} via {$user['auth_source']}");
    }
}
