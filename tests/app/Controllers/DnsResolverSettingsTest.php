<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Feature tests for the appliance DNS resolution settings on /dns?tab=settings:
 *
 *   GET  dns?tab=settings  — both pfSense-style controls are rendered
 *   POST dns/resolver      — the checkbox and select are persisted, the stub
 *                            resolver file is compiled and applied via IPC
 *
 * The suite shares one in-memory database, so the single `dns_settings` row is
 * snapshotted in setUp and restored in tearDown: leaving a mutated row behind
 * would change what every later DNS test renders.
 *
 * @internal
 */
final class DnsResolverSettingsTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    protected $refresh = false;

    /** Permission keys the SuperAdmin-style session is granted. */
    private const PERMISSIONS = ['dns.view', 'dns.edit', 'dns.flush'];

    /** @var array<string, mixed>|null */
    private ?array $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();

        // Prime cookie-based CSRF: Security reads the token from the cookie on
        // construction, so seed it before the service is first resolved.
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->delete();
        if (is_array($this->original)) {
            $this->db->table('dns_settings')->insert($this->original);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /** Both controls and their help text are on the settings tab. */
    public function testSettingsTabRendersTheResolutionControls(): void
    {
        $result = $this->withSession($this->session())->get('dns?tab=settings');

        $result->assertOK();
        $result->assertSee('DNS Resolution Behavior');
        $result->assertSee('DNS Server Override');
        $result->assertSee('Allow DNS server list to be overridden by DHCP/PPP on WAN or remote OpenVPN server');
        $result->assertSee('Use local DNS (127.0.0.1), fall back to remote DNS Servers (Default)');
        $result->assertSee('Use local DNS (127.0.0.1), ignore remote DNS Servers');
        $result->assertSee('Use remote DNS Servers, ignore local DNS');
        // The help text explains how the firewall itself resolves.
        $result->assertSee('This option is more secure as it forces DNS');
        $result->assertSee('the firewall still needs unrestricted access to DNS');
    }

    /** The page reports the servers the firewall is currently using. */
    public function testPageReportsNameserversInEffect(): void
    {
        $result = $this->withSession($this->session())->get('dns?tab=settings');

        $result->assertOK();
        $result->assertSee('Currently in effect:');
        $result->assertSee('127.0.0.1');
        $result->assertSee('/etc/resolv.conf');
    }

    /** An unknown stored behavior renders as the default rather than a blank select. */
    public function testUnknownStoredBehaviorRendersAsDefault(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->update(['dns_resolution_behavior' => 'garbage']);

        $result = $this->withSession($this->session())->get('dns?tab=settings');

        $result->assertOK();
        $result->assertSee('(Default)');
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    public function testSavePersistsOverrideAndBehaviorAndAppliesTheConfig(): void
    {
        $ipc = $this->verifyingIpc();

        $this->post('dns/resolver', $this->csrf([
            'dns_server_override'     => '1',
            'dns_resolution_behavior' => 'local-only',
        ]))->assertRedirect();

        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->assertSame(1, (int) $row['dns_server_override']);
        $this->assertSame('local-only', $row['dns_resolution_behavior']);

        $this->assertSame('dns.resolver.apply', $ipc->calls[0]['method']);
        $this->assertSame('local-only', $ipc->calls[0]['params']['behavior']);
        $this->assertTrue($ipc->calls[0]['params']['override']);

        $config = $ipc->calls[0]['params']['config'];
        $this->assertStringContainsString("\nnameserver 127.0.0.1\n", $config);
        $this->assertStringNotContainsString('nameserver 1.1.1.1', $config, 'local-only must not list remote servers');
        $this->assertStringContainsString('# DNS Resolution Behavior: Use local DNS (127.0.0.1), ignore remote DNS Servers', $config);
    }

    /** The checkbox is a real checkbox: absent means unchecked. */
    public function testUncheckedOverrideIsPersistedAndCompiled(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->update(['dns_server_override' => 1]);
        $ipc = $this->verifyingIpc();

        $this->post('dns/resolver', $this->csrf([
            'dns_resolution_behavior' => 'local-fallback',
        ]))->assertRedirect();

        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->assertSame(0, (int) $row['dns_server_override']);

        $this->assertFalse($ipc->calls[0]['params']['override']);
        $this->assertStringContainsString('# dynamic-wan-override: disabled', $ipc->calls[0]['params']['config']);
        $this->assertStringContainsString('# DNS Server Override: disabled', $ipc->calls[0]['params']['config']);
    }

    /** remote-only drops 127.0.0.1 from the compiled file. */
    public function testRemoteOnlyIsAppliedWithoutTheLocalResolver(): void
    {
        $ipc = $this->verifyingIpc();

        $this->post('dns/resolver', $this->csrf([
            'dns_server_override'     => '1',
            'dns_resolution_behavior' => 'remote-only',
        ]))->assertRedirect();

        $config = $ipc->calls[0]['params']['config'];
        $this->assertStringNotContainsString('nameserver 127.0.0.1', $config);
        $this->assertStringContainsString("\nnameserver 1.1.1.1\n", $config);
    }

    /** An unknown behavior is refused, and nothing is written. */
    public function testUnknownBehaviorIsRejected(): void
    {
        $ipc = $this->verifyingIpc();

        $result = $this->post('dns/resolver', $this->csrf([
            'dns_server_override'     => '1',
            'dns_resolution_behavior' => 'split-horizon',
        ]));

        $result->assertRedirect();
        $result->assertSessionHas('error', "'split-horizon' is not a recognized DNS resolution behavior.");

        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->assertSame('local-fallback', (string) $row['dns_resolution_behavior']);
        $this->assertSame([], $ipc->calls, 'a rejected save must not touch the daemon');
    }

    /** remote-only with nothing to resolve against cannot be saved. */
    public function testRemoteOnlyWithoutRemoteServersIsRejected(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->update(['upstream_servers' => '[]']);
        $ipc = $this->verifyingIpc();

        $result = $this->post('dns/resolver', $this->csrf([
            'dns_resolution_behavior' => 'remote-only',
        ]));

        $result->assertRedirect();
        $result->assertSessionHas('error');

        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->assertSame('local-fallback', (string) $row['dns_resolution_behavior']);
        $this->assertSame([], $ipc->calls);
    }

    /** A client that omits the select (pre-existing page) gets the default. */
    public function testMissingBehaviorFallsBackToTheDefault(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->update(['dns_resolution_behavior' => 'local-only']);
        $this->verifyingIpc();

        $this->post('dns/resolver', $this->csrf(['dns_server_override' => '1']))->assertRedirect();

        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->assertSame('local-fallback', $row['dns_resolution_behavior']);
    }

    /** A failed apply is reported; the setting itself is not lied about. */
    public function testDaemonFailureIsSurfaced(): void
    {
        $ipc = new FakeIpcClient([
            'dns.resolver.apply' => [
                'result' => ['success' => false, 'status' => 'error', 'message' => 'refusing to apply a resolver config with no nameserver'],
                'error'  => null,
            ],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('dns/resolver', $this->csrf([
            'dns_resolution_behavior' => 'local-only',
        ]));

        $result->assertRedirect();
        $result->assertSessionHas(
            'error',
            'DNS resolution behavior saved, but applying the resolver configuration failed: refusing to apply a resolver config with no nameserver',
        );

        // The setting is persisted (the operator can see and correct it), but
        // the failure is not reported as success.
        $row = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->assertSame('local-only', $row['dns_resolution_behavior']);
        $this->assertSame('dns.resolver.apply', $ipc->calls[0]['method']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Injects an IpcClient that accepts the resolver apply. */
    private function verifyingIpc(): FakeIpcClient
    {
        $ipc = new FakeIpcClient([
            'dns.resolver.apply' => [
                'result' => ['success' => true, 'status' => 'applied', 'file' => '/etc/resolv.conf', 'nameservers' => ['127.0.0.1']],
                'error'  => null,
            ],
        ]);
        Services::injectMock('ipc', $ipc);

        return $ipc;
    }

    /** Returns the session array with the granted permission set. */
    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    /** Returns POST params with the current CSRF token merged in. */
    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }
}
