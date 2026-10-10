<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the SIEM event-table DOM harness must run in the PHP run.
 *
 * `tests/js/siem_events_harness.mjs` reads the shipped `app/Views/siem/index.php`,
 * gives it a `document` whose nodes really nest, and drives the row builder the
 * stream table is drawn with: the answer becomes one row per event with each wire
 * field as a cell's text, the action in the badge classed for it, the delivery
 * status in its badge beside the check mark, a re-render that swaps the rows
 * rather than adding to them, the empty row for an empty stream, and the Inspect
 * button clicked like an operator would click it.
 *
 * It also drives the escaping, which the rows now get structurally: the action
 * used to be interpolated straight into its badge's markup, so the harness feeds
 * the builder values that look like markup and asserts on the nodes — the value
 * is the badge's or cell's text, nothing shaped like an element became one, and
 * no node in the table is built from an HTML string.
 *
 * PHP can pin the endpoint and its answer, but it cannot watch a row be built out
 * of it. This test wires the harness into the project's run so those behaviours
 * are enforced by the build, following StatesDomHarnessTest,
 * LldpdNeighboursHarnessTest, PickerDomHarnessTest and SchedulerDomHarnessTest.
 *
 * @internal
 */
final class SiemEventsHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/siem_events_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL SIEM EVENTS HARNESS CHECKS PASSED';

    /** Without the harness the event row's behaviour is enforced by nothing. */
    public function testTheSiemEventsHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The SIEM events harness is missing; nothing drives the event rows themselves.'
        );
    }

    public function testTheSiemTableIsBuiltFromNodesAndInspectsTheRowEvent(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the SIEM events harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The SIEM events harness failed:\n" . $report);
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must report how many checks ran, so a harness that stopped
        // running them cannot pass, and it must name the behaviours the table
        // exists to provide.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        preg_match('/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/', $report, $matches);
        $this->assertGreaterThanOrEqual(
            20,
            (int) ($matches[1] ?? 0),
            "The harness ran fewer checks than it must:\n" . $report
        );

        // The row: cells as nodes, badges inside the cells that style them, and a
        // re-render that swaps the body instead of piling rows up behind it.
        $this->assertStringContainsString('a row is as many cells as the header declares', $report);
        $this->assertStringContainsString('the action is a badge classed for it', $report);
        $this->assertStringContainsString('the delivery status is the badge text beside its check mark', $report);
        $this->assertStringContainsString('a re-render swaps the rows rather than adding to them', $report);

        // The escaping, structurally: a value is a node's text, nothing shaped
        // like an element is made from it, and nothing in the table is built from
        // an HTML string.
        $this->assertStringContainsString('a subsystem that looks like an element is the cell text', $report);
        $this->assertStringContainsString('an action that looks like an element is the badge text', $report);
        $this->assertStringContainsString('the table is built from nodes, never from markup', $report);

        // And the button that used to bake a row index into an `onclick`.
        $this->assertStringContainsString('the last cell holds the Inspect button as a node', $report);
        $this->assertStringContainsString('clicking Inspect opens the modal for that row event', $report);
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
