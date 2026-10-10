<?php

declare(strict_types=1);

use App\Libraries\IpcClient;
use Tests\Support\BaseTestCase;

/**
 * IpcClient ZeroTier mock contract (dev / no-socket mode).
 *
 * The overlay page polls `plugin.zerotier.status` and drives join/leave through
 * the mock on a developer's machine, so the mock has to be honest in the same
 * way the real daemon is: it reports the *node's* identity, and it reports only
 * the memberships the caller actually asked for — never a network list it
 * invented. A mock that always looked connected would hide exactly the bugs the
 * page exists to surface.
 *
 * @internal
 */
final class IpcClientZerotierMockTest extends BaseTestCase
{
    public function testStatusReportsTheNodeWithoutInventingMemberships(): void
    {
        $ipc = new IpcClient();
        $this->assertTrue($ipc->isMock(), 'expected mock mode with no daemon socket');

        $result = $ipc->call('plugin.zerotier.status')['result'];

        $this->assertTrue($result['success']);
        $this->assertSame('zerotier', $result['id']);
        $this->assertSame('running', $result['state']);
        $this->assertNotSame('', (string) $result['data']['address']);
        // No agent runs in mock mode, so nothing has been joined.
        $this->assertSame([], $result['data']['networks']);
        $this->assertSame(0, $result['data']['networks_joined']);
    }

    public function testJoinReflectsTheNetworkTheCallerNamed(): void
    {
        $ipc = new IpcClient();

        $result = $ipc->call('plugin.zerotier.join', ['nwid' => '8056C2E21C000001'])['result'];

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['networks_joined']);
        // The daemon lowercases the id; the mock answers for the same network.
        $this->assertSame('8056c2e21c000001', $result['data']['networks'][0]['nwid']);
        $this->assertSame('OK', $result['data']['networks'][0]['status']);
        $this->assertStringContainsString('8056c2e21c000001', implode(' ', $result['steps']));
    }

    public function testLeaveReportsTheNetworkGone(): void
    {
        $ipc = new IpcClient();

        $result = $ipc->call('plugin.zerotier.leave', ['nwid' => '8056c2e21c000001'])['result'];

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['networks_joined']);
        $this->assertSame([], $result['data']['networks']);
    }
}
