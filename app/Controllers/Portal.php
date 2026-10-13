<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\VoucherEngine;
use App\Models\CaptiveCustomizationModel;
use App\Models\CaptiveSessionModel;
use App\Models\CaptiveVoucherModel;
use App\Models\CaptiveZoneModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Portal Controller — Guest-Facing Captive Portal Engine, CNA Interception,
 * Voucher & Click-Through Authentication, and Self-Service Session Logout.
 */
class Portal extends BaseController
{
    protected CaptiveZoneModel $zoneModel;
    protected CaptiveVoucherModel $voucherModel;
    protected CaptiveSessionModel $sessionModel;
    protected CaptiveCustomizationModel $customModel;
    protected VoucherEngine $voucherEngine;
    protected IpcClient $ipcClient;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->zoneModel     = new CaptiveZoneModel();
        $this->voucherModel  = new CaptiveVoucherModel();
        $this->sessionModel  = new CaptiveSessionModel();
        $this->customModel   = new CaptiveCustomizationModel();
        $this->voucherEngine = new VoucherEngine($this->voucherModel, $this->sessionModel, $this->zoneModel);
        $this->ipcClient     = new IpcClient();
    }

    /**
     * Renders responsive guest authentication splash screen.
     */
    public function auth(?string $zoneSlug = null)
    {
        $zone = null;
        if (!empty($zoneSlug)) {
            $zone = $this->zoneModel->getBySlug($zoneSlug);
        }

        // If no slug or invalid, pick first enabled zone
        if (!$zone) {
            $zone = $this->zoneModel->where('enabled', 1)->first();
        }

        if (!$zone) {
            return $this->response->setStatusCode(404)->setBody('Captive portal is currently inactive or unconfigured.');
        }

        $clientIp  = $this->request->getIPAddress();
        $clientMac = $this->resolveClientMac($clientIp);

        // Check if already authenticated
        $existing = $this->sessionModel->findActive($clientIp, $zone['id']);
        if ($existing) {
            return redirect()->to(base_url('portal/success?zone=' . $zone['slug']));
        }

        $customization = $this->customModel->getForZone($zone['id']);

        return view('portal/auth', [
            'zone'          => $zone,
            'customization' => $customization,
            'clientIp'      => $clientIp,
            'clientMac'     => $clientMac,
            'origUrl'       => $this->request->getGet('redir') ?: ($zone['default_redirect_url'] ?: 'https://www.google.com'),
        ]);
    }

    /**
     * Evaluates guest login submission (voucher or click-through).
     */
    public function login(): RedirectResponse
    {
        $zoneId   = trim((string) $this->request->getPost('zone_id'));
        $authMode = trim((string) $this->request->getPost('auth_mode')) ?: 'clickthrough';
        $clientIp = $this->request->getIPAddress();
        $clientMac = $this->resolveClientMac($clientIp);
        $origUrl  = trim((string) $this->request->getPost('orig_url')) ?: 'https://www.google.com';

        $zone = $this->zoneModel->find($zoneId);
        if (!$zone) {
            return redirect()->back()->with('error', 'Invalid captive zone.');
        }

        $timeoutSec = ((int) ($zone['session_timeout'] ?: 60)) * 60;
        $username   = 'Guest';

        if ($authMode === 'voucher') {
            $code = trim((string) $this->request->getPost('voucher_code'));
            if (empty($code)) {
                return redirect()->back()->with('error', 'Please enter your hotspot voucher code.');
            }

            $result = $this->voucherEngine->validateAndActivate($code, $clientIp, $clientMac, $zoneId);
            if (!$result['success']) {
                return redirect()->back()->with('error', $result['message']);
            }

            $timeoutSec = ((int) ($result['voucher']['duration_minutes'] ?: 60)) * 60;
            $username   = $code;
        } else {
            // Click-through / Terms acceptance
            $termsAccepted = $this->request->getPost('terms_accepted');
            if (!$termsAccepted) {
                return redirect()->back()->with('error', 'You must agree to the Terms of Service to continue.');
            }

            $nowTs     = time();
            $expiresAt = date('Y-m-d H:i:s', $nowTs + $timeoutSec);

            $sessionId = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            $this->sessionModel->insert([
                'id'           => $sessionId,
                'zone_id'      => $zoneId,
                'ip_address'   => $clientIp,
                'mac_address'  => $clientMac,
                'auth_type'    => 'clickthrough',
                'username'     => 'Guest (' . $clientIp . ')',
                'bytes_in'     => 0,
                'bytes_out'    => 0,
                'connected_at' => date('Y-m-d H:i:s', $nowTs),
                'expires_at'   => $expiresAt,
                'last_seen_at' => date('Y-m-d H:i:s', $nowTs),
                'status'       => 'authorized',
            ]);
        }

        // Authorize in Linux Netfilter kernel sets
        $tag = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($zone['slug'] ?: $zone['id']));
        $this->ipcClient->call('captive.authorize', [
            'zone_tag'        => $tag,
            'ip'              => $clientIp,
            'mac'             => $clientMac,
            'timeout_seconds' => $timeoutSec,
        ]);

        return redirect()->to(base_url('portal/success?zone=' . urlencode($zone['slug']) . '&redir=' . urlencode($origUrl)));
    }

    /**
     * Connected confirmation screen.
     */
    public function success(): string
    {
        $clientIp = $this->request->getIPAddress();
        $session  = $this->sessionModel->findActive($clientIp);
        $zoneSlug = $this->request->getGet('zone');
        $zone     = $zoneSlug ? $this->zoneModel->getBySlug($zoneSlug) : ($session ? $this->zoneModel->find($session['zone_id']) : null);
        $custom   = $zone ? $this->customModel->getForZone($zone['id']) : null;
        $redirUrl = $this->request->getGet('redir') ?: ($zone['default_redirect_url'] ?? 'https://www.google.com');

        return view('portal/success', [
            'session'       => $session,
            'zone'          => $zone,
            'customization' => $custom,
            'redirUrl'      => $redirUrl,
        ]);
    }

    /**
     * Client session status query (JSON).
     */
    public function status(): ResponseInterface
    {
        $clientIp = $this->request->getIPAddress();
        $session  = $this->sessionModel->findActive($clientIp);

        if (!$session) {
            return $this->response->setJSON(['connected' => false]);
        }

        $nowTs     = time();
        $expiresTs = strtotime($session['expires_at']);
        $leftSec   = max(0, $expiresTs - $nowTs);

        return $this->response->setJSON([
            'connected'    => true,
            'ip'           => $session['ip_address'],
            'mac'          => $session['mac_address'],
            'username'     => $session['username'],
            'auth_type'    => $session['auth_type'],
            'connected_at' => $session['connected_at'],
            'expires_at'   => $session['expires_at'],
            'seconds_left' => $leftSec,
            'minutes_left' => ceil($leftSec / 60),
            'bytes_in'     => (int) $session['bytes_in'],
            'bytes_out'    => (int) $session['bytes_out'],
        ]);
    }

    /**
     * Self-service session disconnect.
     */
    public function logout(): RedirectResponse
    {
        $clientIp = $this->request->getIPAddress();
        $session  = $this->sessionModel->findActive($clientIp);

        if ($session) {
            $this->sessionModel->update($session['id'], ['status' => 'disconnected']);
            $this->ipcClient->call('captive.revoke', [
                'ip'  => $session['ip_address'],
                'mac' => $session['mac_address'],
            ]);
        }

        return redirect()->to(base_url('portal/auth'))->with('message', 'You have been disconnected from the guest network.');
    }

    /**
     * Captive Network Assistant (CNA) probe handler (Apple, Android, Windows).
     * Returns HTTP 302 Redirect to activate instant splash window on smartphones.
     */
    public function cnaRedirect(): RedirectResponse
    {
        $firstZone = $this->zoneModel->where('enabled', 1)->first();
        $slug      = $firstZone['slug'] ?? 'default';

        return redirect()->to(base_url('portal/auth/' . $slug));
    }

    /**
     * Resolves client MAC address from local ARP cache or fallback.
     */
    protected function resolveClientMac(string $ip): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows ARP lookup
            $output = @shell_exec("arp -a " . escapeshellarg($ip));
            if ($output && preg_match('/([0-9a-f]{2}[:-][0-9a-f]{2}[:-][0-9a-f]{2}[:-][0-9a-f]{2}[:-][0-9a-f]{2}[:-][0-9a-f]{2})/i', $output, $m)) {
                return strtoupper(str_replace('-', ':', $m[1]));
            }
        } else {
            // Linux /proc/net/arp lookup
            if (file_exists('/proc/net/arp')) {
                $lines = @file('/proc/net/arp');
                if ($lines) {
                    foreach ($lines as $line) {
                        $parts = preg_split('/\s+/', trim($line));
                        if (count($parts) >= 4 && $parts[0] === $ip && $parts[3] !== '00:00:00:00:00:00') {
                            return strtoupper($parts[3]);
                        }
                    }
                }
            }
        }

        return '00:00:00:00:00:00';
    }
}
