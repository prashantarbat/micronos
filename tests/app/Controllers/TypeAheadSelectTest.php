<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * Every single-select object dropdown — the NAT interface and schedule pickers,
 * the DHCP relay destination, the firewall policy's own interfaces — holds the
 * same long, unordered list of appliance objects, and the browser's own
 * type-ahead matches an option's *leading* characters only. Reaching
 * "WAN-Secondary" in a list of four hundred interfaces meant scrolling. Typing
 * over the field now narrows the list to the options whose text or value
 * contains the query and puts the first of them in the field, with no separate
 * search box beside it.
 *
 * The behaviour is one document-delegated script in `app/Views/layouts/main.php`
 * (so it reaches every screen, including a fragment a panel loads later, and
 * there is a single implementation to keep right) and the "list is filtered"
 * ring is `public/css/micronos.css`. These tests pin the parts that are easy to
 * regress: the opt-out, the keys it must leave to the browser, the one `change`
 * it may fire, and the dropdowns the request named.
 *
 * @internal
 */
final class TypeAheadSelectTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** A page per shell: a dialog host, the NAT screen and the relay fragment. */
    private const PAGES = [
        'firewall/rules' => ['firewall.rules.view'],
        'nat'            => ['nat.view'],
        'routing'        => ['routing.table.view'],
        'dhcp/relay'     => ['dhcp.view'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    /**
     * One implementation, shipped from the layout to every screen. A second copy
     * per view is exactly what the shared script exists to avoid, and the
     * constant it declares is also the class the stylesheet dresses, so pinning
     * the count keeps the JS and the CSS from drifting apart.
     */
    public function testEveryScreenShipsOneTypeAhead(): void
    {
        foreach (self::PAGES as $route => $permissions) {
            $page = $this->withSession($this->session($permissions))->get($route);

            $page->assertOK();

            $body = (string) $page->getBody();

            $this->assertSame(
                1,
                substr_count($body, "'mno-typeahead-active'"),
                "{$route} must ship the type-ahead exactly once"
            );
        }
    }

    /**
     * Bound once on the document rather than per screen, so a dropdown a page
     * adds after load — or one that only exists inside a reloaded panel — is
     * covered too, and no view carries its own copy.
     */
    public function testItIsBoundOnceOnTheDocumentAndNoViewReimplementsIt(): void
    {
        $script = $this->typeAheadScript();

        foreach ([
            "document.addEventListener('keydown', onKeydown)",
            "document.addEventListener('focusout'",
            "document.addEventListener('change'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $script, 'the type-ahead must be delegated from the document');
        }

        $this->assertSame(
            [],
            $this->viewsDeclaring('mno-typeahead-active'),
            'the type-ahead has one implementation: it belongs in the layout, not in a view'
        );
    }

    /** A dropdown is searched only on purpose: single, enabled and not opted out. */
    public function testItActsOnlyOnASingleEnabledSelectThatHasNotOptedOut(): void
    {
        $script = $this->typeAheadScript();

        foreach ([
            "if (!select || select.tagName !== 'SELECT' || select.multiple || select.disabled) return;" => 'only a single, enabled dropdown is searched',
            "if (select.dataset && select.dataset.mnoTypeahead === 'off') return;"                        => 'a dropdown must be able to opt out',
            "if (e.ctrlKey || e.metaKey || e.altKey) return;"                                              => 'a shortcut is not a query',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $script, $why);
        }
    }

    /**
     * Arrows, Enter to accept and Tab to leave stay the browser's own. Only a
     * printable character (or a Space that continues a query already under way)
     * is consumed, which is why no other key ever reaches `preventDefault`.
     */
    public function testArrowsEnterAndTabStayTheBrowsersOwn(): void
    {
        $script = $this->typeAheadScript();

        $this->assertStringContainsString('} else if (e.key.length !== 1) {', $script, 'a non-printable key must be left alone');

        foreach (["e.key === 'ArrowDown'", "e.key === 'ArrowUp'", "e.key === 'Enter'", "e.key === 'Tab'"] as $needle) {
            $this->assertStringNotContainsString($needle, $script, "{$needle} must not be intercepted");
        }

        // Backspace (to correct the query) and a printable key are the only two
        // things the script may swallow.
        $this->assertSame(
            2,
            substr_count($script, 'e.preventDefault()'),
            'only Backspace and a printable key may be consumed'
        );
    }

    /**
     * The search commits once, when it settles — and only if it actually moved
     * the selection. A dropdown whose onchange submits a form or navigates must
     * not be triggered once per keystroke.
     */
    public function testAQueryCommitsTheSelectionOnceAndOnlyWhenItMoved(): void
    {
        $script = $this->typeAheadScript();

        $this->assertSame(
            1,
            substr_count($script, "dispatchEvent(new Event('change'"),
            'a dropdown must not fire a change per keystroke'
        );
        $this->assertStringContainsString('select.value === startedWith', $script, 'nothing fires when the search did not move the selection');
        $this->assertStringContainsString('IDLE_MS', $script, 'the commit has to wait for the query to settle');
        $this->assertStringContainsString('setTimeout(function () { stop(select, true); }', $script, 'settling is what commits the pick');
    }

    /** Escape ends the search without taking over the key a page may also use. */
    public function testEscapeLeavesThePagesOwnHandlerAlone(): void
    {
        $script = $this->typeAheadScript();

        $start = strpos($script, "if (e.key === 'Escape')");
        $this->assertNotFalse($start, 'the type-ahead must handle Escape');

        $end = strpos($script, '}', strpos($script, 'return;', $start));
        $this->assertNotFalse($end, 'the Escape branch must return without consuming the key');

        $this->assertStringNotContainsString(
            'e.preventDefault()',
            substr($script, $start, $end - $start),
            'Escape must still reach the page\'s own handler'
        );
    }

    /**
     * The ring that marks a filtered list is the design system's, declared once
     * in the shared sheet. It rides on `box-shadow`, which no per-view `select`
     * rule touches, so it shows even where a screen sets its own border inline.
     */
    public function testTheFilteredRingBelongsToTheDesignSystem(): void
    {
        $css = (string) file_get_contents(FCPATH . 'css/micronos.css');

        $this->assertStringContainsString('select.mno-typeahead-active {', $css, 'the filtered ring belongs in the shared sheet');
        $this->assertStringContainsString('box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.18)', $css, 'the ring must ride on box-shadow to beat a per-view border');

        $this->assertSame(
            [],
            $this->viewsDeclaring('mno-typeahead-active'),
            'the filtered ring has one implementation: it belongs in public/css/micronos.css, not in a view'
        );
    }

    /**
     * The dropdowns the request named are, in fact, single-selects that have not
     * opted out — the type-ahead only drives those, so a later `multiple` or an
     * opt-out would quietly take the search away.
     */
    public function testTheNamedObjectDropdownsAreSearchableSingleSelects(): void
    {
        $nat = (string) $this->withSession($this->session(['nat.view']))->get('nat')->getBody();

        foreach (['id="dnatIface"', 'id="dnatSchedule"', 'id="snatPoolIface"', 'id="nptIface"'] as $attr) {
            $this->assertSearchableSingleSelect($nat, $attr, "nat: {$attr}");
        }

        $relay = (string) $this->withSession($this->session(['dhcp.view']))->get('dhcp/relay')->getBody();

        foreach (['name="interface_id"', 'name="destination_id"'] as $attr) {
            $this->assertSearchableSingleSelect($relay, $attr, "dhcp/relay: {$attr}");
        }
    }

    private function assertSearchableSingleSelect(string $body, string $attr, string $where): void
    {
        $this->assertSame(
            1,
            preg_match('/<select\b[^>]*\b' . $attr . '[^>]*>/', $body, $match),
            "{$where} must be rendered as a select"
        );

        $this->assertStringNotContainsString('multiple', $match[0], "{$where} must stay a single-select: the type-ahead only drives those");
        $this->assertStringNotContainsString('data-mno-typeahead="off"', $match[0], "{$where} must not opt out of the search");
    }

    /** The type-ahead `<script>` alone, located by the constant it declares. */
    private function typeAheadScript(): string
    {
        $layout = (string) file_get_contents(APPPATH . 'Views/layouts/main.php');

        $marker = strpos($layout, "var ACTIVE_CLASS = 'mno-typeahead-active';");
        $this->assertNotFalse($marker, 'the layout must declare the type-ahead active class');

        $open  = strrpos(substr($layout, 0, $marker), '<script>');
        $close = strpos($layout, '</script>', $marker);

        $this->assertNotFalse($open, 'the type-ahead must live in its own <script>');
        $this->assertNotFalse($close, 'the type-ahead <script> must be closed');

        return substr($layout, $open, $close - $open);
    }

    /** @return list<string> views, other than the layout, that mention `$needle` */
    private function viewsDeclaring(string $needle): array
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(APPPATH . 'Views', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'main.php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $offenders[] = str_replace(APPPATH . 'Views/', '', $file->getPathname());
            }
        }

        return $offenders;
    }

    /** @param list<string> $permissions */
    private function session(array $permissions): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => $permissions,
            'user_permissions' => $permissions,
        ];
    }
}
