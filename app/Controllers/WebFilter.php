<?php

namespace App\Controllers;

use App\Libraries\InterceptionCa;
use App\Libraries\IpcClient;
use App\Libraries\WpadGenerator;
use App\Libraries\X509;
use App\Models\WebfilterSettingsModel;
use App\Models\WebfilterCategoryModel;
use App\Models\WebfilterFeedModel;
use App\Models\WebfilterAccessLogModel;
use App\Models\WebfilterAvDetectionModel;

/**
 * WebFilter Controller — Enterprise Web Proxy, Content Filtering, ACLs, and WPAD/PAC Auto-Discovery
 */
class WebFilter extends BaseController
{
    private IpcClient $ipc;
    private WebfilterSettingsModel $settingsModel;
    private WebfilterCategoryModel $categoryModel;
    private WebfilterFeedModel $feedModel;
    private WebfilterAccessLogModel $logModel;
    private WebfilterAvDetectionModel $avModel;
    private WpadGenerator $wpadGen;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->settingsModel = new WebfilterSettingsModel();
        $this->categoryModel = new WebfilterCategoryModel();
        $this->feedModel     = new WebfilterFeedModel();
        $this->logModel      = new WebfilterAccessLogModel();
        $this->avModel       = new WebfilterAvDetectionModel();
        $this->wpadGen       = new WpadGenerator();
    }

    /**
     * Main Web Filter & Proxy Dashboard
     */
    public function index(): string
    {
        $settings   = $this->settingsModel->getSettings();
        $categories = $this->categoryModel->getCategories();
        $feeds      = $this->feedModel->getFeeds();

        $activeTab  = $this->request->getGet('tab') ?? 'general';
        $logSearch  = $this->request->getGet('log_search');
        $logAction  = $this->request->getGet('log_action');

        $accessLogs = $this->logModel->getFilteredLogs($logSearch, $logAction, 50);

        // Fetch physical & virtual interfaces
        $db = \Config\Database::connect();
        $availableIfaces = [];
        try {
            $ifaceRows = $db->table('interfaces')->select('name, ifname, enabled, type')->get()->getResultArray();
            foreach ($ifaceRows as $row) {
                $availableIfaces[] = [
                    'id'    => $row['name'] ?: $row['ifname'],
                    'label' => strtoupper($row['name'] ?: $row['ifname']) . ' (' . ($row['ifname'] ?: 'eth0') . ')',
                ];
            }
        } catch (\Throwable $e) {
            $availableIfaces = [
                ['id' => 'lan', 'label' => 'LAN (Primary Local Network)'],
                ['id' => 'opt1', 'label' => 'OPT1 (Guest / IoT Network)'],
                ['id' => 'dmz', 'label' => 'DMZ (Isolated Services)'],
            ];
        }

        if (empty($availableIfaces)) {
            $availableIfaces = [
                ['id' => 'lan', 'label' => 'LAN (Primary Local Network)'],
                ['id' => 'opt1', 'label' => 'OPT1 (Guest / IoT Network)'],
            ];
        }

        // Live PAC Script generation
        $clientHost = $this->request->getHeaderLine('Host') ?: '192.168.1.1';
        if (str_contains($clientHost, ':')) {
            $clientHost = explode(':', $clientHost)[0];
        }
        $pacScript = $this->wpadGen->generatePac($settings, $clientHost);

        // Fetch live proxy telemetry via IPC. Cache hit-rate and memory/disk
        // usage come from Squid's own cache manager; when the daemon is
        // unreachable the panel shows an explicit unavailable state instead of
        // made-up figures, which would be indistinguishable from real data.
        $statusRes = $this->ipc->call('webfilter.status')['result'] ?? null;
        $status = is_array($statusRes) && array_key_exists('running', $statusRes) ? $statusRes : [
            'running'        => false,
            'cache_manager'  => false,
            'uptime'         => null,
            'requests_today' => 0,
            'blocked_today'  => 0,
            'cache_hit_rate' => null,
            'mem_usage'      => null,
            'disk_usage'     => null,
        ];

        // C-ICAP + ClamAV antivirus inspection telemetry (falls back to the
        // last persisted definitions snapshot when the bridge is unreachable).
        $avRes    = $this->ipc->call('webfilter.av.status')['result'] ?? null;
        $avStatus = is_array($avRes) && isset($avRes['running']) ? $avRes : [
            'running'          => false,
            'engine'           => 'ClamAV (bridge unavailable)',
            'icap_running'     => false,
            'icap_service'     => (string) ($settings['icap_service'] ?? 'squidclamav'),
            'signatures'       => (int) ($settings['clamav_signatures'] ?? 0),
            'defs_version'     => (string) ($settings['clamav_defs_version'] ?? 'unknown'),
            'last_update'      => (string) ($settings['clamav_last_update_at'] ?? ''),
            'requests_scanned' => 0,
            'threats_blocked'  => 0,
        ];

        $avDetections = $this->avModel->getRecent(50);
        $avStats      = $this->avModel->stats();

        return view('webfilter/index', [
            'title'           => 'Enterprise Web Proxy & Content Filter — MicronOS',
            'breadcrumb'      => 'Web Filter & Proxy',
            'settings'        => $settings,
            'categories'      => $categories,
            'feeds'           => $feeds,
            'accessLogs'      => $accessLogs,
            'availableIfaces' => $availableIfaces,
            'activeTab'       => $activeTab,
            'logSearch'       => $logSearch,
            'logAction'       => $logAction,
            'pacScript'       => $pacScript,
            'status'          => $status,
            'avStatus'        => $avStatus,
            'avDetections'    => $avDetections,
            'avStats'         => $avStats,
            'isMock'          => $this->ipc->isMock(),
        ]);
    }

    /**
     * Root WPAD / PAC Discovery Endpoint (wpad.dat, wpad.da, proxy.pac, wpad.pac)
     */
    public function serveWpad(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings   = $this->settingsModel->getSettings();
        $clientHost = $this->request->getHeaderLine('Host') ?: '192.168.1.1';
        if (str_contains($clientHost, ':')) {
            $clientHost = explode(':', $clientHost)[0];
        }

        $pacScript = $this->wpadGen->generatePac($settings, $clientHost);

        return $this->response
            ->setHeader('Content-Type', 'application/x-ns-proxy-autoconfig')
            ->setHeader('Content-Disposition', 'inline; filename="wpad.dat"')
            ->setHeader('Cache-Control', 'no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setBody($pacScript);
    }

    /**
     * Download generated PAC script as an attachment
     */
    public function downloadPac(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings   = $this->settingsModel->getSettings();
        $clientHost = $this->request->getHeaderLine('Host') ?: '192.168.1.1';
        if (str_contains($clientHost, ':')) {
            $clientHost = explode(':', $clientHost)[0];
        }

        $pacScript = $this->wpadGen->generatePac($settings, $clientHost);

        return $this->response
            ->setHeader('Content-Type', 'application/x-ns-proxy-autoconfig')
            ->setHeader('Content-Disposition', 'attachment; filename="proxy.pac"')
            ->setBody($pacScript);
    }

    /**
     * Download root Certificate Authority (CA) certificate for client installation.
     *
     * Serves the CA actually in use for SSL-Bump: the provisioned PEM on disk
     * when present, otherwise the interception CA held in the PKI store. A
     * placeholder certificate is never served — handing an operator a CA that
     * cannot validate intercepted sessions is worse than an honest error.
     */
    public function downloadCa(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings = $this->settingsModel->getSettings();
        $certPath = $settings['ca_cert_path'] ?? '/etc/squid/certs/micron_ca.pem';

        $certContent = '';
        if (is_file($certPath) && is_readable($certPath)) {
            $certContent = (string) file_get_contents($certPath);
        } else {
            $certContent = (string) (InterceptionCa::certificate() ?? '');
        }

        if (trim($certContent) === '' || ! X509::looksLikeCertificate($certContent)) {
            return $this->response
                ->setStatusCode(503)
                ->setHeader('Content-Type', 'text/plain')
                ->setBody('No SSL-Bump interception CA is available yet. Generate one from the SSL / TLS Deep Inspection tab.');
        }

        return $this->response
            ->setHeader('Content-Type', 'application/x-x509-ca-cert')
            ->setHeader('Content-Disposition', 'attachment; filename="micronos-proxy-ca.crt"')
            ->setBody($certContent);
    }

    /**
     * Generate the SSL-Bump interception CA (if needed) and install it at the
     * configured Squid paths.
     */
    public function provisionCa(): \CodeIgniter\HTTP\RedirectResponse
    {
        $settings = $this->settingsModel->getSettings();
        $certPath = (string) ($settings['ca_cert_path'] ?? '/etc/squid/certs/micron_ca.pem');
        $keyPath  = (string) ($settings['ca_key_path'] ?? '/etc/squid/certs/micron_ca.key');

        $ca = InterceptionCa::ensure();
        if (! $ca) {
            return redirect()->to(base_url('webfilter?tab=ssl'))
                ->with('error', 'Could not generate the interception CA — check that the OpenSSL extension is available.');
        }

        $result = InterceptionCa::provision($certPath, $keyPath);
        $this->audit('webfilter.ca.provision', 'squid', 'Provisioned SSL-Bump interception CA (' . InterceptionCa::COMMON_NAME . ')');

        if ($result['cert_written'] && $result['key_written']) {
            return redirect()->to(base_url('webfilter?tab=ssl'))
                ->with('success', 'Interception CA ready and installed at ' . $certPath . '.');
        }

        $detail = $result['error'] ?: 'the CA is generated in the PKI store but the files could not be written.';

        return redirect()->to(base_url('webfilter?tab=ssl'))
            ->with('success', 'Interception CA generated. It must be installed on the appliance by micrond — ' . $detail);
    }

    /**
     * Interactive PAC rule simulation & evaluation test
     */
    public function testWpad(): \CodeIgniter\HTTP\ResponseInterface
    {
        $testUrl = (string) $this->request->getPost('test_url');
        $settings = $this->settingsModel->getSettings();
        $result = $this->wpadGen->simulate($testUrl, $settings);

        if ($this->request->isAJAX() || $this->request->getHeaderLine('Accept') === 'application/json') {
            return $this->response->setJSON($result);
        }

        if (!$result['success']) {
            return redirect()->to(base_url('webfilter?tab=wpad'))->with('error', $result['error'] ?? 'Evaluation failed.');
        }

        return redirect()->to(base_url('webfilter?tab=wpad'))
            ->with('sim_url', $testUrl)
            ->with('sim_rule', $result['matched_rule'])
            ->with('sim_decision', $result['decision'])
            ->with('sim_desc', $result['description']);
    }

    /**
     * Save General Proxy Settings
     */
    public function saveGeneral(): \CodeIgniter\HTTP\RedirectResponse
    {
        $httpPort   = (int) $this->request->getPost('http_port');
        $httpsPort  = (int) $this->request->getPost('https_port');
        $sslPort    = (int) $this->request->getPost('ssl_port');

        if ($httpPort <= 0 || $httpPort > 65535) $httpPort = 3128;
        if ($httpsPort <= 0 || $httpsPort > 65535) $httpsPort = 3129;
        if ($sslPort <= 0 || $sslPort > 65535) $sslPort = 3130;

        $interfaces = (array) ($this->request->getPost('interfaces') ?? ['lan']);

        $data = [
            'enabled'            => $this->request->getPost('enabled') ? 1 : 0,
            'mode'               => (string) $this->request->getPost('mode') ?: 'transparent_sni',
            'http_port'          => $httpPort,
            'https_port'         => $httpsPort,
            'ssl_port'           => $sslPort,
            'interfaces'         => $interfaces,
            'ipv6_enabled'       => $this->request->getPost('ipv6_enabled') ? 1 : 0,
            'visible_hostname'   => trim((string) $this->request->getPost('visible_hostname')) ?: 'micronos-proxy',
            'admin_email'        => trim((string) $this->request->getPost('admin_email')) ?: 'admin@micronos.local',
            'dns_nameservers'    => trim((string) $this->request->getPost('dns_nameservers')),
            'forwarded_for'      => (string) $this->request->getPost('forwarded_for') ?: 'on',
            'suppress_headers'   => $this->request->getPost('suppress_headers') ? 1 : 0,
            'log_client_ip'      => $this->request->getPost('log_client_ip') ? 1 : 0,
            'log_user_agent'     => $this->request->getPost('log_user_agent') ? 1 : 0,
            'log_query_terms'    => $this->request->getPost('log_query_terms') ? 1 : 0,
            'log_retention_days' => max(1, (int) $this->request->getPost('log_retention_days') ?: 30),
        ];

        $this->settingsModel->saveSettings($data);

        return redirect()->to(base_url('webfilter?tab=general'))->with('success', 'General proxy configuration saved successfully.');
    }

    /**
     * Save Access Control Lists (ACLs)
     */
    public function saveAcls(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = [
            'allowed_subnets'        => trim((string) $this->request->getPost('allowed_subnets')),
            'unrestricted_ips'       => trim((string) $this->request->getPost('unrestricted_ips')),
            'banned_ips'             => trim((string) $this->request->getPost('banned_ips')),
            'banned_user_agents'     => trim((string) $this->request->getPost('banned_user_agents')),
            'banned_extensions'      => trim((string) $this->request->getPost('banned_extensions')),
            'banned_mime_types'      => trim((string) $this->request->getPost('banned_mime_types')),
            'max_upload_size_mb'     => max(1, (int) $this->request->getPost('max_upload_size_mb') ?: 50),
            'max_download_size_mb'   => max(1, (int) $this->request->getPost('max_download_size_mb') ?: 2048),
            'max_client_connections' => max(10, (int) $this->request->getPost('max_client_connections') ?: 150),
        ];

        $this->settingsModel->saveSettings($data);

        return redirect()->to(base_url('webfilter?tab=acls'))->with('success', 'Access Control Lists (ACLs) updated successfully.');
    }

    /**
     * Save Content Filtering, Domain Whitelist/Blacklist & SafeSearch
     */
    public function saveFiltering(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = [
            'custom_whitelist'        => trim((string) $this->request->getPost('custom_whitelist')),
            'custom_blacklist'        => trim((string) $this->request->getPost('custom_blacklist')),
            'custom_regex_blacklist'  => trim((string) $this->request->getPost('custom_regex_blacklist')),
            'safesearch_enabled'      => $this->request->getPost('safesearch_enabled') ? 1 : 0,
            'youtube_restricted_mode' => (string) $this->request->getPost('youtube_restricted_mode') ?: 'moderate',
            'block_page_title'        => trim((string) $this->request->getPost('block_page_title')) ?: 'Access Restricted by Corporate Policy',
            'block_page_message'      => trim((string) $this->request->getPost('block_page_message')),
            'block_page_redirect_url' => trim((string) $this->request->getPost('block_page_redirect_url')),
        ];

        $this->settingsModel->saveSettings($data);

        return redirect()->to(base_url('webfilter?tab=filtering'))->with('success', 'Content filtering and domain rules updated successfully.');
    }

    /**
     * Quick toggle category policy action (Block / Allow / Monitor)
     */
    public function toggleCategory(string $id): \CodeIgniter\HTTP\ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        $newAction = $this->categoryModel->toggleAction($id);

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'success' => $newAction !== null,
                'action'  => $newAction,
            ]);
        }

        return redirect()->to(base_url('webfilter?tab=filtering'))->with('success', 'Category enforcement updated to ' . strtoupper((string) $newAction));
    }

    /**
     * Trigger synchronization of threat intelligence feeds
     */
    public function syncFeeds(): \CodeIgniter\HTTP\RedirectResponse
    {
        $count = $this->feedModel->syncAll();
        return redirect()->to(base_url('webfilter?tab=filtering'))->with('success', "Synchronized {$count} threat intelligence feeds successfully.");
    }

    /**
     * Save SSL / TLS Deep Inspection Settings
     */
    public function saveSsl(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = [
            'ssl_bump_enabled'    => $this->request->getPost('ssl_bump_enabled') ? 1 : 0,
            'ssl_inspection_mode' => (string) $this->request->getPost('ssl_inspection_mode') ?: 'transparent_sni',
            'ca_cert_path'        => trim((string) $this->request->getPost('ca_cert_path')) ?: '/etc/squid/certs/micron_ca.pem',
            'ca_key_path'         => trim((string) $this->request->getPost('ca_key_path')) ?: '/etc/squid/certs/micron_ca.key',
            'ssl_bypass_domains'  => trim((string) $this->request->getPost('ssl_bypass_domains')),
            'ssl_min_tls_version' => (string) $this->request->getPost('ssl_min_tls_version') ?: '1.2',
        ];

        $this->settingsModel->saveSettings($data);

        return redirect()->to(base_url('webfilter?tab=ssl'))->with('success', 'SSL/TLS deep inspection settings updated.');
    }

    /**
     * Save WPAD & PAC Auto-Discovery configuration (Nguvu Simplified)
     */
    public function saveWpad(): \CodeIgniter\HTTP\RedirectResponse
    {
        $wpadPort = (int) $this->request->getPost('wpad_proxy_port');
        if ($wpadPort <= 0 || $wpadPort > 65535) $wpadPort = 3128;

        $data = [
            'wpad_enabled'              => $this->request->getPost('wpad_enabled') ? 1 : 0,
            'wpad_proxy_host'           => trim((string) $this->request->getPost('wpad_proxy_host')),
            'wpad_proxy_port'           => $wpadPort,
            'wpad_failover_proxy'       => trim((string) $this->request->getPost('wpad_failover_proxy')),
            'wpad_fallback_policy'      => (string) $this->request->getPost('wpad_fallback_policy') ?: 'DIRECT',
            'wpad_bypass_plain_hosts'   => $this->request->getPost('wpad_bypass_plain_hosts') ? 1 : 0,
            'wpad_bypass_rfc1918'       => $this->request->getPost('wpad_bypass_rfc1918') ? 1 : 0,
            'wpad_bypass_networks'      => trim((string) $this->request->getPost('wpad_bypass_networks')),
            'wpad_bypass_domains'       => trim((string) $this->request->getPost('wpad_bypass_domains')),
            'wpad_dhcp_option252'       => $this->request->getPost('wpad_dhcp_option252') ? 1 : 0,
            'wpad_dns_override'         => $this->request->getPost('wpad_dns_override') ? 1 : 0,
            'wpad_anti_bypass_firewall' => $this->request->getPost('wpad_anti_bypass_firewall') ? 1 : 0,
        ];

        $this->settingsModel->saveSettings($data);

        return redirect()->to(base_url('webfilter?tab=wpad'))->with('success', 'WPAD & PAC auto-discovery settings and anti-bypass policies saved.');
    }

    /**
     * Save Cache & Storage Engine settings
     */
    public function saveCache(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = [
            'cache_enabled'            => $this->request->getPost('cache_enabled') ? 1 : 0,
            'cache_mem_mb'             => max(32, (int) $this->request->getPost('cache_mem_mb') ?: 256),
            'cache_size_mb'            => max(128, (int) $this->request->getPost('cache_size_mb') ?: 4096),
            'cache_max_obj_kb'         => max(4, (int) $this->request->getPost('cache_max_obj_kb') ?: 4096),
            'cache_replacement_policy' => (string) $this->request->getPost('cache_replacement_policy') ?: 'heap LFUDA',
            'cache_ignore_queries'     => $this->request->getPost('cache_ignore_queries') ? 1 : 0,
            'cache_refresh_enabled'         => $this->request->getPost('cache_refresh_enabled') ? 1 : 0,
            'cache_refresh_min_minutes'     => max(0, (int) ($this->request->getPost('cache_refresh_min_minutes') ?? 0)),
            'cache_refresh_percent'         => min(100, max(0, (int) ($this->request->getPost('cache_refresh_percent') ?? 20))),
            'cache_refresh_max_minutes'     => max(0, (int) ($this->request->getPost('cache_refresh_max_minutes') ?? 4320)),
            'cache_refresh_ignore_reload'   => $this->request->getPost('cache_refresh_ignore_reload') ? 1 : 0,
            'cache_refresh_ignore_no_store' => $this->request->getPost('cache_refresh_ignore_no_store') ? 1 : 0,
            'cache_refresh_ignore_private'  => $this->request->getPost('cache_refresh_ignore_private') ? 1 : 0,
            'cache_refresh_reload_into_ims' => $this->request->getPost('cache_refresh_reload_into_ims') ? 1 : 0,
            'cache_refresh_custom'          => trim((string) $this->request->getPost('cache_refresh_custom')),
            'cache_update_preset_enabled'   => $this->request->getPost('cache_update_preset_enabled') ? 1 : 0,
            'cache_do_not_cache'            => trim((string) $this->request->getPost('cache_do_not_cache')),
        ];

        $this->settingsModel->saveSettings($data);

        return redirect()->to(base_url('webfilter?tab=cache'))->with('success', 'Cache and storage engine parameters saved.');
    }

    /**
     * Purge and rebuild proxy cache storage
     */
    public function clearCache(): \CodeIgniter\HTTP\RedirectResponse
    {
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('webfilter.cache.clear'),
            'Proxy cache storage purged and rebuilt successfully.',
            'Cache purge'
        );

        if ($key === 'error') {
            $this->audit('webfilter.cache.clear.failed', 'squid', $message, 'alert', 'purge requested');
        }

        return redirect()->to(base_url('webfilter?tab=cache'))->with($key, $message);
    }

    /**
     * 1-Click inline Whitelist or Blacklist action from Access Logs
     */
    public function quickAction(): \CodeIgniter\HTTP\ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        $domain = strtolower(trim((string) $this->request->getPost('domain')));
        $action = strtolower(trim((string) $this->request->getPost('action')));

        if (empty($domain)) {
            return redirect()->to(base_url('webfilter?tab=logs'))->with('error', 'Invalid domain specified.');
        }

        $settings = $this->settingsModel->getSettings();

        if ($action === 'whitelist') {
            $existing = trim($settings['custom_whitelist'] ?? '');
            if (!str_contains($existing, $domain)) {
                $updated = ($existing !== '') ? ($existing . "\n" . $domain) : $domain;
                $this->settingsModel->saveSettings(['custom_whitelist' => $updated]);
            }
            $msg = "Domain '{$domain}' added to Whitelist.";
        } elseif ($action === 'blacklist') {
            $existing = trim($settings['custom_blacklist'] ?? '');
            if (!str_contains($existing, $domain)) {
                $updated = ($existing !== '') ? ($existing . "\n" . $domain) : $domain;
                $this->settingsModel->saveSettings(['custom_blacklist' => $updated]);
            }
            $msg = "Domain '{$domain}' added to Blacklist.";
        } else {
            return redirect()->to(base_url('webfilter?tab=logs'))->with('error', 'Unknown action.');
        }

        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['success' => true, 'message' => $msg]);
        }

        return redirect()->to(base_url('webfilter?tab=logs'))->with('success', $msg);
    }

    /**
     * Clear all access log events
     */
    public function clearLogs(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->logModel->clearLogs();
        return redirect()->to(base_url('webfilter?tab=logs'))->with('success', 'Access logs cleared successfully.');
    }

    /**
     * Live Squid access-log tail for the Real-Time monitor (JSON).
     *
     * The browser polls this; `available` is false (with an empty list) when
     * the daemon is unreachable, so the UI can say so instead of rendering
     * fabricated traffic.
     */
    public function logsData(): \CodeIgniter\HTTP\ResponseInterface
    {
        $limit = max(1, min(200, (int) ($this->request->getGet('limit') ?? 40)));

        $res    = $this->ipc->call('webfilter.access_log', ['limit' => $limit])['result'] ?? null;
        $stream = is_array($res) && isset($res['entries']) && is_array($res['entries']);

        return $this->response->setJSON([
            'available' => $stream,
            'entries'   => $stream ? $res['entries'] : [],
        ]);
    }

    /**
     * Apply & Reload proxy daemon configuration
     */
    public function reload(): \CodeIgniter\HTTP\RedirectResponse
    {
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('webfilter.reload'),
            'Web Proxy & Content Filter rules reloaded and active.',
            'Reload'
        );

        if ($key === 'error') {
            $this->audit('webfilter.reload.failed', 'squid', $message, 'alert', 'reload requested');
        }

        return redirect()->to(base_url('webfilter'))->with($key, $message);
    }

    /**
     * Save C-ICAP / ClamAV antivirus inspection settings.
     */
    public function saveAntivirus(): \CodeIgniter\HTTP\RedirectResponse
    {
        $port = (int) $this->request->getPost('icap_port');
        if ($port <= 0 || $port > 65535) {
            $port = 1344;
        }

        $scanMode = (string) $this->request->getPost('icap_scan_mode');
        if (! in_array($scanMode, ['request', 'response', 'both'], true)) {
            $scanMode = 'response';
        }

        $failureMode = (string) $this->request->getPost('icap_failure_mode');
        if (! in_array($failureMode, ['allow', 'block'], true)) {
            $failureMode = 'allow';
        }

        $schedule = (string) $this->request->getPost('clamav_update_schedule');
        if (! in_array($schedule, ['hourly', 'daily', 'weekly'], true)) {
            $schedule = 'daily';
        }

        $data = [
            'icap_antivirus_enabled' => $this->request->getPost('icap_antivirus_enabled') ? 1 : 0,
            'icap_host'              => trim((string) $this->request->getPost('icap_host')) ?: '127.0.0.1',
            'icap_port'              => $port,
            'icap_service'           => trim((string) $this->request->getPost('icap_service')) ?: 'squidclamav',
            'icap_scan_mode'         => $scanMode,
            'icap_preview_kb'        => max(1, (int) $this->request->getPost('icap_preview_kb') ?: 1024),
            'icap_max_file_mb'       => max(1, (int) $this->request->getPost('icap_max_file_mb') ?: 25),
            'icap_failure_mode'      => $failureMode,
            'icap_scan_https'        => $this->request->getPost('icap_scan_https') ? 1 : 0,
            'icap_bypass_domains'    => trim((string) $this->request->getPost('icap_bypass_domains')),
            'clamav_update_schedule' => $schedule,
        ];

        $this->settingsModel->saveSettings($data);

        // ICAP/ClamAV is enforced by Squid, so the new inspection policy only
        // takes effect once the proxy reloads. Surface a daemon failure instead
        // of claiming success, and keep a persistent record of it.
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('webfilter.reload'),
            'Antivirus (C-ICAP + ClamAV) inspection settings saved.',
            'Saved, but the proxy reload'
        );

        if ($key === 'error') {
            $this->audit('webfilter.antivirus.save.failed', 'squid', $message, 'alert', 'antivirus settings save requested');
        } else {
            $this->audit('webfilter.antivirus.save', 'squid', 'Updated C-ICAP/ClamAV antivirus inspection settings');
        }

        return redirect()->to(base_url('webfilter?tab=antivirus'))->with($key, $message);
    }

    /**
     * Trigger a ClamAV signature (freshclam) update through the IPC bridge.
     */
    public function updateSignatures(): \CodeIgniter\HTTP\ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        $response = $this->ipc->call('webfilter.clamav.update');
        $outcome  = $this->ipcOutcome($response);
        $res      = is_array($response['result'] ?? null) ? $response['result'] : [];
        $ok       = $outcome['ok'];

        $update = [
            'clamav_last_update_at'     => date('Y-m-d H:i:s'),
            'clamav_signatures'         => (int) ($res['signatures'] ?? 0),
            'clamav_defs_version'       => (string) ($res['defs_version'] ?? ''),
            'clamav_last_update_status' => $ok
                ? 'Definitions updated to ' . ($res['defs_version'] ?? 'latest') . ' (simulated)'
                : 'Update failed: ' . $outcome['reason'],
        ];
        $this->settingsModel->saveSettings($update);
        $this->audit('webfilter.antivirus.update', 'clamav', $update['clamav_last_update_status'], $ok ? 'security' : 'alert');

        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['success' => $ok] + $update);
        }

        return redirect()->to(base_url('webfilter?tab=antivirus'))
            ->with($ok ? 'success' : 'error', $update['clamav_last_update_status']);
    }

    /**
     * Clear the malware detection log.
     */
    public function clearDetections(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->avModel->clearAll();
        $this->audit('webfilter.antivirus.clear', 'detections', 'Cleared C-ICAP/ClamAV malware detection log');

        return redirect()->to(base_url('webfilter?tab=antivirus'))->with('success', 'Antivirus detection log cleared.');
    }

    /**
     * Backward-compatible policy save
     */
    public function savePolicy(): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->saveFiltering();
    }

    /**
     * Writes a Web Filter security event to the audit trail (best-effort).
     */
    private function audit(string $action, string $target, string $desc, string $actionType = 'security', ?string $oldState = null): void
    {
        try {
            $db = \Config\Database::connect();
            if ($db->tableExists('audit_logs')) {
                $user = session('username') ?? 'admin';
                $ip   = $this->request->getIPAddress();

                $db->table('audit_logs')->insert([
                    'admin_user'      => $user,
                    'username'        => $user,
                    'user_id'         => session('user_id') ?? 'system',
                    'client_ip'       => $ip,
                    'ip_address'      => $ip,
                    'action_type'     => $actionType,
                    'action'          => $action,
                    'target_resource' => 'WebFilter',
                    'target'          => $target,
                    'old_state'       => $oldState,
                    'new_state'       => $desc,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
}
