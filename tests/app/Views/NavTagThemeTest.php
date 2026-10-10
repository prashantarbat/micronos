<?php

namespace Tests\App\Views;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Appearance;

/**
 * The rail's status tags — Live, States, Daemons, v2/v3 and their like.
 *
 * Those labels used to carry their colours as inline styles written against the
 * dark rail: pale text (#86efac, #67e8f9, #c4b5fd …) over a translucent tint of
 * its own hue. On a light theme that pair collapses — a quarter-strength tint of
 * a mid-tone is all but white on a white rail, and the pastel text is a shade or
 * two away from it — which is why "Live" and "Daemons" could not be read once
 * the operator picked a light theme.
 *
 * The colours live in the stylesheet now, one dark pair and one light pair per
 * tone. Two things make that work and neither is visible from the other half:
 * the markup has to stop carrying colours of its own (any inline pair beats the
 * classes), and the pair actually in force — the tint composited over the
 * theme's own rail colour, since these tints are translucent — has to be legible
 * on every theme, not just the one that was looked at.
 *
 * @internal
 */
final class NavTagThemeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** The tones the rail uses. Each has to exist in both palettes. */
    private const TONES = ['emerald', 'cyan', 'purple', 'amber', 'red'];

    /** WCAG AA for the 0.6rem text these tags are drawn at. */
    private const MIN_CONTRAST = 4.5;

    /**
     * What each label is tinted as. Pinned because the tone is what carries the
     * meaning the label itself does not spell out — a red tag and a cyan one are
     * the same rectangle of text.
     */
    private const TONE_OF = [
        'Live'      => 'emerald',
        'Daemons'   => 'emerald',
        'Portal'    => 'emerald',
        'States'    => 'cyan',
        'v2/v3'     => 'cyan',
        'Update'    => 'cyan',
        'Guides'    => 'cyan',
        'Updates'   => 'cyan',
        'Signed'    => 'cyan',
        'IPTV'      => 'cyan',
        'DNAT/SNAT' => 'cyan',
        'CAKE'      => 'cyan',
        'WPAD'      => 'cyan',
        'SOC'       => 'cyan',
        'Tasks'     => 'purple',
        'FRR'       => 'purple',
        'X.509'     => 'purple',
        'Extend'    => 'amber',
        'VRRP'      => 'amber',
        'DDoS'      => 'red',
    ];

    /** Wide enough that no route's permission gate hides part of the nav. */
    private const PERMISSIONS = [
        'dashboard.view', 'dns.view', 'interfaces.view', 'firewall.rules.view',
        'vpn.view', 'plugins.view', 'diag.view', 'users.view', 'cron.view', 'system.view',
    ];

    /**
     * The bug itself: a tag may not carry a colour of its own.
     *
     * An inline `background`/`color` outranks the stylesheet, so a single tag
     * left with one would keep the dark-only pair it was written with — and it
     * would be the invisible one on a light theme. The labels are read back from
     * a rendered page, so this is the markup the browser is handed.
     */
    public function testNoNavLabelCarriesItsColourInline(): void
    {
        $body = $this->page('docs');

        preg_match_all(
            '/<(?:span|a)\b[^>]*style="[^"]*(?:background|color)\s*:[^"]*"/i',
            $body,
            $inline
        );

        $this->assertSame(
            [],
            $inline[0],
            'a nav label must be coloured by the stylesheet; an inline pair outranks the classes '
            . 'and would keep the dark-only colours it was written with'
        );
    }

    /**
     * Every tag names a tone, and the tone it names is the one it is tinted as.
     */
    public function testEachTagNamesTheToneItIsDrawnIn(): void
    {
        $body  = $this->page('docs');
        $tones = $this->tonesInMarkup($body);

        $this->assertNotEmpty($tones, 'the rail carries tags; none were found in the rendered page');

        foreach (self::TONE_OF as $label => $tone) {
            $this->assertArrayHasKey(
                $label,
                $tones,
                "the \"{$label}\" tag has to render with a tone class, or it falls back to the rail's own colours"
            );
            $this->assertSame(
                $tone,
                $tones[$label],
                "the \"{$label}\" tag is tinted {$tones[$label]} but reads as {$tone}"
            );
        }

        // The rail's plugin rows are drawn from whatever the plugin registry
        // reports, so no page has one unless a plugin is installed. The row is
        // read from the template instead — it still has to carry a tone.
        $this->assertStringContainsString(
            '<span class="nav-tag nav-tag-amber">Plugin</span>',
            (string) file_get_contents(APPPATH . 'Views/layouts/main.php'),
            'a plugin menu row has to be tinted like every other tag'
        );
    }

    /**
     * The dropdown labels of the topnav layout are the same tone as their rail
     * twins, and the rule that sizes them no longer sets a colour.
     *
     * That last part is the trap: `[data-mode="light"] .nav-tag-cyan` and
     * `.topnav-dropdown-item .nav-badge-sm` are both two-class selectors, so a
     * colour left on the sizing rule would win on order and the dropdown would
     * quietly keep a hardcoded cyan through every theme.
     */
    public function testTheDropdownLabelsShareTheRailTones(): void
    {
        $body = $this->page('docs');

        $this->assertStringContainsString(
            'class="nav-badge-sm nav-tag-emerald">Live</span>',
            $body,
            'a dropdown label and its rail twin have to be the same pair'
        );

        $rule = $this->rule($this->css(), '.topnav-dropdown-item .nav-badge-sm');

        $this->assertStringNotContainsString(
            'color',
            $rule,
            'the sizing rule must not set a colour, or the tone classes lose to it'
        );
        $this->assertStringNotContainsString(
            'background',
            $rule,
            'and it must not set a background either — that belongs to the tone class, in both modes'
        );
    }

    /**
     * The measurement: on all nine themes, every tone's pair clears AA.
     *
     * Each pair is resolved through the theme the way the browser does — the
     * theme's own tokens, then the light-mode set where the theme renders light —
     * and the tint is composited over `--color-surface-1`, which is the rail
     * behind it. A pair that only works on the theme it was picked in is exactly
     * the regression this pins: the tags are read on all of them.
     */
    public function testEveryToneClearsAaOnEveryTheme(): void
    {
        $css       = $this->css();
        $appearance = new Appearance();
        $palette   = $this->palette($css);
        $failures  = [];

        foreach ($appearance->ids() as $theme) {
            $light  = $appearance->isLight($theme);
            $tokens = $this->tokens($css, $theme, $light);

            foreach (self::TONES as $tone) {
                [$background, $foreground] = $palette[$tone][$light ? 'light' : 'dark'];

                $ratio = $this->contrast(
                    $this->colour($this->resolve($foreground, $tokens)),
                    $this->composite(
                        $this->colour($this->resolve($background, $tokens)),
                        $this->colour($this->resolve($tokens['--color-surface-1'] ?? '#ffffff', $tokens))
                    )
                );

                if ($ratio < self::MIN_CONTRAST) {
                    $failures[] = sprintf(
                        '%s/%s: %.2f:1 (%s on the tint over %s)',
                        $theme,
                        $tone,
                        $ratio,
                        $foreground,
                        $tokens['--color-surface-1'] ?? 'unset'
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "a tag has to be readable on every theme it can be shown on:\n  " . implode("\n  ", $failures)
        );
    }

    /**
     * Both palettes define every tone, so adding a tag cannot quietly render in
     * the neutral fallback because a tone was only coloured for one mode.
     */
    public function testBothPalettesDefineEveryTone(): void
    {
        foreach ($this->palette($this->css()) as $tone => $modes) {
            foreach (['dark', 'light'] as $mode) {
                $this->assertArrayHasKey(
                    $mode,
                    $modes,
                    "the {$tone} tone has no {$mode} pair, so a {$mode} theme would fall back to the base tag colour"
                );
            }
        }

        $declared = array_keys($this->palette($this->css()));
        $used     = self::TONES;
        sort($declared);
        sort($used);

        $this->assertSame(
            $used,
            $declared,
            'the palette and the tones the rail uses have to be the same set'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A rendered page, with a session wide enough that the whole rail draws. */
    private function page(string $route): string
    {
        $session = [
            'logged_in'        => true,
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];

        $result = $this->withSession($session)->get($route);
        $result->assertOK();

        return (string) $result->getBody();
    }

    private function css(): string
    {
        return (string) file_get_contents(FCPATH . 'css/micronos.css');
    }

    /**
     * Tag label => tone, as the browser receives it.
     *
     * Both homes are read: the rail's own tags, and the dropdown labels of the
     * topnav layout, which are the same labels one size smaller. A label that
     * appears in both has to keep one tone, and the array says so by collision —
     * a mismatch would land as the dropdown's tone under the rail label.
     *
     * @return array<string, string>
     */
    private function tonesInMarkup(string $body): array
    {
        preg_match_all(
            '/<span class="[^"]*\bnav-tag-(?P<tone>[a-z]+)[^"]*">(?P<label>[^<]+)<\/span>/',
            $body,
            $matches,
            PREG_SET_ORDER
        );

        $tones = [];

        foreach ($matches as $match) {
            $tones[trim($match['label'])] = $match['tone'];
        }

        return $tones;
    }

    /** One rule's declarations, by selector. */
    private function rule(string $css, string $selector): string
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';

        $this->assertSame(1, preg_match($pattern, $css, $m), "the stylesheet must define {$selector}");

        return $m[1];
    }

    /**
     * The tone pairs the stylesheet declares: tone => [mode => [background, foreground]].
     *
     * Read from the file the browser gets, and anchored to the start of a line so
     * the scoped light-mode overrides (`[data-mode="light"] .nav-tag-cyan`) are
     * collected separately rather than doubling as the dark pair.
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    private function palette(string $css): array
    {
        $palette = [];

        foreach ([
            'dark'  => '/^\.nav-tag-([a-z]+)\s*\{([^}]*)\}/m',
            'light' => '/^\[data-mode="light"\]\s+\.nav-tag-([a-z]+)\s*\{([^}]*)\}/m',
        ] as $mode => $pattern) {
            $this->assertSame(5, preg_match_all($pattern, $css, $matches, PREG_SET_ORDER),
                "the {$mode} palette has to colour all five tones");

            foreach ($matches as $match) {
                $body = $match[2];

                $this->assertSame(
                    1,
                    preg_match('/background\s*:\s*([^;]+);/', $body, $bg),
                    "the {$mode} {$match[1]} tag has no background"
                );
                $this->assertSame(
                    1,
                    preg_match('/(?<![-\w])color\s*:\s*([^;]+);/', $body, $fg),
                    "the {$mode} {$match[1]} tag has no foreground"
                );

                $palette[$match[1]][$mode] = [trim($bg[1]), trim($fg[1])];
            }
        }

        // Declaration order, which is the order the tones are written in, so the
        // keys line up with the tones the rail uses without a sort.
        return $palette;
    }

    /**
     * The colour tokens in force on a theme.
     *
     * The three blocks that can carry them are merged in the order the browser
     * resolves them: `:root` first, then the light-mode set for a light theme,
     * then the theme's own block. They are all single attribute/pseudo-class
     * selectors, so the file's order is the cascade's order.
     *
     * @return array<string, string>
     */
    private function tokens(string $css, string $theme, bool $light): array
    {
        $pattern = '/^(:root|\[data-theme="([a-z]+)"\]|\[data-mode="light"\])\s*\{([^}]*)\}/m';

        preg_match_all($pattern, $css, $blocks, PREG_SET_ORDER);

        $this->assertNotEmpty($blocks, 'the stylesheet must declare its palettes in single blocks');

        $tokens = [];

        foreach ($blocks as $block) {
            $selector = $block[1];
            $applies  = $selector === ':root'
                || ($selector === '[data-mode="light"]' && $light)
                || (($block[2] ?? '') === $theme);

            if (! $applies) {
                continue;
            }

            preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $block[3], $declarations, PREG_SET_ORDER);

            $this->assertNotEmpty($declarations, 'each palette block has to declare tokens');

            foreach ($declarations as $declaration) {
                $tokens[$declaration[1]] = trim($declaration[2]);
            }
        }

        $this->assertArrayHasKey('--color-surface-1', $tokens, "the {$theme} theme must define its rail colour");

        return $tokens;
    }

    /**
     * Follow `var(--token)` through the theme's own token set; a literal colour
     * is already final. Both appear in the palette: the light pairs take their
     * tint from a token and their foreground from a measured shade.
     */
    private function resolve(string $value, array $tokens): string
    {
        $value = trim($value);

        if (! str_starts_with($value, 'var(')) {
            return $value;
        }

        $this->assertSame(1, preg_match('/^var\((--[a-z0-9-]+)\)$/', $value, $m),
            "a tag colour has to be a theme token or a literal colour, not: {$value}");

        $this->assertArrayHasKey($m[1], $tokens, "the theme does not define {$m[1]}");

        return trim($tokens[$m[1]]);
    }

    /**
     * A colour as [r, g, b, a], with r/g/b scaled 0-255 and a 0-1.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function colour(string $value): array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
            return [
                hexdec(substr($m[1], 0, 2)) + 0,
                hexdec(substr($m[1], 2, 2)) + 0,
                hexdec(substr($m[1], 4, 2)) + 0,
                1.0,
            ];
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $m)) {
            return [
                hexdec(str_repeat($m[1][0], 2)) + 0,
                hexdec(str_repeat($m[1][1], 2)) + 0,
                hexdec(str_repeat($m[1][2], 2)) + 0,
                1.0,
            ];
        }

        if (preg_match('/^rgba?\(([^)]+)\)$/', $value, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));

            $this->assertGreaterThanOrEqual(3, count($parts), "unreadable colour: {$value}");

            return [
                (float) $parts[0],
                (float) $parts[1],
                (float) $parts[2],
                isset($parts[3]) ? (float) $parts[3] : 1.0,
            ];
        }

        $this->fail("unreadable colour: {$value}");
    }

    /**
     * A translucent colour drawn over an opaque one, as the rail does with its
     * tints — the contrast that matters is against what ends up on screen, not
     * against the tint on its own.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $top
     * @param array{0: float, 1: float, 2: float, 3: float} $under
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function composite(array $top, array $under): array
    {
        $alpha = $top[3] + $under[3] * (1 - $top[3]);

        if ($alpha === 0.0) {
            return [255.0, 255.0, 255.0, 1.0];
        }

        return [
            ($top[0] * $top[3] + $under[0] * $under[3] * (1 - $top[3])) / $alpha,
            ($top[1] * $top[3] + $under[1] * $under[3] * (1 - $top[3])) / $alpha,
            ($top[2] * $top[3] + $under[2] * $under[3] * (1 - $top[3])) / $alpha,
            $alpha,
        ];
    }

    /** WCAG 2.1 contrast ratio between two opaque colours. */
    private function contrast(array $a, array $b): float
    {
        $lighter = max($this->luminance($a), $this->luminance($b));
        $darker  = min($this->luminance($a), $this->luminance($b));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $colour
     */
    private function luminance(array $colour): float
    {
        $channel = static function (float $value): float {
            $value /= 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($colour[0])
            + 0.7152 * $channel($colour[1])
            + 0.0722 * $channel($colour[2]);
    }
}
