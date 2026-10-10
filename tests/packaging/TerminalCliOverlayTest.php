<?php

declare(strict_types=1);

namespace Tests\Packaging;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the wiring that ships the Terminal CLI inside the appliance image.
 *
 * live-build copies packaging/live-build/config/includes.chroot/ verbatim onto
 * the appliance root filesystem, so whatever that overlay holds is what the
 * console actually runs. The overlay is staged from bin/microctl on every build
 * rather than checked in beside it, which means the staging step itself is the
 * thing that can silently break: drop the call from auto/config, or stop staging
 * a file, and the image boots without a CLI. These tests execute the staging
 * path and inspect the result, so that failure is caught here rather than at
 * install time.
 *
 * The tests touch only temporary directories; nothing under packaging/ is
 * written to.
 */
final class TerminalCliOverlayTest extends CIUnitTestCase
{
    private const CLI        = 'bin/microctl';
    private const STAGE      = 'packaging/live-build/stage-overlay.sh';
    private const COMPLETION = 'packaging/live-build/completion/microctl.bash';
    private const AUTO_CONFIG = 'packaging/live-build/auto/config';
    private const HOOK       = 'packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot';
    private const MANIFEST   = 'packaging/live-build/config/package-lists/micronos.list.chroot';

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/micron-overlay-' . bin2hex(random_bytes(6));

        if (! mkdir($this->tmp, 0777, true) && ! is_dir($this->tmp)) {
            $this->fail('Could not create the staging directory ' . $this->tmp);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);

        parent::tearDown();
    }

    /**
     * The staging assets are sources, not generated output: the script and the
     * completion have to be present for a build to stage anything at all.
     */
    public function testTheOverlayStagingAssetsAreShipped(): void
    {
        foreach ([self::STAGE, self::COMPLETION] as $relative) {
            $path = $this->path($relative);

            $this->assertFileExists($path, "The ISO build needs {$relative} to stage the Terminal CLI.");
            $this->assertGreaterThan(0, (int) filesize($path), "{$relative} is empty.");
        }
    }

    /**
     * Run the real staging script into a throwaway overlay root and check that
     * both files land where the appliance expects them, byte-for-byte.
     *
     * Identity matters more than existence: a truncated or hand-edited copy is
     * exactly the drift the staging step exists to remove.
     */
    public function testStagingPlacesTheCliAndCompletionInTheOverlay(): void
    {
        $out = $this->tmp . '/includes.chroot';

        $result = $this->bash([$this->bashBinary(), $this->bashPath($this->path(self::STAGE)), '--out', $this->bashPath($out)]);
        $this->assertSame(0, $result['status'], "stage-overlay.sh failed:\n" . $result['output']);

        $stagedCli        = $out . '/usr/local/bin/microctl';
        $stagedCompletion = $out . '/usr/share/bash-completion/completions/microctl';

        $this->assertFileExists($stagedCli);
        $this->assertFileExists($stagedCompletion);

        $this->assertSame(
            md5_file($this->path(self::CLI)),
            md5_file($stagedCli),
            'The staged microctl must be byte-for-byte the CLI the repository ships.'
        );
        $this->assertSame(
            md5_file($this->path(self::COMPLETION)),
            md5_file($stagedCompletion),
            'The staged completion must be byte-for-byte the completion the repository ships.'
        );

        // NTFS carries no POSIX mode bits, so PHP reports 0666 for everything
        // on this host; the modes are asserted where they exist (Linux/CI).
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame('0755', $this->modeOf($stagedCli), 'microctl must be staged executable.');
            $this->assertSame('0644', $this->modeOf($stagedCompletion), 'The completion must be staged readable.');
        }

        // The script advertises what it staged, which is what a build log shows.
        $this->assertStringContainsString($this->bashPath($stagedCli), $result['output']);
        $this->assertStringContainsString($this->bashPath($stagedCompletion), $result['output']);
    }

    /**
     * The appliance keeps the application at /usr/local/micron and the CLI on
     * PATH, so the installed copy has to find an application root that is not
     * its parent directory. MICRON_ROOT is the documented escape hatch and the
     * only one that can be exercised off-appliance.
     */
    public function testTheStagedCliResolvesItsApplicationRoot(): void
    {
        $out = $this->tmp . '/includes.chroot';
        $this->bash([$this->bashBinary(), $this->bashPath($this->path(self::STAGE)), '--out', $this->bashPath($out)]);

        $stagedCli = $out . '/usr/local/bin/microctl';
        $this->assertFileExists($stagedCli);

        $env                  = getenv();
        $env['MICRON_ROOT']   = rtrim((string) ROOTPATH, "/\\");

        $result = $this->bash(['php', $stagedCli, 'help'], $env);
        $this->assertSame(0, $result['status'], "The staged CLI could not run:\n" . $result['output']);
        $this->assertStringContainsString('MicronOS Console Management Utility (microctl)', $result['output']);
    }

    /**
     * Execute the staged completion rather than grepping it: a completion file
     * with a syntax error, or one that offers the wrong words, is worse than
     * none.
     */
    public function testTheCompletionOffersTheCommandsMicroctlAccepts(): void
    {
        $snippet = <<<'BASH'
        source "$1"
        probe() {
            COMP_WORDS=("$@")
            COMP_CWORD=$(( ${#COMP_WORDS[@]} - 1 ))
            COMPREPLY=()
            _microctl
            printf '%s\n' "${COMPREPLY[@]}"
        }
        echo "###top"
        probe microctl ""
        echo "###backup"
        probe microctl backup ""
        echo "###config"
        probe microctl config ""
        echo "###interface"
        probe microctl interface ""
        echo "###create-options"
        probe microctl backup create "--"
        echo "###restore-options"
        probe microctl backup restore "--"
        BASH;

        $result = $this->bash(
            [$this->bashBinary(), '-c', $snippet, 'micron-completion-probe', $this->bashPath($this->path(self::COMPLETION))]
        );
        $this->assertSame(0, $result['status'], "The completion is not valid bash:\n" . $result['output']);

        $sections = $this->sections($result['output']);

        foreach (['top', 'backup', 'config', 'interface', 'create-options', 'restore-options'] as $probe) {
            $this->assertArrayHasKey($probe, $sections, "The completion probe '{$probe}' produced no output:\n" . $result['output']);
        }

        // Every top-level command the CLI dispatches on.
        $this->assertSame(
            ['status', 'interface', 'firewall', 'backup', 'config', 'admin', 'help'],
            $sections['top']
        );

        $this->assertSame(['create', 'restore'], $sections['backup']);
        $this->assertSame(['rollback'], $sections['config']);
        $this->assertSame(['set-lan'], $sections['interface']);

        // Options are scoped to the subcommand: create and restore differ.
        $this->assertContains('--encrypt', $sections['create-options']);
        $this->assertNotContains('--replace-key', $sections['create-options']);

        $this->assertContains('--replace-key', $sections['restore-options']);
        $this->assertContains('--force', $sections['restore-options']);
    }

    /**
     * The overlay is generated, so a checkout has to stage it before lb build
     * runs. auto/config is the one script live-build always executes first.
     */
    public function testTheBuildStagesTheOverlayWithoutBeingAskedTo(): void
    {
        $autoConfig = (string) file_get_contents($this->path(self::AUTO_CONFIG));

        $this->assertStringContainsString(
            'stage-overlay.sh',
            $autoConfig,
            'auto/config must run the overlay staging script, or a fresh checkout builds an image with no CLI.'
        );
        $this->assertLessThan(
            strpos($autoConfig, "\nlb config"),
            strpos($autoConfig, 'stage-overlay.sh'),
            'The overlay must be staged before lb config snapshots the configuration tree.'
        );

        $gitignore = (string) file_get_contents($this->path('.gitignore'));
        $this->assertStringContainsString(
            'packaging/live-build/config/includes.chroot',
            $gitignore,
            'The generated overlay must stay out of version control; its source of truth is bin/microctl.'
        );
    }

    /**
     * bash-completion is what makes /usr/share/bash-completion/completions work
     * in an interactive shell, so the manifest has to install it — a staged
     * completion file alone would never load.
     */
    public function testThePackageManifestInstallsTheCompletionFramework(): void
    {
        $manifest = $this->manifestLines();

        $this->assertContains('bash-completion', $manifest);

        // And the hook normalises the CLI onto PATH for the recovery console.
        $hook = (string) file_get_contents($this->path(self::HOOK));
        $this->assertStringContainsString('/usr/local/bin/microctl', $hook);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function manifestLines(): array
    {
        $lines = [];

        foreach (file($this->path(self::MANIFEST), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Split the completion probe's output into its `###name` sections.
     *
     * @return array<string, list<string>>
     */
    private function sections(string $output): array
    {
        $sections = [];
        $current  = '';

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '###')) {
                $current                = substr($line, 3);
                $sections[$current]     = [];
                continue;
            }

            if ($current !== '' && $line !== '') {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    /**
     * The POSIX mode bits as a four-digit string, e.g. `0755`. Only meaningful
     * where the filesystem stores them; see the caller's host guard.
     */
    private function modeOf(string $path): string
    {
        $perms = @fileperms($path);

        if ($perms === false) {
            $this->fail('Could not stat ' . $path);
        }

        return '0' . substr(sprintf('%o', $perms), -3);
    }

    private function path(string $relative): string
    {
        return rtrim((string) ROOTPATH, "/\\") . '/' . $relative;
    }

    /**
     * An absolute path to a bash that can actually run a script.
     *
     * A bare `bash` will not do: on Windows, `CreateProcess` searches System32
     * before PATH, so it finds the WSL launcher (`C:\Windows\System32\bash.exe`)
     * rather than Git Bash, and WSL cannot see a Windows path at all. The
     * interpreter is therefore resolved explicitly, with MICRON_BASH as the
     * escape hatch for an unusual layout. A host with no bash fails loudly: the
     * ISO build is a Debian live-build, so bash is a hard dependency of the
     * thing under test, not an optional extra.
     */
    private function bashBinary(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $candidates = [
            getenv('MICRON_BASH') ?: null,
            ($local = getenv('LOCALAPPDATA') ?: '') !== '' ? $local . '/Programs/Git/bin/bash.exe' : null,
            ($programFiles = getenv('ProgramFiles') ?: '') !== '' ? $programFiles . '/Git/bin/bash.exe' : null,
            ($programFiles = getenv('ProgramFiles') ?: '') !== '' ? $programFiles . '/Git/usr/bin/bash.exe' : null,
            '/usr/bin/bash',
            '/bin/bash',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $resolved = $candidate;
            }
        }

        $this->fail(
            'No bash interpreter found. The ISO build is a Debian live-build, so bash is required; '
            . 'set MICRON_BASH to an absolute bash path to run this test.'
        );
    }

    /**
     * A path in the form bash will accept on this host. Git Bash opens files
     * given a native Windows path, but cannot open a script handed to it as
     * `bash <script>`, so separators are normalised before anything is passed
     * across the boundary. No-op on Linux.
     */
    private function bashPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Run a command with an explicit argv array — no shell string, so paths and
     * arguments need no quoting on any host.
     *
     * @param list<string>              $argv
     * @param array<string, string>|null $env
     *
     * @return array{status: int, output: string}
     */
    private function bash(array $argv, ?array $env = null): array
    {
        $process = proc_open(
            $argv,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );

        if (! is_resource($process)) {
            $this->fail('Could not launch: ' . implode(' ', $argv));
        }

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
