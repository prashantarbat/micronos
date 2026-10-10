<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the picker DOM harness must run inside the PHP test run.
 *
 * tests/js/picker_harness.mjs reads the inline `<script>` out of
 * app/Views/partials/picker_search.php, gives it a minimal DOM, and drives it the
 * way a page and an operator do: filtering, the keyboard contract, the
 * server-backed paging path — plus a filtering time budget measured over a
 * synthetic 5000-row catalogue. None of that is reachable from PHP: the PHP suite
 * pins the wiring on each screen and the endpoint's answers, but nothing ran the
 * script itself, so a change to the picker's behaviour or to what a keystroke
 * costs would have shipped unlooked-at.
 *
 * A harness only protects the tree while it runs, so this test wires it into the
 * project's test run. It follows the same shape as StrictDecodingGuardTest — the
 * real checks live in the external suite, here they are simply made load-bearing
 * for the build.
 *
 * @internal
 */
final class PickerDomHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/picker_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL PICKER DOM HARNESS CHECKS PASSED';

    /** Without the harness the picker's behaviour is enforced by nothing. */
    public function testPickerHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The picker DOM harness is missing; nothing drives the picker script itself.'
        );
    }

    public function testThePickerBehavesAndFiltersWithinItsBudget(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome this harness exists to
        // prevent. Node is a hard build dependency for the front end, and the
        // appliance never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the picker DOM harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The harness derives the repository root from its own path, so the
        // working directory is irrelevant. The whole report is captured — never
        // piped or filtered — so a failure names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(
            0,
            $status,
            "The picker DOM harness failed:\n" . $report
        );
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The budget is a real measurement, not a claim the harness makes about
        // itself: a harness that stopped measuring, or that ran no checks, must
        // not be able to print the marker on its own.
        $this->assertStringContainsString('The filtering time budget', $report);
        $this->assertMatchesRegularExpression(
            '/median \d+\.\d+ ms over \d+ samples/',
            $report,
            "The harness did not report a measured keystroke median:\n" . $report
        );
    }

    /**
     * The first interpreter on PATH that answers, or null when the host has none.
     * `node` first, then `node.exe` (a Windows dev box reached by a shell that
     * does not resolve the extension).
     */
    private function nodeInterpreter(): ?string
    {
        foreach (['node', 'node.exe'] as $candidate) {
            $output = [];
            $status = 0;
            exec($candidate . ' --version 2>&1', $output, $status);

            if ($status === 0) {
                return $candidate;
            }
        }

        return null;
    }
}
