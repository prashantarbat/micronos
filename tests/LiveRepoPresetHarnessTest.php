<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta-test — the live preset-verification harness must stay wired in, and its
 * network path must stay opt-in.
 *
 * `tests/test_repo_presets_live.py` fetches each shipped preset's `InRelease`
 * over the network and proves the preset's declared fingerprint really signs the
 * vendor's metadata. That fetch cannot run inside a deterministic test run, so
 * the harness is opt-in: without `--live` it must fetch nothing and say so.
 *
 * This test makes two things load-bearing without ever reaching the network:
 *
 *  1. The opt-in gate. A bare invocation must exit success *and* report that it
 *     skipped, so the default run can never begin fetching (or silently look like
 *     it verified something).
 *  2. The offline `--list` view, which resolves every shipped preset and the
 *     exact `InRelease` URL it would fetch — so a preset that lost its
 *     fingerprint, or a URL that stopped being built, fails here rather than at
 *     the moment a human runs the live check.
 *
 * It follows the same shape as StrictDecodingGuardTest: the real work lives in
 * the external harness, and this only makes it load-bearing for the build.
 *
 * @internal
 */
final class LiveRepoPresetHarnessTest extends CIUnitTestCase
{
    private const HARNESS = 'tests/test_repo_presets_live.py';

    public function testHarnessExists(): void
    {
        $this->assertFileExists(
            ROOTPATH . self::HARNESS,
            'The live preset-verification harness is missing; nothing proves the shipped presets still verify against their vendors.'
        );
    }

    /**
     * The whole point of "opt-in": a bare run never touches the network.
     */
    public function testABareRunIsSkippedAndFetchesNothing(): void
    {
        [$status, $report] = $this->runHarness([]);

        $this->assertSame(0, $status, "A bare run must exit success:\n" . $report);
        $this->assertStringContainsString(
            'SKIPPED (opt-in)',
            $report,
            "A bare run must state that it skipped, not look like a pass:\n" . $report
        );
        // The skip notice names the flag that would make it fetch, so an operator
        // is never left guessing how to run the real check.
        $this->assertStringContainsString('--live', $report);
    }

    /**
     * `--list` resolves every shipped preset and the InRelease URL each would be
     * fetched from — without a network call.
     */
    public function testTheOfflineListViewNamesEveryPresetAndItsInReleaseUrl(): void
    {
        [$status, $report] = $this->runHarness(['--list']);

        $this->assertSame(0, $status, "--list must exit success:\n" . $report);

        $headers = [];
        $inrelease = 0;
        $detached = 0;

        foreach (preg_split('/\r?\n/', $report) as $line) {
            if (preg_match('/^(\S+): ([0-9A-F]{40}|[0-9A-F]{64})\s+suite=/', $line, $m) === 1) {
                $headers[$m[1]] = $m[2];
            }
            if (str_starts_with($line, '  inrelease: ')) {
                $inrelease++;
                $this->assertStringContainsString(
                    'InRelease',
                    $line,
                    'the InRelease candidate must name an InRelease URL'
                );
                $this->assertMatchesRegularExpression(
                    '#^  inrelease: https://#',
                    $line,
                    'the InRelease candidate must be an https URL'
                );
            }
            if (str_starts_with($line, '  release: ')) {
                $detached++;
                $this->assertStringContainsString('Release.gpg', $line, 'the detached fallback names its signature');
            }
        }

        $this->assertNotSame([], $headers, "--list named no presets:\n" . $report);
        $this->assertSame(
            count($headers),
            $inrelease,
            'every preset must resolve exactly one InRelease candidate'
        );
        $this->assertSame(
            count($headers),
            $detached,
            'every preset must resolve exactly one detached-release fallback'
        );
    }

    /**
     * A preset that is not in the registry is a failure, not a silent no-op — the
     * harness must not report success for a check it never ran.
     */
    public function testAnUnknownPresetIsRefused(): void
    {
        [$status, $report] = $this->runHarness(['--list', '--preset', 'does-not-exist']);

        $this->assertSame(1, $status, "--list for an unknown preset must fail:\n" . $report);
        $this->assertStringContainsString('every requested preset exists', $report);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string} [exit status, combined output]
     */
    private function runHarness(array $arguments): array
    {
        $python = $this->pythonInterpreter();

        // A missing interpreter is a hard failure, never a skip: the whole
        // micrond data plane is Python, and a skip here would be exactly the
        // "land unnoticed" outcome this guard exists to prevent.
        $this->assertNotNull(
            $python,
            'No python3/python interpreter is available, so the live preset harness cannot be driven.'
        );

        $command = escapeshellarg($python) . ' ' . escapeshellarg(ROOTPATH . self::HARNESS);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
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
