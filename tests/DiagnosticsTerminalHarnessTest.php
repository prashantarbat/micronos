<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the Diagnostics terminal DOM harness must run in the PHP run.
 *
 * `tests/js/diagnostics_terminal_harness.mjs` reads the shipped
 * `app/Views/diag/index.php`, gives it a hand-written `document`, a `FormData`
 * that records what a request carried and a controllable `MicronScheduler`, and
 * drives the one Diagnostic Terminal's streamed probe runner: that the ping and
 * traceroute forms open their sessions with the fields the controller validates,
 * that each poll of `diag/stream` *appends* only the bytes it brought (an empty
 * chunk adding nothing), that the answer reporting the run finished completes the
 * probe and stands the poll down, that the next hash out of a response is the one
 * the next request sends, that stopping closes the session, and that the three
 * ways a probe can fail are told apart — with a poll the daemon *refuses* ending
 * the probe while a poll whose transport failed leaves it running for the shared
 * scheduler to back off.
 *
 * PHP can pin the endpoints and their answers, but it cannot watch the console be
 * appended to, poll by poll. This test wires the harness into the project's run
 * so those behaviours are enforced by the build, following
 * StatesDomHarnessTest, LldpdNeighboursHarnessTest, PickerDomHarnessTest and
 * SchedulerDomHarnessTest.
 *
 * @internal
 */
final class DiagnosticsTerminalHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/diagnostics_terminal_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL DIAGNOSTICS TERMINAL HARNESS CHECKS PASSED';

    /** Without the harness the streamed probe runner is enforced by nothing. */
    public function testTheDiagnosticsTerminalHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The Diagnostics terminal harness is missing; nothing drives the streamed probe runner itself.'
        );
    }

    public function testTheTerminalStreamsAppendsAndStandsDownEachProbe(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome the harness prevents.
        // Node is a hard build dependency for the front end, and the appliance
        // never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the Diagnostics terminal harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The whole report is captured — never piped or filtered — so a failure
        // names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The Diagnostics terminal harness failed:\n" . $report);
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must report how many checks ran, so a harness that stopped
        // running them cannot pass, and it must name the behaviours the runner
        // exists to provide.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        preg_match('/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/', $report, $matches);
        $this->assertGreaterThanOrEqual(
            33,
            (int) ($matches[1] ?? 0),
            "The harness ran fewer checks than it must:\n" . $report
        );

        // The stream itself: opened at the right endpoint, appended to poll by
        // poll, and stood down when the run reports it finished.
        $this->assertStringContainsString('the ping form is posted to the stream start endpoint', $report);
        $this->assertStringContainsString('a poll appends the bytes it brought to what is already there', $report);
        $this->assertStringContainsString('the answer that reports the run finished completes the probe', $report);

        // The failure contract: a refused poll ends the probe, a failed one does
        // not — that difference is what the shared scheduler backs off on.
        $this->assertStringContainsString('a failed poll resolves false so the shared scheduler backs off', $report);
        $this->assertStringContainsString('a refused poll ends the probe quietly', $report);

        // And the hand-off that keeps every probe after the first from a 403.
        $this->assertStringContainsString('the hash the last answer carried is what the next request sends', $report);
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
