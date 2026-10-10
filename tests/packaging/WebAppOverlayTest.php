<?php

declare(strict_types=1);

namespace Tests\Packaging;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the wiring that ships the web application inside the appliance image.
 *
 * live-build copies packaging/live-build/config/includes.chroot/ verbatim onto
 * the appliance root filesystem, and the image runs the application from
 * /usr/local/micron. That tree used to be copied in by hand, which drifts
 * silently: a tree copied a release ago, or copied minus a directory added since,
 * boots an appliance that answers 200 and behaves like an older build.
 *
 * These tests run the real staging script and compare what landed against the
 * repository, in both directions — everything that should ship is there,
 * byte-for-byte, and nothing that should not ship is.
 *
 * The tests touch only temporary directories; nothing under packaging/ is written
 * to.
 *
 * @internal
 */
final class WebAppOverlayTest extends CIUnitTestCase
{
    private const STAGE = 'packaging/live-build/stage-overlay.sh';
    private const HOOK  = 'packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot';

    /**
     * The application root the appliance runs from. The provisioning hook serves
     * DocumentRoot /usr/local/micron/public and starts the IPC daemon from
     * /usr/local/micron/system/micrond; the CLI resolves the same root.
     */
    private const APP_ROOT = '/usr/local/micron';

    /**
     * What the image is allowed to carry, and nothing else. This is the same list
     * the staging script holds, asserted against it below: it is an allow-list, so
     * a development artifact that appears in the repository later cannot start
     * shipping by being under a directory that was already copied.
     *
     * @var list<string>
     */
    private const SHIPPED = [
        'app', 'bin', 'installer', 'public', 'system', 'vendor',
        'spark', 'preload.php', 'composer.json', 'composer.lock', 'env',
        'LICENSE', 'README.md',
    ];

    /**
     * Development state that exists in the checkout and must never reach an image:
     * the environment file, the test suite, the build's own tree, and the
     * writable/ state — including the development configuration database.
     *
     * @var list<string>
     */
    private const NEVER_SHIPPED = [
        '.env',
        'tests',
        'docs',
        'packaging',
        'build',
        'builds',
        'phpunit.dist.xml',
        '.pytest_cache',
        'test_nat_render.html',
        'writable/micron_config.db',
        'writable/_refactor_backup',
    ];

    /** State directories the application expects to find, created empty. */
    private const STATE_DIRS = [
        'backups', 'cache', 'connectors', 'debugbar', 'fqdn', 'geoip',
        'isdb', 'logs', 'pki', 'session', 'uploads',
    ];

    private static ?string $staged = null;

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/micron-app-' . bin2hex(random_bytes(6));

        if (! mkdir($this->tmp, 0777, true) && ! is_dir($this->tmp)) {
            $this->fail('Could not create the staging directory ' . $this->tmp);
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$staged !== null) {
            self::removeTree(self::$staged);
            self::$staged = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * The staging script is the single source of the image's application tree, so
     * its allow-list is the contract. Asserted in both directions: a directory the
     * image needs cannot quietly drop out, and a development directory cannot
     * quietly join by sitting beside the ones that are copied.
     */
    public function testTheStagingScriptShipsTheApplicationFromOneAllowList(): void
    {
        $script = (string) file_get_contents($this->path(self::STAGE));

        $this->assertSame(
            1,
            preg_match('#\nshipped=\(\n(.*?)\n\)#s', $script, $list),
            'stage-overlay.sh must declare what it ships as one list.'
        );

        $shipped = preg_split('/\s+/', trim($list[1])) ?: [];
        sort($shipped);

        $expected = self::SHIPPED;
        sort($expected);

        $this->assertSame($expected, $shipped, 'the staged application tree is not the allow-list the image is built from');

        foreach (self::SHIPPED as $entry) {
            $this->assertFileExists($this->path($entry), "the repository does not hold {$entry}");
        }

        foreach (self::NEVER_SHIPPED as $entry) {
            $this->assertNotContains($entry, $shipped, "{$entry} must never be staged into the image");
        }
    }

    /**
     * Every file of every shipped entry lands, byte-for-byte. The whole tree is
     * compared as one manifest rather than a sample, because the drift this guards
     * against is exactly the file nobody thought to sample.
     */
    public function testEveryShippedFileReachesTheOverlayUnchanged(): void
    {
        $overlay = $this->stage();

        $expected = [];
        $actual   = [];

        foreach (self::SHIPPED as $entry) {
            $expected[] = $this->manifest($this->path($entry), $entry);
            $actual[]   = $this->manifest($overlay . '/' . $entry, $entry);
        }

        $expected = array_merge(...$expected);
        $actual   = array_merge(...$actual);

        // Compared as a digest rather than element by element: a mismatch is worth
        // one clear failure, not a three-thousand-line array diff.
        $this->assertSame(
            count($expected),
            count($actual),
            'the staged application tree does not hold the same number of files as the repository'
        );
        $this->assertSame(
            hash('sha256', implode("\n", $expected)),
            hash('sha256', implode("\n", $actual)),
            'the staged application tree is not the repository tree: a file is missing, extra, or different'
        );

        $this->assertGreaterThan(3000, count($actual), 'the manifest found far too few files to be the application');
    }

    /**
     * The failure this replaces was a whole-tree copy, which would have carried the
     * checkout's own state into the image. So the sweep is not only over the
     * allow-list: everything the repository holds and the image must not is
     * asserted present in the checkout and absent from the overlay.
     */
    public function testTheOverlayCarriesNoDevelopmentState(): void
    {
        $overlay = $this->stage();

        foreach (self::NEVER_SHIPPED as $entry) {
            $this->assertTrue(
                file_exists($this->path($entry)),
                "this check is vacuous for {$entry}: the repository does not hold it"
            );
            $this->assertFileDoesNotExist(
                $overlay . '/' . $entry,
                "{$entry} reached the appliance overlay"
            );
        }

        // writable/ is state, not code: the directories the application expects are
        // staged empty, so a development database, a captured session or an
        // exported backup cannot ride along.
        foreach (self::STATE_DIRS as $state) {
            $this->assertDirectoryExists($overlay . '/writable/' . $state, "the appliance needs writable/{$state}");
            $this->assertSame([], $this->relativeFiles($overlay . '/writable/' . $state), "writable/{$state} must be staged empty");
        }

        $this->assertSame(
            [],
            $this->matching($overlay, ['__pycache__', '*.pyc']),
            'byte-compiled Python from a development run is not part of the application'
        );
    }

    /**
     * The overlay is generated output, so a file left in it by an earlier build (or
     * by the hand copy this replaces) must not survive the next run. A merge would
     * let a stale application live on inside a freshly built image.
     */
    public function testStagingReplacesTheApplicationTreeRatherThanMergingIntoIt(): void
    {
        $out      = $this->tmp . '/includes.chroot';
        $appRoot  = $out . self::APP_ROOT;
        $decoy    = $appRoot . '/.env';
        $devDb    = $appRoot . '/writable/micron_config.db';
        $staleFile = $appRoot . '/app/Controllers/DeletedLastRelease.php';

        foreach ([$decoy, $devDb, $staleFile] as $path) {
            $this->makeDirFor($path);
            file_put_contents($path, 'stale');
            $this->assertFileExists($path);
        }

        $result = $this->execute([$this->bashBinary(), $this->bashPath($this->path(self::STAGE)), '--out', $this->bashPath($out)]);
        $this->assertSame(0, $result['status'], "stage-overlay.sh failed:\n" . $result['output']);

        foreach ([$decoy, $devDb, $staleFile] as $path) {
            $this->assertFileDoesNotExist($path, 'a stale file survived staging');
        }

        $this->assertFileExists($appRoot . '/spark', 'the staged application is missing after the replacement');
    }

    /**
     * Presence is not enough: the staged tree has to be an application, not a pile
     * of files. The appliance's own CI4 console is booted out of the overlay, which
     * exercises the front controller's path resolution, the framework bootstrap and
     * the autoloader together.
     */
    public function testTheStagedApplicationBoots(): void
    {
        $overlay = $this->stage();

        foreach ([
            'public/index.php',
            'app/Config/Paths.php',
            'vendor/autoload.php',
            'vendor/codeigniter4/framework/system/Boot.php',
            'spark',
            'bin/microctl',
            'system/micrond/micrond.py',
            'installer/install.sh',
        ] as $entry) {
            $this->assertFileExists($overlay . '/' . $entry, "the appliance cannot run without {$entry}");
        }

        // NTFS carries no POSIX mode bits, so the execute bit only exists — and is
        // only assertable — on the host that builds and runs the appliance.
        if (DIRECTORY_SEPARATOR !== '\\') {
            foreach (['spark', 'bin/microctl', 'installer/micron-slot', 'installer/micron-healthcheck'] as $entry) {
                $this->assertSame('0755', $this->modeOf($overlay . '/' . $entry), "{$entry} must be staged executable");
            }

            $this->assertSame('0644', $this->modeOf($overlay . '/public/index.php'), 'source files must not be staged executable');
        }

        $result = $this->execute([PHP_BINARY, $overlay . '/spark', 'list']);
        $this->assertSame(0, $result['status'], "the staged application does not boot:\n" . $result['output']);
        $this->assertStringContainsString('CodeIgniter', $result['output'], 'the staged console is not a CodeIgniter application');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Stage into a throwaway overlay and return the staged application root. The
     * overlay is built once per class: staging copies the whole application, and
     * the tests only read it.
     */
    private function stage(): string
    {
        if (self::$staged === null) {
            // Its own directory, not $this->tmp: that one belongs to a single test
            // and is removed when it ends.
            self::$staged = sys_get_temp_dir() . '/micron-app-overlay-' . bin2hex(random_bytes(6));

            $result = $this->execute([$this->bashBinary(), $this->bashPath($this->path(self::STAGE)), '--out', $this->bashPath(self::$staged)]);
            $this->assertSame(0, $result['status'], "stage-overlay.sh failed:\n" . $result['output']);
        }

        return self::$staged . self::APP_ROOT;
    }

    /**
     * A sorted `path md5` manifest of a tree, as paths relative to the tree's
     * parent. Generated output is skipped, since it is deliberately not shipped.
     *
     * @return list<string>
     */
    private function manifest(string $dir, string $prefix): array
    {
        if (is_file($dir)) {
            return [$prefix . ' ' . md5_file($dir)];
        }

        $manifest = [];

        foreach ($this->relativeFiles($dir) as $relative) {
            $manifest[] = $prefix . '/' . $relative . ' ' . md5_file($dir . '/' . $relative);
        }

        return $manifest;
    }

    /**
     * Is this a file the staging step deliberately does not carry?
     */
    private static function isGenerated(string $relative): bool
    {
        return str_contains($relative, '__pycache__') || str_ends_with($relative, '.pyc');
    }

    /** Every file under a directory, sorted, as paths relative to it. @return list<string> */
    private function relativeFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));

            if (! self::isGenerated($relative)) {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Files anywhere under a directory whose path matches one of the patterns.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function matching(string $dir, array $patterns): array
    {
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            foreach ($patterns as $pattern) {
                if (fnmatch('*' . $pattern . '*', $path)) {
                    $found[] = $path;
                }
            }
        }

        return $found;
    }

    private function makeDirFor(string $path): bool
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->fail('Could not create ' . $dir);
        }

        return true;
    }

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
     * An absolute path to a bash that can actually run a script; see
     * TerminalCliOverlayTest for why a bare `bash` will not do on Windows.
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

    private function bashPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @param list<string>               $argv
     * @param array<string, string>|null $env
     *
     * @return array{status: int, output: string}
     */
    private function execute(array $argv, ?array $env = null): array
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

    private static function removeTree(string $path): void
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
