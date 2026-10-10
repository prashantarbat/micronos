<?php

namespace App\Controllers;

use App\Libraries\ConfigRevisionEngine;
use App\Libraries\InterceptionCa;
use App\Libraries\IpcClient;
use App\Models\DpiCategoryModel;
use App\Models\SslBypassDomainModel;
use App\Models\SslInspectionProfileModel;
use App\Models\SslMitmSettingsModel;

/**
 * SslInspection Controller — Full SSL/TLS Deep Packet Inspection & MITM CA Engine
 * Manages certificate authorities, dynamic synthetic certificate forging,
 * SSL-Bump profiles, and domain bypass lists.
 */
class SslInspection extends BaseController
{
    protected IpcClient $ipc;
    protected SslInspectionProfileModel $profileModel;
    protected SslBypassDomainModel $bypassModel;
    protected SslMitmSettingsModel $settingsModel;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->profileModel  = new SslInspectionProfileModel();
        $this->bypassModel   = new SslBypassDomainModel();
        $this->settingsModel = new SslMitmSettingsModel();
        $this->revEngine     = new ConfigRevisionEngine();
    }

    public function index()
    {
        // 1. Ensure Interception CA exists
        $caData = InterceptionCa::ensure();

        // 2. Query Daemon Status
        $ipcStatus = $this->ipc->call('ssl.ca.status')['result'] ?? [
            'status'       => 'active',
            'common_name'  => InterceptionCa::COMMON_NAME,
            'key_type'     => 'RSA 4096-bit',
            'cached_certs' => 0,
        ];

        // 3. Profiles, Bypasses, Settings
        $profiles = $this->profileModel->orderBy('is_default', 'DESC')->orderBy('name', 'ASC')->findAll();
        $bypasses = $this->bypassModel->orderBy('category', 'ASC')->orderBy('domain_pattern', 'ASC')->findAll();
        $settings = $this->settingsModel->getSettings();

        // 4. Categories for exclusion pickers
        $catModel = new DpiCategoryModel();
        $categories = $catModel->orderBy('name', 'ASC')->findAll();

        // 5. Active intercepted sessions
        $sessionsRes = $this->ipc->call('ssl.sessions.active');
        $activeSessions = $sessionsRes['result']['sessions'] ?? [];

        return view('ssl_inspection/index', [
            'caData'         => $caData,
            'ipcStatus'      => $ipcStatus,
            'profiles'       => $profiles,
            'bypasses'       => $bypasses,
            'settings'       => $settings,
            'categories'     => $categories,
            'activeSessions' => $activeSessions,
            'title'          => 'Full SSL/TLS Deep Packet Inspection & MITM CA Engine',
        ]);
    }

    /**
     * Save or update an SSL inspection profile.
     */
    public function saveProfile()
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Profile name is required.']);
        }

        $exemptCategories = $this->request->getPost('exempt_categories');

        $data = [
            'name'                    => $name,
            'description'             => trim((string) $this->request->getPost('description')),
            'mode'                    => $this->request->getPost('mode') ?: 'deep_inspection',
            'min_tls_version'         => $this->request->getPost('min_tls_version') ?: '1.2',
            'allow_invalid_certs'     => (int) ($this->request->getPost('allow_invalid_certs') ?? 0),
            'block_untrusted_issuers' => (int) ($this->request->getPost('block_untrusted_issuers') ?? 1),
            'exempt_categories'       => is_array($exemptCategories) ? json_encode(array_values($exemptCategories)) : json_encode([]),
            'exempt_domains'          => trim((string) $this->request->getPost('exempt_domains')),
            'log_inspected_sessions'  => (int) ($this->request->getPost('log_inspected_sessions') ?? 1),
            'is_default'              => (int) ($this->request->getPost('is_default') ?? 0),
        ];

        if (!empty($data['is_default'])) {
            // Unset previous defaults
            $this->profileModel->where('is_default', 1)->set(['is_default' => 0])->update();
        }

        if (!empty($id)) {
            $data['id'] = $id;
            $this->profileModel->update($id, $data);
            $actionWord = 'Updated';
        } else {
            $data['id'] = 'prof_' . bin2hex(random_bytes(4));
            $this->profileModel->insert($data);
            $actionWord = 'Created';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$actionWord} SSL inspection profile '{$name}' (Mode: {$data['mode']})",
            'ssl'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Profile '{$name}' saved successfully.",
        ]);
    }

    /**
     * Delete an SSL inspection profile.
     */
    public function deleteProfile(string $id)
    {
        $profile = $this->profileModel->find($id);
        if (!$profile) {
            return $this->response->setJSON(['success' => false, 'message' => 'Profile not found.']);
        }

        if (!empty($profile['is_default'])) {
            return $this->response->setJSON(['success' => false, 'message' => 'Cannot delete the default active profile.']);
        }

        $this->profileModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted SSL inspection profile '{$profile['name']}'",
            'ssl'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Profile '{$profile['name']}' deleted.",
        ]);
    }

    /**
     * Save or update SSL bypass domain pattern.
     */
    public function saveBypassDomain()
    {
        $id = $this->request->getPost('id');
        $domainPattern = strtolower(trim((string) $this->request->getPost('domain_pattern')));

        if ($domainPattern === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Domain pattern is required.']);
        }

        $data = [
            'domain_pattern' => $domainPattern,
            'reason'         => trim((string) $this->request->getPost('reason')),
            'category'       => $this->request->getPost('category') ?: 'pinned_app',
            'enabled'        => (int) ($this->request->getPost('enabled') ?? 1),
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->bypassModel->update($id, $data);
            $actionWord = 'Updated';
        } else {
            $data['id'] = 'byp_' . bin2hex(random_bytes(4));
            $this->bypassModel->insert($data);
            $actionWord = 'Added';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$actionWord} SSL bypass domain '{$domainPattern}'",
            'ssl'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Bypass domain '{$domainPattern}' saved.",
        ]);
    }

    /**
     * Delete an SSL bypass domain.
     */
    public function deleteBypassDomain(string $id)
    {
        $bypass = $this->bypassModel->find($id);
        if (!$bypass) {
            return $this->response->setJSON(['success' => false, 'message' => 'Bypass entry not found.']);
        }

        $this->bypassModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted SSL bypass domain '{$bypass['domain_pattern']}'",
            'ssl'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Bypass domain deleted.",
        ]);
    }

    /**
     * Save global MITM settings.
     */
    public function saveSettings()
    {
        $data = [
            'id'                      => 1,
            'enabled'                 => (int) ($this->request->getPost('enabled') ?? 1),
            'active_profile_id'       => $this->request->getPost('active_profile_id') ?: 'prof_deep_inspection',
            'dynamic_cert_cache_size' => max(1000, (int) ($this->request->getPost('dynamic_cert_cache_size') ?: 10000)),
            'cert_validity_days'      => max(30, (int) ($this->request->getPost('cert_validity_days') ?: 365)),
            'generate_missing_san'    => (int) ($this->request->getPost('generate_missing_san') ?? 1),
            'updated_at'              => date('Y-m-d H:i:s'),
        ];

        $this->settingsModel->replace($data);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Updated MITM SSL Inspection settings (Profile: {$data['active_profile_id']})",
            'ssl'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => 'SSL Inspection settings updated.',
        ]);
    }

    /**
     * Dynamic Synthetic Certificate Forger & Simulator
     */
    public function simulateMitmCert()
    {
        $domain = trim((string) $this->request->getPost('domain'));
        if ($domain === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Target domain is required.']);
        }

        $forged = InterceptionCa::forgeCertificate($domain);
        if (!$forged) {
            return $this->response->setJSON(['success' => false, 'message' => 'Failed to forge synthetic certificate via OpenSSL.']);
        }

        return $this->response->setJSON([
            'success'     => true,
            'certificate' => $forged,
        ]);
    }

    /**
     * Download Appliance Root CA in multiple distribution formats.
     */
    public function downloadCaCert(string $format = 'pem')
    {
        $pem = InterceptionCa::certificate();
        if (!$pem) {
            return $this->response->setStatusCode(404)->setBody('Root CA certificate not generated.');
        }

        if ($format === 'der' || $format === 'cer' || $format === 'crt') {
            $der = InterceptionCa::derCertificate();
            return $this->response
                ->setHeader('Content-Type', 'application/x-x509-ca-cert')
                ->setHeader('Content-Disposition', 'attachment; filename="micron-os-root-ca.cer"')
                ->setBody($der ?? $pem);
        }

        if ($format === 'mobileconfig') {
            $xml = InterceptionCa::mobileConfig();
            return $this->response
                ->setHeader('Content-Type', 'application/x-apple-aspen-config')
                ->setHeader('Content-Disposition', 'attachment; filename="micron-os-ca.mobileconfig"')
                ->setBody($xml ?? '');
        }

        // Default PEM
        return $this->response
            ->setHeader('Content-Type', 'application/x-pem-file')
            ->setHeader('Content-Disposition', 'attachment; filename="micron-os-root-ca.pem"')
            ->setBody($pem);
    }

    /**
     * Apply SSL-Bump rules and reload interception daemon.
     */
    public function apply()
    {
        $res = $this->ipc->call('ssl.apply');

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Applied SSL/TLS Deep Packet Inspection profiles and reloaded SSL-Bump engine',
            'ssl'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => 'SSL/TLS Deep Packet Inspection engine updated and active.',
            'ipc'     => $res['result'] ?? null,
        ]);
    }

    /**
     * Active sessions telemetry JSON endpoint.
     */
    public function activeSessions()
    {
        $res = $this->ipc->call('ssl.sessions.active');
        return $this->response->setJSON([
            'success'  => true,
            'sessions' => $res['result']['sessions'] ?? [],
        ]);
    }
}
