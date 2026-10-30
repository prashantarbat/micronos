<?php

namespace Tests\App\Libraries;

use App\Libraries\IpcClient;
use App\Libraries\NetworkHardware;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * An installed or live-booted appliance never runs the development client, so
 * it never shows sample data; the development client only applies off-box.
 *
 * @internal
 */
final class ApplianceModeTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/micron-osrel-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        putenv('MICRON_APPLIANCE');
        parent::tearDown();
    }

    private function osRelease(string $text): string
    {
        $path = $this->dir . '/os-release';
        file_put_contents($path, $text);

        return $path;
    }

    public function testMicronOsReleaseIsAnAppliance(): void
    {
        $this->assertTrue(IpcClient::isAppliance($this->osRelease("NAME=\"Micron OS\"\nID=micronos\nID_LIKE=debian\n")));
        $this->assertTrue(IpcClient::isAppliance($this->osRelease("ID=\"micronos\"\n")));
    }

    public function testOtherSystemsAreNot(): void
    {
        $this->assertFalse(IpcClient::isAppliance($this->osRelease("ID=debian\nVERSION_CODENAME=trixie\n")));
        $this->assertFalse(IpcClient::isAppliance($this->osRelease("ID=ubuntu\nID_LIKE=micronos\n")));
        $this->assertFalse(IpcClient::isAppliance($this->dir . '/missing'));
    }

    public function testBuildAndInstallerCanDeclareTheAppliance(): void
    {
        putenv('MICRON_APPLIANCE=1');
        $this->assertTrue(IpcClient::isAppliance());
    }

    public function testTheForcedDevelopmentClientStillWorksForTests(): void
    {
        // phpunit.dist.xml sets MICRON_IPC_MOCK=1.
        $this->assertTrue((new IpcClient())->isMock());
    }

    public function testHardwareInventoryHasNoRadioUnlessOneIsPresent(): void
    {
        $hw = new NetworkHardware(new IpcClient());
        $this->assertFalse($hw->hasWireless());
        $this->assertNotContains('wlan0', $hw->names());

        putenv('MICRON_MOCK_WIRELESS=wlp3s0');
        try {
            $hw = new NetworkHardware(new IpcClient());
            $this->assertSame(['wlp3s0'], $hw->wirelessNames());
        } finally {
            putenv('MICRON_MOCK_WIRELESS');
        }
    }

    public function testPortDescriptions(): void
    {
        $this->assertSame('enp7s0 — igc, copper, 2.5 Gb/s',
            NetworkHardware::describe(['name' => 'enp7s0', 'driver' => 'igc', 'port_type' => 'tp', 'speed_mbps' => 2500, 'wireless' => false]));
        $this->assertSame('wlp2s0 — iwlwifi, Wi-Fi, no link',
            NetworkHardware::describe(['name' => 'wlp2s0', 'driver' => 'iwlwifi', 'wireless' => true, 'carrier' => false]));
    }
}
