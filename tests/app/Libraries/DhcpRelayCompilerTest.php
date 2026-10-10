<?php

declare(strict_types=1);

use App\Libraries\DhcpRelayCompiler;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

/**
 * DhcpRelayCompiler: group relays by destination, build the dhcp-helper argv,
 * and surface the UDP/67 conflict and unusable destinations (DHCP Relay).
 *
 * @internal
 */
final class DhcpRelayCompilerTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        foreach (['dhcp_relay_interfaces', 'dhcp_relay_destinations', 'dhcp_server_settings'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->emptyTable();
            }
        }
    }

    private function destination(string $id, string $name, string $servers): void
    {
        $this->db->table('dhcp_relay_destinations')->insert([
            'id' => $id, 'name' => $name, 'servers' => $servers,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function relay(string $id, string $iface, ?string $destId, int $enabled = 1): void
    {
        $this->db->table('dhcp_relay_interfaces')->insert([
            'id' => $id, 'interface_id' => $iface, 'destination_id' => $destId, 'enabled' => $enabled,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testGroupsInterfacesByDestinationAndBuildsTheCommand(): void
    {
        $this->destination('d1', 'Branch DHCP', '10.0.0.10, 10.0.0.11');
        $this->relay('r1', 'dmz', 'd1');
        $this->relay('r2', 'opt1', 'd1');

        $plan = (new DhcpRelayCompiler())->compile();

        $this->assertTrue($plan['enabled']);
        $this->assertSame([], $plan['conflicts']);
        $this->assertCount(1, $plan['instances']);

        $inst = $plan['instances'][0];
        $this->assertSame('Branch DHCP', $inst['name']);
        $this->assertSame('branch-dhcp', $inst['slug']);
        $this->assertSame(['dmz', 'opt1'], $inst['interfaces']);
        $this->assertSame(['10.0.0.10', '10.0.0.11'], $inst['servers']);
        $this->assertSame('dhcp-helper@branch-dhcp.service', $inst['unit']);
        $this->assertContains('-i', $inst['args']);
        $this->assertContains('dmz', $inst['args']);
        $this->assertContains('10.0.0.10', $inst['args']);
    }

    public function testSkipsDisabledRelays(): void
    {
        $this->destination('d1', 'Branch', '10.0.0.10');
        $this->relay('r1', 'dmz', 'd1', 0);

        $plan = (new DhcpRelayCompiler())->compile();

        $this->assertFalse($plan['enabled']);
        $this->assertSame([], $plan['instances']);
    }

    public function testReportsAConflictWhenTheInterfaceAlsoRunsTheDhcpServer(): void
    {
        $this->destination('d1', 'Branch', '10.0.0.10');
        $this->relay('r1', 'lan', 'd1');
        $this->db->table('dhcp_server_settings')->insert([
            'interface_id' => 'lan', 'enabled' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $plan = (new DhcpRelayCompiler())->compile();

        $this->assertNotEmpty($plan['conflicts']);
        $this->assertStringContainsString('UDP/67', $plan['conflicts'][0]);
        $this->assertSame([], $plan['instances']);
    }

    public function testWarnsWhenADestinationHasNoValidServer(): void
    {
        $this->destination('d1', 'Broken', 'not-an-ip');
        $this->relay('r1', 'dmz', 'd1');

        $plan = (new DhcpRelayCompiler())->compile();

        $this->assertFalse($plan['enabled']);
        $this->assertNotEmpty($plan['warnings']);
    }

    public function testWarnsWhenARelayHasNoDestination(): void
    {
        $this->relay('r1', 'dmz', null);

        $plan = (new DhcpRelayCompiler())->compile();

        $this->assertNotEmpty($plan['warnings']);
        $this->assertFalse($plan['enabled']);
    }

    public function testEntryArgsAreShapedForDhcpHelper(): void
    {
        $args = DhcpRelayCompiler::entryArgs('branch', ['dmz'], ['10.0.0.10']);

        $this->assertSame('/usr/sbin/dhcp-helper', $args[0]);
        $this->assertContains('-i', $args);
        $this->assertContains('-s', $args);
        $this->assertContains('-r', $args);
        $this->assertContains('/var/run/dhcp-helper-branch.pid', $args);
    }
}
