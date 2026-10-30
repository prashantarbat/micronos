<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Regression tests for the 2026-10 completion pass.
 *
 * Each test pins one defect that was fixed: configuration that was saved but
 * never sent to the daemon, success messages that did not reflect the
 * daemon's answer, secrets rendered into HTML, unescaped flash messages,
 * invented counts, and the shared author footer.
 */
final class CompletionRegressionTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private FakeIpcClient $ipc;

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
        $this->ipc = new FakeIpcClient();
        Services::injectMock('ipc', $this->ipc);
        if ($this->db->table('system_settings')->where('id', 1)->countAllResults() === 0) {
            $this->db->table('system_settings')->insert(['id' => 1, 'hostname' => 'micronos-fw01', 'theme' => 'dark']);
        }
    }

    private function session(): array
    {
        $perms = ['dashboard.view', 'dns.view', 'dns.edit', 'qos.view', 'qos.apply', 'vpn.wireguard.view',
            'vpn.wireguard.manage', 'ha.view', 'system.info.view', 'system.settings.edit', 'diag.ping',
            'plugins.view', 'cron.view', 'system.view', 'docs.view'];

        return ['user_id' => 'usr_superadmin', 'username' => 'ministrator', 'is_superadmin' => 1,
            'permissions' => $perms, 'user_permissions' => $perms];
    }

    private function csrf(array $params = []): array
    {
        return $params + [config(SecurityConfig::class)->tokenName => service('security')->getHash()];
    }

    private function callsTo(string $method): array
    {
        return array_values(array_filter($this->ipc->calls, static fn ($c) => $c['method'] === $method));
    }

    // ── Author attribution ─────────────────────────────────────────────

    public function testEveryLayoutPageCarriesTheAuthorFooter(): void
    {
        foreach (['dashboard', 'diag', 'plugins', 'system', 'docs', 'cron'] as $page) {
            $body = (string) $this->withSession($this->session())->get($page)->getBody();
            $this->assertStringContainsString('Author: <strong>Prashant Arbat</strong>', $body, "{$page} must show the author");
            $this->assertStringContainsString('href="https://github.com/prashantarbat/micronos"', $body, "{$page} must link the project");
            $this->assertStringNotContainsString('mailto:prashant.arbat@gmail.com', $body, "{$page} must not show the author email");
            $this->assertSame(1, substr_count($body, '<footer class="app-footer'), "{$page} must render the footer exactly once");
        }
    }

    public function testTheSignInPageCarriesTheAuthorFooter(): void
    {
        $body = (string) $this->get('auth/login')->getBody();
        $this->assertStringContainsString('Prashant Arbat', $body);
    }

    public function testThe404PageCarriesTheAuthorFooter(): void
    {
        $body = (string) view('errors/html/error_404', ['message' => 'nope']);
        $this->assertStringContainsString('Prashant Arbat', $body);
    }

    // ── Flash messages are escaped ─────────────────────────────────────

    public function testFlashMessagesAreEscaped(): void
    {
        $_SESSION['success'] = 'Gateway <script>alert(1)</script> added.';
        session()->markAsFlashdata('success');
        $body = (string) $this->withSession($this->session() + ['success' => 'Gateway <script>alert(1)</script> added.'])
            ->get('dashboard')->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    // ── Compile-and-apply: configuration reaches the daemon ────────────

    public function testDnsChangesSendTheCompiledUnboundConfiguration(): void
    {
        $this->ipc->respond('dns.config.reload', ['result' => ['success' => true, 'status' => 'applied'], 'error' => null]);
        $this->withSession($this->session())->post('dns/domain-overrides/store', $this->csrf([
            'domain' => 'corp.example', 'dns_server' => '10.0.0.53', 'port' => '53', 'enabled' => '1',
        ]));

        $calls = $this->callsTo('dns.config.reload');
        $this->assertNotEmpty($calls, 'the DNS change must be pushed to the daemon');
        $this->assertStringContainsString('server:', $calls[0]['params']['config'] ?? '');
    }

    public function testADaemonRefusalIsReportedNotHiddenBehindSuccess(): void
    {
        $this->ipc->respond('qos.apply', ['result' => ['success' => false, 'status' => 'error',
            'message' => 'refusing tc batch line'], 'error' => null]);

        $result = $this->withSession($this->session())->post('qos/apply', $this->csrf());

        $this->assertNotEmpty($this->callsTo('qos.apply'));
        $this->assertStringContainsString('refusing tc batch line', (string) session('error'));
        $this->assertNull(session('success'));
    }

    // ── WireGuard ──────────────────────────────────────────────────────

    public function testWireguardSyncSendsTheCompiledInterfaceConfiguration(): void
    {
        $this->db->table('wireguard_tunnels')->insert([
            'id' => 'wgt_regress', 'name' => 'regress', 'interface_name' => 'wg9', 'listen_port' => 51829,
            'private_key' => 'aGVsbG8gd29ybGQgaGVsbG8gd29ybGQgaGVsbG8hIQ==', 'public_key' => 'cHViIGtleSBwdWIga2V5IHB1YiBrZXkgcHViIGs=',
            'addresses' => '10.99.0.1/24', 'ipv4_address' => '10.99.0.1', 'ipv4_subnet' => 24, 'mtu' => 1420, 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->ipc->respond('vpn.wireguard.sync', ['result' => ['success' => false, 'status' => 'error',
            'message' => 'wg-quick up failed'], 'error' => null]);

        $this->withSession($this->session())->post('vpn/wireguard/tunnels/wgt_regress/toggle', $this->csrf());

        $calls = $this->callsTo('vpn.wireguard.sync');
        $this->assertNotEmpty($calls);
        $this->assertSame('wg9', $calls[0]['params']['interface']);
        $this->assertStringContainsString('[Interface]', $calls[0]['params']['config']);
        $this->assertStringNotContainsString('SaveConfig', $calls[0]['params']['config']);
        $this->assertFalse($calls[0]['params']['enabled'], 'the toggle disabled the tunnel');
        $this->assertStringContainsString('wg-quick up failed', (string) session('error'),
            'a failed sync must not be reported as "brought up / torn down"');
    }

    public function testWireguardPageRestoresServiceControlsAndRemovesPeerIpHelpRow(): void
    {
        $response = $this->withSession($this->session())->get('vpn/wireguard');

        $response->assertOK();
        $html = $response->getBody();
        $this->assertStringContainsString('data-service="wireguard"', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringContainsString('data-action="restart"', $html);
        $this->assertStringContainsString('data-action="stop"', $html);
        $this->assertStringNotContainsString('Peer tunnel IP (optional)', $html);
    }

    public function testWireguardPeerStatusDistinguishesTunnelDownFromNoHandshake(): void
    {
        $tunnelId = 'wgstat_' . bin2hex(random_bytes(4));
        $peerKey  = 'cHViIGtleSBmb3Igd2lyZWd1YXJkIHBlZXI=';
        $this->db->table('wireguard_tunnels')->insert([
            'id' => $tunnelId, 'name' => 'Status test', 'interface_name' => 'wg7', 'listen_port' => 51827,
            'private_key' => 'aGVsbG8gd29ybGQgaGVsbG8gd29ybGQgaGVsbG8hIQ==', 'public_key' => 'cHViIGtleSBmb3IgdGhpcyB0dW5uZWw=',
            'addresses' => '10.97.0.1/24', 'ipv4_address' => '10.97.0.1', 'ipv4_subnet' => 24, 'mtu' => 1420, 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('wireguard_peers')->insert([
            'id' => 'wgpeer_' . bin2hex(random_bytes(4)), 'tunnel_id' => $tunnelId, 'name' => 'Status peer',
            'public_key' => $peerKey, 'allowed_ips' => '10.97.0.2/32', 'persistent_keepalive' => 25, 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->respond('vpn.wireguard.status', [
            'result' => ['status' => 'ok', 'interfaces' => [], 'peers' => []], 'error' => null,
        ]);
        $down = $this->withSession($this->session())->get('vpn/wireguard')->getBody();
        $this->assertStringContainsString('Tunnel down', $down);

        $this->ipc->respond('vpn.wireguard.status', [
            'result' => [
                'status' => 'ok',
                'interfaces' => ['wg7' => ['interface' => 'wg7', 'status' => 'up']],
                'peers' => [['peer' => $peerKey, 'latest_handshake' => 0]],
            ],
            'error' => null,
        ]);
        $up = $this->withSession($this->session())->get('vpn/wireguard')->getBody();
        $this->assertStringContainsString('No handshake', $up);
    }

    // ── High availability ──────────────────────────────────────────────

    public function testHaSecretsAreNeverRenderedIntoThePage(): void
    {
        $secret = 'Vr7pQ2x!';
        $apiKey = 'sync-api-key-should-stay-server-side';
        $model  = new \App\Models\HaSettingsModel();
        $row    = $model->getSettings();
        $model->update($row['id'], ['vrrp_auth_pass' => $secret, 'config_sync_api_key' => $apiKey]);

        $body = (string) $this->withSession($this->session())->get('ha')->getBody();

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString($apiKey, $body);
    }

    // ── Diagnostics: real counts, no invented output on a live box ─────

    public function testKillHostStatesReportsTheDaemonsCount(): void
    {
        $this->ipc->respond('diag.conntrack_kill_host', ['result' => ['success' => true, 'killed_count' => 4], 'error' => null]);

        $data = json_decode((string) $this->withSession($this->session())
            ->post('diag/states/kill-host', $this->csrf(['host' => '10.0.0.9']))->getJSON(), true);

        $this->assertSame(4, $data['killed_count']);
    }

    public function testRouteLookupDoesNotInventARouteWhenTheDaemonIsSilent(): void
    {
        $result = $this->withSession($this->session())->post('diag/route-lookup', $this->csrf(['target' => '8.8.8.8']));

        $data = json_decode((string) $result->getJSON(), true);
        $this->assertSame(503, $result->response()->getStatusCode());
        $this->assertStringNotContainsString('FIB Route Decision', $data['output']);
    }

    // ── Contract: every route permission is catalogued ─────────────────

    public function testEveryRoutePermissionExistsInTheCatalogue(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        preg_match_all("/permission:([a-z0-9_.]+)/", $routes, $m);
        $keys = array_unique($m[1]);

        $catalogue = array_column($this->db->table('permissions')->select('key')->get()->getResultArray(), 'key');
        $seeder    = file_get_contents(APPPATH . 'Database/Seeds/RolesPermissionsSeeder.php');
        $migrations = implode("\n", array_map('file_get_contents', glob(APPPATH . 'Database/Migrations/*.php')));

        $missing = [];
        foreach ($keys as $key) {
            if (! in_array($key, $catalogue, true)
                && ! str_contains($seeder, "'{$key}'")
                && ! str_contains($migrations, "'{$key}'")) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'route permission keys that no role can ever be granted');
    }
}
