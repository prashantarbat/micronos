<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the lldpd neighbour-table DOM harness must run in the PHP run.
 *
 * `tests/js/lldpd_neighbours_harness.mjs` reads the shipped
 * `app/Views/plugins/apps/lldpd.php`, gives it a hand-written `document` and a
 * controllable `MicronScheduler`, and drives the neighbour view: structured rows
 * as a sortable table (a TTL compared as a number, blanks last), the fall back
 * to the raw `lldpcli` listing when no table can be built, the view toggle, and
 * that a neighbour name off the wire is rendered as text rather than markup.
 *
 * PHP can pin the wiring — that the route forwards the format, that the page
 * carries the table markup — but it cannot watch the table sort. This test wires
 * the harness into the project's run so those behaviours are enforced by the
 * build, following PickerDomHarnessTest and SchedulerDomHarnessTest.
 *
 * @internal
 */
final class LldpdNeighboursHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/lldpd_neighbours_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL LLDPD NEIGHBOUR HARNESS CHECKS PASSED';

    /** Without the harness the neighbour view's behaviour is enforced by nothing. */
    public function testTheNeighbourHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The lldpd neighbour harness is missing; nothing drives the neighbour view itself.'
        );
    }

    public function testTheNeighbourTableSortsAndFallsBackToTheRawListing(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the lldpd neighbour harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The lldpd neighbour harness failed:\n" . $report);
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must report how many checks ran, so a harness that stopped
        // running them cannot pass, and it must name the two behaviours the view
        // exists to provide.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        $this->assertStringContainsString('a TTL sorts as a number, not as text', $report);
        $this->assertStringContainsString('falls back to the raw listing', $report);
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
