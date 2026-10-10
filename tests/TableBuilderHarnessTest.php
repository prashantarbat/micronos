<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the shared table builder's DOM harness must run in the PHP run,
 * and the module must be on every page that draws a table.
 *
 * `tests/js/table_harness.mjs` reads the shipped
 * `public/assets/js/micronos-table.js` and drives its whole contract on a
 * `document` whose nodes really nest: a cell is filled from a `text` field or
 * from a `node` the page built and from nothing else, a spec that asks for
 * markup is refused, a re-render swaps the body, an empty list draws the row
 * that says so, and `badge`/`button` build the two things every table needs —
 * the second one with its handler closed over its own row instead of written
 * into an attribute.
 *
 * This test wires that harness into the project's run, following
 * StatesDomHarnessTest, LldpdNeighboursHarnessTest, PickerDomHarnessTest,
 * SchedulerDomHarnessTest and the per-table harnesses. It also pins the two
 * things PHP can see and the harness cannot: that the layout loads the module
 * (synchronously, and versioned so a replaced file is a new URL — the same
 * promise every asset under `assets/js/` carries), and that the pages that draw
 * tables do not go back to assembling their rows as strings.
 *
 * @internal
 */
final class TableBuilderHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/table_harness.mjs';

    private const MODULE = 'public/assets/js/micronos-table.js';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL TABLE HARNESS CHECKS PASSED';

    /** The module's own checks are the contract; a shrunken run must not pass. */
    private const MIN_CHECKS = 50;

    /**
     * The pages whose tables are drawn with the builder. Listed by the file that
     * renders them, so a page that stops using the module — or a new one that
     * starts — is caught here rather than in a browser.
     */
    private const TABLES = [
        'app/Views/diag/states.php',
        'app/Views/siem/index.php',
        'app/Views/cron/index.php',
        'app/Views/snmp/index.php',
    ];

    public function testTheTableBuilderHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The table-builder harness is missing; nothing drives the module the live tables share.'
        );
        $this->assertFileExists(
            ROOTPATH . self::MODULE,
            'The shared table builder is missing; the live tables would draw themselves again.'
        );
    }

    public function testTheTableBuilderRefusesMarkupAndBuildsItsRowsFromNodes(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the table-builder harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The table-builder harness failed:\n" . $report);
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must report how many checks ran, so a harness that stopped
        // running them cannot pass.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        preg_match('/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/', $report, $matches);
        $this->assertGreaterThanOrEqual(
            self::MIN_CHECKS,
            (int) ($matches[1] ?? 0),
            "The harness ran fewer checks than it must:\n" . $report
        );

        // A cell is filled from a text field or from a node, and nothing else.
        $this->assertStringContainsString('the module exposes exactly the three builders a page needs', $report);
        $this->assertStringContainsString('a text column fills its cell with the row field', $report);
        $this->assertStringContainsString('a node column appends the node the page built', $report);

        // The escaping: a value is a node's text, nothing shaped like an element
        // became one, and no table is built from an HTML string.
        $this->assertStringContainsString('a value that looks like an element is the cell text', $report);
        $this->assertStringContainsString('an element-shaped value creates no element', $report);
        $this->assertStringContainsString('the table is built from nodes, never from markup', $report);

        // The refusal is the guarantee: there is no supported way to hand the
        // module markup, so a page cannot opt out of the contract by accident.
        $this->assertStringContainsString('a column naming html is refused', $report);
        $this->assertStringContainsString('a column naming innerHTML is refused', $report);
        $this->assertStringContainsString('a refused spec builds nothing at all', $report);

        // The two builders, including the handler that is a listener rather than
        // an attribute carrying the row.
        $this->assertStringContainsString('a badge label that looks like markup is the badge text', $report);
        $this->assertStringContainsString('a row action writes no handler into an attribute', $report);
        $this->assertStringContainsString("clicking a row's button runs the handler closed over its own row", $report);
    }

    /**
     * The module is a page-wide dependency now, so it has to be loaded the way
     * the scheduler is: from the layout, synchronously and before any page script
     * that builds a row, with a version token taken from the file's own mtime.
     */
    public function testTheLayoutLoadsTheTableBuilderSynchronouslyAndVersioned(): void
    {
        $layout = (string) file_get_contents(ROOTPATH . 'app/Views/layouts/main.php');

        $this->assertSame(
            1,
            preg_match(
                '#<script src="<\?= base_url\(\'assets/js/micronos-table\.js\'\) \?>\?v=<\?= @filemtime\(FCPATH \. \'assets/js/micronos-table\.js\'\) \?: \'1\' \?>"></script>#',
                $layout
            ),
            'the layout must load the shared table builder with a version token from that same file'
        );

        // Both shared scripts are in the head, and the builder comes after the
        // scheduler so a page script can reach either by the time it runs.
        $scheduler = strpos($layout, 'micronos-scheduler.js');
        $builder   = strpos($layout, 'micronos-table.js');

        $this->assertIsInt($scheduler, 'the layout must load the shared scheduler');
        $this->assertLessThan(
            strpos($layout, '</head>'),
            $builder,
            'the table builder must be loaded from the head, before any page script builds a row'
        );
        $this->assertGreaterThan($scheduler, $builder, 'the builder is loaded after the scheduler it may report through');
    }

    /**
     * The pages that draw tables must draw them through the module: no page may
     * assemble a row as an HTML string again, and none may keep its own escape
     * helper to make that safe. This is the check PHP can make cheaply and a
     * browser cannot make at all — the ones the harnesses enforce from the inside
     * are about behaviour, this one is about who builds the cells.
     */
    public function testTheTablePagesBuildTheirRowsWithTheSharedBuilder(): void
    {
        foreach (self::TABLES as $relative) {
            $view = (string) file_get_contents(ROOTPATH . $relative);

            $this->assertStringContainsString(
                'MicronTable.render',
                $view,
                "{$relative} must draw its table with the shared builder"
            );
            $this->assertSame(
                0,
                preg_match('/function\s+escapeHtml\s*\(/', $view),
                "{$relative} still carries its own escape helper, which the builder makes unnecessary"
            );

            // `innerHTML` is not banned outright — a button's own icon is fixed
            // markup a page is entitled to write. What no page may do is hand it
            // something that is not a fixed literal: that is the expression
            // every value-off-the-wire row used to be built from.
            //
            // Read line by line, because the literal itself may contain a
            // semicolon (an inline style inside an SVG). A right-hand side that
            // runs past its line, or is not one quoted string, fails.
            foreach (preg_split('/\R/', $view) ?: [] as $offset => $line) {
                if (preg_match('/innerHTML\s*=\s*(.*)$/', $line, $rhs) !== 1) {
                    continue;
                }

                $value = rtrim(trim($rhs[1]), ';');

                $this->assertSame(
                    1,
                    preg_match("/^'[^']*'$/", $value),
                    sprintf(
                        '%s writes markup into innerHTML on line %d from something other than a fixed string: %s',
                        $relative,
                        $offset + 1,
                        trim($rhs[1])
                    )
                );
            }
        }
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
