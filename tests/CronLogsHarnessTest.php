<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the cron execution-history DOM harness must run in the PHP run.
 *
 * `tests/js/cron_logs_harness.mjs` reads the shipped `app/Views/cron/index.php`,
 * gives it a `document` whose nodes really nest, and drives the row builder the
 * history table is drawn with: the answer becomes one row per execution with the
 * stamp, pid and duration as the cells' text, the user and the status in the
 * badges classed for them, the command as both its cell's text and that cell's
 * `title`, a re-render that swaps the rows rather than adding to them, the empty
 * row for no history, and the Inspect Output button clicked like an operator
 * would click it.
 *
 * It also drives the escaping, which the rows now get structurally: the button
 * used to JSON-encode the run's output into an `onclick` attribute, so the
 * harness feeds the builder values that look like markup and asserts on the nodes
 * — the value is the cell's or badge's text, the command's `title` is the string
 * verbatim, nothing shaped like an element became one, and no node in the table
 * is built from an HTML string.
 *
 * PHP can pin the endpoint and its answer, but it cannot watch a row be built out
 * of it. This test wires the harness into the project's run so those behaviours
 * are enforced by the build, following StatesDomHarnessTest, SiemEventsHarnessTest,
 * LldpdNeighboursHarnessTest, PickerDomHarnessTest and SchedulerDomHarnessTest.
 *
 * @internal
 */
final class CronLogsHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/cron_logs_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL CRON LOGS HARNESS CHECKS PASSED';

    /** Without the harness the history row's behaviour is enforced by nothing. */
    public function testTheCronLogsHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The cron logs harness is missing; nothing drives the execution rows themselves.'
        );
    }

    public function testTheCronHistoryIsBuiltFromNodesAndInspectsTheRunOutput(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the cron logs harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The cron logs harness failed:\n" . $report);
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
            22,
            (int) ($matches[1] ?? 0),
            "The harness ran fewer checks than it must:\n" . $report
        );

        // The row: cells as nodes, badges inside the cells that style them, and a
        // re-render that swaps the body instead of piling rows up behind it.
        $this->assertStringContainsString('a row is as many cells as the header declares', $report);
        $this->assertStringContainsString('the user is a badge holding the user as text', $report);
        $this->assertStringContainsString('the status is a badge classed for a success', $report);
        $this->assertStringContainsString('a re-render swaps the rows rather than adding to them', $report);

        // The escaping, structurally: the command fills a cell and a `title`, both
        // through the DOM, and nothing in the table is built from an HTML string.
        $this->assertStringContainsString('the command is the cell text and its title, for the cell that truncates it', $report);
        $this->assertStringContainsString('a command that looks like an element is the title verbatim', $report);
        $this->assertStringContainsString('the table is built from nodes, never from markup', $report);

        // And the button that used to carry the run's output in an attribute.
        $this->assertStringContainsString('the last cell holds the Inspect Output button as a node', $report);
        $this->assertStringContainsString('clicking it opens the output modal titled for that execution', $report);
        $this->assertStringContainsString('a markup-shaped output reaches the modal body as text', $report);
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
