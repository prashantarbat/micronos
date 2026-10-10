<?php

declare(strict_types=1);

use App\Libraries\IpcClient;
use Tests\Support\BaseTestCase;

/**
 * IpcClient DHCP mock contract (dev / no-socket mode).
 *
 * The DHCP page now pushes its compiled Kea config through `dhcp.apply`; on
 * Windows the mock must mirror the daemon's behaviour closely enough that a
 * missing or non-Kea payload is reported as a failure rather than a fake
 * success — otherwise the controller's `ipcFlash` would claim a reload that
 * never happened.
 *
 * @internal
 */
final class IpcClientDhcpMockTest extends BaseTestCase
{
    public function testApplyAcceptsAKeaConfig(): void
    {
        $ipc = new IpcClient();
        $this->assertTrue($ipc->isMock(), 'expected mock mode with no daemon socket');

        $result = $ipc->call('dhcp.apply', ['config' => '{"Dhcp4":{"subnet4":[]}}'])['result'];

        $this->assertTrue($result['success']);
        $this->assertSame('applied', $result['status']);
        $this->assertSame('running', $result['service']);
        $this->assertContains('(mock) systemctl restart kea-dhcp4-server', $result['steps']);
    }

    public function testApplyRejectsAMissingOrNonKeaConfig(): void
    {
        $ipc = new IpcClient();

        $missing = $ipc->call('dhcp.apply', [])['result'];
        $this->assertFalse($missing['success']);
        $this->assertSame('error', $missing['status']);

        $notKea = $ipc->call('dhcp.apply', ['config' => '{"nope":1}'])['result'];
        $this->assertFalse($notKea['success']);
        $this->assertSame('error', $notKea['status']);
    }

    public function testRestartReportsRunning(): void
    {
        $ipc = new IpcClient();

        $result = $ipc->call('dhcp.restart')['result'];

        $this->assertTrue($result['success']);
        $this->assertSame('restarted', $result['status']);
        $this->assertSame('running', $result['service']);
    }
}
