<?php

declare(strict_types=1);

namespace Tests\Packaging;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the wiring that ships the UI's offline assets inside the appliance image.
 *
 * The management UI has to render on an appliance with no route to the internet,
 * so its typefaces and the documentation browser's markdown renderer / syntax
 * highlighter are vendored under public/assets/ rather than fetched from a CDN.
 * live-build copies packaging/live-build/config/includes.chroot/ verbatim onto the
 * appliance root filesystem, so whatever that overlay holds is what the running
 * appliance serves.
 *
 * The application tree is copied into the overlay by the packager, but these two
 * asset trees are staged by packaging/live-build/stage-overlay.sh from the single
 * source of truth (public/assets/). That staging step is the thing that fails
 * *silently*: an image built from it still answers 200 and still draws a page,
 * just in a system typeface with the guides rendered as raw markdown. These tests
 * run the real staging script into a throwaway overlay and check what landed
 * against what the UI actually asks for, so the regression is caught here rather
 * than noticed on a customer's console.
 *
 * The tests touch only temporary directories; nothing under packaging/ is written
 * to.
 *
 * @internal
 */
final class OfflineAssetOverlayTest extends CIUnitTestCase
{
    private const STAGE       = 'packaging/live-build/stage-overlay.sh';
    private const AUTO_CONFIG = 'packaging/live-build/auto/config';
    private const HOOK        = 'packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot';
    private const HTACCESS    = 'public/.htaccess';
    private const ASSETS      = 'public/assets';

    /** The trees the UI keeps offline; the script stages exactly these. */
    private const TREES = ['fonts', 'js', 'vendor'];

    private static ?string $staged = null;

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/micron-assets-' . bin2hex(random_bytes(6));

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
     * The asset trees are sources, not generated output: the packaging step can
     * only stage what the repository actually ships.
     */
    public function testTheOfflineAssetTreesArePresentToStage(): void
    {
        foreach (self::TREES as $tree) {
            $dir = $this->path(self::ASSETS . '/' . $tree);

            $this->assertDirectoryExists($dir, "The UI's {$tree} assets must be vendored in the repository.");
            $this->assertNotEmpty(
                $this->treeFiles($dir),
                "public/assets/{$tree} is empty, so staging it would ship nothing."
            );
        }

        $this->assertFileExists($this->path(self::ASSETS . '/fonts/fonts.css'), 'The @font-face declarations are what the staged woff2 files are for.');
    }

    /**
     * Three files name the same two trees — the repository's assets, the staging
     * list in stage-overlay.sh, and the immutable-cache scope in public/.htaccess.
     * If they ever disagree, either an offline asset is not shipped or a shipped
     * one is not cached, and both are silent on the appliance.
     */
    public function testTheStagedTreesMatchTheShippedTreesAndTheCacheScope(): void
    {
        $shipped = array_values(array_filter(
            scandir($this->path(self::ASSETS)) ?: [],
            fn (string $entry): bool => ! str_starts_with($entry, '.') && is_dir($this->path(self::ASSETS . '/' . $entry))
        ));
        sort($shipped);

        $this->assertSame(self::TREES, $shipped, 'public/assets/ holds a tree the offline set does not account for.');

        // The trees reach the image as part of public/, so what has to agree here
        // is the shipped set and the cache scope. That the whole application is
        // staged (and not a hand-picked list) is WebAppOverlayTest's subject.
        $script = (string) file_get_contents($this->path(self::STAGE));
        $this->assertStringContainsString(
            "\n    public\n",
            $script,
            'stage-overlay.sh must ship public/ wholesale, which is what carries the offline assets.'
        );

        $htaccess = (string) file_get_contents($this->path(self::HTACCESS));
        $this->assertSame(
            1,
            preg_match('#SetEnvIf Request_URI "/assets/\(([a-z|]+)\)/"#', $htaccess, $cached),
            'public/.htaccess must scope the immutable header to the versioned asset trees.'
        );
        $this->assertSame(
            implode('|', self::TREES),
            $cached[1],
            'The @import/preload strategy and the cache scope must cover the same trees.'
        );
    }

    /**
     * Run the real staging script into a throwaway overlay root and check that
     * every vendored file lands, byte-for-byte.
     *
     * Identity matters more than existence: a truncated or stale copy is exactly
     * the drift the staging step exists to remove, and a font that is 200 bytes
     * short still has a valid woff2 magic number.
     */
    public function testStagingCopiesEveryOfflineAssetIntoTheApplianceOverlay(): void
    {
        $overlay = $this->stage();

        $checked = 0;

        foreach (self::TREES as $tree) {
            $source = $this->path(self::ASSETS . '/' . $tree);
            $target = $overlay . '/assets/' . $tree;

            $this->assertDirectoryExists($target, "public/assets/{$tree} did not reach the appliance overlay.");

            $expected = $this->relativeFiles($source);
            $actual   = $this->relativeFiles($target);

            $this->assertSame(
                $expected,
                $actual,
                "The staged {$tree} tree is not the vendored tree: files are missing or extra."
            );

            foreach ($expected as $relative) {
                $this->assertSame(
                    md5_file($source . '/' . $relative),
                    md5_file($target . '/' . $relative),
                    "public/assets/{$tree}/{$relative} must be staged byte-for-byte."
                );
                $checked++;

                // NTFS carries no POSIX mode bits, so PHP reports 0666 for
                // everything on this host; modes are asserted where they exist.
                if (DIRECTORY_SEPARATOR !== '\\') {
                    $this->assertSame('0644', $this->modeOf($target . '/' . $relative), "{$relative} must be staged readable, not executable.");
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No offline asset was staged at all.');
    }

    /**
     * The real guarantee: every asset the UI *asks for* is in the image. The
     * sweep is driven by the markup and the stylesheets rather than by a list
     * here, so a new preload target, a new vendored library or a renamed subset
     * has to be staged the day it is referenced.
     */
    public function testEveryAssetTheUiReferencesReachesTheOverlay(): void
    {
        $overlay = $this->stage();
        $refs    = [];

        foreach (array_merge($this->views(), [self::ASSETS . '/fonts/fonts.css', 'public/css/micronos.css']) as $file) {
            $contents = (string) file_get_contents($this->path($file));

            // Views address the assets as `assets/fonts/...`, `assets/js/...` or
            // `assets/vendor/...`.
            preg_match_all(
                '#assets/(fonts|js|vendor)/([A-Za-z0-9][A-Za-z0-9._-]*\.(?:woff2|js|css))#',
                $contents,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $refs[$match[1] . '/' . $match[2]] = true;
            }

            // fonts.css addresses its woff2 files relative to itself, e.g.
            // `url(inter-latin.woff2?v=1)`, so the family prefix is implicit.
            if (str_ends_with($file, 'fonts.css')) {
                preg_match_all('#url\(([a-z0-9-]+\.woff2)(?:\?v=[A-Za-z0-9._-]+)?\)#', $contents, $faces);

                foreach ($faces[1] as $face) {
                    $refs['fonts/' . $face] = true;
                }
            }
        }

        $this->assertGreaterThan(15, count($refs), 'The reference sweep found far too few assets to be real.');

        foreach (array_keys($refs) as $relative) {
            $this->assertFileExists(
                $overlay . '/assets/' . $relative,
                "The UI requests assets/{$relative}, but it does not reach the appliance overlay."
            );
        }
    }

    /**
     * Staging the assets is only useful where the appliance's web server looks
     * for them. The provisioning hook is what the image actually runs, so the
     * overlay path and its DocumentRoot are asserted against each other rather
     * than each against a literal.
     */
    public function testTheStagedAssetsLandUnderTheApplianceDocumentRoot(): void
    {
        $hook = (string) file_get_contents($this->path(self::HOOK));

        $this->assertSame(
            1,
            preg_match('#^\s*DocumentRoot\s+(\S+)#m', $hook, $documentRoot),
            'The provisioning hook must declare the web root the appliance serves.'
        );

        $webRoot = $documentRoot[1];
        $script  = (string) file_get_contents($this->path(self::STAGE));
        $appRoot = '/usr/local/micron';

        $this->assertStringContainsString(
            'app_root="${out}' . $appRoot . '"',
            $script,
            'stage-overlay.sh must stage the application at the root the provisioning hook serves.'
        );
        $this->assertSame(
            $appRoot . '/public',
            $webRoot,
            "the hook's DocumentRoot must be the public/ directory of the staged application"
        );

        $overlay = $this->stage();

        $this->assertFileExists(
            $overlay . '/assets/fonts/inter-latin.woff2',
            'The primary font subset must land inside the DocumentRoot the appliance serves.'
        );
    }

    /**
     * auto/config is the one script live-build always executes first, so the
     * staging (and therefore the assets) has to happen there — and the generated
     * overlay has to stay out of version control, or it becomes a second copy
     * that can disagree with public/assets/.
     */
    public function testTheBuildStagesTheAssetsWithoutBeingAskedTo(): void
    {
        $autoConfig = (string) file_get_contents($this->path(self::AUTO_CONFIG));

        $this->assertStringContainsString('stage-overlay.sh', $autoConfig);
        $this->assertLessThan(
            strpos($autoConfig, "\nlb config"),
            strpos($autoConfig, 'stage-overlay.sh'),
            'The offline assets must be staged before lb config snapshots the configuration tree.'
        );

        $script = (string) file_get_contents($this->path(self::STAGE));
        $this->assertStringContainsString(
            'out="${here}/config/includes.chroot"',
            $script,
            'The default overlay root must be the directory live-build copies onto the image.'
        );

        $gitignore = (string) file_get_contents($this->path('.gitignore'));
        $this->assertStringContainsString(
            'packaging/live-build/config/includes.chroot',
            $gitignore,
            'The generated overlay must stay out of version control; its source of truth is public/assets/.'
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Stage into a throwaway overlay and return the path the assets land under,
     * which is derived from the hook so this helper cannot mask a mismatch.
     */
    private function stage(): string
    {
        if (self::$staged === null) {
            // Its own directory, not $this->tmp: that one belongs to a single test
            // and is removed when it ends.
            self::$staged = sys_get_temp_dir() . '/micron-assets-overlay-' . bin2hex(random_bytes(6));

            $result = $this->bash([$this->bashBinary(), $this->bashPath($this->path(self::STAGE)), '--out', $this->bashPath(self::$staged)]);
            $this->assertSame(0, $result['status'], "stage-overlay.sh failed:\n" . $result['output']);
        }

        $hook = (string) file_get_contents($this->path(self::HOOK));
        preg_match('#^\s*DocumentRoot\s+(\S+)#m', $hook, $documentRoot);

        return self::$staged . ($documentRoot[1] ?? '');
    }

    /** @return list<string> */
    private function views(): array
    {
        $views = [];

        foreach (glob(ROOTPATH . 'app/Views/**/*.php') ?: [] as $view) {
            $views[] = str_replace(ROOTPATH, '', $view);
        }

        $this->assertGreaterThan(50, count($views), 'The view sweep found far too few templates to be real.');

        return $views;
    }

    /** Every file under a directory, as sorted paths relative to it. @return list<string> */
    private function relativeFiles(string $dir): array
    {
        $files = [];

        foreach ($this->treeFiles($dir) as $file) {
            $files[] = str_replace('\\', '/', substr($file, strlen($dir) + 1));
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function treeFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The POSIX mode bits as a four-digit string, e.g. `0644`. Only meaningful
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
     * escape hatch for an unusual layout.
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
     * @param list<string>               $argv
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
