<?php

namespace Tests\App\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The management UI has to work on an appliance with no route to the internet.
 *
 * The stylesheets used to pull their typefaces from `fonts.googleapis.com` with
 * an `@import`, and the documentation page pulled its markdown renderer and
 * syntax highlighter from public CDNs. Both failed *quietly*: the page still
 * looked loaded, the text simply fell back to a system font and the guides came
 * out as raw markdown. These tests keep the assets where they can be served from
 * this appliance.
 *
 * @internal
 */
final class OfflineAssetsTest extends CIUnitTestCase
{
    /** Stylesheets the UI ships, relative to the web root. */
    private const STYLESHEETS = ['css/micronos.css', 'assets/fonts/fonts.css'];

    /**
     * The preload tag every design-system view must carry, capturing the font
     * set's version token so it can be checked against `fonts.css`.
     */
    private const FONT_PRELOAD = '#<link rel="preload" href="<\?= base_url\(\'assets/fonts/inter-latin\.woff2\'\) \?>(\?v=[A-Za-z0-9._-]+)" as="font" type="font/woff2" crossorigin>#';

    /** Hosts that must never be referenced by a stylesheet or a view again. */
    private const RETIRED_HOSTS = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'unpkg.com',
    ];

    public function testNoStylesheetFetchesAnythingRemotely(): void
    {
        $checked = 0;

        foreach ($this->stylesheets() as $relative => $css) {
            // Comments are provenance, not traffic: `fonts.css` records the exact
            // Google Fonts request it was generated from, and `micronos.css`
            // names the host it no longer depends on. Only declarations are
            // checked, so a future maintainer can still explain the history.
            $declarations = (string) preg_replace('#/\*.*?\*/#s', '', $css);

            $this->assertSame(
                0,
                preg_match('/url\(\s*[\'"]?\s*(?:https?:)?\/\//i', $declarations),
                "{$relative} must not fetch a font, image or stylesheet over the network"
            );

            foreach (self::RETIRED_HOSTS as $host) {
                $this->assertStringNotContainsString(
                    $host,
                    $declarations,
                    "{$relative} must not reference {$host}"
                );
            }

            $checked++;
        }

        $this->assertSame(count(self::STYLESHEETS), $checked, 'the stylesheet sweep found nothing');
    }

    public function testNoViewReferencesARetiredAssetHost(): void
    {
        $checked = 0;

        foreach (glob(ROOTPATH . 'app/Views/**/*.php') ?: [] as $view) {
            $contents = (string) file_get_contents($view);
            $relative = str_replace(ROOTPATH, '', $view);

            foreach (self::RETIRED_HOSTS as $host) {
                $this->assertStringNotContainsString(
                    $host,
                    $contents,
                    "{$relative} must not reference {$host}"
                );
            }

            $checked++;
        }

        $this->assertGreaterThan(50, $checked, 'the view sweep found far too few templates to be real');
    }

    /**
     * The named-host list above is a tripwire; this is the actual guarantee. A
     * page that draws itself has to load its scripts, styles and images from
     * this appliance, whatever host a future edit reaches for.
     */
    public function testNoViewLoadsAResourceFromOutsideTheAppliance(): void
    {
        $checked = 0;

        foreach (glob(ROOTPATH . 'app/Views/**/*.php') ?: [] as $view) {
            $contents = (string) file_get_contents($view);
            $relative = str_replace(ROOTPATH, '', $view);

            // Render dependencies only: an `<a href>` pointing at a vendor's
            // download page is navigation an operator chooses, not something the
            // page fetches in order to draw itself.
            preg_match_all('#<(?:script|link|img)\b[^>]*\s(?:src|href)\s*=\s*"([^"]*)"#i', $contents, $refs);

            foreach ($refs[1] as $ref) {
                $this->assertSame(
                    0,
                    preg_match('#^(?:https?:)?//#i', $ref),
                    "{$relative} loads {$ref} from outside the appliance"
                );
            }

            $checked++;
        }

        $this->assertGreaterThan(50, $checked, 'the view sweep found far too few templates to be real');
    }

    public function testTheDesignSystemImportsTheLocalFonts(): void
    {
        $css = (string) file_get_contents(FCPATH . 'css/micronos.css');

        // Loading it from the design system (rather than per view) is what gets
        // the typeface onto the standalone sign-in and 2FA screens too. The
        // font set's version token rides along on the URL; that the token is
        // the right one is asserted against fonts.css below.
        $this->assertSame(
            1,
            preg_match("#@import url\('\.\./assets/fonts/fonts\.css\?v=[A-Za-z0-9._-]+'\);#", $css),
            'the design system must import the self-hosted, versioned fonts'
        );
        $this->assertStringNotContainsString('@import url(\'http', $css, 'no remote stylesheet import');
    }

    public function testEveryFontFacePointsAtARealWoff2(): void
    {
        $css = (string) file_get_contents(FCPATH . 'assets/fonts/fonts.css');

        $this->assertSame(
            13,
            preg_match_all('/@font-face\s*\{/', $css),
            'Inter (7 subsets) and JetBrains Mono (6) are expected, as variable builds'
        );

        // Both families, both weight axes: a subset that quietly lost its weight
        // range would render some UI text in a synthesized bold.
        $this->assertSame(7, preg_match_all("/font-family:\s*'Inter'/", $css));
        $this->assertSame(6, preg_match_all("/font-family:\s*'JetBrains Mono'/", $css));
        $this->assertSame(7, preg_match_all('/font-weight:\s*300 800;/', $css));
        $this->assertSame(6, preg_match_all('/font-weight:\s*400 500;/', $css));
        $this->assertSame(13, preg_match_all('/font-display:\s*swap;/', $css), 'text must never be blocked on a font');

        $this->assertSame(13, preg_match_all('#url\(([a-z0-9-]+\.woff2)(?:\?v=[A-Za-z0-9._-]+)?\)#', $css, $sources));

        foreach (array_unique($sources[1]) as $file) {
            $path = FCPATH . 'assets/fonts/' . $file;

            $this->assertFileExists($path, "{$file} is referenced by fonts.css but not vendored");
            $this->assertGreaterThan(0, (int) filesize($path), "{$file} is empty");
            $this->assertSame(
                'wOF2',
                (string) file_get_contents($path, false, null, 0, 4),
                "{$file} is not a woff2 file"
            );
        }
    }

    /**
     * The primary subset is the one every page renders, so it is requested from
     * the markup rather than left to the stylesheet chain: without the preload
     * the font request only begins once `micronos.css` has been fetched and
     * parsed and its `fonts.css` `@import` fetched in turn, and the first paint
     * happens in the fallback face.
     *
     * `crossorigin` is not decoration — a font is always fetched in CORS mode,
     * even same-origin, so a preload without it is discarded and the file is
     * downloaded a second time.
     */
    public function testEveryViewThatLoadsTheDesignSystemPreloadsThePrimarySubset(): void
    {
        $views = $this->designSystemViews();

        $this->assertSame(4, count($views), 'the layout, the two standalone auth screens, and the standalone ztna viewer load the design system');

        foreach ($views as $relative => $contents) {
            $this->assertSame(
                1,
                preg_match(self::FONT_PRELOAD, $contents),
                "{$relative} must preload the primary font subset as a font, with crossorigin"
            );

            // A preload placed after the stylesheet still helps, but the whole
            // point is to start the download as early as the markup allows.
            $this->assertLessThan(
                strpos($contents, 'rel="stylesheet"'),
                strpos($contents, 'rel="preload"'),
                "{$relative} must preload the font ahead of its stylesheet"
            );
        }
    }

    /**
     * `/assets/fonts/` is served `immutable` (public/.htaccess, and the nginx
     * server block the appliance ships — see §6.1 of the ISO guide), which is
     * only safe while the URL changes whenever the bytes do. The font set's
     * version token therefore has to be identical in every place it is written:
     * the 13 `@font-face` sources, the design system's `@import`, and the
     * preloads. It also has to match *exactly* — token included — because a
     * preload that names a different URL than the `@font-face` rule makes the
     * browser fetch the same font twice instead of reusing it.
     */
    public function testTheFontVersionTokenAgreesAcrossTheStylesheetsAndViews(): void
    {
        $fonts = (string) file_get_contents(FCPATH . 'assets/fonts/fonts.css');

        $this->assertSame(
            13,
            preg_match_all('#url\([a-z0-9-]+\.woff2(\?v=[A-Za-z0-9._-]+)\)#', $fonts, $sources),
            'every @font-face source must carry the font set version token'
        );
        $this->assertCount(1, array_unique($sources[1]), 'the @font-face sources disagree on the version token');

        $token = $sources[1][0];

        $micronos = (string) file_get_contents(FCPATH . 'css/micronos.css');

        $this->assertSame(
            1,
            preg_match("#@import url\('\.\./assets/fonts/fonts\.css(\?v=[A-Za-z0-9._-]+)'\);#", $micronos, $import),
            'the design system must import the versioned fonts.css'
        );
        $this->assertSame($token, $import[1], 'the design system imports a different font version than the @font-face sources');

        foreach ($this->designSystemViews() as $relative => $contents) {
            preg_match(self::FONT_PRELOAD, $contents, $preload);

            $this->assertSame(
                $token,
                $preload[1] ?? '',
                "{$relative} preloads a different font version than fonts.css"
            );
        }
    }

    /**
     * The typefaces, the vendored libraries and the shared scheduler script are
     * the assets under public/ the browser should never revalidate: all three
     * trees are URL-versioned, so a replaced file is a new URL. Everything else
     * keeps the default policy, which is why the rule is scoped by path and not
     * by file extension.
     */
    public function testImmutableCachingIsScopedToTheVersionedOfflineAssets(): void
    {
        $htaccess = (string) file_get_contents(FCPATH . '.htaccess');

        $this->assertStringContainsString(
            'SetEnvIf Request_URI "/assets/(fonts|js|vendor)/" micron_immutable_asset',
            $htaccess,
            'the immutable rule must name the versioned asset trees'
        );
        $this->assertStringContainsString(
            'Header set Cache-Control "public, max-age=31536000, immutable" env=micron_immutable_asset',
            $htaccess,
            'the versioned offline assets must be served immutable'
        );

        // A blanket rule would pin an unversioned file for a year, and no
        // redeploy could correct the copies already out there.
        $this->assertSame(1, preg_match_all('/Header set Cache-Control/', $htaccess));

        // Both modules are optional on the fleet, so the directives are guarded
        // and a server without them still serves the UI.
        $this->assertStringContainsString('<IfModule mod_setenvif.c>', $htaccess);
        $this->assertStringContainsString('<IfModule mod_headers.c>', $htaccess);
    }

    /**
     * The appliance itself runs nginx (§6.1 of the ISO guide); public/.htaccess
     * only configures the development server, and the live-build overlay ships
     * no nginx config of its own. So the header has to be documented there too,
     * or the caching policy silently disappears on the real appliance.
     */
    public function testTheShippedNginxBlockServesTheSameImmutableHeader(): void
    {
        $guide = (string) file_get_contents(ROOTPATH . 'docs/ISO_CREATION_AND_INSTALLER_GUIDE.md');

        $this->assertSame(
            3,
            preg_match_all(
                '#location \^~ /assets/(?:fonts|js|vendor)/ \{\s*access_log off;\s*add_header Cache-Control "public, max-age=31536000, immutable";\s*\}#',
                $guide
            ),
            'the ISO guide must document the immutable header for every offline asset tree'
        );
    }

    /**
     * A versioned asset is one the app addresses with a content token (`?v=...`),
     * which is the promise that a replaced file becomes a new URL. That promise
     * only buys anything if the server also caches the URL and never revalidates
     * it: on an unversioned policy every load still pays the round trip, and the
     * token just makes the URL look cacheable.
     *
     * So the two sets have to coincide exactly. Every asset the app requests with
     * a token must be served `immutable` by both servers the appliance ships, and
     * every path either of them pins for a year must actually be versioned
     * somewhere — that second direction is the dangerous one, because an
     * unversioned file under an immutable rule is pinned for a year and no
     * redeploy can correct the copies already out there.
     */
    public function testEveryVersionedAssetUnderPublicIsServedImmutable(): void
    {
        $versioned = $this->versionedAssetPaths();

        $this->assertGreaterThan(
            15,
            count($versioned),
            'the versioned-asset sweep found far too few files to be real'
        );

        $htaccess = (string) file_get_contents(FCPATH . '.htaccess');
        $this->assertNotEmpty(
            preg_match_all('#SetEnvIf Request_URI "([^"]+)" micron_immutable_asset#', $htaccess, $rules) ? $rules[1] : [],
            'public/.htaccess must declare the immutable-cache scope'
        );

        $guide     = (string) file_get_contents(ROOTPATH . 'docs/ISO_CREATION_AND_INSTALLER_GUIDE.md');
        $locations = $this->nginxImmutableLocations($guide);
        $this->assertNotEmpty($locations, 'the ISO guide must document the immutable nginx locations');

        foreach ($versioned as $asset) {
            $this->assertTrue(
                $this->servedByHtaccess($rules[1], $asset),
                "public/{$asset} is requested with a version token but public/.htaccess does not serve it immutable"
            );
            $this->assertTrue(
                $this->servedByNginx($locations, $asset),
                "public/{$asset} is requested with a version token but the nginx block the appliance ships does not serve it immutable"
            );
        }

        foreach ($rules[1] as $rule) {
            $this->assertNotEmpty(
                array_filter(
                    $versioned,
                    static fn (string $asset): bool => preg_match('#' . $rule . '#', '/' . $asset) === 1
                ),
                "the immutable rule for {$rule} covers no versioned asset, so it would pin an unversioned file for a year"
            );
        }
    }

    /**
     * The documentation page is the only place the vendored libraries are loaded,
     * and its two script URLs are what the immutable rule on /assets/vendor/ is
     * for. Each URL has to carry a token derived from that same file: a bare URL
     * would be pinned at its first download for a year, so a library upgrade
     * would never reach a browser that had already seen the old one.
     */
    public function testTheDocsPageVersionsItsVendoredLibraryUrls(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/docs/page.php');

        foreach (['marked.min.js', 'highlight.min.js'] as $file) {
            $quoted = preg_quote($file, '#');

            $this->assertSame(
                1,
                preg_match(
                    '#base_url\(\s*\'assets/vendor/' . $quoted . '\'\s*\)\s*\?>\s*\?v=<\?=\s*@filemtime\(FCPATH \. \'assets/vendor/' . $quoted . '\'\)#',
                    $view
                ),
                "the guides must load assets/vendor/{$file} with a version token taken from that same file"
            );
        }

        // …and no vendored library may be loaded without one, whatever its name.
        preg_match_all('#base_url\(\s*\'assets/vendor/([^\']+)\'\s*\)\s*\?>([^"]*)#', $view, $refs, PREG_SET_ORDER);
        $this->assertNotEmpty($refs, 'the docs page must load its renderer and highlighter from this appliance');

        foreach ($refs as $ref) {
            $this->assertStringStartsWith(
                '?v=',
                ltrim($ref[2]),
                "assets/vendor/{$ref[1]} is loaded without a version token"
            );
        }
    }

    /**
     * Views that load the shared design system, keyed by path under the project
     * root. Derived from the stylesheet reference itself rather than listed, so
     * a new standalone screen that cannot inherit the layout is covered (and
     * forced to preload the font) the day it lands.
     *
     * @return array<string, string>
     */
    private function designSystemViews(): array
    {
        $views = [];

        foreach (glob(ROOTPATH . 'app/Views/**/*.php') ?: [] as $view) {
            $contents = (string) file_get_contents($view);

            // Matched on the load itself, not on the filename: views mention
            // `public/css/micronos.css` in prose when they explain which rules
            // they re-declare, and a mention is not a page that draws the UI.
            if (preg_match('#base_url\(\s*[\'"]css/micronos\.css[\'"]\s*\)#', $contents)) {
                $views[str_replace(ROOTPATH, '', $view)] = $contents;
            }
        }

        return $views;
    }

    /**
     * The shipped stylesheets, keyed by their path under the web root.
     *
     * @return array<string, string>
     */
    private function stylesheets(): array
    {
        $sheets = [];

        foreach (self::STYLESHEETS as $relative) {
            $path = FCPATH . $relative;

            $this->assertFileExists($path, "{$relative} must exist");
            $sheets[$relative] = (string) file_get_contents($path);
        }

        return $sheets;
    }

    /**
     * Every asset under public/ the app addresses with a version token, as paths
     * relative to the web root. Driven by the sources rather than by a list, so a
     * new preload, `@import` or vendored library has to be accounted for.
     *
     * @return list<string>
     */
    private function versionedAssetPaths(): array
    {
        $assets = [];

        foreach (glob(ROOTPATH . 'app/Views/**/*.php') ?: [] as $view) {
            $contents = (string) file_get_contents($view);

            // A view emits a tokenised URL as base_url('…') followed by `?v=…`.
            preg_match_all('#base_url\(\s*\'([^\']+)\'\s*\)\s*\?>\s*\?v=#', $contents, $refs);

            foreach ($refs[1] as $path) {
                $assets[$path] = true;
            }
        }

        foreach ($this->stylesheets() as $relative => $css) {
            // `url(…?v=…)`, which may be relative to the stylesheet itself.
            preg_match_all('#url\(\s*[\'"]?([^\'")\s?]+)\?v=[A-Za-z0-9._-]+[\'"]?\s*\)#', $css, $refs);

            foreach ($refs[1] as $href) {
                $assets[$this->resolveAgainst($relative, $href)] = true;
            }
        }

        ksort($assets);

        return array_keys($assets);
    }

    /** Resolve a stylesheet-relative URL to a path under the web root. */
    private function resolveAgainst(string $stylesheet, string $href): string
    {
        $dir  = dirname($stylesheet);
        $path = $dir === '.' ? $href : $dir . '/' . $href;
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * Does any `SetEnvIf` scope in public/.htaccess match this asset path? The
     * scope is written as a regex in the config, so it is matched as one here.
     *
     * @param list<string> $rules
     */
    private function servedByHtaccess(array $rules, string $asset): bool
    {
        foreach ($rules as $rule) {
            if (preg_match('#' . $rule . '#', '/' . $asset) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The locations in the shipped nginx block that pin their contents for a year,
     * as modifier + pattern pairs.
     *
     * @return list<array{modifier: string, pattern: string}>
     */
    private function nginxImmutableLocations(string $guide): array
    {
        preg_match_all('#location\s+(=)?\s*(\^~)?\s*(/\S+)\s*\{([^}]*)\}#', $guide, $blocks, PREG_SET_ORDER);

        $locations = [];

        foreach ($blocks as $block) {
            if (str_contains($block[4], 'public, max-age=31536000, immutable')) {
                $locations[] = ['modifier' => $block[1] . $block[2], 'pattern' => $block[3]];
            }
        }

        return $locations;
    }

    /**
     * Would the shipped nginx block serve this asset immutably? An exact match is
     * compared to the whole path (nginx never sees the query string); a prefix
     * match covers the tree.
     *
     * @param list<array{modifier: string, pattern: string}> $locations
     */
    private function servedByNginx(array $locations, string $asset): bool
    {
        foreach ($locations as $location) {
            if ($location['modifier'] === '=' && ltrim($location['pattern'], '/') === $asset) {
                return true;
            }

            if (str_starts_with($asset, rtrim(ltrim($location['pattern'], '/'), '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
