<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Feature tests for Domain Overrides (per-domain forwarding):
 *   POST dns/domain-overrides/store|update|toggle|delete
 *   GET  dns?tab=hosts  (the Host Overrides tab must render the section)
 *
 * Auth and permission filters are satisfied through the session
 * (`user_id` + `user_permissions`), which PermissionModel reads before ever
 * touching the database. CSRF is satisfied by priming the cookie-based token.
 *
 * @internal
 */
final class DnsDomainOverridesTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    // Shared in-memory database, migrated once; DNS rows are cleared per test.
    protected $refresh = false;

    /** Permission keys the SuperAdmin-style session is granted. */
    private const PERMISSIONS = ['dns.view', 'dns.edit', 'dns.flush'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('dns_domain_overrides')->emptyTable();

        // Prime cookie-based CSRF: Security reads the token from the cookie on
        // construction, so seed it before the service is first resolved.
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    public function testHostsTabRendersDomainOverridesSection(): void
    {
        $this->seedOverride('dov_render', 'corp.internal');

        $result = $this->withSession($this->session())->get('dns?tab=hosts');

        $result->assertOK();
        $result->assertSee('Domain Overrides (Per-Domain Forwarding)');
        $result->assertSee('Local DNS Host Overrides');
        $result->assertSee('corp.internal');
        $result->assertSee('10.0.0.53');
        $this->assertStringContainsString('.dns-panel { display: none; }', $result->getBody());
        $this->assertStringContainsString('.dns-panel.active { display: block; }', $result->getBody());
    }

    public function testStoreCreatesEnabledOverride(): void
    {
        $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'      => 'corp.internal',
            'dns_server'  => '10.0.0.53',
            'port'        => '53',
            'description' => 'Internal AD resolver',
        ]))->assertRedirect();

        $row = $this->db->table('dns_domain_overrides')->where('domain', 'corp.internal')->get()->getRowArray();
        $this->assertNotNull($row, 'Domain override was not persisted.');
        $this->assertStringStartsWith('dov_', $row['id']);
        $this->assertSame('10.0.0.53', $row['dns_server']);
        $this->assertSame(53, (int) $row['port']);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame('Internal AD resolver', $row['description']);
    }

    public function testStoreLowercasesDomainAndStripsTrailingDot(): void
    {
        $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'     => 'Corp.Internal.',
            'dns_server' => '192.168.10.53',
        ]))->assertRedirect();

        $row = $this->db->table('dns_domain_overrides')->where('domain', 'corp.internal')->get()->getRowArray();
        $this->assertNotNull($row, 'Normalized domain was not persisted.');
    }

    public function testStoreRejectsInvalidDomain(): void
    {
        $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'     => 'bad domain!!',
            'dns_server' => '10.0.0.53',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('dns_domain_overrides')->countAllResults());
    }

    public function testStoreRejectsNonIpForwardServer(): void
    {
        $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'     => 'corp.example.com',
            'dns_server' => 'resolver.example.com',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('dns_domain_overrides')->countAllResults());
    }

    public function testStoreRejectsOutOfRangePort(): void
    {
        $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'     => 'corp.example.com',
            'dns_server' => '10.0.0.53',
            'port'       => '70000',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('dns_domain_overrides')->countAllResults());
    }

    public function testStoreAcceptsIpv6ForwardServer(): void
    {
        $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'     => 'v6.internal',
            'dns_server' => '2001:db8::53',
            'port'       => '5353',
        ]))->assertRedirect();

        $row = $this->db->table('dns_domain_overrides')->where('domain', 'v6.internal')->get()->getRowArray();
        $this->assertNotNull($row, 'IPv6 forward server was not persisted.');
        $this->assertSame(5353, (int) $row['port']);
    }

    public function testUpdateChangesFields(): void
    {
        $this->seedOverride('dov_upd', 'corp.internal');

        $this->post('dns/domain-overrides/dov_upd/update', $this->csrf([
            'domain'     => 'corp.internal',
            'dns_server' => '10.9.9.9',
            'port'       => '853',
        ]))->assertRedirect();

        $row = $this->db->table('dns_domain_overrides')->where('id', 'dov_upd')->get()->getRowArray();
        $this->assertSame('10.9.9.9', $row['dns_server']);
        $this->assertSame(853, (int) $row['port']);
    }

    public function testToggleFlipsEnabled(): void
    {
        $this->seedOverride('dov_tgl', 'corp.internal', ['enabled' => 1]);

        $this->post('dns/domain-overrides/dov_tgl/toggle', $this->csrf([]))->assertRedirect();

        $row = $this->db->table('dns_domain_overrides')->where('id', 'dov_tgl')->get()->getRowArray();
        $this->assertSame(0, (int) $row['enabled']);
    }

    public function testDeleteRemovesOverride(): void
    {
        $this->seedOverride('dov_del', 'corp.internal');

        $this->post('dns/domain-overrides/dov_del/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('dns_domain_overrides')->where('id', 'dov_del')->countAllResults());
    }

    public function testStoreSurfacesDaemonReloadFailure(): void
    {
        // The ipc service is resolved from the container, so a fake can be
        // injected to exercise the daemon-failure path without an appliance.
        // BaseTestCase discards injected mocks after each test, so no manual
        // restore is needed here.
        $ipc = new FakeIpcClient([
            'dns.config.reload' => [
                'result' => ['success' => false, 'status' => 'error', 'message' => 'unbound-checkconf: error opening config'],
                'error'  => null,
            ],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('dns/domain-overrides/store', $this->csrf([
            'domain'     => 'corp.internal',
            'dns_server' => '10.0.0.53',
            'port'       => '53',
        ]));

        $result->assertRedirect();

        // The injected instance actually received the call...
        $this->assertSame('dns.config.reload', $ipc->calls[0]['method']);
        // ...and the failed reload is surfaced to the operator.
        $result->assertSessionHas('error', 'Added, but the DNS reload failed: unbound-checkconf: error opening config');
        // The override itself is still persisted; only the reload failed.
        $this->assertNotNull(
            $this->db->table('dns_domain_overrides')->where('domain', 'corp.internal')->get()->getRowArray()
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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

    private function seedOverride(string $id, string $domain, array $overrides = []): void
    {
        $this->db->table('dns_domain_overrides')->insert(array_merge([
            'id'          => $id,
            'domain'      => $domain,
            'dns_server'  => '10.0.0.53',
            'port'        => 53,
            'description' => 'Seeded for tests',
            'enabled'     => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], $overrides));
    }
}
