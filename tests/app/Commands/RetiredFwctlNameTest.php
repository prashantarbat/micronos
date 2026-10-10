<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `fwctl` is retired, and this test is what keeps it retired.
 *
 * The Terminal CLI was once shipped as `fwctl`; it is `microctl` now, and the
 * name has to stay gone from everything the appliance ships — the sources, the
 * guides and their generated HTML, the ISO overlay, the installer, the served
 * assets and the repository's own documentation. A half-renamed surface is how
 * an operator ends up typing a command that does not exist, on a console that
 * has no web interface to check against.
 *
 * `TerminalCliNamingTest` classifies every `*ctl` token and would also catch
 * this name, so the two overlap on purpose. This test exists for three things
 * that general sweep does not do:
 *
 *  - it spells the retired name out, so the guarantee survives even if the
 *    classifier is rewritten or its foreign-tool list grows a hole;
 *  - it scans files that ship but sit outside that sweep's roots — `public/`,
 *    `installer/` and the repository's own root files;
 *  - it checks **file and directory names**, because a shipped `bin/fwctl` or
 *    `docs/fwctl-guide.md` reintroduces the name as surely as a paragraph does.
 *
 * `tests/` is deliberately not scanned: the negative fixtures here, and in the
 * naming sweep, have to be able to spell the retired name, and a test fixture is
 * not something the appliance ships.
 *
 * The guarantee is absolute, and that is worth knowing before adding an
 * exception for one: a shipped guide cannot even say "the command was once
 * called fwctl", because the sentence is the reintroduction. This docblock is
 * the place for that history — it is the one surface the sweep does not read.
 *
 * @internal
 */
final class RetiredFwctlNameTest extends CIUnitTestCase
{
    /**
     * The retired name in every spelling that would put it back in use:
     * `fwctl`, `FWCTL`, `fw_ctl`, `fw-ctl`, and inside a compound such as
     * `micron-fwctl`. The lookarounds keep it from firing on a longer word that
     * merely contains those letters, and on the CLI's current name.
     */
    private const RETIRED = '/(?<![a-z0-9])fw[-_]?ctl(?![a-z0-9])/i';

    /**
     * Everything the appliance ships, plus the repository's own documentation.
     *
     * `public` is served as the web root and `installer` is copied to
     * `/usr/local/micron/installer` by the ISO hook, so both are shipping paths
     * even though they carry no PHP command. `docs` includes the generated
     * `.html` exports, which are what an operator reads outside the app.
     *
     * @var list<string>
     */
    private const SCANNED_ROOTS = ['bin', 'app', 'system', 'docs', 'packaging', 'public', 'installer'];

    /**
     * Root-level files: `README.md`, `composer.json`, `spark`, `preload.php` and
     * the like. Dot-files are skipped — `.env` and `.gitignore` are local and
     * tooling configuration, not shipped artefacts.
     *
     * @var list<string>
     */
    private const SCANNED_ROOT_FILES = ['README.md', 'composer.json', 'composer.lock', 'LICENSE', 'spark', 'preload.php', 'env', 'phpunit.dist.xml'];

    /**
     * Directories that are not the sources or the docs, with the reason each one
     * is out of scope. Every entry is deliberate: an exclusion added to silence
     * a finding would be a hole in the guarantee, so the list is asserted below
     * to be exactly this set.
     *
     * @var array<string, string>
     */
    private const SKIPPED_DIRECTORIES = [
        'tests'        => 'fixtures must be able to spell the retired name',
        'vendor'       => 'third-party code, not ours to name anything',
        'node_modules' => 'third-party code',
        '.git'         => 'repository metadata',
        'writable'     => 'runtime state: caches, logs and sessions',
        'build'        => "live-build's output tree",
        'builds'       => 'packaging output',
        'graphify-out' => 'generated code-graph output, regenerated from the sources',
        '__pycache__'  => 'compiled Python',
    ];

    /** Only text is read; anything else is checked by name alone. */
    private const TEXT_EXTENSIONS = [
        'php', 'py', 'md', 'html', 'htm', 'sh', 'bash', 'sql', 'json', 'yml', 'yaml', 'conf', 'ini',
        'service', 'socket', 'target', 'nft', 'txt', 'js', 'css', 'csv', 'xml', 'env', 'chroot', 'htaccess',
    ];

    /** A file bigger than this is not a source or a guide; read it by name only. */
    private const MAX_BYTES_READ = 4_000_000;

    /**
     * Files the sweep must reach. The roots above are broad, so a move, an
     * ignore rule or an extension gap could shrink coverage without any
     * assertion failing — this list is what turns that into a failure. It also
     * pins the other half of the guarantee: `microctl` must still be there, or
     * "no `fwctl` anywhere" would be satisfied by shipping no CLI at all.
     *
     * @var list<string>
     */
    private const REQUIRED_SWEPT = [
        'bin/microctl',
        'docs/MICRON_FIREWALL_OS_ARCHITECTURE.md',
        'docs/MICRON_FIREWALL_OS_ARCHITECTURE.html',
        'packaging/live-build/stage-overlay.sh',
        'packaging/live-build/completion/microctl.bash',
        'packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot',
        'packaging/live-build/config/package-lists/micronos.list.chroot',
        'installer/install.sh',
        'public/index.php',
        'README.md',
        'composer.json',
        'spark',
    ];

    public function testTheRetiredNameIsAbsentFromEveryShippedFile(): void
    {
        $offenders = [];

        foreach ($this->shippedFiles() as $relative => $absolute) {
            $contents = $this->readableText($absolute);
            if ($contents === null) {
                continue;
            }

            foreach ($this->retiredHitsInText($contents) as $line) {
                $offenders[] = $relative . ':' . $line;
            }
        }

        $this->assertSame([], $offenders, $this->hint($offenders, 'mentions'));
    }

    public function testNoShippedFileOrDirectoryIsNamedAfterTheRetiredCli(): void
    {
        $offenders = [];

        foreach ($this->shippedPaths() as $relative) {
            if (preg_match(self::RETIRED, $relative) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, $this->hint($offenders, 'names'));
    }

    /**
     * The sweep has to be looking at the shipped surface, at a real number of
     * files, and at the CLI that is supposed to exist instead.
     */
    public function testTheSweepReachesTheShippedSurface(): void
    {
        $files = array_keys($this->shippedFiles());

        $this->assertGreaterThanOrEqual(
            200,
            count($files),
            'The sweep must cover the shipped tree; a collapsed walk would pass the checks above while proving nothing.'
        );

        foreach (self::REQUIRED_SWEPT as $required) {
            $this->assertContains(
                $required,
                $files,
                'The retired-name sweep must cover ' . $required . '.'
            );
        }

        $this->assertFileExists(ROOTPATH . 'bin/microctl', 'The Terminal CLI must still ship as bin/microctl.');
    }

    /** The exclusions are the only way the sweep could be hollowed out, so they are pinned. */
    public function testTheSkippedDirectoriesAreExactlyTheDocumentedSet(): void
    {
        $this->assertSame(
            [
                'tests', 'vendor', 'node_modules', '.git', 'writable', 'build', 'builds', 'graphify-out', '__pycache__',
            ],
            array_keys(self::SKIPPED_DIRECTORIES),
            'Every skipped directory needs a reason; adding one to silence a finding would be a hole in the guarantee.'
        );
    }

    public function testTheDetectorFlagsTheRetiredNameAndNothingElse(): void
    {
        // Every spelling that puts the retired name back in use.
        $this->assertSame([1], $this->retiredHitsInText('Usage: fwctl interface set-lan <ip/cidr>'));
        $this->assertSame([1], $this->retiredHitsInText('FWCTL STATUS'));
        $this->assertSame([1], $this->retiredHitsInText('$argv[0] = \'fw_ctl\';'));
        $this->assertSame([1], $this->retiredHitsInText('shipped as micron-fwctl'));
        $this->assertSame([3], $this->retiredHitsInText("line one\nline two\n  fwctl backup create"));
        $this->assertSame([1, 2], $this->retiredHitsInText("run fwctl\nand fw-ctl again"));
        $this->assertSame([1], $this->retiredHitsInText('docs/fwctl.md'));

        $this->assertSame([], $this->retiredHitsInText('microctl backup restore /tmp/x.mbk'));
        $this->assertSame([], $this->retiredHitsInText('systemctl restart squid && journalctl -u micrond'));
        $this->assertSame([], $this->retiredHitsInText('resolvectl flush-caches; networkctl status'));
        $this->assertSame([], $this->retiredHitsInText('the CLI is microctl, and its completion is microctl.bash'));
        $this->assertSame([], $this->retiredHitsInText('bash-completion loads /usr/share/bash-completion/completions/microctl'));

        // Paths are judged the same way: the retired name as a segment or a word.
        $this->assertSame(1, preg_match(self::RETIRED, 'bin/fwctl'));
        $this->assertSame(1, preg_match(self::RETIRED, 'docs/guide/fw_ctl-status.md'));
        $this->assertSame(1, preg_match(self::RETIRED, 'packaging/fwctl.bash'));
        $this->assertSame(0, preg_match(self::RETIRED, 'bin/microctl'));
        $this->assertSame(0, preg_match(self::RETIRED, 'packaging/live-build/completion/microctl.bash'));
        $this->assertSame(0, preg_match(self::RETIRED, 'app/Commands/BackupCreate.php'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Every shipped file, relative path => absolute path, files and directories
     * alike so that a name check can see them.
     *
     * @return array<string, string>
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
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());

                if ($this->isSkipped($path)) {
                    continue;
                }

                $relative = $this->relative($path);

                if ($this->isText($path)) {
                    $files[$relative] = $path;
                }
            }
        }

        foreach (self::SCANNED_ROOT_FILES as $name) {
            $path = ROOTPATH . $name;
            if (is_file($path)) {
                $files[$name] = $path;
            }
        }

        ksort($files);

        $this->assertNotSame([], $files, 'The retired-name sweep must find shipped files, or it proves nothing.');

        return $files;
    }

    /**
     * Every shipped path — files *and* the directories holding them — so a
     * reintroduction as a file name cannot hide.
     *
     * @return list<string>
     */
    private function shippedPaths(): array
    {
        $paths = [];

        foreach (self::SCANNED_ROOTS as $root) {
            $base = ROOTPATH . $root;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $entry) {
                $path = str_replace('\\', '/', $entry->getPathname());

                if ($this->isSkipped($path)) {
                    continue;
                }

                $paths[$this->relative($path)] = true;
            }
        }

        foreach (self::SCANNED_ROOT_FILES as $name) {
            if (is_file(ROOTPATH . $name)) {
                $paths[$name] = true;
            }
        }

        $this->assertNotSame([], $paths, 'The path sweep must find shipped paths.');

        return array_keys($paths);
    }

    /**
     * The 1-based line numbers of every retired-name occurrence in a text.
     *
     * @return list<int>
     */
    private function retiredHitsInText(string $text): array
    {
        preg_match_all(self::RETIRED, $text, $matches, PREG_OFFSET_CAPTURE);

        $lines = [];

        foreach ($matches[0] as [, $offset]) {
            $lines[] = substr_count(substr($text, 0, (int) $offset), "\n") + 1;
        }

        return array_values(array_unique($lines));
    }

    private function isSkipped(string $path): bool
    {
        $normalised = str_replace('\\', '/', $path);

        foreach (array_keys(self::SKIPPED_DIRECTORIES) as $skipped) {
            if (str_contains($normalised, '/' . $skipped . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isText(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        // Extension-less files count: `bin/microctl`, `auto/config` and the
        // staged overlay copies carry no extension and are exactly the files
        // that name the command.
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' || in_array($extension, self::TEXT_EXTENSIONS, true);
    }

    /**
     * The file's contents, or null when it is too large to be a source or a
     * guide. Size is checked first so a stray artefact cannot make the sweep
     * read megabytes into memory.
     */
    private function readableText(string $path): ?string
    {
        if (filesize($path) > self::MAX_BYTES_READ) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, str_replace('\\', '/', ROOTPATH))
            ? substr($path, strlen(str_replace('\\', '/', ROOTPATH)))
            : str_replace('\\', '/', $path);
    }

    /**
     * @param list<string> $offenders
     */
    private function hint(array $offenders, string $what): string
    {
        return 'The Terminal CLI is `microctl`; `fwctl` is retired and must not come back. These shipped '
            . $what . ' reintroduce it: ' . json_encode($offenders)
            . ' — rename them to microctl rather than adding an exception here.';
    }
}
