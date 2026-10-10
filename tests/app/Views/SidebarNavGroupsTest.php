<?php

namespace Tests\App\Views;

use App\Libraries\NavGroups;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The sidebar's parents.
 *
 * The rail used to be one flat run of links broken up by unclickable section
 * headings: on a real install that is 39 rows, and the only way to reach the
 * last section was to scroll past every earlier one. Each heading is now a
 * button that folds its own sub-menu away, so the rail opens as eight rows and
 * the operator chooses which one to unfold.
 *
 * Two things about that are easy to get wrong and invisible from the markup:
 *
 *  - a group must be closed *by the server*, not by the script. Collapsing in
 *    JS leaves the first paint with every menu open, which is the flash — and
 *    a page whose script failed is back to the flat list this replaced.
 *  - a folded menu must not be the one holding the page you are on. A parent
 *    that owns the current URL therefore renders open, and it is the only one
 *    that does.
 *
 * The rail also has to survive the trip to the next page: an operator who unfolds
 * Security while chasing something down does not want to re-open it on every
 * screen they visit. The section they choose is therefore reported back to the
 * session and read again on the way out.
 *
 * That makes the rail an accordion — one section open at a time — and gives two
 * ways for a section to be open: the operator chose it, or the rail chose it for
 * them by opening the parent that owns the page being drawn. The first wins, so
 * a page whose own section was folded away still folds it; only the owning
 * parent carries the active highlight, because being unfolded is not the same as
 * being where you are.
 *
 * All of it is pinned against the rendered page rather than the template, since
 * what an operator sees is the page.
 *
 * @internal
 */
final class SidebarNavGroupsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** Wide enough that a route's own permission gate never hides the sidebar. */
    private const PERMISSIONS = [
        'dashboard.view',
        'dns.view',
        'interfaces.view',
        'firewall.rules.view',
        'vpn.view',
        'plugins.view',
        'diag.view',
        'users.view',
        'cron.view',
        'system.view',
    ];

    /** The parents, in the order the rail must list them. */
    private const PARENTS = ['network', 'policy', 'routing', 'vpn', 'security', 'identity', 'admin', 'plugins'];

    /** A route inside each parent, used to prove a group opens for its own pages. */
    private const OWNER_OF = [
        'docs'           => 'admin',
        'interfaces'     => 'network',
        'firewall/rules' => 'policy',
    ];

    public function testTheRailIsEightParentsAndNoFlatHeadings(): void
    {
        $sidebar = $this->sidebar('docs');
        $groups  = $this->parentToggles($sidebar);

        $expected = array_map(static fn (string $key): string => 'navGroup-' . $key, self::PARENTS);

        $this->assertSame(
            $expected,
            array_keys($groups),
            'the rail must be exactly these parents, in this order'
        );

        foreach ($groups as $id => $group) {
            $this->assertNotSame(
                '',
                $group['menu'],
                "{$id}'s toggle must point at a sub-menu that is actually on the page"
            );
        }

        // The heading a group replaced must not survive as a second, unclickable
        // row: `nav-section-label` is what a flat section used to be.
        $this->assertStringNotContainsString(
            'nav-section-label',
            $sidebar,
            'a parent is the only thing between the rail and its items now'
        );
    }

    public function testOnlyTheParentHoldingThisPageStartsOpen(): void
    {
        foreach (self::OWNER_OF as $route => $owner) {
            $groups = $this->parentToggles($this->sidebar($route));

            foreach ($groups as $id => $group) {
                $isOpen = $id === 'navGroup-' . $owner;

                $this->assertSame(
                    $isOpen,
                    $group['expanded'],
                    "{$route}: {$id} must " . ($isOpen ? '' : 'not ') . 'be expanded on load'
                );
                $this->assertSame(
                    $isOpen,
                    $group['menu'] !== '' && ! str_contains($group['menu'], ' hidden'),
                    "{$route}: {$id}'s sub-menu must be rendered " . ($isOpen ? 'open' : 'closed')
                );
                $this->assertSame(
                    $isOpen,
                    $group['active'],
                    "{$route}: the parent owning the page is the one marked active"
                );
            }
        }
    }

    public function testAFoldedParentStillCarriesItsLinks(): void
    {
        $sidebar = $this->sidebar('docs');

        // Closed is a presentation state: every link local to a parent stays in
        // that parent's sub-menu, so the group is the whole of the navigation
        // and folding one away never removes a page.
        $inGroup = [
            'network' => ['interfaces', 'multicast'],
            'policy'  => ['firewall/rules', 'nat', 'qos'],
            'routing' => ['routing', 'ha', 'dhcp', 'dns'],
            'vpn'     => ['vpn/wireguard', 'vpn/ipsec'],
            'security' => ['ids', 'certificates', 'siem'],
            'identity' => ['users', 'roles'],
            'admin'   => ['backup', 'diag/logs', 'snmp', 'cron', 'system', 'docs'],
            'plugins' => ['plugins', 'plugins/updates', 'plugins/repositories'],
        ];

        foreach ($inGroup as $key => $links) {
            $menu = $this->menu($sidebar, $key);

            foreach ($links as $link) {
                $this->assertStringContainsString(
                    'href="' . base_url($link) . '"',
                    $menu,
                    "{$link} must live in the {$key} parent's sub-menu"
                );
            }
        }

        // The extension surface moved out of Administration, which is the point
        // of giving Plugins a parent of its own.
        $admin = $this->menu($sidebar, 'admin');
        foreach (['plugins', 'plugins/updates', 'plugins/repositories'] as $link) {
            $this->assertStringNotContainsString(
                'href="' . base_url($link) . '"',
                $admin,
                'the package manager belongs under Plugins, not Administration'
            );
        }
    }

    public function testTheScriptSettlesAParentsButtonAndItsMenuTogether(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            "document.querySelectorAll('.sidebar-nav .nav-group-toggle')",
            $layout,
            'something has to make the parents clickable'
        );

        // One helper settles both halves of a toggle's state, so the menu and
        // the button that announces it can never disagree about it.
        foreach ([
            'function foldNavGroup(btn, open) {'                            => 'folding a parent has to be one operation',
            "btn.setAttribute('aria-expanded', open ? 'true' : 'false');"   => 'which sets the state a screen reader reads',
            "const menu = document.getElementById(btn.getAttribute('aria-controls'));" => 'and finds the sub-menu it controls',
            'if (menu) menu.hidden = !open;'                                => 'before applying the same state to it',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $layout, $why);
        }

        // And the accordion itself: every toggle is settled on every click, so
        // opening one parent is the same act as closing the others — including
        // when the one clicked was the open one, which leaves none open.
        $this->assertStringContainsString(
            "navToggles.forEach(other => foldNavGroup(other, !open && other === btn));",
            $layout,
            'opening a section has to fold the rest, or two stay open at once'
        );
    }

    public function testTheCollapsedRailStillReachesEveryParent(): void
    {
        $css = $this->css();

        // In the icon rail a group is one icon, so its title and chevron go —
        // but the toggle has to remain a toggle, or the rail is a dead end.
        $this->assertStringContainsString('.sidebar.collapsed .nav-group-toggle {', $css);
        $this->assertStringContainsString('.sidebar.collapsed .nav-group-title,', $css);
        $this->assertStringContainsString('.sidebar.collapsed .nav-group-chevron {', $css);

        // And the closed state has to be a rule, not just the attribute: a menu
        // the script has closed must not spring open again from a display rule.
        $this->assertStringContainsString('.nav-group-menu[hidden] {', $css);
    }

    public function testTheSectionTheOperatorUnfoldedIsTheOnlyOneOpenOnTheNextPage(): void
    {
        // Security, while standing on a page owned by Network. The operator has
        // chosen, and their choice is what the next page shows: an accordion
        // cannot both remember one section and always open the current one.
        $answer = $this->unfold(['security']);

        $this->assertSame('security', $answer['group']);
        $this->assertSame(
            $answer['group'],
            $_SESSION[NavGroups::SESSION_KEY] ?? null,
            'the endpoint must write the session key the layout reads'
        );

        foreach (['interfaces' => 'network', 'docs' => 'admin'] as $route => $owner) {
            $groups = $this->parentToggles($this->sidebar($route, 'security'));

            $this->assertSame(
                ['navGroup-security'],
                $this->expanded($groups),
                "{$route}: the section the operator unfolded is the only one open"
            );

            // The highlight still follows the page, even where that parent is
            // folded: the rail remembers where you were working, not where you are.
            $this->assertSame(
                ['navGroup-' . $owner],
                array_keys(array_filter($groups, static fn (array $g): bool => $g['active'])),
                "{$route}: the parent holding the page is still the active one"
            );
        }
    }

    public function testFoldingTheLastSectionAwayIsRememberedToo(): void
    {
        $answer = $this->unfold([]);

        // Folding everything away is an answer, not a silence: it has to be
        // stored as such, or the old section would spring back open.
        $this->assertSame('', $answer['group']);
        $this->assertArrayHasKey(
            NavGroups::SESSION_KEY,
            $_SESSION,
            'the choice must be stored even when it is "nothing"'
        );
        $this->assertSame('', $_SESSION[NavGroups::SESSION_KEY]);

        // And it has to be honoured over the page's own parent: an operator who
        // asked for a rail of eight rows gets one.
        $this->assertSame(
            [],
            $this->expanded($this->parentToggles($this->sidebar('docs', ''))),
            'nothing is open once the operator has folded the last section away'
        );
    }

    public function testOnlyRealParentsCanBeRemembered(): void
    {
        $answer = $this->unfold([
            'security',
            '../admin',            // a path is not a parent
            'navGroup-vpn',        // the menu id is not the key
            'Admin',               // the keys are lower case
            42,                    // not a string
            ['vpn'],               // not a string either
            'siem',                // a page, not a parent
        ]);

        $this->assertSame('security', $answer['group'], 'unknown values must not reach the session');
        $this->assertSame('security', $_SESSION[NavGroups::SESSION_KEY] ?? null);

        // The first real parent wins and everything after it is dropped: only
        // one section can be open, so a request naming several must not smuggle
        // in a second one.
        $this->assertSame('security', NavGroups::sanitize(['nonsense', 'security', 'vpn']));
        $this->assertSame('vpn', NavGroups::sanitize('vpn'));
        $this->assertSame('', NavGroups::sanitize('nonsense'));
        $this->assertSame('', NavGroups::sanitize(42));
    }

    public function testASessionWrittenBeforeTheAccordionCollapsesToOneSection(): void
    {
        // Before this the rail remembered a set of unfolded parents, and a
        // session from then still holds two. One has to win — the same one every
        // time, not whichever the array happened to yield last.
        $groups = $this->parentToggles($this->sidebar('docs', ['vpn', 'security']));

        $this->assertSame(
            ['navGroup-vpn'],
            $this->expanded($groups),
            'a stale multi-section session must collapse to exactly one section'
        );
    }

    public function testTheMemoryEndpointRefusesAnUntokenedPost(): void
    {
        // The endpoint writes to the session, so it is a state change like any
        // other: without a valid token the framework must reject it rather than
        // let a third-party page choose what the rail shows.
        $this->expectException(SecurityException::class);

        $this->withSession($this->session())
            ->post('system/nav-groups', ['groups' => ['security'], 'csrf_test_name' => 'not-the-hash']);
    }

    public function testTheToggleScriptReportsTheChosenSectionToTheRegisteredRoute(): void
    {
        $layout = $this->layout();

        foreach ([
            "const open = btn.getAttribute('aria-expanded') === 'true';"       => 'a click has to know whether it is opening or closing',
            "rememberNavGroup(open ? '' : btn.dataset.navGroup || '');"         => 'and report the section it leaves open, or nothing at all when it closes the last one',
            'function rememberNavGroup(group) {'                               => 'the report is one section, not a set',
            "if (group) body.append('groups[]', group);"                       => 'sent as the field the endpoint reads',
            "body.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');"        => 'with the CSRF token, or the endpoint rejects it',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $layout, $why);
        }

        // An empty answer has to travel as an empty field rather than being
        // skipped silently: it is how "I folded the last one away" is said.
        $this->assertStringContainsString(
            'if (group) body.append(',
            $layout,
            'the closing click must still send its (empty) answer'
        );

        // And the URL in the script must be a route that exists: a rename on
        // either side would otherwise fail silently at runtime.
        $this->assertStringContainsString(
            "base_url('system/nav-groups')",
            $layout,
            'the script must post where the route is registered'
        );
        $this->assertStringContainsString(
            base_url('system/nav-groups'),
            $this->page('docs'),
            'and the rendered page must carry that URL'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The parents whose toggles are expanded, by menu id, in rail order.
     *
     * @param array<string, array{expanded: bool, active: bool, menu: string, key: string}> $groups
     *
     * @return list<string>
     */
    private function expanded(array $groups): array
    {
        return array_keys(array_filter($groups, static fn (array $g): bool => $g['expanded']));
    }

    /**
     * Unfold a section the way the rail's script does, and read back what the
     * endpoint says it stored.
     *
     * @param list<string> $groups the sections the operator left open
     *
     * @return array{ok: bool, group: string}
     */
    private function unfold(array $groups): array
    {
        $this->withSession($this->session());

        $result = $this->post('system/nav-groups', [
            'groups'         => $groups,
            'csrf_test_name' => service('security')->getHash(),
        ]);

        $result->assertOK();

        return (array) json_decode((string) $result->getJSON(), true);
    }

    private function layout(): string
    {
        return (string) file_get_contents(APPPATH . 'Views/layouts/main.php');
    }

    private function css(): string
    {
        return (string) file_get_contents(FCPATH . 'css/micronos.css');
    }

    /**
     * A rendered page.
     *
     * $chosen is written straight into the session, so it can be anything a real
     * session might hold — a parent key, '' for nothing open, or an older
     * build's array of several. Null is the honest "has not chosen yet":
     * leaving the key out entirely is a different answer from ''.
     *
     * @param string|list<string>|null $chosen
     */
    private function page(string $route, string|array|null $chosen = null): string
    {
        $values = $this->session();

        if ($chosen !== null) {
            $values[NavGroups::SESSION_KEY] = $chosen;
        }

        $result = $this->withSession($values)->get($route);

        $result->assertOK();

        return (string) $result->getBody();
    }

    /**
     * The sidebar as the browser receives it, cut out of a rendered page.
     *
     * @param string|list<string>|null $chosen the section this session unfolded
     */
    private function sidebar(string $route, string|array|null $chosen = null): string
    {
        $body = $this->page($route, $chosen);

        $open  = strpos($body, '<div class="sidebar-nav">');
        $close = strpos($body, '</div><!-- /sidebar-nav -->', (int) $open);

        $this->assertNotFalse($open, 'the layout must render the sidebar');
        $this->assertNotFalse($close, 'the sidebar nav must be closed');

        return substr($body, (int) $open, (int) $close - (int) $open);
    }

    /**
     * Each parent's toggle and the sub-menu it controls.
     *
     * Deliberately read from the rendered page rather than from a template
     * variable: the state under test is what the browser is handed.
     *
     * @return array<string, array{expanded: bool, active: bool, menu: string, key: string}>
     */
    private function parentToggles(string $sidebar): array
    {
        preg_match_all(
            '/<button type="button" class="nav-group-toggle(?P<active> active)?"'
            . ' aria-expanded="(?P<expanded>true|false)" aria-controls="(?P<id>[^"]+)"'
            . ' data-nav-group="(?P<key>[^"]+)">/',
            $sidebar,
            $matches,
            PREG_SET_ORDER
        );

        $groups = [];

        foreach ($matches as $match) {
            $id  = $match['id'];
            $tag = '';

            if (preg_match('/<ul class="nav-group-menu" id="' . preg_quote($id, '/') . '"[^>]*>/', $sidebar, $ul)) {
                $tag = $ul[0];
            }

            // The key the toggle reports to the endpoint is the key the rail
            // lists, or the script would remember a parent that does not exist.
            $this->assertSame('navGroup-' . $match['key'], $id, 'a toggle must report the parent it stands for');

            $groups[$id] = [
                'expanded' => $match['expanded'] === 'true',
                'active'   => trim($match['active']) === 'active',
                'menu'     => $tag,
                'key'      => $match['key'],
            ];
        }

        return $groups;
    }

    /** One parent's sub-menu, as markup. */
    private function menu(string $sidebar, string $key): string
    {
        $pattern = '/<ul class="nav-group-menu" id="navGroup-' . preg_quote($key, '/') . '"[^>]*>.*?<\/ul>/s';

        $this->assertSame(1, preg_match($pattern, $sidebar, $m), "the {$key} parent must render its sub-menu");

        return $m[0];
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }
}
