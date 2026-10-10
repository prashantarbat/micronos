<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the States-table DOM harness must run in the PHP run.
 *
 * `tests/js/states_harness.mjs` reads the shipped `app/Views/diag/states.php`,
 * gives it a `document` whose nodes really nest and a controllable
 * `MicronScheduler`, and drives the Connection States table: a state whose
 * interface resolved renders that name (`WAN`), an unassigned port renders its
 * raw device name, and a state whose interface is blank or missing renders the
 * em-dash fallback — plus the row's ten cells and their badges, a re-render that
 * swaps the body rather than adding to it, the endpoint's answer, and a search
 * that matches the resolved name.
 *
 * It also drives the escaping, which the table now gets structurally: the page
 * builds every cell as a node and sets each value as that node's *text*, so a
 * value off the wire that looks like markup is asserted to be the cell's text
 * with no element children, and nothing in the table may be built from an HTML
 * string. No address reaches an attribute either — the kill button is a node
 * whose handler closes over its own row, which the harness pins by clicking it
 * and reading the addresses the request carried.
 *
 * PHP can pin the controller's resolution and the endpoint's answers, but it
 * cannot watch a cell be built out of the resolved value. This test wires the
 * harness into the project's run so those behaviours are enforced by the build,
 * following LldpdNeighboursHarnessTest, PickerDomHarnessTest and
 * SchedulerDomHarnessTest.
 *
 * @internal
 */
final class StatesDomHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/states_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL STATES HARNESS CHECKS PASSED';

    /** Without the harness the States table's rendering is enforced by nothing. */
    public function testTheStatesHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The States-table harness is missing; nothing drives the table the page renders.'
        );
    }

    public function testTheStatesTableRendersTheResolvedInterfaceAndTheDashFallback(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the States-table harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The States-table harness failed:\n" . $report);
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must report how many checks ran, so a harness that stopped
        // running them cannot pass, and it must name the behaviours the table
        // exists to provide.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        $this->assertStringContainsString('a resolved interface renders its friendly name', $report);
        $this->assertStringContainsString('an unresolved interface renders the dash fallback', $report);

        // The escaping is the point of the newest checks; a harness trimmed back
        // to the rendering checks must not be able to pass as complete.
        preg_match('/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/', $report, $matches);
        $this->assertGreaterThanOrEqual(
            27,
            (int) ($matches[1] ?? 0),
            "The harness ran fewer checks than it must:\n" . $report
        );

        // The table is nodes: the row is ten cells, and a re-render swaps the
        // body instead of piling rows up behind it.
        $this->assertStringContainsString('a row is as many cells as the header declares', $report);
        $this->assertStringContainsString('a re-render swaps the rows rather than adding to them', $report);

        // The escaping, structurally: a value is the cell's text, no element is
        // made from it, no address reaches an attribute, and no cell is ever
        // built from an HTML string.
        $this->assertStringContainsString('a value that looks like an element is rendered as the cell text', $report);
        $this->assertStringContainsString('an element-shaped value creates no element', $report);
        $this->assertStringContainsString('the address cell carries no attribute for a quote to break out of', $report);
        $this->assertStringContainsString('the table is built from nodes, never from markup', $report);

        // And the kill button, clicked like an operator would click it, carrying
        // its own row's addresses as data rather than as markup.
        $this->assertStringContainsString('the last cell holds the kill button as a node', $report);
        $this->assertStringContainsString('clicking the row button kills that row, through the endpoint', $report);
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
