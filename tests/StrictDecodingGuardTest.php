<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the Python strict-decoding guard must run inside the PHP test run.
 *
 * tests/test_strict_decoding_guard.py is an AST scanner that fails on any lenient
 * decoder (`json.loads` without `parse_constant=`, `b64decode` without
 * `validate=True`, `raw_decode`, `binascii.a2b_base64`), on raw crypto
 * (`hashlib`/`hmac`) and on shell primitives, across every harness in tests/ and
 * across the micrond data plane in system/micrond/.
 *
 * A guard only protects the tree while it runs, so this test wires it into the
 * project's test run: a lenient decoder can no longer land unnoticed. It follows
 * the same shape as Phase0SlotBTest — the real checks live in the external suite,
 * here they are simply made load-bearing for the build.
 *
 * @internal
 */
final class StrictDecodingGuardTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/test_strict_decoding_guard.py';

    /** Without the harness the rule is enforced by nothing. */
    public function testGuardHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The strict-decoding guard harness is missing; nothing enforces the strict-primitive rules.'
        );
    }

    public function testNoLenientDecodersOrRawPrimitivesInTheTree(): void
    {
        $python = $this->pythonInterpreter();

        // A missing interpreter is a hard failure, never a skip: skipping here
        // would be exactly the "land unnoticed" outcome this guard exists to
        // prevent. The Python toolchain is a hard project dependency (the whole
        // micrond data plane is Python), so this does not weaken the build.
        $this->assertNotNull(
            $python,
            'No python3/python interpreter is available, so the strict-decoding guard cannot run.'
        );

        $harness = ROOTPATH . self::HARNESS;
        $output  = [];
        $status  = 0;

        // The harness derives the repository root from its own path, so the
        // working directory is irrelevant. The whole report is captured — never
        // piped or filtered — so a failure names the offending file and line.
        exec(escapeshellarg($python) . ' ' . escapeshellarg($harness) . ' 2>&1', $output, $status);
        $report = implode("\n", $output);

        $this->assertSame(
            0,
            $status,
            "The strict-decoding guard flagged the tree:\n" . $report
        );
        $this->assertStringContainsString('ALL STRICT-PRIMITIVE GUARD CHECKS PASSED', $report);
    }

    /**
     * The first interpreter on PATH that answers, or null when the host has none.
     * `python3` first (Linux/appliance), then `python` (Windows dev boxes).
     */
    private function pythonInterpreter(): ?string
    {
        foreach (['python3', 'python'] as $candidate) {
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
