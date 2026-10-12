<?php

namespace App\Controllers;

use App\Libraries\ConfigRevisionEngine;
use App\Libraries\ThreatIntelEngine;
use App\Models\ThreatFeedModel;
use App\Models\ThreatIndicatorModel;
use App\Models\ThreatIntelSettingsModel;
use App\Models\ThreatIntelWhitelistModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Exception;

/**
 * ThreatIntel Controller — Automated Dynamic Threat Intelligence Feed Ingest (MISP / AlienVault / AbuseIPDB).
 */
class ThreatIntel extends BaseController
{
    protected ThreatFeedModel $feedModel;
    protected ThreatIndicatorModel $indicatorModel;
    protected ThreatIntelSettingsModel $settingsModel;
    protected ThreatIntelWhitelistModel $whitelistModel;
    protected ThreatIntelEngine $tiEngine;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->feedModel      = new ThreatFeedModel();
        $this->indicatorModel = new ThreatIndicatorModel();
        $this->settingsModel  = new ThreatIntelSettingsModel();
        $this->whitelistModel = new ThreatIntelWhitelistModel();
        $this->tiEngine       = new ThreatIntelEngine();
        $this->revEngine      = new ConfigRevisionEngine();
    }

    // =========================================================================
    // Dashboard & Feed Management
    // =========================================================================

    public function index(): string
    {
        $feeds            = $this->feedModel->orderBy('name', 'ASC')->findAll();
        $indicators       = $this->indicatorModel->getActiveIndicators();
        $manualIndicators = $this->indicatorModel->getManualIndicators();
        $whitelists       = $this->whitelistModel->orderBy('created_at', 'DESC')->findAll();
        $settings         = $this->settingsModel->getSettings();

        $activeFeedCount = count(array_filter($feeds, fn($f) => ! empty($f['enabled'])));
        $totalIndicators = count($indicators);
        $totalIps        = count($this->indicatorModel->getActiveIpIndicators());
        $totalFqdns      = count($this->indicatorModel->getActiveFqdnIndicators());

        return view('threat_intel/index', [
            'title'            => 'Dynamic Threat Intelligence Feeds — Micron OS',
            'feeds'            => $feeds,
            'indicators'       => array_slice($indicators, 0, 100),
            'manualIndicators' => $manualIndicators,
            'whitelists'       => $whitelists,
            'settings'         => $settings,
            'stats'            => [
                'total_feeds'       => count($feeds),
                'active_feeds'      => $activeFeedCount,
                'total_indicators'  => $totalIndicators,
                'total_ips'         => $totalIps,
                'total_fqdns'       => $totalFqdns,
                'manual_count'      => count($manualIndicators),
                'nft_set_name'      => $settings['nft_set_name'] ?? 'threat_intel_ipv4',
            ],
        ]);
    }

    public function saveFeed(): RedirectResponse
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));
        $slug = trim((string) $this->request->getPost('slug'));
        $feedType = trim((string) $this->request->getPost('feed_type'));
        $url = trim((string) $this->request->getPost('url'));

        if (empty($name) || empty($feedType) || empty($url)) {
            return redirect()->back()->with('error', 'Feed name, type, and source URL are required.');
        }

        if (empty($slug)) {
            $slug = url_title(strtolower($name));
        }

        $data = [
            'name'                  => $name,
            'slug'                  => $slug,
            'feed_type'             => $feedType,
            'url'                   => $url,
            'api_key'               => trim((string) $this->request->getPost('api_key')),
            'min_confidence'        => (int) ($this->request->getPost('min_confidence') ?: 80),
            'update_interval_hours' => (int) ($this->request->getPost('update_interval_hours') ?: 6),
            'enabled'               => $this->request->getPost('enabled') ? 1 : 0,
        ];

        if (! empty($id)) {
            $this->feedModel->update($id, $data);
            $message = "Updated Threat Intelligence Feed '{$name}'.";
        } else {
            $data['id'] = 'feed_' . bin2hex(random_bytes(6));
            $this->feedModel->insert($data);
            $message = "Added Threat Intelligence Feed '{$name}'.";
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            $message,
            'firewall'
        );

        return redirect()->to(base_url('security/threat-intel'))->with('success', $message);
    }

    public function toggleFeed(string $id): RedirectResponse
    {
        $feed = $this->feedModel->find($id);
        if (! $feed) {
            return redirect()->back()->with('error', 'Feed not found.');
        }

        $newState = $feed['enabled'] ? 0 : 1;
        $this->feedModel->update($id, ['enabled' => $newState]);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            ($newState ? 'Enabled' : 'Disabled') . " Threat Feed '{$feed['name']}'",
            'firewall'
        );

        return redirect()->to(base_url('security/threat-intel'))->with(
            'success',
            "Feed '{$feed['name']}' " . ($newState ? 'enabled' : 'disabled') . '.'
        );
    }

    public function deleteFeed(string $id): RedirectResponse
    {
        $feed = $this->feedModel->find($id);
        if (! $feed) {
            return redirect()->back()->with('error', 'Feed not found.');
        }

        // Delete indicators associated with feed
        $this->indicatorModel->where('feed_id', $id)->delete();
        $this->feedModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            "Deleted Threat Feed '{$feed['name']}'",
            'firewall'
        );

        return redirect()->to(base_url('security/threat-intel'))->with('success', "Feed '{$feed['name']}' deleted.");
    }

    public function syncNow(?string $id = null): RedirectResponse
    {
        try {
            if (! empty($id)) {
                $feed = $this->feedModel->find($id);
                if (! $feed) {
                    return redirect()->back()->with('error', 'Feed not found.');
                }
                $count = $this->tiEngine->syncFeed($feed);
                $this->tiEngine->compileNftablesConfig();
                $msg = "Successfully ingested {$count} threat indicators from '{$feed['name']}'.";
            } else {
                $res = $this->tiEngine->syncAllFeeds();
                $msg = "Synchronized {$res['successful_feeds']} feeds. Total {$res['total_indicators']} active threat indicators.";
            }

            return redirect()->to(base_url('security/threat-intel'))->with('success', $msg);
        } catch (Exception $e) {
            return redirect()->to(base_url('security/threat-intel'))->with('error', 'Feed sync error: ' . $e->getMessage());
        }
    }

    public function saveSettings(): RedirectResponse
    {
        $settings = $this->settingsModel->getSettings();

        $data = [
            'enabled'             => $this->request->getPost('enabled') ? 1 : 0,
            'action'              => $this->request->getPost('action') ?: 'drop',
            'safeguard_rfc1918'   => $this->request->getPost('safeguard_rfc1918') ? 1 : 0,
            'log_blocked_packets' => $this->request->getPost('log_blocked_packets') ? 1 : 0,
            'auto_prune_days'     => (int) ($this->request->getPost('auto_prune_days') ?: 7),
        ];

        $this->settingsModel->update($settings['id'], $data);
        $this->tiEngine->compileNftablesConfig();

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            'Updated Threat Intelligence firewall policies',
            'firewall'
        );

        return redirect()->to(base_url('security/threat-intel'))->with('success', 'Threat Intelligence settings saved.');
    }

    public function addWhitelist(): RedirectResponse
    {
        $ip = trim((string) $this->request->getPost('indicator_value'));
        $reason = trim((string) $this->request->getPost('reason'));

        if (empty($ip)) {
            return redirect()->back()->with('error', 'IP address is required.');
        }

        if (! $this->tiEngine->isValidIpOrCidr($ip)) {
            return redirect()->back()->with('error', 'Invalid IPv4 address or CIDR.');
        }

        if ($this->whitelistModel->isWhitelisted($ip)) {
            return redirect()->back()->with('error', "IP '{$ip}' is already whitelisted.");
        }

        $this->whitelistModel->insert([
            'id'              => 'wl_' . bin2hex(random_bytes(6)),
            'indicator_value' => $ip,
            'reason'          => $reason,
            'created_by'      => session()->get('username') ?? 'admin',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Remove from active indicators if present
        $this->indicatorModel->where('indicator_value', $ip)->delete();
        $this->tiEngine->compileNftablesConfig();

        $this->revEngine->createRevision(
            session()->get('username') ?? 'admin',
            "Whitelisted IP '{$ip}' from threat intelligence blocklist",
            'firewall'
        );

        return redirect()->to(base_url('security/threat-intel'))->with('success', "IP '{$ip}' added to whitelist.");
    }

    public function deleteWhitelist(string $id): RedirectResponse
    {
        $wl = $this->whitelistModel->find($id);
        if ($wl) {
            $this->whitelistModel->delete($id);
            $this->tiEngine->compileNftablesConfig();
            $this->revEngine->createRevision(
                session()->get('username') ?? 'admin',
                "Removed '{$wl['indicator_value']}' from threat intelligence whitelist",
                'firewall'
            );
        }

        return redirect()->to(base_url('security/threat-intel'))->with('success', 'Whitelist entry removed.');
    }

    public function compileRuleset(): ResponseInterface
    {
        $config = $this->tiEngine->compileNftablesConfig();

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setBody($config);
    }

    public function compileDnsblRuleset(): ResponseInterface
    {
        $config = $this->tiEngine->compileDnsblConfig();

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setBody($config);
    }

    public function addManualIoc(): RedirectResponse
    {
        $rawValues = trim((string) $this->request->getPost('indicator_value'));
        $threatType = trim((string) $this->request->getPost('threat_type')) ?: 'custom_blacklist';
        $confidence = (int) ($this->request->getPost('confidence_score') ?: 100);
        $comment = trim((string) $this->request->getPost('comment'));
        $expiresInDays = $this->request->getPost('expires_days');

        if (empty($rawValues)) {
            return redirect()->back()->with('error', 'Please enter at least one IP address, CIDR, or FQDN.');
        }

        $expiresAt = (! empty($expiresInDays) && is_numeric($expiresInDays) && (int)$expiresInDays > 0)
            ? date('Y-m-d H:i:s', time() + ((int)$expiresInDays * 86400))
            : null;

        // Support multiline input (one per line or comma-separated)
        $lines = preg_split('/[\r\n,]+/', $rawValues);
        $added = 0;
        $errors = [];

        foreach ($lines as $line) {
            $val = trim($line);
            if ($val === '') {
                continue;
            }

            try {
                $this->tiEngine->addManualIndicator($val, $threatType, $confidence, $comment, $expiresAt);
                $added++;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($added > 0) {
            $this->revEngine->createRevision(
                session()->get('username') ?? 'admin',
                "Added {$added} manual Threat Intelligence IoCs / blacklist entries",
                'firewall'
            );
        }

        if (! empty($errors) && $added === 0) {
            return redirect()->to(base_url('security/threat-intel'))->with('error', implode('; ', $errors));
        }

        $msg = "Successfully added {$added} manual IoC blacklist entries.";
        if (! empty($errors)) {
            $msg .= ' (' . count($errors) . ' items skipped: ' . implode(', ', array_slice($errors, 0, 3)) . ')';
        }

        return redirect()->to(base_url('security/threat-intel'))->with('success', $msg);
    }

    public function deleteManualIoc(string $id): RedirectResponse
    {
        $item = $this->indicatorModel->find($id);
        if ($item) {
            $this->tiEngine->deleteManualIndicator($id);
            $this->revEngine->createRevision(
                session()->get('username') ?? 'admin',
                "Removed manual IoC '{$item['indicator_value']}' from blacklist",
                'firewall'
            );
        }

        return redirect()->to(base_url('security/threat-intel'))->with('success', 'Manual IoC entry removed from blacklist.');
    }
}
