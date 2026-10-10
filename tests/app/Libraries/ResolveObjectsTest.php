<?php

use App\Libraries\ResolveObjects;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shared resolve engine: freshness classification and the run summary.
 *
 * @internal
 */
final class ResolveObjectsTest extends CIUnitTestCase
{
    private ResolveObjects $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ResolveObjects();
    }

    public function testLiteralObjectsHaveNoFreshness(): void
    {
        $fresh = $this->service->freshness(['type' => 'ipmask', 'value' => '10.0.0.0/8']);

        $this->assertSame('literal', $fresh['state']);
        $this->assertSame('', $fresh['label']);
        $this->assertNull($fresh['age']);
    }

    public function testNeverResolvedObjectIsFlagged(): void
    {
        $fresh = $this->service->freshness([
            'type'        => 'fqdn',
            'value'       => 'never-resolved-' . bin2hex(random_bytes(4)) . '.example',
            'resolved_at' => null,
        ]);

        $this->assertSame('never', $fresh['state']);
        $this->assertSame('Never resolved', $fresh['label']);
    }

    public function testRecentlyResolvedObjectIsFresh(): void
    {
        $fresh = $this->service->freshness([
            'type'        => 'fqdn',
            'value'       => 'fresh-' . bin2hex(random_bytes(4)) . '.example',
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('fresh', $fresh['state']);
        $this->assertStringContainsString('Resolved', $fresh['label']);
    }

    public function testOldResolutionIsStale(): void
    {
        $fresh = $this->service->freshness([
            'type'        => 'fqdn',
            'value'       => 'stale-' . bin2hex(random_bytes(4)) . '.example',
            'resolved_at' => date('Y-m-d H:i:s', time() - (ResolveObjects::STALE_AFTER + 3600)),
        ]);

        $this->assertSame('stale', $fresh['state']);
        $this->assertStringContainsString('Stale', $fresh['label']);
    }

    public function testFreshnessMapKeysByAddressId(): void
    {
        $map = $this->service->freshnessMap([
            ['id' => 'a1', 'type' => 'ipmask', 'value' => '10.0.0.0/8'],
            ['id' => 'a2', 'type' => 'fqdn', 'value' => 'x.example', 'resolved_at' => date('Y-m-d H:i:s')],
        ]);

        $this->assertSame(['a1', 'a2'], array_keys($map));
        $this->assertSame('literal', $map['a1']['state']);
        $this->assertSame('fresh', $map['a2']['state']);
    }

    public function testRunSummaryShape(): void
    {
        $summary = $this->service->run(['name' => '__no_such_object__'], true);

        $this->assertSame(0, $summary['processed']);
        $this->assertSame(0, $summary['stored']);
        $this->assertSame([], $summary['objects']);
        $this->assertArrayHasKey('unresolvable', $summary);
        $this->assertArrayHasKey('failed', $summary);
    }
}
