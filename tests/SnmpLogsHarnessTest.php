<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the SNMP log-list DOM harness must run in the PHP run.
 *
 * `tests/js/snmp_logs_harness.mjs` reads the shipped `app/Views/snmp/index.php`,
 * gives it a `document` whose nodes really nest, and drives the row builder the
 * agent's log is drawn with: the answer becomes one row per entry with the stamp,
 * subsystem and message as the spans' text, the level in the chip classed for it
 * (a trap is shipped as `TRAP-OUT` and shown as `TRAP`), an unknown level on the
 * plain chip, a re-render that swaps the rows rather than adding to them, the
 * empty line for no logs, and the filter narrowing the same list.
 *
 * It also drives the escaping, which the rows now get structurally: the level
 * used to be concatenated straight into its chip's markup, so the harness feeds
 * the builder values that look like markup and asserts on the nodes — the value
 * is the chip's or span's text, nothing shaped like an element became one, and no
 * node in the list is built from an HTML string. The chip table is read through
 * `hasOwnProperty`, which the harness pins with a level named like an object key.
 *
 * PHP can pin the endpoint and its answer, but it cannot watch a row be built out
 * of it. This test wires the harness into the project's run so those behaviours
 * are enforced by the build, following StatesDomHarnessTest,
 * CronLogsHarnessTest, SiemEventsHarnessTest, LldpdNeighboursHarnessTest,
 * PickerDomHarnessTest and SchedulerDomHarnessTest.
 *
 * @internal
 */
final class SnmpLogsHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/snmp_logs_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL SNMP LOGS HARNESS CHECKS PASSED';

    /** Without the harness the log row's behaviour is enforced by nothing. */
    public function testTheSnmpLogsHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The SNMP log harness is missing; nothing drives the log rows themselves.'
        );
    }

    public function testTheSnmpLogListIsBuiltFromNodesAndFiltersThroughThem(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the SNMP log harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The SNMP log harness failed:\n" . $report);
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must report how many checks ran, so a harness that stopped
        // running them cannot pass, and it must name the behaviours the list
        // exists to provide.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        preg_match('/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/', $report, $matches);
        $this->assertGreaterThanOrEqual(
            23,
            (int) ($matches[1] ?? 0),
            "The harness ran fewer checks than it must:\n" . $report
        );

        // The row: the four fields the stylesheet paints, the chip classed per
        // level, and a re-render that swaps the list instead of piling rows up.
        $this->assertStringContainsString('a row has the four fields the stylesheet paints', $report);
        $this->assertStringContainsString('a trap is shipped as TRAP-OUT and shown as TRAP on the wan chip', $report);
        $this->assertStringContainsString('a re-render swaps the rows rather than adding to them', $report);

        // The escaping, structurally, and the chip table read through its own keys.
        $this->assertStringContainsString('a level named like an object key falls back to the plain chip', $report);
        $this->assertStringContainsString('a level that looks like an element is the chip text with no element made from it', $report);
        $this->assertStringContainsString('the list is built from nodes, never from markup', $report);

        // And the filter, which must narrow the same list through the same builder.
        $this->assertStringContainsString('the filter narrows the list through the same builder', $report);
        $this->assertStringContainsString('clearing the filter puts the whole list back', $report);
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
