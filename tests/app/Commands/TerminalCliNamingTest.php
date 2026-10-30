<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Terminal CLI is `microctl`, and it may not drift out of use again.
 *
 * The tool was once shipped as `fwctl`, which meant the emergency console, the
 * guides and the ISO overlay all had to agree on one spelling by hand. This test
 * makes that agreement mechanical: the entry point must exist, be executable and
 * run, and no `*ctl` name anywhere in the shipped surface — sources, the CLI
 * itself, or the guides — may be anything but `microctl`.
 *
 * The name sweep is deliberately general rather than a search for one old
 * string: it classifies every `*ctl` token, so a rename to *any* other name (or
 * a second alias shipped beside the real one) fails, not just a reversion.
 *
 * `tests/` is not scanned. Test fixtures may legitimately need to spell a
 * historical or hypothetical name — including this file's own negative
 * fixtures — and the guarantee being made here is about what ships and what is
 * documented, not about what a test asserts.
 *
 * @internal
 */
final class TerminalCliNamingTest extends CIUnitTestCase
{
    private const CLI = 'bin/microctl';

    private const SHEBANG = '#!/usr/bin/env php';

    private const ARCHITECTURE_GUIDE = 'docs/MICRON_FIREWALL_OS_ARCHITECTURE.md';

    /**
     * The shipped surface: the CLI, the code backing it, the guides, and the
     * packaging that installs it on the appliance. `packaging` belongs here
     * because the ISO overlay is a shipping path — the staged name, the
     * completion and the package manifest all describe the command.
     */
    private const SCANNED_ROOTS = ['bin', 'app', 'system', 'docs', 'packaging'];

    private const TEXT_EXTENSIONS = [
        'php', 'py', 'md', 'html', 'sh', 'sql', 'json', 'yml', 'yaml', 'conf', 'service', 'nft', 'txt',
        // Packaging-specific: shell completion and live-build hook/package-list
        // files ship the CLI's name without carrying a common extension.
        'bash', 'chroot',
    ];

    /**
     * Files the sweep must actually reach. The roots above are broad, so a
     * reorganisation, an ignore rule or an extension gap could quietly shrink
     * coverage without any assertion failing — this list is what makes that a
     * failure instead.
     *
     * @var list<string>
     */
    private const REQUIRED_SWEPT = [
        'bin/microctl',
        'docs/MICRON_FIREWALL_OS_ARCHITECTURE.md',
        'packaging/live-build/stage-overlay.sh',
        'packaging/live-build/completion/microctl.bash',
        'packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot',
        'packaging/live-build/config/package-lists/micronos.list.chroot',
    ];

    /**
     * `build` is live-build's output directory: `lb build` fills it with the
     * chroot, the binary tree and the ISO itself, none of which is a source of
     * the CLI's name.
     */
    private const SKIPPED_DIRECTORIES = ['__pycache__', 'vendor', 'node_modules', '.git', 'build'];

    /**
     * Names that end in `ctl` but are not the MicronOS Terminal CLI: third-party
     * tools the sources and guides legitimately call. A token is acceptable when
     * it *is* `microctl`, or when it ends with one of these — which also covers
     * compound forms such as `reloadipsecswanctl` and `ipv6sysctl`.
     *
     * @var list<string>
     */
    private const FOREIGN_CTL_TOOLS = ['systemctl', 'journalctl', 'networkctl', 'resolvectl', 'sysctl', 'swanctl', 'timedatectl', 'ioctl', 'fcntl', 'smartctl'];

    /** §19.1 lists the console commands; a shrunken section must not pass vacuously. */
    private const MIN_DOCUMENTED_COMMANDS = 7;

    public function testTheTerminalCliShipsAsAnExecutableMicroctl(): void
    {
        $path = ROOTPATH . self::CLI;

        $this->assertFileExists($path, 'The Terminal CLI must ship as ' . self::CLI . '.');
        $this->assertTrue(is_file($path), self::CLI . ' must be a regular file.');

        $lines = explode("\n", (string) file_get_contents($path), 2);
        $this->assertSame(
            self::SHEBANG,
            trim($lines[0]),
            self::CLI . ' must keep its shebang, or `./microctl` cannot run on the appliance.'
        );

        // NTFS carries no Unix mode bits (fileperms() reports 0666 here), so the
        // execute bit only exists — and is only assertable — on a POSIX host,
        // which is where the appliance and the ISO build run.
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertTrue(
                is_executable($path),
                self::CLI . ' must be executable (chmod +x); a shebang alone does not make it runnable.'
            );
        }
    }

    public function testNoOtherNameIsShippedForTheTerminalCli(): void
    {
        $entries = array_values(array_diff(scandir(ROOTPATH . 'bin') ?: [], ['.', '..']));

        $this->assertContains('microctl', $entries, 'bin/ must contain the microctl entry point.');

        foreach ($entries as $entry) {
            if (preg_match('/ctl$/i', $entry) === 1) {
                $this->assertSame(
                    'microctl',
                    $entry,
                    'bin/ may ship only microctl as a `*ctl` entry point; ' . $entry
                    . ' would be the Terminal CLI under a second name.'
                );
            }
        }
    }

    public function testTheCliRunsAndAdvertisesOnlyItsOwnName(): void
    {
        $output = [];
        $status = 0;

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOTPATH . self::CLI) . ' help 2>&1',
            $output,
            $status
        );
        $report = implode("\n", $output);

        $this->assertSame(0, $status, "microctl help must run:\n" . $report);
        $this->assertStringContainsString('microctl', $report, 'The CLI must advertise its own name.');
        $this->assertSame([], $this->foreignCtlNamesIn($report), $this->foreignNameHint(['help output' => $report]));
    }

    public function testNoShippedSourceOrGuideNamesTheTerminalCliSomethingElse(): void
    {
        $swept = array_map(fn (string $path): string => $this->relativePath($path), $this->shippedFiles());

        foreach (self::REQUIRED_SWEPT as $required) {
            $this->assertContains(
                $required,
                $swept,
                'The naming sweep must cover ' . $required . '; every shipped surface that names the CLI has to be scanned.'
            );
        }

        $offenders = [];

        foreach ($this->shippedFiles() as $file) {
            // A systemd enablement link points at its target's path on the
            // appliance (/etc/systemd/...), which does not exist on a build host.
            if (is_link($file)) {
                continue;
            }

            foreach ($this->foreignCtlNamesIn((string) file_get_contents($file)) as $name) {
                $offenders[$name][] = $this->relativePath($file);
            }
        }

        $this->assertSame([], array_keys($offenders), $this->foreignNameHint($offenders));
    }

    public function testTheArchitectureGuideDocumentsTheCliAsMicroctl(): void
    {
        $path = ROOTPATH . self::ARCHITECTURE_GUIDE;
        $this->assertFileExists($path);

        $markdown = (string) file_get_contents($path);
        $start    = strpos($markdown, '### 19.1');
        $this->assertNotFalse($start, 'The architecture guide must keep §19.1, the Terminal CLI section.');

        $section = substr($markdown, (int) $start);
        $end     = strpos($section, "\n---");
        if ($end !== false) {
            $section = substr($section, 0, $end);
        }

        $this->assertMatchesRegularExpression(
            '/^### 19\.1[^\n]*`microctl`/m',
            $section,
            '§19.1 must name the Terminal CLI microctl.'
        );

        preg_match_all('/^- `([a-z0-9_-]+)/mi', $section, $matches);
        $names = array_values(array_unique(array_map('strtolower', $matches[1])));

        $this->assertGreaterThanOrEqual(
            self::MIN_DOCUMENTED_COMMANDS,
            count($matches[1]),
            '§19.1 must still list the console commands; an emptied section must not pass this guard vacuously.'
        );
        $this->assertSame(
            ['microctl'],
            $names,
            'Every command documented in §19.1 must be named microctl, whatever else it is renamed to.'
        );
    }

    /** The sweep is only worth anything if it can actually fail. */
    public function testTheDetectorFlagsAnyOtherName(): void
    {
        $this->assertSame(['fwctl'], $this->foreignCtlNamesIn('Usage: fwctl interface set-lan <ip/cidr>'));
        $this->assertSame(['micronctl'], $this->foreignCtlNamesIn('- `micronctl status`: Displays interface state.'));
        $this->assertSame(['micron-firewallctl'], $this->foreignCtlNamesIn('shipped as micron-firewallctl'));
        $this->assertSame(['fw_ctl'], $this->foreignCtlNamesIn('$argv[0] = \'fw_ctl\';'));

        // Names the guides are allowed to use stay silent.
        $this->assertSame([], $this->foreignCtlNamesIn('systemctl restart squid'));
        // A slug or qualified path naming the CLI itself is not another name.
        $this->assertSame([], $this->foreignCtlNamesIn('(#19-codeigniter-4-management-plane-architecture--cli-microctl)'));
        $this->assertSame([], $this->foreignCtlNamesIn('resolvectl flush-caches && journalctl -u micrond'));
        $this->assertSame([], $this->foreignCtlNamesIn('microctl backup restore /tmp/x.mbk'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Every `*ctl` token in the text that is not the Terminal CLI and not a
     * known third-party tool, lowercased and de-duplicated.
     *
     * `-` and `_` are part of a token so that snake_case identifiers (`fw_ctl`)
     * and hyphenated names (`micron-firewallctl`) cannot slip through as a bare
     * `ctl` suffix. The cost is that a heading slug matches as one long token
     * (`19-codeigniter-4-…--cli-microctl`), so any token *ending* in the CLI's
     * own name is accepted — those are the CLI's name, qualified or slugified,
     * not a different name for it.
     *
     * @return list<string>
     */
    private function foreignCtlNamesIn(string $text): array
    {
        preg_match_all('/\b[a-z0-9_-]*ctl\b/i', $text, $matches);

        $foreign = [];

        foreach ($matches[0] as $token) {
            $name = strtolower($token);

            if (str_ends_with($name, 'microctl')) {
                continue;
            }

            foreach (self::FOREIGN_CTL_TOOLS as $tool) {
                if (str_ends_with($name, $tool)) {
                    continue 2;
                }
            }

            $foreign[$name] = true;
        }

        return array_keys($foreign);
    }

    /**
     * @return list<string>
     */
    private function shippedFiles(): array
    {
        $files = [];

        foreach (self::SCANNED_ROOTS as $root) {
            $base = ROOTPATH . $root;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $base,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                $path = $file->getPathname();

                foreach (self::SKIPPED_DIRECTORIES as $skipped) {
                    if (str_contains($path, DIRECTORY_SEPARATOR . $skipped . DIRECTORY_SEPARATOR)) {
                        continue 2;
                    }
                }

                // Extension-less files count: `bin/microctl`, `auto/config` and
                // the staged overlay copies are exactly the files that name the
                // command, and they carry no extension to match on.
                $extension = strtolower($file->getExtension());
                if ($extension !== '' && ! in_array($extension, self::TEXT_EXTENSIONS, true)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        $this->assertNotSame([], $files, 'The naming sweep must find shipped files, or it proves nothing.');

        return $files;
    }

    private function relativePath(string $path): string
    {
        $relative = str_starts_with($path, ROOTPATH) ? substr($path, strlen(ROOTPATH)) : $path;

        return str_replace('\\', '/', $relative);
    }

    /**
     * @param array<string, mixed> $offenders
     */
    private function foreignNameHint(array $offenders = []): string
    {
        $detail = $offenders === [] ? '' : ' Found: ' . json_encode($offenders);

        return 'Every `*ctl` name in the shipped surface must be `microctl`. If one of these is a new '
            . 'third-party tool, add it to FOREIGN_CTL_TOOLS; otherwise the Terminal CLI has been renamed.'
            . $detail;
    }
}
