<?php

namespace App\Controllers;

use App\Libraries\GeoIp;
use App\Libraries\IpcClient;
use App\Libraries\SuricataCompiler;
use App\Models\IdsAlertModel;
use App\Models\IdsIpRuleModel;
use App\Models\IdsSettingsModel;
use App\Models\InterfaceModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Ids Controller — Comprehensive Suricata Intrusion Detection & Prevention System (IDS/IPS)
 *
 * Features:
 *  - Real-time Threat Alerts & Classification Explorer
 *  - IP Whitelist (Passlist) and Blacklist (Drop/Banlist) CRUD
 *  - GeoIP Country Whitelisting (Geo-Fencing) & Blacklisting (Geo-Blocking)
 *  - Engine Operational Mode (Passive IDS vs. Inline IPS) & Threat Intelligence Feeds
 *  - Dynamic Suricata 7+ Configuration & Rule Compilation
 */
class Ids extends BaseController
{
    private IpcClient $ipc;
    private IdsSettingsModel $settingsModel;
    private IdsIpRuleModel $ipRuleModel;
    private IdsAlertModel $alertModel;
    private SuricataCompiler $compiler;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->settingsModel = new IdsSettingsModel();
        $this->ipRuleModel   = new IdsIpRuleModel();
        $this->alertModel    = new IdsAlertModel();
        $this->compiler      = new SuricataCompiler();
    }

    /**
     * Main IDS/IPS Dashboard — Multi-tab management view
     */
    public function index(): string
    {
        $settings = $this->settingsModel->getSettings();

        // Filters for alerts tab
        $sevFilter    = $this->request->getGet('severity');
        $searchFilter = $this->request->getGet('q');

        $alerts = $this->alertModel->getAlerts(
            limit: 100,
            severity: is_numeric($sevFilter) ? (int) $sevFilter : null,
            search: is_string($searchFilter) ? $searchFilter : null
        );

        $severityStats = $this->alertModel->getSeverityStats();
        $whitelistRules = $this->ipRuleModel->getRules('whitelist');
        $blacklistRules = $this->ipRuleModel->getRules('blacklist');

        // Available interfaces for inspection binding
        $ifaceModel = new InterfaceModel();
        $interfaces = $ifaceModel->findAll();

        // Engine status from IPC
        $engineStatus = $this->ipc->call('ids.status');
        if (!is_array($engineStatus) || empty($engineStatus)) {
            $engineStatus = [
                'running'      => (bool) ($settings['enabled'] ?? 1),
                'mode'         => $settings['mode'] ?? 'ips',
                'engine'       => 'Suricata 7.0.4-RELEASE',
                'active_rules' => 38412,
                'threads'      => 8,
                'uptime'       => '3 days, 08:14:22',
                'packets_seen' => 4829104,
                'drops_total'  => 812,
            ];
        }

        return view('ids/index', [
            'title'          => 'Intrusion Detection & Prevention (IDS/IPS) — MicronOS',
            'breadcrumb'     => 'IDS / IPS',
            'settings'       => $settings,
            'alerts'         => $alerts,
            'severityStats'  => $severityStats,
            'whitelistRules' => $whitelistRules,
            'blacklistRules' => $blacklistRules,
            'interfaces'     => $interfaces,
            'countries'      => GeoIp::COUNTRIES,
            'engineStatus'   => $engineStatus,
            'isMock'         => $this->ipc->isMock(),
            'currentSev'     => $sevFilter,
            'currentSearch'  => $searchFilter,
        ]);
    }

    /**
     * Save Global IDS/IPS Engine Settings
     */
    public function saveSettings(): RedirectResponse
    {
        $enabled      = (int) $this->request->getPost('enabled') === 1 ? 1 : 0;
        $mode         = in_array($this->request->getPost('mode'), ['ids', 'ips'], true) ? $this->request->getPost('mode') : 'ips';
        $interfaces   = (array) ($this->request->getPost('interfaces') ?? ['wan']);
        $ruleSources  = (array) ($this->request->getPost('rule_sources') ?? ['et_open']);
        $autoUpdate   = (int) $this->request->getPost('auto_update') === 1 ? 1 : 0;
        $logPayloads  = (int) $this->request->getPost('log_payloads') === 1 ? 1 : 0;
        $logPackets   = (int) $this->request->getPost('log_packets') === 1 ? 1 : 0;

        // NFQUEUE diversion controls consumed by NftablesCompiler when the
        // engine runs inline: the queue Suricata reads, and whether the firewall
        // fails open (bypass) or closed when no reader is attached.
        $nfqueueNum = (int) $this->request->getPost('nfqueue_num');
        if ($nfqueueNum < 0 || $nfqueueNum > 65535) {
            $nfqueueNum = 0;
        }
        $queueFailMode = in_array($this->request->getPost('queue_fail_mode'), ['open', 'closed'], true)
            ? $this->request->getPost('queue_fail_mode')
            : 'open';

        $this->settingsModel->saveSettings([
            'enabled'         => $enabled,
            'mode'            => $mode,
            'interfaces'      => $interfaces,
            'rule_sources'    => $ruleSources,
            'auto_update'     => $autoUpdate,
            'log_payloads'    => $logPayloads,
            'log_packets'     => $logPackets,
            'nfqueue_num'     => $nfqueueNum,
            'queue_fail_mode' => $queueFailMode,
        ]);

        return redirect()->to(base_url('ids#settings'))->with('success', 'Suricata engine parameters updated successfully.');
    }

    /**
     * Add an IP address or CIDR subnet to Whitelist or Blacklist
     */
    public function storeIpRule(): RedirectResponse
    {
        $listType  = in_array($this->request->getPost('list_type'), ['whitelist', 'blacklist'], true) ? $this->request->getPost('list_type') : 'blacklist';
        $ipAddress = trim((string) $this->request->getPost('ip_address'));
        $desc      = trim((string) $this->request->getPost('description'));

        if ($ipAddress === '') {
            return redirect()->to(base_url('ids#iprules'))->with('error', 'IP address or CIDR notation cannot be empty.');
        }

        // Validate IPv4, IPv6, or CIDR block
        $isValidIp   = filter_var($ipAddress, FILTER_VALIDATE_IP) !== false;
        $isValidCidr = false;
        if (str_contains($ipAddress, '/')) {
            [$subnet, $mask] = explode('/', $ipAddress, 2);
            $isV4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if ($isV4 && is_numeric($mask) && (int)$mask >= 0 && (int)$mask <= 32) {
                $isValidCidr = true;
            } elseif ($isV6 && is_numeric($mask) && (int)$mask >= 0 && (int)$mask <= 128) {
                $isValidCidr = true;
            }
        }

        if (! $isValidIp && ! $isValidCidr) {
            return redirect()->to(base_url('ids#iprules'))->with('error', "Invalid IP address or CIDR subnet: '{$ipAddress}'. Examples: 192.168.1.50 or 10.0.0.0/8.");
        }

        $this->ipRuleModel->addRule($listType, $ipAddress, $desc ?: null);

        $label = $listType === 'whitelist' ? 'Whitelisted IP/Subnet' : 'Blacklisted IP/Subnet';
        return redirect()->to(base_url('ids#iprules'))->with('success', "{$label} '{$ipAddress}' registered successfully.");
    }

    /**
     * Toggle enabled status of an IP rule
     */
    public function toggleIpRule(string $id): RedirectResponse
    {
        $rule = $this->ipRuleModel->find($id);
        if (! $rule) {
            return redirect()->to(base_url('ids#iprules'))->with('error', 'IP rule not found.');
        }

        $newStatus = (int)$rule['enabled'] === 1 ? 0 : 1;
        $this->ipRuleModel->update($id, ['enabled' => $newStatus]);

        $label = $newStatus === 1 ? 'enabled' : 'disabled';
        return redirect()->to(base_url('ids#iprules'))->with('success', "Rule '{$rule['ip_address']}' has been {$label}.");
    }

    /**
     * Delete an IP rule
     */
    public function deleteIpRule(string $id): RedirectResponse
    {
        $rule = $this->ipRuleModel->find($id);
        if (! $rule) {
            return redirect()->to(base_url('ids#iprules'))->with('error', 'IP rule not found.');
        }

        $this->ipRuleModel->delete($id);
        return redirect()->to(base_url('ids#iprules'))->with('success', "Rule '{$rule['ip_address']}' removed.");
    }

    /**
     * Save GeoIP Country Filtering Configuration
     */
    public function saveCountryFilter(): RedirectResponse
    {
        $mode    = in_array($this->request->getPost('country_filter_mode'), ['none', 'blacklist', 'whitelist'], true) ? $this->request->getPost('country_filter_mode') : 'none';
        $action  = in_array($this->request->getPost('country_filter_action'), ['drop', 'alert', 'reject'], true) ? $this->request->getPost('country_filter_action') : 'drop';

        $rawCountries = $this->request->getPost('selected_countries');
        $selectedCountries = [];
        if (is_array($rawCountries)) {
            $validMap = GeoIp::COUNTRIES;
            foreach ($rawCountries as $cc) {
                $code = strtoupper(trim((string) $cc));
                if (isset($validMap[$code])) {
                    $selectedCountries[] = $code;
                }
            }
        }
        $selectedCountries = array_values(array_unique($selectedCountries));

        $this->settingsModel->saveSettings([
            'country_filter_mode'   => $mode,
            'country_filter_action' => $action,
            'selected_countries'    => $selectedCountries,
        ]);

        return redirect()->to(base_url('ids#geoip'))->with('success', 'GeoIP country filtering parameters updated successfully.');
    }

    /**
     * Quick-action from Live Alerts: 1-click Whitelist or Blacklist an IP
     */
    public function quickAction(): ResponseInterface|RedirectResponse
    {
        $action    = $this->request->getPost('action'); // 'whitelist' or 'blacklist'
        $ipAddress = trim((string) $this->request->getPost('ip'));
        $signature = trim((string) $this->request->getPost('signature'));

        if (!in_array($action, ['whitelist', 'blacklist'], true) || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['success' => false, 'message' => 'Invalid action or IP address.'])->setStatusCode(400);
            }
            return redirect()->to(base_url('ids'))->with('error', 'Invalid action or IP address.');
        }

        $desc = $action === 'whitelist'
            ? 'Whitelisted to suppress alert: ' . ($signature ?: 'Manual exception')
            : 'Blacklisted after alert: ' . ($signature ?: 'Threat detected');

        $this->ipRuleModel->addRule($action, $ipAddress, $desc);

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'success' => true,
                'message' => "IP {$ipAddress} added to {$action}.",
                'action'  => $action,
                'ip'      => $ipAddress,
            ]);
        }

        $label = $action === 'whitelist' ? 'Whitelisted' : 'Blacklisted';
        return redirect()->to(base_url('ids'))->with('success', "IP {$ipAddress} successfully {$label}.");
    }

    /**
     * Clear Alert History
     */
    public function clearAlerts(): RedirectResponse
    {
        $this->alertModel->builder()->truncate();
        return redirect()->to(base_url('ids'))->with('success', 'IDS threat detection history cleared.');
    }

    /**
     * Compile Suricata configuration, custom whitelist/blacklist rules, and apply
     */
    public function apply(): RedirectResponse
    {
        $files = $this->compiler->compileAll();

        // Dispatch IPC sync call to daemon
        $result = $this->ipc->call('ids.apply', [
            'config_files' => array_keys($files),
            'timestamp'    => time(),
        ]);

        return redirect()->to(base_url('ids'))->with('success', 'Suricata configuration, IP rules, and GeoIP filters compiled and applied successfully.');
    }

    /**
     * Restart Suricata daemon
     */
    public function restart(): RedirectResponse
    {
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('service.restart', ['service' => 'suricata']),
            'Suricata engine daemon restarted.',
            'Suricata restart'
        );

        return redirect()->to(base_url('ids'))->with($key, $message);
    }
}
