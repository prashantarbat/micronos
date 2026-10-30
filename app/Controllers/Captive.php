<?php

namespace App\Controllers;

use App\Libraries\CaptiveCompiler;
use App\Libraries\ConfigRevisionEngine;
use App\Libraries\IpcClient;
use App\Libraries\VoucherEngine;
use App\Models\CaptiveCustomizationModel;
use App\Models\CaptiveSessionModel;
use App\Models\CaptiveVoucherModel;
use App\Models\CaptiveWalledGardenModel;
use App\Models\CaptiveZoneModel;
use App\Models\InterfaceModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Captive Controller — Management Plane for Captive Portal, Hotspot Vouchers,
 * Walled Gardens, Custom Branding, and Guest Session Accounting.
 */
class Captive extends BaseController
{
    protected CaptiveZoneModel $zoneModel;
    protected CaptiveVoucherModel $voucherModel;
    protected CaptiveSessionModel $sessionModel;
    protected CaptiveWalledGardenModel $walledGardenModel;
    protected CaptiveCustomizationModel $customModel;
    protected InterfaceModel $interfaceModel;
    protected CaptiveCompiler $compiler;
    protected VoucherEngine $voucherEngine;
    protected IpcClient $ipcClient;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->zoneModel         = new CaptiveZoneModel();
        $this->voucherModel      = new CaptiveVoucherModel();
        $this->sessionModel      = new CaptiveSessionModel();
        $this->walledGardenModel = new CaptiveWalledGardenModel();
        $this->customModel       = new CaptiveCustomizationModel();
        $this->interfaceModel    = new InterfaceModel();
        $this->compiler          = new CaptiveCompiler($this->zoneModel, $this->walledGardenModel);
        $this->voucherEngine     = new VoucherEngine($this->voucherModel, $this->sessionModel, $this->zoneModel);
        $this->ipcClient         = new IpcClient();
        $this->revEngine         = new ConfigRevisionEngine();
    }

    /**
     * Captive Zones Overview & Zone Matrix.
     */
    public function index(): string
    {
        $zones      = $this->zoneModel->orderBy('name', 'ASC')->findAll();
        $db = \Config\Database::connect();
        $unifiedInterfaces = \App\Libraries\VpnInterfaceProvider::getUnifiedInterfaces($db);
        $interfaces = array_map(static function ($if) {
            $name = $if['name'] ?? ($if['id'] ?? '');
            $dev  = $if['physical_device'] ?? '';
            $if['label'] = ($dev !== '' && $dev !== $name) ? "{$name} ({$dev})" : $name;
            return $if;
        }, $unifiedInterfaces);
        $sessions   = $this->sessionModel->getActiveSessions();
        $rolls      = $this->voucherModel->getRollSummaries();

        // Calculate statistics
        $stats = [
            'total_zones'     => count($zones),
            'active_zones'    => count(array_filter($zones, static fn ($z) => !empty($z['enabled']))),
            'active_sessions' => count($sessions),
            'total_rolls'     => count($rolls),
        ];

        return view('captive/index', [
            'title'          => 'Captive Portal & Guest Hotspot — Micron OS',
            'zones'          => array_map(fn ($z) => $this->normalizeZone($z), $zones),
            'interfaces'     => $interfaces,
            'stats'          => $stats,
            'sessions'       => $sessions,
            'rolls'          => $rolls,
            'activeSessions' => count($sessions),
            'totalVouchers'  => $this->voucherModel->where('status', 'active')->countAllResults(),
        ]);
    }

    /**
     * Create or update a captive zone.
     */
    public function saveZone(): RedirectResponse
    {
        $id                  = $this->request->getPost('id');
        $name                = trim((string) $this->request->getPost('name'));
        // Form posts `interface`; API clients may post `interface_id`.
        $interfaceId         = trim((string) ($this->request->getPost('interface_id') ?? $this->request->getPost('interface')));
        $authMode            = trim((string) $this->request->getPost('auth_mode')) ?: 'clickthrough';
        $sessionTimeout      = (int) $this->request->getPost('session_timeout') ?: 60;
        $idleTimeout         = (int) $this->request->getPost('idle_timeout') ?: 900;
        $defaultRedirectUrl  = trim((string) $this->request->getPost('default_redirect_url')) ?: null;
        $enabled             = $this->request->getPost('enabled') ? 1 : 0;
        $httpPort            = (int) $this->request->getPost('http_port') ?: 8088;
        $httpsPort           = (int) $this->request->getPost('https_port') ?: 8443;

        if (!in_array($authMode, ['clickthrough', 'voucher'], true)) {
            $authMode = 'clickthrough';
        }
        if ($httpPort < 1 || $httpPort > 65535 || $httpsPort < 1 || $httpsPort > 65535) {
            return redirect()->back()->with('error', 'Portal ports must be between 1 and 65535.');
        }

        if (empty($name) || empty($interfaceId)) {
            return redirect()->back()->with('error', 'Zone Name and Bound Interface are mandatory fields.');
        }

        $slugSource = trim((string) $this->request->getPost('slug')) ?: $name;
        $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower($slugSource));
        $slug = trim((string) $slug, '-');

        $data = [
            'name'                 => $name,
            'slug'                 => $slug,
            'interface_id'         => $interfaceId,
            'auth_mode'            => $authMode,
            'portal_port'          => $httpPort,
            'portal_ssl_port'      => $httpsPort,
            'session_timeout'      => $sessionTimeout,
            'idle_timeout'         => $idleTimeout,
            'default_redirect_url' => $defaultRedirectUrl,
            'enabled'              => $enabled,
        ];

        if (!empty($id)) {
            $this->zoneModel->update($id, $data);
            $msg = "Captive zone '{$name}' updated.";
        } else {
            $data['id'] = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $this->zoneModel->insert($data);

            // Initialize default branding customization for new zone
            $this->customModel->insert([
                'zone_id'         => $data['id'],
                'venue_title'     => $name . ' Hotspot',
                'welcome_message' => 'Welcome to our guest network. Please authenticate below.',
                'terms_text'      => 'By continuing, you agree to our Acceptable Use Policy.',
                'primary_color'   => '#4f46e5',
                'bg_color'        => '#0f172a',
            ]);

            $msg = "Captive zone '{$name}' created.";
        }

        $this->revEngine->commit("Captive: {$msg}", (string) session('username', 'admin'));
        return redirect()->to(base_url('services/captive'))->with('success', $msg);
    }

    /**
     * Delete captive zone and associated vouchers/sessions.
     */
    public function deleteZone(string $id): RedirectResponse
    {
        $zone = $this->zoneModel->find($id);
        if (!$zone) {
            return redirect()->back()->with('error', 'Captive zone not found.');
        }

        $this->zoneModel->delete($id);
        $this->customModel->where('zone_id', $id)->delete();
        $this->walledGardenModel->where('zone_id', $id)->delete();
        $this->voucherModel->where('zone_id', $id)->delete();
        $this->sessionModel->where('zone_id', $id)->delete();

        $this->revEngine->commit("Captive: Deleted zone '{$zone['name']}'", (string) session('username', 'admin'));
        return redirect()->to(base_url('services/captive'))->with('success', "Captive zone '{$zone['name']}' deleted.");
    }

    /**
     * Enable / disable a captive zone. Rules take effect on next Apply.
     */
    public function toggleZone(string $id): RedirectResponse
    {
        $zone = $this->zoneModel->find($id);
        if (!$zone) {
            return redirect()->back()->with('error', 'Captive zone not found.');
        }

        $enabled = empty($zone['enabled']) ? 1 : 0;
        $this->zoneModel->update($id, ['enabled' => $enabled]);

        $state = $enabled ? 'enabled' : 'disabled';
        $this->revEngine->commit("Captive: Zone '{$zone['name']}' {$state}", (string) session('username', 'admin'));
        return redirect()->to(base_url('services/captive'))->with('success', "Captive zone '{$zone['name']}' {$state}. Click Apply to push rules.");
    }

    /**
     * Compile and apply Netfilter captive portal configuration.
     */
    public function applyRuleset(): RedirectResponse
    {
        $nftablesRuleset = $this->compiler->compile();
        $response        = $this->ipcClient->call('captive.apply', ['ruleset' => $nftablesRuleset]);

        if (!empty($response['success']) || !empty($response['mock'])) {
            return redirect()->to(base_url('services/captive'))->with('success', 'Captive Portal Netfilter rules applied and committed.');
        }

        return redirect()->to(base_url('services/captive'))->with('error', 'Failed to apply Captive rules: ' . ($response['error'] ?? 'Unknown daemon error'));
    }

    /**
     * Voucher Roll Management & Batch Generator.
     */
    public function vouchers(): string
    {
        $zones   = $this->zoneModel->where('enabled', 1)->findAll();
        $rolls   = $this->voucherModel->getRollSummaries();
        $recent  = $this->voucherModel->orderBy('id', 'DESC')->findAll(100);
        $names   = $this->zoneNameMap();

        return view('captive/vouchers', [
            'title'         => 'Hotspot Vouchers — Micron OS',
            'zones'         => $zones,
            'rolls'         => $rolls,
            'recent'        => $recent,
            'rollSummaries' => array_map(fn ($r) => $this->normalizeRoll($r, $names), $rolls),
            'vouchers'      => array_map(fn ($v) => $this->normalizeVoucher($v, $names), $recent),
        ]);
    }

    /**
     * Export all vouchers as CSV (for PMS / front-desk import).
     */
    public function exportCsv(): ResponseInterface
    {
        $names = $this->zoneNameMap();
        $rows  = $this->voucherModel->orderBy('id', 'DESC')->findAll();

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['code', 'roll_id', 'zone', 'status', 'duration_minutes', 'quota_mb', 'max_devices', 'used_count', 'first_used_at', 'expires_at', 'created_at']);
        foreach ($rows as $v) {
            $n = $this->normalizeVoucher($v, $names);
            fputcsv($fh, [
                $n['code'], $n['roll_id'], $n['zone_name'], $n['status'], $n['duration_minutes'],
                $n['data_quota_mb'], $n['max_devices'], $n['used_count'],
                $n['first_used_at'], $n['expires_at'], $n['created_at'],
            ]);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="micronos-vouchers-' . date('Ymd-His') . '.csv"')
            ->setBody($csv);
    }

    /**
     * Batch generate vouchers.
     */
    public function generateVouchers(): RedirectResponse
    {
        $zoneId             = trim((string) $this->request->getPost('zone_id'));
        $count              = (int) $this->request->getPost('count') ?: 10;
        $durationMinutes    = (int) $this->request->getPost('duration_minutes') ?: 60;
        $quotaMb            = (int) ($this->request->getPost('quota_mb') ?? $this->request->getPost('data_quota_mb')) ?: 0;
        $quotaBytes         = $quotaMb > 0 ? $quotaMb * 1024 * 1024 : 0;
        $bandwidthDownKbps  = (int) $this->request->getPost('bandwidth_down_kbps') ?: 0;
        $bandwidthUpKbps    = (int) $this->request->getPost('bandwidth_up_kbps') ?: 0;
        $maxDevices         = (int) $this->request->getPost('max_devices') ?: 1;
        $prefix             = trim((string) ($this->request->getPost('roll_prefix') ?? $this->request->getPost('roll_id'))) ?: 'HOTSPOT';

        $zone = $this->zoneModel->find($zoneId);
        if (!$zone) {
            return redirect()->back()->with('error', 'Select a valid captive zone.');
        }

        $result = $this->voucherEngine->generateRoll(
            $zoneId,
            $count,
            $durationMinutes,
            $quotaBytes,
            $bandwidthDownKbps,
            $bandwidthUpKbps,
            $maxDevices,
            $prefix
        );

        $msg = "Generated roll {$result['roll_id']} with {$result['count']} vouchers (Sample: {$result['sample_code']}).";
        $this->revEngine->commit("Captive: {$msg}", (string) session('username', 'admin'));

        return redirect()->to(base_url('services/captive/vouchers'))->with('success', $msg);
    }

    /**
     * Thermal / Sheet printable voucher cards.
     */
    public function printVouchers(string $rollId): string
    {
        $vouchers = $this->voucherModel->getByRoll($rollId);
        if (empty($vouchers)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound("Voucher roll '{$rollId}' not found.");
        }

        $zone          = $this->zoneModel->find($vouchers[0]['zone_id']);
        $customization = $this->customModel->getForZone($vouchers[0]['zone_id']);
        $names         = $this->zoneNameMap();

        return view('captive/print', [
            'rollId'        => $rollId,
            'vouchers'      => array_map(fn ($v) => $this->normalizeVoucher($v, $names), $vouchers),
            'zone'          => $zone,
            'zoneName'      => $customization['venue_title'] ?? ($zone['name'] ?? 'Guest Wi-Fi'),
            'customization' => $customization,
        ]);
    }

    /**
     * Revoke single voucher.
     */
    public function revokeVoucher(int $id): RedirectResponse
    {
        $voucher = $this->voucherModel->find($id);
        if (!$voucher) {
            return redirect()->back()->with('error', 'Voucher not found.');
        }

        $this->voucherModel->update($id, ['status' => 'revoked']);

        // Disconnect any active sessions using this voucher
        $sessions = $this->sessionModel->where('username', $voucher['code'])->where('status', 'authorized')->findAll();
        foreach ($sessions as $s) {
            $this->sessionModel->update($s['id'], ['status' => 'disconnected']);
            $this->ipcClient->call('captive.revoke', ['ip' => $s['ip_address'], 'mac' => $s['mac_address']]);
        }

        return redirect()->back()->with('success', "Voucher '{$voucher['code']}' revoked and active sessions disconnected.");
    }

    /**
     * Live Connected Guest Sessions.
     */
    public function sessions(): string
    {
        $sessions = $this->sessionModel->getActiveSessions();
        $names    = $this->zoneNameMap();

        return view('captive/sessions', [
            'title'    => 'Active Guest Sessions — Micron OS',
            'sessions' => array_map(fn ($s) => $this->normalizeSession($s, $names), $sessions),
        ]);
    }

    /**
     * Disconnect guest session.
     */
    public function disconnectSession(string $id): RedirectResponse
    {
        $session = $this->sessionModel->find($id);
        if (!$session) {
            return redirect()->back()->with('error', 'Session not found.');
        }

        $this->sessionModel->update($id, ['status' => 'disconnected']);
        $this->ipcClient->call('captive.revoke', [
            'ip'  => $session['ip_address'],
            'mac' => $session['mac_address'],
        ]);

        return redirect()->back()->with('success', "Guest session for {$session['ip_address']} ({$session['mac_address']}) disconnected.");
    }

    /**
     * Visual Branding Customizer.
     */
    public function branding(string $zoneId): string
    {
        $zone   = $this->zoneModel->find($zoneId);
        if (!$zone) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Captive zone not found.');
        }
        $custom = $this->customModel->getForZone($zoneId);
        $walled = $this->walledGardenModel->where('zone_id', $zoneId)->findAll();

        return view('captive/branding', [
            'title'  => "Portal Customizer: {$zone['name']} — Micron OS",
            'zone'   => $zone,
            'custom' => $custom,
            'walled' => $walled,
        ]);
    }

    /**
     * Save branding customization.
     */
    public function saveBranding(?string $routeZoneId = null): RedirectResponse
    {
        $zoneId         = trim((string) ($routeZoneId ?? $this->request->getPost('zone_id')));
        if (!$this->zoneModel->find($zoneId)) {
            return redirect()->back()->with('error', 'Captive zone not found.');
        }
        $venueTitle     = trim((string) $this->request->getPost('venue_title')) ?: 'Guest Network Access';
        $welcomeMessage = trim((string) $this->request->getPost('welcome_message'));
        $termsText      = trim((string) $this->request->getPost('terms_text'));
        $primaryColor   = trim((string) $this->request->getPost('primary_color')) ?: '#4f46e5';
        $bgColor        = trim((string) $this->request->getPost('bg_color')) ?: '#0f172a';
        $logoPath       = trim((string) $this->request->getPost('logo_path')) ?: null;
        // Prevent breaking out of the <style> block in the guest splash page.
        $customCss      = str_ireplace('</style', '', trim((string) $this->request->getPost('custom_css')));

        // Only accept #rrggbb colours — values are interpolated into CSS.
        $hex = '/^#[0-9a-fA-F]{6}$/';
        if (!preg_match($hex, $primaryColor)) {
            $primaryColor = '#4f46e5';
        }
        if (!preg_match($hex, $bgColor)) {
            $bgColor = '#0f172a';
        }

        $existing = $this->customModel->where('zone_id', $zoneId)->first();
        $payload  = [
            'zone_id'         => $zoneId,
            'venue_title'     => $venueTitle,
            'welcome_message' => $welcomeMessage,
            'terms_text'      => $termsText,
            'primary_color'   => $primaryColor,
            'bg_color'        => $bgColor,
            'logo_path'       => $logoPath,
            'custom_css'      => $customCss,
        ];

        if ($existing) {
            $this->customModel->update($existing['id'], $payload);
        } else {
            $this->customModel->insert($payload);
        }

        $this->revEngine->commit("Captive: Updated branding for zone {$zoneId}", (string) session('username', 'admin'));
        return redirect()->to(base_url("services/captive/branding/{$zoneId}"))->with('success', 'Portal branding saved.');
    }

    /**
     * Add Walled Garden entry.
     */
    public function addWalledGarden(): RedirectResponse
    {
        $zoneId      = trim((string) $this->request->getPost('zone_id'));
        $target      = trim((string) $this->request->getPost('target'));
        $description = trim((string) $this->request->getPost('description'));

        if (empty($zoneId) || empty($target)) {
            return redirect()->back()->with('error', 'Zone and Target host/IP are required.');
        }

        $this->walledGardenModel->insert([
            'zone_id'     => $zoneId,
            'target'      => $target,
            'description' => $description,
            'enabled'     => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url("services/captive/branding/{$zoneId}"))->with('success', "Walled garden entry '{$target}' added.");
    }

    /**
     * Delete Walled Garden entry.
     */
    public function deleteWalledGarden(int $id): RedirectResponse
    {
        $entry = $this->walledGardenModel->find($id);
        if ($entry) {
            $zoneId = $entry['zone_id'];
            $this->walledGardenModel->delete($id);
            return redirect()->to(base_url("services/captive/branding/{$zoneId}"))->with('success', 'Walled garden entry removed.');
        }

        return redirect()->back()->with('error', 'Entry not found.');
    }

    // ------------------------------------------------------------------
    // View adapters — map DB column names to the field names used by views
    // ------------------------------------------------------------------

    /** @return array<string,string> zone_id => name */
    protected function zoneNameMap(): array
    {
        return array_column($this->zoneModel->select('id, name')->findAll(), 'name', 'id');
    }

    protected function normalizeZone(array $z): array
    {
        $z['interface']            = $z['interface_id'] ?? '';
        $z['http_port']            = (int) ($z['portal_port'] ?? 8088);
        $z['https_port']           = (int) ($z['portal_ssl_port'] ?? 8443);
        $z['rate_limit_up_kbps']   = (int) ($z['rate_limit_up_kbps'] ?? 0);
        $z['rate_limit_down_kbps'] = (int) ($z['rate_limit_down_kbps'] ?? 0);
        $z['concurrent_devices']   = (int) ($z['concurrent_devices'] ?? 1);
        return $z;
    }

    protected function normalizeVoucher(array $v, array $names): array
    {
        $v['zone_name']     = $names[$v['zone_id']] ?? ('Zone #' . $v['zone_id']);
        $v['data_quota_mb'] = (int) round(((int) ($v['quota_bytes'] ?? 0)) / 1048576);
        return $v;
    }

    protected function normalizeRoll(array $r, array $names): array
    {
        $r['zone_name']     = $names[$r['zone_id']] ?? ('Zone #' . $r['zone_id']);
        $r['total']         = (int) ($r['total_vouchers'] ?? 0);
        $r['expired_count'] = (int) ($r['expired_count'] ?? 0);
        return $r;
    }

    protected function normalizeSession(array $s, array $names): array
    {
        $s['zone_name']     = $s['zone_name'] ?? ($names[$s['zone_id']] ?? ('Zone #' . $s['zone_id']));
        $s['voucher_code']  = ($s['auth_type'] ?? '') === 'voucher' ? $s['username'] : null;
        $s['authorized_at'] = $s['connected_at'] ?? '';
        // DB uses 'authorized'; the sessions view keys its badge/actions off 'active'.
        $s['status']        = ($s['status'] ?? '') === 'authorized' ? 'active' : ($s['status'] ?? '');
        return $s;
    }
}
