<?php

namespace Tests\App\Views;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The two layouts must agree on what the header is once the bar runs out of room.
 *
 * The topnav layout's own bar carried seven groups, the logo, the commit button,
 * the status pills and the sign-out link. Below roughly 1200px that row no longer
 * fits, and because the menu deliberately is not a scroll container (that would
 * clip the dropdown panels hanging below it) the last groups simply ran under the
 * right-hand controls — "Plugins" overlapping "Commit" — and wrapping the row onto
 * a second line only moved the problem. From that width down the layout now shows
 * the compact header the sidebar layout uses at every width: hamburger, logo, page
 * title, commit, status, sign-out, with the full menu in the off-canvas rail the
 * hamburger opens in the sidebar layout below 768px.
 *
 * Two things make that work, and both are pinned here because each is invisible
 * from the other: the stylesheet hides the bar and reveals the header, and the
 * layout's own script has to call the rail a *drawer* at the same breakpoint — a
 * hamburger that collapses a rail which is off-canvas does nothing at all. The two
 * widths therefore have to be the same number, so that is asserted rather than
 * spelled out twice.
 *
 * @internal
 */
final class HeaderResponsiveTest extends CIUnitTestCase
{
    private function css(): string
    {
        return (string) file_get_contents(FCPATH . 'css/micronos.css');
    }

    private function layout(): string
    {
        return (string) file_get_contents(APPPATH . 'Views/layouts/main.php');
    }

    public function testTheTopnavBarHandsTheHeaderOverWhereItsMenuNoLongerFits(): void
    {
        $css = $this->css();

        // The switch block, taken as a whole: the bar goes, the compact header
        // arrives, and the rail it hides becomes the drawer the hamburger opens.
        $needles = [
            '[data-layout="topnav"] .topnav-bar    { display: none; }'   => 'the horizontal menu must not survive on a bar too narrow to hold it',
            '[data-layout="topnav"] .topbar        { display: flex; }'   => 'the compact header is what replaces it',
            '[data-layout="topnav"] .topbar-toggle { display: flex; }'   => 'and the hamburger has to be on it, or there is no way to the menu at all',
            "display: flex !important;\n    transform: translateX(-100%);" => 'the rail that layout hides is the drawer, off-canvas until asked for',
            '[data-layout="topnav"] .sidebar.mobile-open'                => 'and opening it is the same class the sidebar layout already uses',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $css, $why);
        }
    }

    public function testTheBarAndTheDrawerShareOneBreakpoint(): void
    {
        $css    = $this->css();
        $layout = $this->layout();

        // The stylesheet's topnav switch.
        $block = strpos($css, '[data-layout="topnav"] .topnav-bar    { display: none; }');
        $this->assertNotFalse($block, 'the topnav layout must hand the header over below its breakpoint');

        $open = strrpos(substr($css, 0, $block), '@media');
        $this->assertNotFalse($open, 'the switch has to be inside a media query, not on every width');

        $cssWidth = 0;
        $this->assertSame(
            1,
            preg_match('/max-width:\s*(\d+)px/', substr($css, $open, 120), $m),
            'the switch has to be bounded by a max-width'
        );
        $cssWidth = (int) $m[1];

        // The script's own answer to "is the rail a drawer here?" — read from the
        // topnav branch, since the 768px rule that came first is the sidebar
        // layout's and is not the width being compared here.
        $script = strpos($layout, 'const mobileRail = ()');
        $this->assertNotFalse($script, 'the hamburger\'s behaviour has to be decided in one place');

        $span = substr($layout, $script, 400);
        $this->assertStringContainsString("dataset.layout === 'topnav'", $span,
            'the topnav layout opens the rail as a drawer, not as a collapsing rail');

        // Pinned as the whole expression, not just the label: the branch has to
        // be the one that decides, and `false && ...` would read the same to a
        // substring search while leaving the hamburger doing nothing.
        $this->assertStringContainsString(
            "|| (document.documentElement.dataset.layout === 'topnav'",
            $span,
            'the layout check has to be live, not short-circuited away'
        );

        $branch = strpos($span, "dataset.layout === 'topnav'");
        $this->assertNotFalse($branch, 'the topnav layout has to be named in the script');

        $jsWidth = 0;
        $this->assertSame(
            1,
            preg_match('/max-width:\s*(\d+)px/', substr($span, (int) $branch), $jm),
            'the drawer the topnav layout opens has to be bounded by the same kind of width'
        );
        $jsWidth = (int) $jm[1];

        $this->assertSame(
            $cssWidth,
            $jsWidth,
            'the bar hands over at ' . $cssWidth . 'px, so the drawer must start there too — a mismatch '
            . 'leaves a width where the bar is gone and the hamburger only toggles a hidden rail'
        );
    }

    /**
     * The compact header is the sidebar layout's, so it has to hold everything
     * the topnav bar was holding — otherwise hiding the bar loses a control.
     */
    public function testTheCompactHeaderCarriesWhatTheBarWasCarrying(): void
    {
        $layout = $this->layout();

        $open  = strpos($layout, '<header class="topbar">');
        $close = strpos($layout, '</header>', (int) $open);
        $this->assertNotFalse($open, 'the layout must render the compact header');
        $this->assertNotFalse($close, 'the compact header must be closed');

        $header = substr($layout, (int) $open, (int) $close - (int) $open);

        $needles = [
            'id="sidebarToggle"'          => 'the hamburger the drawer hangs off',
            'class="topbar-breadcrumb"'   => 'the logo and the page title',
            'topbar-commit-group'         => 'the commit control',
            'topbar-badge-group'          => 'the status pills',
            'auth/logout'                 => 'sign-out',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $header,
                'the topnav layout shows this header on tablet and mobile, so it must carry ' . $why);
        }
    }

    /**
     * A control that sheds itself on a narrow bar has to actually shed.
     *
     * The commit button also carries the `d-flex` utility, whose `display: flex`
     * is declared further down the stylesheet: on a tie the later rule wins, so
     * the container query that hides it was dead and the button stayed on a bar
     * too narrow for it, squeezing the hamburger and the sign-out button down to
     * about 25px. Scoping the rule to the bar is what makes it apply.
     */
    public function testANarrowBarShedsItsOptionalControls(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            '.topbar .topbar-commit-group { display: none; }',
            $css,
            'the rule that drops the commit button on a narrow bar has to beat the `d-flex` utility'
        );

        $this->assertStringContainsString(
            '@container (max-width: 390px)',
            $css,
            'and it has to be conditioned on the bar\'s own width, not the viewport\'s'
        );
    }

    /**
     * On a phone-width bar the row is narrower than everything in it wants to be,
     * so the page title gives the space up — a 36px tap target squeezed to 25px
     * clips its own glyph, and there is nothing to tap.
     */
    public function testTheTitleTruncatesInsteadOfTheControls(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            ".topbar-toggle,\n.topbar-icon-btn,",
            $css,
            'the controls must keep their size when the bar is narrow'
        );
        $this->assertStringContainsString('flex-shrink: 0;', $css, 'which is what stops the squeeze');

        // Pinned as the whole rule: `text-overflow: ellipsis` appears elsewhere in
        // the stylesheet, so a bare substring search would pass on a title that
        // still runs under the sign-out button.
        $this->assertStringContainsString(
            ".topbar-breadcrumb strong {\n  overflow: hidden;\n  text-overflow: ellipsis;\n  white-space: nowrap;\n}",
            $css,
            'and the title has to truncate visibly rather than run under the sign-out button'
        );
    }
}
