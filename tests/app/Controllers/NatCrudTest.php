<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for the object-first NAT CRUD routes:
 *   POST nat/dnat/store|update|toggle|delete
 *   POST nat/snat-pools/store|update|toggle|delete
 *   GET  nat?tab=dnat|snat
 *
 * Auth and permission filters are satisfied through the session
 * (`user_id` + `user_permissions`), which PermissionModel reads before ever
 * touching the database. CSRF is satisfied by priming the cookie-based token.
 *
 * @internal
 */
final class NatCrudTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    // Shared in-memory database, migrated once. Individual NAT rows are cleared
    // per test so tests stay isolated without a full (down-method) regression.
    protected $refresh = false;

    /** Permission keys the SuperAdmin-style session is granted. */
    private const PERMISSIONS = ['nat.view', 'nat.edit', 'nat.delete', 'nat.apply'];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('nat_dnat')->emptyTable();
        $this->db->table('nat_snat_pools')->emptyTable();

        // Prime cookie-based CSRF: Security reads the token from the cookie on
        // construction, so seed it before the service is first resolved.
        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    // ------------------------------------------------------------------
    // Destination NAT
    // ------------------------------------------------------------------

    public function testStoreDnatCreatesRule(): void
    {
        $result = $this->post('nat/dnat/store', $this->csrf([
            'name'           => 'Web Forward',
            'interface_id'   => 'wan',
            'external_ip'    => '203.0.113.10',
            'external_port'  => '8443',
            'protocol'       => 'tcp',
            'mapped_ip'      => '192.168.1.50',
            'mapped_port'    => '443',
            'nat_reflection' => '1',
            'enabled'        => '1',
            'comment'        => 'Public web server',
        ]));

        $result->assertRedirect();

        $row = $this->db->table('nat_dnat')->where('name', 'Web Forward')->get()->getRowArray();
        $this->assertNotNull($row, 'DNAT rule was not persisted.');
        $this->assertStringStartsWith('dnat_', $row['id']);
        $this->assertSame('203.0.113.10', $row['external_ip']);
        $this->assertSame(8443, (int) $row['external_port']);
        $this->assertSame('192.168.1.50', $row['mapped_ip']);
        $this->assertSame(443, (int) $row['mapped_port']);
        $this->assertSame(1, (int) $row['nat_reflection']);
    }

    public function testStoreDnatRejectsInvalidIp(): void
    {
        $this->post('nat/dnat/store', $this->csrf([
            'name'        => 'Bad Forward',
            'external_ip' => 'not-an-ip',
            'mapped_ip'   => '192.168.1.50',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('nat_dnat')->where('name', 'Bad Forward')->countAllResults());
    }

    public function testStoreDnatRejectsDuplicateName(): void
    {
        $this->seedDnat('dnat_dup', 'Duplicate');

        $this->post('nat/dnat/store', $this->csrf([
            'name'        => 'Duplicate',
            'external_ip' => '203.0.113.99',
            'mapped_ip'   => '192.168.1.99',
        ]))->assertRedirect();

        $this->assertSame(1, $this->db->table('nat_dnat')->where('name', 'Duplicate')->countAllResults());
    }

    public function testUpdateDnatChangesFields(): void
    {
        $this->seedDnat('dnat_upd', 'Original', ['mapped_ip' => '192.168.1.10']);

        $this->post('nat/dnat/dnat_upd/update', $this->csrf([
            'name'        => 'Original',
            'external_ip' => '203.0.113.10',
            'mapped_ip'   => '192.168.1.20',
            'mapped_port' => '8080',
        ]))->assertRedirect();

        $row = $this->db->table('nat_dnat')->where('id', 'dnat_upd')->get()->getRowArray();
        $this->assertSame('192.168.1.20', $row['mapped_ip']);
        $this->assertSame(8080, (int) $row['mapped_port']);
    }

    public function testToggleDnatFlipsEnabled(): void
    {
        $this->seedDnat('dnat_tgl', 'Toggle Me', ['enabled' => 1]);

        $this->post('nat/dnat/dnat_tgl/toggle', $this->csrf([]))->assertRedirect();

        $row = $this->db->table('nat_dnat')->where('id', 'dnat_tgl')->get()->getRowArray();
        $this->assertSame(0, (int) $row['enabled']);
    }

    public function testDeleteDnatRemovesRule(): void
    {
        $this->seedDnat('dnat_del', 'Delete Me');

        $this->post('nat/dnat/dnat_del/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('nat_dnat')->where('id', 'dnat_del')->countAllResults());
    }

    // ------------------------------------------------------------------
    // SNAT pools
    // ------------------------------------------------------------------

    public function testStoreSnatPoolCreatesRange(): void
    {
        $this->post('nat/snat-pools/store', $this->csrf([
            'name'         => 'Outbound Pool',
            'interface_id' => 'wan',
            'start_ip'     => '203.0.113.20',
            'end_ip'       => '203.0.113.30',
            'comment'      => 'Egress range',
        ]))->assertRedirect();

        $row = $this->db->table('nat_snat_pools')->where('name', 'Outbound Pool')->get()->getRowArray();
        $this->assertNotNull($row, 'SNAT pool was not persisted.');
        $this->assertStringStartsWith('snat_', $row['id']);
        $this->assertSame('203.0.113.20', $row['start_ip']);
        $this->assertSame('203.0.113.30', $row['end_ip']);
    }

    public function testStoreSnatPoolRejectsInvalidStartIp(): void
    {
        $this->post('nat/snat-pools/store', $this->csrf([
            'name'     => 'Bad Pool',
            'start_ip' => '999.1.1.1',
        ]))->assertRedirect();

        $this->assertSame(0, $this->db->table('nat_snat_pools')->where('name', 'Bad Pool')->countAllResults());
    }

    public function testToggleSnatPoolFlipsEnabled(): void
    {
        $this->seedSnatPool('snat_tgl', 'Toggle Pool', ['enabled' => 1]);

        $this->post('nat/snat-pools/snat_tgl/toggle', $this->csrf([]))->assertRedirect();

        $row = $this->db->table('nat_snat_pools')->where('id', 'snat_tgl')->get()->getRowArray();
        $this->assertSame(0, (int) $row['enabled']);
    }

    public function testDeleteSnatPoolRemovesPool(): void
    {
        $this->seedSnatPool('snat_del', 'Delete Pool');

        $this->post('nat/snat-pools/snat_del/delete', $this->csrf([]))->assertRedirect();

        $this->assertSame(0, $this->db->table('nat_snat_pools')->where('id', 'snat_del')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Rendering & access control
    // ------------------------------------------------------------------

    public function testIndexRendersStoredObjects(): void
    {
        $this->seedDnat('dnat_view', 'Rendered DNAT');
        $this->seedSnatPool('snat_view', 'Rendered Pool');

        $this->get('nat?tab=dnat', $this->session())->assertOK();
        $this->get('nat?tab=snat', $this->session())->assertOK();
    }

    public function testStoreWithoutPermissionIsForbidden(): void
    {
        // A browser (non-AJAX) request without nat.edit is redirected to the
        // dashboard with an error rather than reaching the controller.
        $this->withSession([
            'user_id'          => 'usr_limited',
            'username'         => 'limited',
            'is_superadmin'    => 0,
            'permissions'      => ['nat.view'],
            'user_permissions' => ['nat.view'], // no nat.edit
        ]);

        $this->post('nat/dnat/store', [
            'csrf_test_name' => service('security')->getHash(),
            'name'           => 'Unauthorised',
            'external_ip'    => '203.0.113.10',
            'mapped_ip'      => '192.168.1.50',
        ])->assertRedirect();

        $this->assertSame(0, $this->db->table('nat_dnat')->where('name', 'Unauthorised')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Returns the session array with the granted permission set. */
    private function session(): array
    {
        // `permissions` + `is_superadmin` are pre-populated so BaseController
        // does not fall back to querying user_roles/roles for this session.
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    /**
     * Returns POST params with the current (possibly rotated) CSRF token
     * merged in, and applies the authenticated session to the next request.
     */
    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    private function seedDnat(string $id, string $name, array $overrides = []): void
    {
        $this->db->table('nat_dnat')->insert(array_merge([
            'id'             => $id,
            'name'           => $name,
            'interface_id'   => 'wan',
            'external_ip'    => '203.0.113.1',
            'external_port'  => null,
            'protocol'       => 'tcp',
            'mapped_ip'      => '192.168.1.2',
            'mapped_port'    => null,
            'nat_reflection' => 0,
            'bidirectional'  => 0,
            'comment'        => null,
            'enabled'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    private function seedSnatPool(string $id, string $name, array $overrides = []): void
    {
        $this->db->table('nat_snat_pools')->insert(array_merge([
            'id'           => $id,
            'name'         => $name,
            'interface_id' => 'wan',
            'start_ip'     => '203.0.113.20',
            'end_ip'       => null,
            'comment'      => null,
            'enabled'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], $overrides));
    }
}
