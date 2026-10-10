<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the request-scheduler DOM harness must run inside the PHP test run.
 *
 * `tests/js/scheduler_harness.mjs` reads the shipped
 * `public/assets/js/micronos-scheduler.js`, gives it a hand-written
 * `window`/`document`/`fetch`, a clock the harness owns, and drives it: the one
 * connection state every poller and AJAX call shares, the backoff schedule that
 * doubles to a ceiling with a bounded jitter, the wake-up when the appliance
 * comes back, and the hidden-tab and browser online/offline signals.
 *
 * None of that is reachable from PHP. `RequestSchedulerTest` pins the *wiring*
 * (that each page's poller hands its work over, that the layout renders the
 * single banner, that the picker reports through it), but nothing ran the module
 * itself, so a change to the scheduler's behaviour — a backoff that stopped
 * doubling, a banner that never showed, two polls that stopped sharing the one
 * state — would have shipped unlooked-at.
 *
 * A harness only protects the tree while it runs, so this test wires it into the
 * project's test run, following the same shape as PickerDomHarnessTest and
 * StrictDecodingGuardTest: the real checks live in the external suite, here they
 * are simply made load-bearing for the build.
 *
 * @internal
 */
final class SchedulerDomHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/js/scheduler_harness.mjs';

    /** Printed by the harness only when every check passed. */
    private const SUCCESS_MARKER = 'ALL SCHEDULER DOM HARNESS CHECKS PASSED';

    /** Without the harness the scheduler's behaviour is enforced by nothing. */
    public function testSchedulerHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The scheduler DOM harness is missing; nothing drives the scheduler script itself.'
        );
    }

    public function testTheSchedulerSharesOneStateAndOneBackoff(): void
    {
        $node = $this->nodeInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "ships unlooked-at" outcome this harness exists to
        // prevent. Node is a hard build dependency for the front end, and the
        // appliance never runs this file.
        $this->assertNotNull(
            $node,
            'No node interpreter is available, so the scheduler DOM harness cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The harness resolves the repository root from its own path, so the
        // working directory is irrelevant. The whole report is captured — never
        // piped or filtered — so a failure names the check that failed.
        exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(
            0,
            $status,
            "The scheduler DOM harness failed:\n" . $report
        );
        $this->assertStringContainsString(self::SUCCESS_MARKER, $report);

        // The marker must not be printable by a harness that stopped running
        // checks: it reports how many it ran, and names the two behaviours the
        // module exists for, so a harness that measured nothing cannot pass.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::SUCCESS_MARKER, '/') . ' \((\d+) checks\)/',
            $report
        );
        $this->assertStringContainsString('the second waits double', $report);
        $this->assertStringContainsString('a single poller succeeding wakes the others immediately', $report);
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
