<?php

namespace Tests\App\Libraries;

use App\Libraries\IpcClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Locks the health-aware contract of the PPPoE redial mock.
 *
 * The real daemon inspects each link and only rebuilds the ones that are down.
 * The mock is what the Windows development appliance exercises, so it has to
 * answer the same way — otherwise a UI or CLI test would "pass" while the mock
 * silently claims to have cycled a healthy link, which is exactly the behaviour
 * this feature removes.
 *
 * @internal
 */
final class PppoeRedialMockTest extends CIUnitTestCase
{
    protected $refresh = false;

    private IpcClient $ipc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ipc = new IpcClient();

        // The mock is only selected off-Windows when the socket is absent. Force
        // it so the contract is testable regardless of host.
        if (! $this->ipc->isMock()) {
            $this->markTestSkipped('IpcClient is not in mock mode on this host.');
        }
    }

    public function testHealthyLinkIsSkippedRatherThanCycled(): void
    {
        // The mock's client session is on eth0 and reports state UP.
        $res = $this->ipc->call('pppoe.redial', ['interfaces' => ['eth0']]);

        $this->assertSame('applied', $res['result']['status']);
        $this->assertSame(0, $res['result']['repaired']);
        $this->assertSame(1, $res['result']['skipped']);
        $this->assertSame('skipped', $res['result']['links'][0]['action']);
        $this->assertNull($res['result']['concentrator'], 'concentrator must not restart when nothing is repaired');
    }

    public function testUnknownLinkIsRedialed(): void
    {
        $res = $this->ipc->call('pppoe.redial', ['interfaces' => ['eth9']]);

        $this->assertSame(1, $res['result']['repaired']);
        $this->assertSame(0, $res['result']['skipped']);
        $this->assertSame('redialed', $res['result']['links'][0]['action']);
        $this->assertSame('restarted', $res['result']['concentrator']);
    }

    public function testForceCyclesHealthyLink(): void
    {
        $res = $this->ipc->call('pppoe.redial', ['interfaces' => ['eth0'], 'force' => true]);

        $this->assertSame(1, $res['result']['repaired']);
        $this->assertSame(0, $res['result']['skipped']);
        $this->assertSame('redialed', $res['result']['links'][0]['action']);
        $this->assertSame('restarted', $res['result']['concentrator']);
    }

    public function testDownOnlyTakesLinkDownWithoutRestartingConcentrator(): void
    {
        $res = $this->ipc->call('pppoe.redial', ['interfaces' => ['eth0'], 'down_only' => true]);

        $this->assertSame(0, $res['result']['repaired']);
        $this->assertSame('down', $res['result']['links'][0]['status']);
        $this->assertSame('down', $res['result']['links'][0]['action']);
        $this->assertNull($res['result']['concentrator']);
    }

    public function testStatusExposesTheDeviceUsedForHealthMatching(): void
    {
        $res = $this->ipc->call('pppoe.status', []);

        $clients = array_values(array_filter(
            $res['result']['sessions'],
            static fn (array $s): bool => ($s['role'] ?? '') === 'client',
        ));

        $this->assertNotEmpty($clients, 'a client session must be present for health matching');
        $this->assertArrayHasKey('device', $clients[0]);
        $this->assertSame('eth0', $clients[0]['device']);
        $this->assertSame('UP', $clients[0]['state']);
    }
}
