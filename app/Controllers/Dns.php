<?php

namespace App\Controllers;

use App\Libraries\IpcClient;
use App\Libraries\SystemResolverCompiler;

/**
 * Dns Controller — DNS Resolver (Unbound) with DNSSEC, DNS-over-TLS (DoT),
 * DNSBL threat-feed blocking, feeds, custom allow/deny lists, and query metrics.
 */
class Dns extends BaseController
{
    private IpcClient $ipc;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();

        // 1. Get DNS Settings
        $settings = $db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        if (!$settings) {
            $settings = [
                'id'                   => 'global',
                'enable_dnssec'        => 1,
                'enable_dnsbl'         => 1,
                'upstream_mode'        => 'dot',
                'upstream_servers'     => json_encode([
                    ['name' => 'Cloudflare Primary (DoT)', 'ip' => '1.1.1.1', 'port' => 853, 'sni' => 'cloudflare-dns.com'],
                    ['name' => 'Quad9 Secured (DoT)', 'ip' => '9.9.9.9', 'port' => 853, 'sni' => 'dns.quad9.net'],
                ]),
                'cache_min_ttl'        => 60,
                'cache_max_ttl'        => 86400,
                'blocked_query_action' => '0.0.0.0',
                'dns_server_override'  => 1,
                'dns_resolution_behavior' => SystemResolverCompiler::DEFAULT_BEHAVIOR,
            ];
        }

        // 2. Local Host Overrides
        $hosts = $db->table('dns_hosts')->orderBy('hostname', 'ASC')->get()->getResultArray();

        // 2b. Domain Overrides (per-domain forwarding to a specific resolver)
        $domainOverrides = $db->table('dns_domain_overrides')->orderBy('domain', 'ASC')->get()->getResultArray();

        // 3. DNSBL Feeds
        $feeds = $db->table('dns_feeds')->orderBy('created_at', 'ASC')->get()->getResultArray();
        $totalBlockedEntries = 0;
        foreach ($feeds as $f) {
            if ($f['enabled']) {
                $totalBlockedEntries += (int) $f['entries_count'];
            }
        }

        // 4. Custom Overrides (Whitelist & Blacklist)
        $overrides = $db->table('dns_overrides')->orderBy('created_at', 'DESC')->get()->getResultArray();
        $whitelist = array_filter($overrides, fn($o) => $o['action'] === 'whitelist');
        $blacklist = array_filter($overrides, fn($o) => $o['action'] === 'blacklist');

        // 5. DNS & DNSBL telemetry stats
        $stats = [
            'total_queries'       => 58492,
            'cache_hits'          => 49830,
            'cache_hit_ratio'     => '85.2%',
            'dnssec_validated'    => '100%',
            'blocked_queries'     => 8663,
            'blocked_percentage'  => '14.8%',
            'total_blocked_rules' => $totalBlockedEntries,
            'upstream_mode'       => $settings['upstream_mode'] === 'dot' ? 'DNS-over-TLS (Strict TLS/853)' : 'Standard Recursive (UDP/53)',
        ];

        // 5b. Appliance stub resolver settings — how the firewall itself
        //     resolves DNS (see SystemResolverCompiler).
        $resolverCompiler = new SystemResolverCompiler($db);
        $resolverBehavior = $resolverCompiler->behavior($settings);
        $resolver = [
            'behavior'   => $resolverBehavior,
            'label'      => SystemResolverCompiler::label($resolverBehavior),
            'override'   => $resolverCompiler->overrideEnabled($settings),
            'servers'    => $resolverCompiler->nameservers($settings),
            'remote'     => $resolverCompiler->remoteServers($settings),
            'file'       => SystemResolverCompiler::FILE,
            'unresolvable' => $resolverCompiler->isUnresolvable($settings),
            // Dynamically assigned servers the override would merge (empty when
            // it is off) and everything currently on record, so the page can
            // explain what the link handed the appliance either way.
            'dynamic'        => $resolverCompiler->dynamicServers($settings),
            'dynamic_stored' => $resolverCompiler->storedDynamicServers(),
        ];

        // 6. Recent Blocked Queries (live simulation / query inspector)
        $recentBlocked = [
            ['time' => date('H:i:s', time() - 14),  'client' => '192.168.1.101', 'domain' => 'telemetry.microsoft.com', 'feed' => 'StevenBlack Unified Hosts', 'type' => 'A'],
            ['time' => date('H:i:s', time() - 38),  'client' => '192.168.1.102', 'domain' => 'pagead2.googlesyndication.com', 'feed' => 'AdGuard DNS Filter', 'type' => 'A'],
            ['time' => date('H:i:s', time() - 72),  'client' => '192.168.1.105', 'domain' => 'graph.facebook.com', 'feed' => 'OISD Blocklist Big', 'type' => 'HTTPS'],
            ['time' => date('H:i:s', time() - 110), 'client' => '192.168.1.101', 'domain' => 'tracking-analytics.appsflyer.com', 'feed' => 'StevenBlack Unified Hosts', 'type' => 'A'],
            ['time' => date('H:i:s', time() - 165), 'client' => '192.168.1.104', 'domain' => 'malware-dropzone.ru', 'feed' => 'abuse.ch URLhaus Phishing', 'type' => 'A'],
            ['time' => date('H:i:s', time() - 210), 'client' => '192.168.1.103', 'domain' => 'adservice.google.com', 'feed' => 'AdGuard DNS Filter', 'type' => 'A'],
        ];

        return view('dns/index', [
            'title'               => 'DNS Resolver & DNSBL Ad-Blocker — MicronOS',
            'breadcrumb'          => 'DNS Resolver & DNSBL',
            'stats'               => $stats,
            'settings'            => $settings,
            'hosts'               => $hosts,
            'domainOverrides'     => $domainOverrides,
            'feeds'               => $feeds,
            'whitelist'           => array_values($whitelist),
            'blacklist'           => array_values($blacklist),
            'recentBlocked'       => $recentBlocked,
            'totalBlockedEntries' => $totalBlockedEntries,
            'resolver'            => $resolver,
            'resolverBehaviors'   => SystemResolverCompiler::behaviors(),
            'isMock'              => $this->ipc->isMock(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Save DNS & DoT Settings
    // ─────────────────────────────────────────────────────────────────
    public function saveSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $enableDnssec = $this->request->getPost('enable_dnssec') ? 1 : 0;
        $enableDnsbl  = $this->request->getPost('enable_dnsbl') ? 1 : 0;
        $upstreamMode = $this->request->getPost('upstream_mode') ?? 'dot';
        $minTtl       = (int) ($this->request->getPost('cache_min_ttl') ?? 60);
        $maxTtl       = (int) ($this->request->getPost('cache_max_ttl') ?? 86400);
        $action       = $this->request->getPost('blocked_query_action') ?? '0.0.0.0';

        $db->table('dns_settings')->where('id', 'global')->update([
            'enable_dnssec'        => $enableDnssec,
            'enable_dnsbl'         => $enableDnsbl,
            'upstream_mode'        => $upstreamMode,
            'cache_min_ttl'        => max(0, $minTtl),
            'cache_max_ttl'        => max($minTtl, $maxTtl),
            'blocked_query_action' => $action,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            'DNS Resolver configuration saved and Unbound reloaded.',
            'Saved, but the DNS reload'
        );

        return redirect()->to(base_url('dns'))->with($key, $message);
    }

    // ─────────────────────────────────────────────────────────────────
    // Appliance DNS Resolution Behavior (firewall's own DNS)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Saves the DNS Server Override checkbox and the DNS Resolution Behavior
     * select, then compiles and applies the appliance stub resolver file.
     *
     * Distinct from `saveSettings()`, which owns the Unbound fields: the two
     * sections post independently, so a save can never blank the other's fields.
     */
    public function saveResolverSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $back = redirect()->to(base_url('dns?tab=settings'));

        $override = $this->request->getPost('dns_server_override') ? 1 : 0;
        $behavior = strtolower(trim((string) ($this->request->getPost('dns_resolution_behavior') ?? '')));

        // A submitted value must be one of the accepted behaviors: coerce only
        // an *absent* value (a client that predates the field), never garbage.
        if ($behavior === '') {
            $behavior = SystemResolverCompiler::DEFAULT_BEHAVIOR;
        } elseif (! SystemResolverCompiler::isValid($behavior)) {
            return $back->with('error', "'{$behavior}' is not a recognized DNS resolution behavior.");
        }

        $settings = $db->table('dns_settings')->where('id', 'global')->get()->getRowArray() ?? [];
        $candidate = array_merge($settings, [
            'dns_server_override'      => $override,
            'dns_resolution_behavior'  => $behavior,
        ]);

        // "Use remote DNS Servers, ignore local DNS" with nothing configured
        // would leave the firewall unable to resolve anything. Refuse to save it;
        // the compiler degrades defensively too, but the operator should know why.
        $compiler = new SystemResolverCompiler($db);
        if ($compiler->isUnresolvable($candidate)) {
            return $back->with('error', 'No remote DNS servers are configured: "Use remote DNS Servers, ignore local DNS" '
                . 'would leave the firewall with no resolver. Add an upstream server first.');
        }

        $db->table('dns_settings')->where('id', 'global')->update([
            'dns_server_override'     => $override,
            'dns_resolution_behavior' => $behavior,
            'updated_at'              => date('Y-m-d H:i:s'),
        ]);

        // The compiler re-reads the persisted row, so what is applied is exactly
        // what the DNS page now renders.
        $content = $compiler->compile();

        $outcome = $this->ipcOutcome($this->ipc->call('dns.resolver.apply', [
            'config'   => $content,
            'behavior' => $behavior,
            'override' => (bool) $override,
        ]));

        if (! $outcome['ok']) {
            return $back->with('error', 'DNS resolution behavior saved, but applying the resolver configuration failed: ' . $outcome['reason']);
        }

        $servers = $compiler->nameservers();

        return $back->with('success', sprintf(
            'DNS resolution behavior saved and applied to %s: %s (%d nameserver(s)).',
            SystemResolverCompiler::FILE,
            SystemResolverCompiler::label($behavior),
            count($servers),
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    // Local Host Overrides
    // ─────────────────────────────────────────────────────────────────
    public function storeHost(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $hostname = trim($this->request->getPost('hostname') ?? '');
        $ip = trim($this->request->getPost('ip_address') ?? '');
        $desc = trim($this->request->getPost('description') ?? '');

        if (empty($hostname) || empty($ip)) {
            return redirect()->to(base_url('dns?tab=hosts'))->with('error', 'Hostname and IP address are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dns_hosts')->insert([
            'id'          => 'dns_' . bin2hex(random_bytes(6)),
            'hostname'    => strtolower($hostname),
            'ip_address'  => $ip,
            'description' => $desc,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            "DNS host override for {$hostname} added.",
            'Added, but the DNS reload'
        );

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    public function updateHost(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $host = $db->table('dns_hosts')->where('id', $id)->get()->getRowArray();
        if (!$host) {
            return redirect()->to(base_url('dns?tab=hosts'))->with('error', 'Host override not found.');
        }

        $data = [
            'hostname'    => strtolower(trim($this->request->getPost('hostname') ?: $host['hostname'])),
            'ip_address'  => trim($this->request->getPost('ip_address') ?: $host['ip_address']),
            'description' => trim($this->request->getPost('description') ?? ''),
            'updated_at'  => date('Y-m-d H:i:s'),
        ];

        $db->table('dns_hosts')->where('id', $id)->update($data);
        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            'DNS host override updated.',
            'Updated, but the DNS reload'
        );

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    public function deleteHost(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('dns_hosts')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            'DNS host override removed.',
            'Removed, but the DNS reload'
        );

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    // ─────────────────────────────────────────────────────────────────
    // Domain Overrides — forward whole domains to a specific resolver
    // ─────────────────────────────────────────────────────────────────

    /**
     * Validates and normalizes the domain override form payload.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} [data, error]
     */
    private function readDomainOverride(): array
    {
        $domain = strtolower(rtrim(trim($this->request->getPost('domain') ?? ''), '.'));
        $server = trim($this->request->getPost('dns_server') ?? '');
        $port   = (int) ($this->request->getPost('port') ?: 53);
        $desc   = trim($this->request->getPost('description') ?? '');

        if ($domain === '' || $server === '') {
            return [null, 'Domain and forward DNS server are required.'];
        }

        if (!preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $domain)) {
            return [null, "'{$domain}' is not a valid domain name."];
        }

        if (filter_var($server, FILTER_VALIDATE_IP) === false) {
            return [null, 'Forward DNS server must be a valid IPv4 or IPv6 address.'];
        }

        if ($port < 1 || $port > 65535) {
            return [null, 'Forward port must be between 1 and 65535.'];
        }

        return [[
            'domain'      => $domain,
            'dns_server'  => $server,
            'port'        => $port,
            'description' => $desc,
        ], null];
    }

    public function storeDomainOverride(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        [$data, $error] = $this->readDomainOverride();
        if ($error !== null) {
            return redirect()->to(base_url('dns?tab=hosts'))->with('error', $error);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dns_domain_overrides')->insert([
            'id'          => 'dov_' . bin2hex(random_bytes(6)),
            'domain'      => $data['domain'],
            'dns_server'  => $data['dns_server'],
            'port'        => $data['port'],
            'description' => $data['description'],
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            "Domain override for {$data['domain']} added.",
            'Added, but the DNS reload'
        );

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    public function updateDomainOverride(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $override = $db->table('dns_domain_overrides')->where('id', $id)->get()->getRowArray();
        if (!$override) {
            return redirect()->to(base_url('dns?tab=hosts'))->with('error', 'Domain override not found.');
        }

        [$data, $error] = $this->readDomainOverride();
        if ($error !== null) {
            return redirect()->to(base_url('dns?tab=hosts'))->with('error', $error);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $db->table('dns_domain_overrides')->where('id', $id)->update($data);
        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            'Domain override updated.',
            'Updated, but the DNS reload'
        );

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    public function toggleDomainOverride(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $override = $db->table('dns_domain_overrides')->where('id', $id)->get()->getRowArray();
        [$key, $message] = ['success', 'Domain override state updated.'];

        if ($override) {
            $db->table('dns_domain_overrides')->where('id', $id)->update([
                'enabled'    => ((int) $override['enabled'] === 1) ? 0 : 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            [$key, $message] = $this->ipcFlash(
                $this->applier()->dns(),
                'Domain override state updated.',
                'State saved, but the DNS reload'
            );
        }

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    public function deleteDomainOverride(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('dns_domain_overrides')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash(
            $this->applier()->dns(),
            'Domain override removed.',
            'Removed, but the DNS reload'
        );

        return redirect()->to(base_url('dns?tab=hosts'))->with($key, $message);
    }

    // ─────────────────────────────────────────────────────────────────
    // DNSBL feed management
    // ─────────────────────────────────────────────────────────────────
    public function storeFeed(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $name     = trim($this->request->getPost('name') ?? '');
        $url      = trim($this->request->getPost('url') ?? '');
        $category = trim($this->request->getPost('category') ?? 'Custom Feed');

        if (empty($name) || empty($url)) {
            return redirect()->to(base_url('dns?tab=feeds'))->with('error', 'Feed name and URL are required.');
        }

        // An operator-supplied URL is an SSRF surface in its own right. Reject
        // a non-public or non-https URL up front so the mistake is caught at
        // entry; the egress guard still resolves-and-pins at fetch time.
        $check = \Config\Services::egressGuard()->checkSyntax($url);
        if (! $check['ok']) {
            return redirect()->to(base_url('dns?tab=feeds'))
                ->with('error', "Feed URL rejected: {$check['error']}. Only public https feed URLs are allowed.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dns_feeds')->insert([
            'id'            => 'feed_' . bin2hex(random_bytes(6)),
            'name'          => $name,
            'url'           => $url,
            'category'      => $category,
            'entries_count' => 0, // populated on the next sync
            'enabled'       => 1,
            'last_updated'  => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return redirect()->to(base_url('dns?tab=feeds'))->with('success', "DNSBL Feed '{$name}' subscribed successfully. Sync to fetch and compile it.");
    }

    public function toggleFeed(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $feed = $db->table('dns_feeds')->where('id', $id)->get()->getRowArray();
        [$key, $message] = ['success', 'Feed state updated.'];

        if ($feed) {
            $newStatus = $feed['enabled'] ? 0 : 1;
            $db->table('dns_feeds')->where('id', $id)->update(['enabled' => $newStatus]);

            [$key, $message] = $this->ipcFlash(
                $this->ipc->call('dnsbl.update'),
                'Feed state updated.',
                'State saved, but the DNSBL rebuild'
            );
        }

        return redirect()->to(base_url('dns?tab=feeds'))->with($key, $message);
    }

    public function deleteFeed(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('dns_feeds')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('dnsbl.update'),
            'DNSBL feed removed.',
            'Removed, but the DNSBL rebuild'
        );

        return redirect()->to(base_url('dns?tab=feeds'))->with($key, $message);
    }

    public function syncFeeds(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $feeds = $db->table('dns_feeds')->where('enabled', 1)->orderBy('created_at', 'ASC')->get()->getResultArray();

        if ($feeds === []) {
            return redirect()->to(base_url('dns?tab=feeds'))->with('error', 'No enabled DNSBL feeds to synchronize.');
        }

        // Every operator-supplied URL is fetched through the connector egress
        // guard: scheme allowlist, resolve-and-pin, per-hop redirect validation
        // and stream caps. A refused or unparseable feed contributes nothing.
        $result = \Config\Services::manualFeedIngestor()->ingest($feeds);
        $now    = date('Y-m-d H:i:s');

        foreach ($result['feeds'] as $row) {
            if (! empty($row['ok'])) {
                $db->table('dns_feeds')->where('id', $row['id'])->update([
                    'entries_count' => (int) $row['entries'],
                    'last_updated'  => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        $entries = $result['entries'];

        // Never apply an empty set: an all-failed sync must leave the live
        // blocklist untouched, not blank it.
        if ($entries === []) {
            return redirect()->to(base_url('dns?tab=feeds'))->with('error', sprintf(
                'DNSBL sync failed: no feed produced a valid domain set (%d of %d feed(s) failed). The live blocklist was left unchanged.',
                (int) $result['failed'],
                count($feeds),
            ));
        }

        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('dnsbl.apply', ['domains' => $entries]),
            sprintf(
                'Synchronized %d feed(s): %d unique domains compiled, %d entries dropped.',
                (int) $result['synced'],
                count($entries),
                (int) $result['dropped'],
            ),
            'Feeds fetched, but the DNSBL rebuild'
        );

        if (! empty($result['failed'])) {
            $message .= sprintf(' %d feed(s) failed and were skipped.', (int) $result['failed']);
        }

        return redirect()->to(base_url('dns?tab=feeds'))->with($key, $message);
    }

    // ─────────────────────────────────────────────────────────────────
    // DNSBL Whitelist & Blacklist Overrides
    // ─────────────────────────────────────────────────────────────────
    public function storeOverride(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $domain  = strtolower(trim($this->request->getPost('domain') ?? ''));
        $action  = trim($this->request->getPost('action') ?? 'blacklist');
        $desc    = trim($this->request->getPost('description') ?? '');
        $wildcard = $this->request->getPost('is_wildcard') ? 1 : 0;

        if (empty($domain)) {
            return redirect()->to(base_url('dns?tab=rules'))->with('error', 'Domain is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dns_overrides')->insert([
            'id'          => 'ovr_' . bin2hex(random_bytes(6)),
            'domain'      => $domain,
            'action'      => $action === 'whitelist' ? 'whitelist' : 'blacklist',
            'is_wildcard' => $wildcard,
            'description' => $desc,
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $msg = $action === 'whitelist' ? "Domain '{$domain}' added to Whitelist." : "Domain '{$domain}' added to Blacklist.";

        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('dnsbl.update'),
            $msg,
            'Saved, but the DNSBL rebuild'
        );

        return redirect()->to(base_url('dns?tab=rules'))->with($key, $message);
    }

    public function deleteOverride(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('dns_overrides')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('dnsbl.update'),
            'Domain rule removed.',
            'Removed, but the DNSBL rebuild'
        );

        return redirect()->to(base_url('dns?tab=rules'))->with($key, $message);
    }

    public function quickWhitelist(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $domain = strtolower(trim($this->request->getPost('domain') ?? ''));

        [$key, $message] = ['success', "Domain '{$domain}' whitelisted successfully."];

        if (!empty($domain)) {
            $now = date('Y-m-d H:i:s');
            $db->table('dns_overrides')->insert([
                'id'          => 'ovr_' . bin2hex(random_bytes(6)),
                'domain'      => $domain,
                'action'      => 'whitelist',
                'is_wildcard' => 0,
                'description' => 'Quick whitelisted from Query Inspector',
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            [$key, $message] = $this->ipcFlash(
                $this->ipc->call('dnsbl.update'),
                "Domain '{$domain}' whitelisted successfully.",
                'Whitelisted, but the DNSBL rebuild'
            );
        }

        return redirect()->to(base_url('dns?tab=inspector'))->with($key, $message);
    }

    public function flush(): \CodeIgniter\HTTP\RedirectResponse
    {
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('dns.cache.flush'),
            'DNS resolver in-memory cache flushed successfully.',
            'DNS cache flush'
        );

        return redirect()->to(base_url('dns'))->with($key, $message);
    }
}
