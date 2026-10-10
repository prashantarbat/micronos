<?php

use App\Libraries\DynamicDnsRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Covers the store behind the dynamic half of the DNS Server Override:
 * recording what the interface path reports, dropping it again, and the
 * validation that keeps garbage out of the compiled resolv.conf.
 *
 * The suite shares one in-memory database, so the table is emptied in setUp
 * and tearDown rather than left for a later DNS test to trip over.
 *
 * @internal
 */
final class DynamicDnsRegistryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const REPORT = [
        [
            'source'       => 'dhcp',
            'interface'    => 'WAN',
            'interface_id' => 'wan',
            'servers'      => ['10.0.0.138', '10.0.0.139'],
        ],
        [
            'source'    => 'openvpn',
            'interface' => 'ovpn-client1',
            'servers'   => ['10.8.0.1'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('dynamic_dns_servers')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->db->table('dynamic_dns_servers')->emptyTable();
        parent::tearDown();
    }

    private function registry(): DynamicDnsRegistry
    {
        return new DynamicDnsRegistry($this->db);
    }

    public function testRecordsAReportedSource(): void
    {
        $stored = $this->registry()->record(self::REPORT);

        $this->assertSame(3, $stored);
        $this->assertSame(['10.0.0.138', '10.0.0.139', '10.8.0.1'], $this->registry()->servers());

        $rows = $this->registry()->all();
        $this->assertCount(3, $rows);
        $this->assertSame('wan', $rows[0]['interface_id']);
        $this->assertSame('WAN', $rows[0]['interface_name']);
        $this->assertNotEmpty($rows[0]['assigned_at']);
    }

    /** A second reconcile replaces the record; a link that went away is dropped. */
    public function testRecordReplacesThePreviousReport(): void
    {
        $registry = $this->registry();
        $registry->record(self::REPORT);

        $registry->record([
            ['source' => 'ppp', 'interface' => 'WAN_PPP', 'servers' => ['100.64.0.53']],
        ]);

        $this->assertSame(['100.64.0.53'], $registry->servers());
        $this->assertSame(1, count($registry->all()));
    }

    /** Invalid addresses, unknown sources and duplicates are all filtered out. */
    public function testRecordValidatesSourcesAndAddresses(): void
    {
        $stored = $this->registry()->record([
            ['source' => 'dhcp',  'interface' => 'WAN', 'servers' => ['10.0.0.1', '10.0.0.1', 'not-an-ip', '']],
            ['source' => 'bogus', 'interface' => 'X',   'servers' => ['8.8.8.8']],
            ['source' => 'ppp',   'interface' => 'P',   'servers' => [' 100.64.0.1 ', '2606:4700:4700::1111']],
            'not-an-array',
        ]);

        $this->assertSame(3, $stored);
        $this->assertSame(['10.0.0.1', '100.64.0.1', '2606:4700:4700::1111'], $this->registry()->servers());
    }

    /** The store never grows past the daemon's ceiling. */
    public function testRecordCapsAtEightServers(): void
    {
        $servers = [];
        for ($i = 1; $i <= 12; $i++) {
            $servers[] = "10.20.30.{$i}";
        }

        $stored = $this->registry()->record([['source' => 'dhcp', 'interface' => 'WAN', 'servers' => $servers]]);

        $this->assertSame(8, $stored);
        $this->assertCount(8, $this->registry()->servers());
    }

    /** Servers come back WAN-first: DHCP, then PPP, then OpenVPN. */
    public function testServersAreOrderedWanFirst(): void
    {
        $this->registry()->record([
            ['source' => 'openvpn', 'interface' => 'o', 'servers' => ['10.8.0.1']],
            ['source' => 'ppp',     'interface' => 'p', 'servers' => ['100.64.0.53']],
            ['source' => 'dhcp',    'interface' => 'd', 'servers' => ['10.0.0.1']],
        ]);

        $this->assertSame(['10.0.0.1', '100.64.0.53', '10.8.0.1'], $this->registry()->servers());
    }

    public function testClearDropsEverythingAndReportsTheCount(): void
    {
        $this->registry()->record(self::REPORT);

        $this->assertSame(3, $this->registry()->clear());
        $this->assertSame([], $this->registry()->servers());
        $this->assertSame(0, $this->registry()->clear(), 'a second clear is a no-op');
    }

    public function testAvailableReflectsTheMigratedTable(): void
    {
        $this->assertTrue($this->registry()->available());
        $this->assertTrue(DynamicDnsRegistry::isValidSource('DHCP'));
        $this->assertFalse(DynamicDnsRegistry::isValidSource('manual'));
    }
}
