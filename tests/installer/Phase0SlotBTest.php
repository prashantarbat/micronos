<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase 0 — the installer must provision a real second root slot.
 *
 * The installer, the slot helper and the boot selector are shell, so the checks
 * live in tests/installer/phase0_slot_b_test.sh (where they can also be run on an
 * appliance that has no PHPUnit). This test wires that suite into the project's
 * test run so a regression in the A/B layout fails the build.
 *
 * @internal
 */
final class Phase0SlotBTest extends CIUnitTestCase
{
    private const ASSETS = [
        'installer/install.sh',
        'installer/micron-slot',
        'installer/micron-healthcheck',
        'installer/micron-healthcheck.service',
        'installer/grub.d/09_micron_slots',
        'tests/installer/phase0_slot_b_test.sh',
    ];

    public function testPhase0AssetsExist(): void
    {
        foreach (self::ASSETS as $asset) {
            $this->assertFileExists(
                ROOTPATH . $asset,
                "Installer asset {$asset} is missing."
            );
        }
    }

    public function testPhase0AcceptanceSuitePasses(): void
    {
        if ($this->bash() === null) {
            $this->markTestSkipped('bash is unavailable on this host, so the installer suite cannot run.');
        }

        $suite  = ROOTPATH . 'tests/installer/phase0_slot_b_test.sh';
        $suiteArg = $suite;
        if (PHP_OS_FAMILY === 'Windows' && preg_match('#^([a-zA-Z]):[\\\\/](.*)$#', $suite, $m)) {
            $wslPath = '/mnt/' . strtolower($m[1]) . '/' . str_replace('\\', '/', $m[2]);
            $probeStatus = 1;
            exec('bash -c ' . escapeshellarg("test -f {$wslPath}") . ' 2>&1', $probeOut, $probeStatus);
            if ($probeStatus === 0) {
                $suiteArg = $wslPath;
            }
        }
        $output = [];
        $status = 0;

        // Filtering the suite's output would hide which assertion failed, so the
        // full report is captured and attached to the assertion message.
        exec('bash ' . escapeshellarg($suiteArg) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(
            0,
            $status,
            "Phase 0 installer acceptance suite failed:\n" . $report
        );
        $this->assertStringContainsString('PASSED', $report);
    }

    /**
     * Returns the shell to use, or null when the host has none.
     */
    private function bash(): ?string
    {
        $output = [];
        $status = 0;
        exec('bash --version 2>&1', $output, $status);

        return $status === 0 ? 'bash' : null;
    }
}
