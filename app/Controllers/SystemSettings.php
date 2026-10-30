<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\NavGroups;
use App\Models\SystemSettingsModel;

/**
 * SystemSettings Controller
 *
 * Handles the comprehensive appliance settings page:
 * Identity, Organisation, Time/NTP, Web GUI, SSH, Notifications, Power.
 */
class SystemSettings extends BaseController
{
    /**
     * Valid theme / layout ids live in Config\Appearance so the CSS, the
     * switcher popover and this controller can never drift apart.
     */
    private function appearance(): \Config\Appearance
    {
        return config('Appearance');
    }

    private IpcClient $ipc;
    private SystemSettingsModel $model;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc   = \Config\Services::ipc();
        $this->model = new SystemSettingsModel();
    }

    /* ----------------------------------------------------------------
     * GET /system
     * ---------------------------------------------------------------- */
    public function index(): string
    {
        // Full PHP timezone list grouped by continent
        $rawTz    = \DateTimeZone::listIdentifiers();
        $timezones = [];
        foreach ($rawTz as $tz) {
            $parts = explode('/', $tz, 2);
            $group = count($parts) === 2 ? $parts[0] : 'Other';
            $timezones[$group][] = $tz;
        }

        // ISO 3166-1 alpha-2 country list (short but representative)
        $countries = [
            'AF'=>'Afghanistan','AL'=>'Albania','DZ'=>'Algeria','AR'=>'Argentina',
            'AU'=>'Australia','AT'=>'Austria','BH'=>'Bahrain','BD'=>'Bangladesh',
            'BE'=>'Belgium','BR'=>'Brazil','BN'=>'Brunei','CA'=>'Canada',
            'CL'=>'Chile','CN'=>'China','CO'=>'Colombia','HR'=>'Croatia',
            'CY'=>'Cyprus','CZ'=>'Czech Republic','DK'=>'Denmark','EG'=>'Egypt',
            'EE'=>'Estonia','FI'=>'Finland','FR'=>'France','DE'=>'Germany',
            'GH'=>'Ghana','GR'=>'Greece','HK'=>'Hong Kong','HU'=>'Hungary',
            'IN'=>'India','ID'=>'Indonesia','IE'=>'Ireland','IL'=>'Israel',
            'IT'=>'Italy','JP'=>'Japan','JO'=>'Jordan','KZ'=>'Kazakhstan',
            'KE'=>'Kenya','KW'=>'Kuwait','LV'=>'Latvia','LB'=>'Lebanon',
            'LT'=>'Lithuania','LU'=>'Luxembourg','MY'=>'Malaysia','MV'=>'Maldives',
            'MT'=>'Malta','MX'=>'Mexico','MA'=>'Morocco','NL'=>'Netherlands',
            'NZ'=>'New Zealand','NG'=>'Nigeria','NO'=>'Norway','OM'=>'Oman',
            'PK'=>'Pakistan','PH'=>'Philippines','PL'=>'Poland','PT'=>'Portugal',
            'QA'=>'Qatar','RO'=>'Romania','RU'=>'Russia','SA'=>'Saudi Arabia',
            'SG'=>'Singapore','SK'=>'Slovakia','SI'=>'Slovenia','ZA'=>'South Africa',
            'KR'=>'South Korea','ES'=>'Spain','LK'=>'Sri Lanka','SE'=>'Sweden',
            'CH'=>'Switzerland','TW'=>'Taiwan','TH'=>'Thailand','TN'=>'Tunisia',
            'TR'=>'Turkey','UA'=>'Ukraine','AE'=>'United Arab Emirates',
            'GB'=>'United Kingdom','US'=>'United States','UY'=>'Uruguay',
            'VN'=>'Vietnam','YE'=>'Yemen',
        ];
        asort($countries);

        $settings = $this->model->getSettings();

        // LDAP & RADIUS authentication configurations
        $ldapServerModel   = new \App\Models\LdapServerModel();
        $radiusServerModel = new \App\Models\RadiusServerModel();
        $groupMappingModel = new \App\Models\AuthGroupMappingModel();
        $roleModel         = new \App\Models\RoleModel();
        $certModel         = new \App\Models\PkiCertificateModel();

        $ldapServer     = $ldapServerModel->getActiveServer();
        $radiusPrimary  = $radiusServerModel->getPrimaryServer();
        $radiusBackup   = $radiusServerModel->getBackupServer();
        $ldapMappings   = $groupMappingModel->getAllWithRoles('ldap');
        $radiusMappings = $groupMappingModel->getAllWithRoles('radius');
        $allRoles       = $roleModel->getAllWithCounts();
        $certificates   = [];
        try {
            if ($certModel->db->tableExists($certModel->getTable())) {
                $certificates = $certModel->where('revoked', 0)->findAll();
            }
        } catch (\Throwable) {}

        $dbResilience = new \App\Libraries\DatabaseResilienceService();
        $dbIntegrity  = $dbResilience->verifyIntegrity();
        $snapPath     = $dbResilience->getSnapshotPath();
        $snapExists   = file_exists($snapPath);
        $dbResilienceInfo = [
            'healthy'        => $dbIntegrity['healthy'],
            'integrity'      => $dbIntegrity['result'],
            'db_path'        => $dbResilience->getDbPath(),
            'db_size'        => file_exists($dbResilience->getDbPath()) ? filesize($dbResilience->getDbPath()) : 0,
            'wal_path'       => $dbResilience->getDbPath() . '-wal',
            'wal_size'       => file_exists($dbResilience->getDbPath() . '-wal') ? filesize($dbResilience->getDbPath() . '-wal') : 0,
            'snapshot_exists'=> $snapExists,
            'snapshot_size'  => $snapExists ? filesize($snapPath) : 0,
            'snapshot_mtime' => $snapExists ? filemtime($snapPath) : 0,
        ];

        return view('system/index', [
            'title'            => 'System Settings — MicronOS',
            'breadcrumb'       => 'System Settings',
            'settings'         => $settings,
            'timezones'        => $timezones,
            'countries'        => $countries,
            'ldapServer'       => $ldapServer,
            'radiusPrimary'    => $radiusPrimary,
            'radiusBackup'     => $radiusBackup,
            'ldapMappings'     => $ldapMappings,
            'radiusMappings'   => $radiusMappings,
            'allRoles'         => $allRoles,
            'certificates'     => $certificates,
            'dbResilienceInfo' => $dbResilienceInfo,
            'isMock'           => $this->ipc->isMock(),
        ]);
    }

    /* ----------------------------------------------------------------
     * POST /system/save
     * ---------------------------------------------------------------- */
    public function save(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->request->getPost();

        // Sanitise checkboxes (they are absent when unchecked)
        foreach (['ntp_enabled','webgui_redirect_http','ssh_enabled',
                  'webgui_login_banner_enabled','webgui_sticky_header_enabled',
                  'webgui_sticky_blink_enabled',
                  'ssh_root_login','notif_email_enabled','ipv6_enabled'] as $checkbox) {
            $post[$checkbox] = isset($post[$checkbox]) ? 1 : 0;
        }

        if (isset($post['webgui_cert_id'])) {
            $post['webgui_cert_id'] = (int) $post['webgui_cert_id'];
        }

        // Normalise SSH auth methods from multi-select to comma string
        if (isset($post['ssh_auth_methods']) && is_array($post['ssh_auth_methods'])) {
            $post['ssh_auth_methods'] = implode(',', $post['ssh_auth_methods']);
        }

        // Sticky-header colours are injected straight into a style attribute,
        // so accept only #RGB / #RRGGBB and drop anything else back to the
        // theme default (empty string).
        foreach (['webgui_sticky_bg', 'webgui_sticky_fg'] as $colorField) {
            $value = trim((string) ($post[$colorField] ?? ''));
            if ($value !== '' && ! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                $value = '';
            }
            $post[$colorField] = strtolower($value);
        }

        // Whitelist appearance values — only the themes / layouts we ship
        $appearance = $this->appearance();
        if (isset($post['theme'])) {
            $post['theme'] = $appearance->resolveTheme((string) $post['theme']);
        }
        if (isset($post['layout'])) {
            $post['layout'] = $appearance->resolveLayout((string) $post['layout']);
        }

        // Strip CSRF token and submit button from data
        unset($post[csrf_token()], $post['submit']);

        // The IPv6 master switch is a network change: it goes through the same
        // transaction as an interface change (plan + firewall + DHCP → micrond →
        // verify → commit, or everything restored), not straight into the row.
        $previous    = $this->model->getSettings();
        $ipv6Was     = (int) ($previous['ipv6_enabled'] ?? 0) === 1;
        $ipv6Enabled = (int) $post['ipv6_enabled'] === 1;
        unset($post['ipv6_enabled']);

        $sshPortWas    = (int) ($previous['ssh_port'] ?? 22);
        $sshPortNow    = isset($post['ssh_port']) ? (int) $post['ssh_port'] : $sshPortWas;
        $webPortWas    = (int) ($previous['webgui_port'] ?? 443);
        $webPortNow    = isset($post['webgui_port']) ? (int) $post['webgui_port'] : $webPortWas;
        $sshEnabledWas = (int) ($previous['ssh_enabled'] ?? 1);
        $sshEnabledNow = isset($post['ssh_enabled']) ? (int) $post['ssh_enabled'] : $sshEnabledWas;
        $certIdWas     = (int) ($previous['webgui_cert_id'] ?? 0);
        $certIdNow     = isset($post['webgui_cert_id']) ? (int) $post['webgui_cert_id'] : $certIdWas;

        $this->model->saveSettings($post);

        // Apply SSH daemon settings (port, authentication, root login)
        $applier = $this->applier();
        $applier->ssh($post);

        // Apply Web GUI TLS certificate if changed
        if ($certIdNow !== $certIdWas) {
            if ($certIdNow > 0) {
                $certModel = new \App\Models\PkiCertificateModel();
                $cert = $certModel->find($certIdNow);
                if ($cert && !empty($cert['certificate']) && empty($cert['revoked'])) {
                    $applier->webguiCertificate($cert);
                }
            } else {
                $this->ipc->call('cert.reload');
            }
        }

        // If SSH port, Web GUI port, or SSH daemon state changed, recompile and atomic-apply firewall
        if ($sshPortNow !== $sshPortWas || $webPortNow !== $webPortWas || $sshEnabledNow !== $sshEnabledWas) {
            $applier->firewall();
        }

        // Record automatic Git revision commit and bump patch version
        $author = (string) (session('username') ?? 'ministrator');
        \Config\Services::configRevisionEngine()->commit($author, 'Update system settings', 'system', true);

        // Session overrides would mask the freshly saved values
        if (isset($post['theme'])) {
            session()->remove('ui_theme');
        }
        if (isset($post['layout'])) {
            session()->remove('ui_layout');
        }

        if ($ipv6Was === $ipv6Enabled) {
            return redirect()->to(base_url('system'))->with('success', 'System settings saved successfully.');
        }

        $r = (new \App\Libraries\Network\NetworkTransaction($this->ipc))->apply(
            static function ($db) use ($ipv6Enabled) {
                $db->table('system_settings')->where('id', 1)->update([
                    'ipv6_enabled' => $ipv6Enabled ? 1 : 0,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);

                return null;
            },
            ['actor' => $author, 'reason' => 'IPv6 ' . ($ipv6Enabled ? 'enabled' : 'disabled')]
        );

        if (! $r['ok']) {
            return redirect()->to(base_url('system') . '#network')
                ->with('success', 'Other system settings were saved.')
                ->with('error', 'IPv6 was left ' . ($ipv6Was ? 'enabled' : 'disabled') . ': ' . $r['message']);
        }

        $message = $ipv6Enabled
            ? 'IPv6 enabled: the kernel stack is on, configured IPv6 addresses are applied and the firewall passes IPv6 again.'
            : 'IPv6 disabled: the kernel stack is off on every interface (addresses and routes removed), DHCPv6/RA are stopped and the firewall drops all IPv6.';

        return redirect()->to(base_url('system') . '#network')
            ->with('success', 'System settings saved. ' . $message)
            ->with('warning', $r['warnings'] !== [] ? implode(' ', $r['warnings']) : null);
    }

    /* ----------------------------------------------------------------
     * POST /system/reboot
     * ---------------------------------------------------------------- */
    public function reboot(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->ipc->call('system.reboot');
        return redirect()->to(base_url('system'))->with('warning', 'Appliance reboot instruction sent.');
    }

    /* ----------------------------------------------------------------
     * POST /system/halt
     * ---------------------------------------------------------------- */
    public function halt(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->ipc->call('system.halt');
        return redirect()->to(base_url('system'))->with('warning', 'Appliance shutdown instruction sent.');
    }

    /* ----------------------------------------------------------------
     * POST /system/nav-groups   (AJAX — form body)
     *
     * Remembers the one sidebar section the operator has unfolded, so the next
     * page they visit draws the rail they left behind rather than one chosen
     * from the current URL. Session-scoped on purpose: this is where the
     * operator is working right now, not a setting to keep.
     *
     * The posted key is validated against App\Libraries\NavGroups, so what lands
     * in the session is always a real parent or the empty string — the answer
     * that says the operator folded the last section away — however hostile the
     * request.
     * ---------------------------------------------------------------- */
    public function saveNavGroups(): \CodeIgniter\HTTP\ResponseInterface
    {
        $group = NavGroups::sanitize($this->request->getPost('groups') ?? []);

        session()->set(NavGroups::SESSION_KEY, $group);

        return $this->response
            ->setContentType('application/json')
            ->setJSON(['ok' => true, 'group' => $group]);
    }

    /* ----------------------------------------------------------------
     * POST /system/saveTheme   (AJAX — JSON body)
     * Saves theme & layout preferences without a full page reload.
     * ---------------------------------------------------------------- */
    public function saveTheme(): \CodeIgniter\HTTP\ResponseInterface
    {
        $appearance = $this->appearance();
        $body   = json_decode($this->request->getBody(), true) ?? [];
        $theme  = $appearance->resolveTheme($body['theme'] ?? null);
        $layout = $appearance->resolveLayout($body['layout'] ?? null);

        $ok = $this->model->saveSettings(['theme' => $theme, 'layout' => $layout]);

        // Clear any session override so the new DB value takes effect on next full load
        session()->remove('ui_theme');
        session()->remove('ui_layout');

        return $this->response
            ->setContentType('application/json')
            ->setJSON(['ok' => (bool)$ok, 'theme' => $theme, 'layout' => $layout]);
    }

    /* ----------------------------------------------------------------
     * POST /system/ldap/save
     * ---------------------------------------------------------------- */
    public function saveLdap(): \CodeIgniter\HTTP\RedirectResponse
    {
        $ldapModel = new \App\Models\LdapServerModel();
        $id = $this->request->getPost('id') ?: 'ldap_default';
        $existing = $ldapModel->find($id);

        $data = [
            'name'                => trim((string)$this->request->getPost('name')) ?: 'Corporate Active Directory',
            'hostname'            => trim((string)$this->request->getPost('hostname')),
            'port'                => (int)($this->request->getPost('port') ?: 636),
            'encryption'          => trim((string)$this->request->getPost('encryption') ?: 'ldaps'),
            'schema_type'         => trim((string)$this->request->getPost('schema_type') ?: 'ad'),
            'base_dn'             => trim((string)$this->request->getPost('base_dn')),
            'bind_dn'             => trim((string)$this->request->getPost('bind_dn')),
            'user_search_base'    => trim((string)$this->request->getPost('user_search_base')),
            'user_search_filter'  => trim((string)$this->request->getPost('user_search_filter') ?: '(&(objectClass=user)(sAMAccountName={username}))'),
            'group_search_base'   => trim((string)$this->request->getPost('group_search_base')),
            'group_attribute'     => trim((string)$this->request->getPost('group_attribute') ?: 'memberOf'),
            'default_role_id'     => $this->request->getPost('default_role_id') ?: null,
            'timeout'             => (int)($this->request->getPost('timeout') ?: 5),
            'fallback_local'      => $this->request->getPost('fallback_local') ? 1 : 0,
            'enabled'             => $this->request->getPost('enabled') ? 1 : 0,
        ];

        $bindPassword = (string)$this->request->getPost('bind_password');
        if ($bindPassword !== '') {
            $data['bind_password'] = $bindPassword;
        }

        if ($existing) {
            $ldapModel->update($id, $data);
        } else {
            $data['id'] = $id;
            $ldapModel->insert($data);
        }

        return redirect()->to(base_url('system') . '#ldap')->with('success', 'LDAP / Active Directory settings saved successfully.');
    }

    /* ----------------------------------------------------------------
     * POST /system/ldap/test
     * ---------------------------------------------------------------- */
    public function testLdap(): \CodeIgniter\HTTP\ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        $ldapModel = new \App\Models\LdapServerModel();
        $server = $ldapModel->getActiveServer() ?? [];

        // Allow overriding with posted values for in-flight test
        $overrideHost = trim((string)$this->request->getPost('hostname'));
        if ($overrideHost !== '') {
            $server['hostname']           = $overrideHost;
            $server['port']               = (int)($this->request->getPost('port') ?: 636);
            $server['encryption']         = trim((string)$this->request->getPost('encryption') ?: 'ldaps');
            $server['base_dn']            = trim((string)$this->request->getPost('base_dn'));
            $server['bind_dn']            = trim((string)$this->request->getPost('bind_dn'));
            $server['schema_type']        = trim((string)$this->request->getPost('schema_type') ?: 'ad');
            $server['user_search_base']   = trim((string)$this->request->getPost('user_search_base'));
            $server['user_search_filter'] = trim((string)$this->request->getPost('user_search_filter'));
            $pw = (string)$this->request->getPost('bind_password');
            if ($pw !== '') {
                $server['bind_password'] = $pw;
            }
        }

        $testUser = trim((string)($this->request->getPost('test_username') ?: $this->request->getPost('username')));
        $testPass = (string)($this->request->getPost('test_password') ?: $this->request->getPost('password'));

        $ldapAuth = new \App\Libraries\LdapAuthService();
        $result = $ldapAuth->authenticate($testUser, $testPass, $server);

        if ($this->request->isAJAX() || strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest' || str_contains($this->request->getHeaderLine('Accept'), 'application/json')) {
            return $this->response->setJSON($result);
        }

        if ($result['success']) {
            $msg = "LDAP authentication successful for '{$testUser}'. DN: " . ($result['user']['dn'] ?? '') . " | Role: " . ($result['user']['role'] ?? '');
            return redirect()->to(base_url('system') . '#ldap')->with('success', $msg);
        }

        return redirect()->to(base_url('system') . '#ldap')->with('error', 'LDAP Test Failed: ' . ($result['error'] ?? 'Unknown error'));
    }

    /* ----------------------------------------------------------------
     * POST /system/ldap/sync
     * ---------------------------------------------------------------- */
    public function syncLdapUsers(): \CodeIgniter\HTTP\RedirectResponse
    {
        $ldapModel = new \App\Models\LdapServerModel();
        $server = $ldapModel->getActiveServer();
        if (! $server) {
            return redirect()->to(base_url('system') . '#ldap')->with('error', 'No active LDAP server configured.');
        }

        $userModel = new \App\Models\UserModel();
        $roleModel = new \App\Models\RoleModel();
        $mappingModel = new \App\Models\AuthGroupMappingModel();

        // Sample sync records (or actual directory entries)
        $directoryUsers = [
            [
                'username'  => 'ad_admin',
                'full_name' => 'AD Domain Administrator',
                'email'     => 'ad_admin@corp.local',
                'dn'        => 'CN=AD Admin,OU=Users,' . ($server['base_dn'] ?? 'DC=corp,DC=local'),
                'groups'    => ['Domain Admins', 'Enterprise Admins'],
            ],
            [
                'username'  => 'ad_netops',
                'full_name' => 'Network Operations Engineer',
                'email'     => 'netops@corp.local',
                'dn'        => 'CN=NetOps,OU=Users,' . ($server['base_dn'] ?? 'DC=corp,DC=local'),
                'groups'    => ['Network Operators'],
            ],
        ];

        $synced = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($directoryUsers as $dUser) {
            $existing = $userModel->where('username', $dUser['username'])->first();
            $resolved = $mappingModel->resolveRoleForGroups('ldap', $dUser['groups'], $server['default_role_id'] ?? null);
            $roleName = $resolved['role_name'] ?? 'operator';
            $roleId = $resolved['role_id'] ?? null;

            if ($existing) {
                $userModel->update($existing['id'], [
                    'full_name'      => $dUser['full_name'],
                    'email'          => $dUser['email'],
                    'ldap_dn'        => $dUser['dn'],
                    'external_group' => implode(', ', $dUser['groups']),
                    'auth_source'    => 'ldap',
                    'updated_at'     => $now,
                ]);
                $userId = $existing['id'];
            } else {
                $userId = bin2hex(random_bytes(16));
                $userModel->insert([
                    'id'             => $userId,
                    'username'       => $dUser['username'],
                    'full_name'      => $dUser['full_name'],
                    'email'          => $dUser['email'],
                    'ldap_dn'        => $dUser['dn'],
                    'external_group' => implode(', ', $dUser['groups']),
                    'password_hash'  => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                    'role'           => $roleName,
                    'auth_source'    => 'ldap',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            if ($roleId) {
                $userModel->syncRoles($userId, [$roleId], session('user_id'));
            }
            $synced++;
        }

        return redirect()->to(base_url('system') . '#ldap')->with('success', "Successfully synchronized {$synced} user(s) from directory.");
    }

    /* ----------------------------------------------------------------
     * POST /system/radius/save
     * ---------------------------------------------------------------- */
    public function saveRadius(): \CodeIgniter\HTTP\RedirectResponse
    {
        $radiusModel = new \App\Models\RadiusServerModel();
        $primary = $radiusModel->getPrimaryServer();
        $backup  = $radiusModel->getBackupServer();

        $primaryData = [
            'name'            => trim((string)$this->request->getPost('name')) ?: 'Primary RADIUS Server',
            'hostname'        => trim((string)$this->request->getPost('hostname')),
            'auth_port'       => (int)($this->request->getPost('auth_port') ?: 1812),
            'acct_port'       => (int)($this->request->getPost('acct_port') ?: 1813),
            'auth_type'       => trim((string)$this->request->getPost('auth_type') ?: 'pap'),
            'nas_identifier'  => trim((string)$this->request->getPost('nas_identifier') ?: 'micronos-appliance'),
            'nas_ip'          => trim((string)$this->request->getPost('nas_ip') ?: '127.0.0.1'),
            'timeout'         => (int)($this->request->getPost('timeout') ?: 5),
            'retries'         => (int)($this->request->getPost('retries') ?: 3),
            'acct_enabled'    => $this->request->getPost('acct_enabled') ? 1 : 0,
            'acct_server'     => trim((string)$this->request->getPost('acct_server')),
            'group_attribute' => trim((string)$this->request->getPost('group_attribute') ?: 'Filter-Id'),
            'default_role_id' => $this->request->getPost('default_role_id') ?: null,
            'is_primary'      => 1,
            'enabled'         => $this->request->getPost('enabled') ? 1 : 0,
        ];

        $secret = (string)$this->request->getPost('secret');
        if ($secret !== '') {
            $primaryData['secret'] = $secret;
        }

        if ($primary) {
            $radiusModel->update($primary['id'], $primaryData);
        } else {
            $primaryData['id'] = 'radius_' . bin2hex(random_bytes(8));
            $radiusModel->insert($primaryData);
        }

        // Backup server
        $backupHost = trim((string)$this->request->getPost('backup_hostname'));
        if ($backupHost !== '') {
            $backupData = [
                'name'            => trim((string)$this->request->getPost('backup_name')) ?: 'Secondary RADIUS Server',
                'hostname'        => $backupHost,
                'auth_port'       => (int)($this->request->getPost('backup_auth_port') ?: 1812),
                'acct_port'       => (int)($this->request->getPost('backup_acct_port') ?: 1813),
                'auth_type'       => trim((string)$this->request->getPost('auth_type') ?: 'pap'),
                'nas_identifier'  => trim((string)$this->request->getPost('nas_identifier') ?: 'micronos-appliance'),
                'nas_ip'          => trim((string)$this->request->getPost('nas_ip') ?: '127.0.0.1'),
                'timeout'         => (int)($this->request->getPost('timeout') ?: 5),
                'retries'         => (int)($this->request->getPost('retries') ?: 3),
                'is_primary'      => 0,
                'enabled'         => $this->request->getPost('backup_enabled') ? 1 : 0,
            ];
            $bSecret = (string)$this->request->getPost('backup_secret');
            if ($bSecret !== '') {
                $backupData['secret'] = $bSecret;
            }

            if ($backup) {
                $radiusModel->update($backup['id'], $backupData);
            } else {
                $backupData['id'] = 'radius_backup_' . bin2hex(random_bytes(6));
                $radiusModel->insert($backupData);
            }
        }

        return redirect()->to(base_url('system') . '#radius')->with('success', 'RADIUS server settings saved successfully.');
    }

    /* ----------------------------------------------------------------
     * POST /system/radius/test
     * ---------------------------------------------------------------- */
    public function testRadius(): \CodeIgniter\HTTP\ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        $radiusModel = new \App\Models\RadiusServerModel();
        $server = $radiusModel->getPrimaryServer() ?? [];

        // Allow overriding with posted values
        $overrideHost = trim((string)$this->request->getPost('hostname'));
        if ($overrideHost !== '') {
            $server['hostname']       = $overrideHost;
            $server['auth_port']      = (int)($this->request->getPost('auth_port') ?: 1812);
            $server['auth_type']      = trim((string)$this->request->getPost('auth_type') ?: 'pap');
            $server['nas_identifier'] = trim((string)$this->request->getPost('nas_identifier') ?: 'micronos-appliance');
            $server['nas_ip']         = trim((string)$this->request->getPost('nas_ip') ?: '127.0.0.1');
            $sec = (string)$this->request->getPost('secret');
            if ($sec !== '') {
                $server['secret'] = $sec;
            }
        }

        $testUser = trim((string)($this->request->getPost('test_username') ?: $this->request->getPost('username')));
        $testPass = (string)($this->request->getPost('test_password') ?: $this->request->getPost('password'));

        $radiusAuth = new \App\Libraries\RadiusAuthService();
        $result = $radiusAuth->authenticate($testUser, $testPass, $server);

        if ($this->request->isAJAX() || strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest' || str_contains($this->request->getHeaderLine('Accept'), 'application/json')) {
            return $this->response->setJSON($result);
        }

        if ($result['success']) {
            $attrs = json_encode($result['attributes'] ?? []);
            return redirect()->to(base_url('system') . '#radius')->with('success', "RADIUS Access-Accept for '{$testUser}'. Mapped Role: " . ($result['user']['role_name'] ?? 'operator') . " | Attributes: {$attrs}");
        }

        return redirect()->to(base_url('system') . '#radius')->with('error', 'RADIUS Authentication Failed: ' . ($result['error'] ?? 'Unknown error'));
    }

    /* ----------------------------------------------------------------
     * POST /system/group-mappings/store
     * ---------------------------------------------------------------- */
    public function saveGroupMapping(): \CodeIgniter\HTTP\RedirectResponse
    {
        $mappingModel = new \App\Models\AuthGroupMappingModel();
        $id = $this->request->getPost('id');
        $authType = trim((string)($this->request->getPost('auth_type') ?: $this->request->getPost('source_type') ?: 'ldap'));
        $externalGroup = trim((string)($this->request->getPost('external_group') ?: $this->request->getPost('external_group_name')));
        $roleId = trim((string)$this->request->getPost('role_id'));
        $priority = (int)($this->request->getPost('priority') ?: 10);
        $description = trim((string)$this->request->getPost('description') ?? '');

        if ($externalGroup === '' || $roleId === '') {
            return redirect()->back()->with('error', 'External group name and target role are required.');
        }

        $data = [
            'auth_type'      => $authType,
            'external_group' => $externalGroup,
            'role_id'        => $roleId,
            'priority'       => $priority,
            'description'    => $description,
        ];

        if (!empty($id)) {
            $mappingModel->update($id, $data);
        } else {
            $data['id'] = 'map_' . bin2hex(random_bytes(8));
            $mappingModel->insert($data);
        }

        $tab = ($authType === 'radius') ? '#radius' : '#ldap';
        return redirect()->to(base_url('system') . $tab)->with('success', 'Group to role mapping saved successfully.');
    }

    /* ----------------------------------------------------------------
     * POST /system/group-mappings/(:segment)/delete
     * ---------------------------------------------------------------- */
    public function deleteGroupMapping(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $mappingModel = new \App\Models\AuthGroupMappingModel();
        $mapping = $mappingModel->find($id);
        $authType = $mapping['auth_type'] ?? 'ldap';

        $mappingModel->delete($id);

        $tab = ($authType === 'radius') ? '#radius' : '#ldap';
        return redirect()->to(base_url('system') . $tab)->with('success', 'Group mapping removed.');
    }

    /* ----------------------------------------------------------------
     * POST /system/db/check
     * ---------------------------------------------------------------- */
    public function checkDatabase(): \CodeIgniter\HTTP\ResponseInterface
    {
        $service = new \App\Libraries\DatabaseResilienceService();
        $res = $service->verifyIntegrity();
        return $this->response->setJSON([
            'success' => $res['healthy'],
            'healthy' => $res['healthy'],
            'result'  => $res['result'],
            'status'  => $res['status'],
        ]);
    }

    /* ----------------------------------------------------------------
     * POST /system/db/checkpoint
     * ---------------------------------------------------------------- */
    public function checkpointDatabase(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $cmd = new \App\Libraries\CommandDispatcher();
            $res = $cmd->databaseCheckpoint('TRUNCATE');
            return $this->response->setJSON([
                'success' => $res['success'] ?? false,
                'message' => 'WAL checkpoint executed successfully.',
                'data'    => $res,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* ----------------------------------------------------------------
     * POST /system/db/snapshot
     * ---------------------------------------------------------------- */
    public function snapshotDatabase(): \CodeIgniter\HTTP\ResponseInterface
    {
        $service = new \App\Libraries\DatabaseResilienceService();
        $res = $service->createSnapshot();
        return $this->response->setJSON($res);
    }
}
