<?php

use App\Libraries\UnboundDnsCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Covers the database-driven sections of the Unbound resolver compiler:
 * local host overrides (`dns_hosts` → local-data) and Domain Overrides
 * (`dns_domain_overrides` → per-domain forward-zones).
 *
 * The full appliance migration set runs against the in-memory `tests`
 * database, so the compiler sees real schema and rows inserted here.
 *
 * @internal
 */
final class UnboundDnsCompilerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('dns_hosts')->emptyTable();
        $this->db->table('dns_domain_overrides')->emptyTable();
    }

    private function compile(): string
    {
        return (new UnboundDnsCompiler($this->db))->compile();
    }

    private function insertDomainOverride(array $overrides): void
    {
        $this->db->table('dns_domain_overrides')->insert(array_merge([
            'id'          => 'dov_test',
            'domain'      => 'corp.internal',
            'dns_server'  => '10.0.0.53',
            'port'        => 53,
            'description' => 'Internal resolver',
            'enabled'     => 1,
            'created_at'  => '2026-10-04 00:00:00',
            'updated_at'  => '2026-10-04 00:00:00',
        ], $overrides));
    }

    /** Host overrides are emitted as local-data records. */
    public function testEmitsHostOverridesAsLocalData(): void
    {
        $this->db->table('dns_hosts')->insert([
            'id'          => 'dns_test_nas',
            'hostname'    => 'nas.corp.internal',
            'ip_address'  => '192.168.1.102',
            'description' => 'Internal Storage Server',
            'created_at'  => '2026-10-04 00:00:00',
            'updated_at'  => '2026-10-04 00:00:00',
        ]);

        $conf = $this->compile();

        $this->assertStringContainsString('local-data: "nas.corp.internal. IN A 192.168.1.102"', $conf);
    }

    /** Enabled domain overrides become forward-zones; disabled ones do not. */
    public function testEmitsEnabledDomainOverridesAsForwardZones(): void
    {
        $this->insertDomainOverride([
            'id'     => 'dov_test_plain',
            'domain' => 'corp.internal',
            'port'   => 53,
        ]);
        $this->insertDomainOverride([
            'id'         => 'dov_test_altport',
            'domain'     => 'branch.corp.internal',
            'dns_server' => '10.20.0.53',
            'port'       => 5353,
        ]);
        $this->insertDomainOverride([
            'id'      => 'dov_test_disabled',
            'domain'  => 'disabled.example.com',
            'enabled' => 0,
        ]);

        $conf = $this->compile();

        // The root forward zone must still be emitted.
        $this->assertStringContainsString('name: "."', $conf);

        // Default port is written without an @port suffix.
        $this->assertStringContainsString('name: "corp.internal."', $conf);
        $this->assertStringContainsString('forward-addr: 10.0.0.53', $conf);
        $this->assertStringNotContainsString('10.0.0.53@', $conf);

        // Non-default port is appended as unbound expects (@port).
        $this->assertStringContainsString('name: "branch.corp.internal."', $conf);
        $this->assertStringContainsString('forward-addr: 10.20.0.53@5353', $conf);

        // Disabled overrides must not reach the config.
        $this->assertStringNotContainsString('disabled.example.com', $conf);
    }

    /** A missing/unmigrated table degrades to an empty section, not a crash. */
    public function testCompilesWhenTablesHaveNoRows(): void
    {
        $conf = $this->compile();

        $this->assertStringContainsString('server:', $conf);
        $this->assertStringContainsString('forward-zone:', $conf);
        $this->assertStringContainsString('# (none defined)', $conf);
        $this->assertStringNotContainsString('local-data:', $conf);
    }
}
