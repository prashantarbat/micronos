<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Feature test for the interface path's dynamic half of the DNS Server
 * Override: applying network changes must merge DHCP/PPP/OpenVPN-assigned DNS
 * servers into the appliance resolver while the override is enabled, and drop
 * them when it is not.
 *
 * The daemon is replaced by a FakeIpcClient so the report is deterministic and
 * the calls the path makes are observable. The shared `dns_settings` and
 * `dynamic_dns_servers` state is snapshotted and restored.
 *
 * @internal
 */
final class InterfacesApplyResolverTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['interfaces.view', 'interfaces.apply'];

    /** @var array<string, mixed>|null */
    private ?array $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->db->table('dns_settings')->where('id', 'global')->delete();
        $this->db->table('dynamic_dns_servers')->emptyTable();

        $this->db->table('dns_settings')->insert([
            'id'                      => 'global',
            'enable_dnssec'           => 1,
            'enable_dnsbl'            => 1,
            'upstream_mode'           => 'dot',
            'upstream_servers'        => json_encode([['ip' => '1.1.1.1']]),
            'cache_min_ttl'           => 60,
            'cache_max_ttl'           => 86400,
            'blocked_query_action'    => '0.0.0.0',
            'dns_server_override'     => 1,
            'dns_resolution_behavior' => 'local-fallback',
            'updated_at'              => '2026-10-06 00:00:00',
        ]);

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->delete();
        if (is_array($this->original)) {
            $this->db->table('dns_settings')->insert($this->original);
        }

        $this->db->table('dynamic_dns_servers')->emptyTable();

        parent::tearDown();
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'admin',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function applyPost(): \CodeIgniter\Test\TestResponse
    {
        $this->withSession($this->session());

        return $this->post('interfaces/apply', [
            'csrf_test_name' => service('security')->getHash(),
        ]);
    }

    public function testApplyMergesReportedDynamicServersWhenOverrideIsEnabled(): void
    {
        $ipc = new FakeIpcClient([
            'network.apply'         => ['error' => null, 'result' => ['status' => 'applied']],
            'interface.dynamic_dns' => ['error' => null, 'result' => [
                'success' => true,
                'sources' => [['source' => 'dhcp', 'interface' => 'WAN', 'servers' => ['10.0.0.138']]],
            ]],
            'dns.resolver.apply'    => ['error' => null, 'result' => ['status' => 'applied']],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->applyPost();

        $result->assertRedirect();

        // The dynamic server was recorded and reported to the daemon.
        $this->assertSame(['10.0.0.138'], (new \App\Libraries\DynamicDnsRegistry($this->db))->servers());

        $applyCall = null;
        foreach ($ipc->calls as $call) {
            if ($call['method'] === 'dns.resolver.apply') {
                $applyCall = $call;
            }
        }
        $this->assertNotNull($applyCall, 'the resolver must be re-applied');
        $this->assertStringContainsString('nameserver 10.0.0.138', (string) $applyCall['params']['config']);

        $this->assertSame(['network.apply', 'interface.dynamic_dns', 'dns.resolver.apply'], array_column($ipc->calls, 'method'));
    }

    public function testApplyDropsDynamicServersWhenOverrideIsDisabled(): void
    {
        // Seed a previously recorded server that must be dropped by this apply.
        $this->db->table('dynamic_dns_servers')->insert([
            'id'             => 'dyn_old',
            'source'         => 'dhcp',
            'interface_id'   => null,
            'interface_name' => 'WAN',
            'address'        => '10.0.0.138',
            'assigned_at'    => '2026-10-06 00:00:00',
            'updated_at'     => '2026-10-06 00:00:00',
        ]);
        $this->db->table('dns_settings')->where('id', 'global')->update(['dns_server_override' => 0]);

        $ipc = new FakeIpcClient([
            'network.apply'      => ['error' => null, 'result' => ['status' => 'applied']],
            'dns.resolver.apply' => ['error' => null, 'result' => ['status' => 'applied']],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->applyPost()->assertRedirect();

        $this->assertSame([], (new \App\Libraries\DynamicDnsRegistry($this->db))->servers(), 'the dropped server must be gone');

        // With the override off the report is never requested.
        $this->assertNotContains('interface.dynamic_dns', array_column($ipc->calls, 'method'));

        $applyCall = null;
        foreach ($ipc->calls as $call) {
            if ($call['method'] === 'dns.resolver.apply') {
                $applyCall = $call;
            }
        }
        $this->assertNotNull($applyCall);
        $this->assertStringNotContainsString('nameserver 10.0.0.138', (string) $applyCall['params']['config']);
        $this->assertFalse($applyCall['params']['override']);
    }
}
