<?php

namespace Tests\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * No shipped path leaves an appliance on a published password, and an
 * installed appliance does not keep the live image's open access.
 */
final class ProductionAccessPolicyTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTheInstallerHasNoDefaultPassword(): void
    {
        $install = $this->read('installer/install.sh');

        $this->assertStringNotContainsString('ADMIN_PW:-M1Cr0N', $install);
        $this->assertStringNotContainsString('[M1Cr0N]', $install);
        $this->assertStringContainsString('admin_pw_problem', $install);
        $this->assertStringContainsString('Confirm administrative password', $install);
    }

    public function testThePasswordNeverTravelsThroughAShellStringOrArgv(): void
    {
        $install = $this->read('installer/install.sh');

        $this->assertStringNotContainsString("user:reset-admin '\${ADMIN_PW}'", $install);
        $this->assertStringContainsString('user:reset-admin --from-env', $install);
        $this->assertStringNotContainsString("echo 'ministrator:\${ADMIN_PW}'", $install);
    }

    public function testTheInstalledSystemDropsLiveImageAccess(): void
    {
        $install = $this->read('installer/install.sh');

        $this->assertStringContainsString("ministrator ALL=(ALL:ALL) ALL", $install);
        $this->assertStringContainsString('getty@tty1.service.d/autologin.conf', $install);
        $this->assertStringContainsString('PermitRootLogin prohibit-password', $install);
    }

    public function testTheLiveImageLocksItsAccounts(): void
    {
        $hook = $this->read('packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot');

        $this->assertStringNotContainsString(':M1Cr0N" | chpasswd', $hook);
        $this->assertStringContainsString('passwd -l root', $hook);
        $this->assertStringContainsString('passwd -l ministrator', $hook);
        $this->assertStringNotContainsString('PermitRootLogin yes', $hook);
    }

    public function testConsoleToolsHaveNoDefaultPassword(): void
    {
        $this->assertStringNotContainsString("'M1Cr0N'", $this->read('bin/microctl'));
        $this->assertStringNotContainsString('NEW_PW:-M1Cr0N', $this->read('bin/micron-menu'));
        $this->assertStringNotContainsString('ADMIN_PW="M1Cr0N"', $this->read('installer/micron-kickstart.cfg.example'));
        $this->assertStringNotContainsString("to 'M1Cr0N'", $this->read('installer/tui.py'));
    }
}
