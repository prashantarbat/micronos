<?php

use App\Libraries\GeoIp;
use App\Libraries\InternetService;
use App\Libraries\ObjectSearch;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * The filterable picker the "New / Edit Firewall Policy" dialog introduced has
 * to reach *every* object picker in the UI, not just that one dialog.
 *
 * A picker is a native `<select multiple>` holding every address object, group,
 * service or country on the appliance — hundreds of rows on a real install.
 * Without a search box the only way to reach one is Ctrl-click hunting. The
 * behaviour itself lives in `app/Views/partials/picker_search.php` and the box's
 * styling in `public/css/micronos.css`; these tests pin both, plus the search
 * box / "no matches" note / reset wiring on each page that renders a picker.
 *
 * @internal
 */
final class ObjectPickerSearchTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'firewall.rules.view',
        'firewall.rules.edit',
        'routing.table.view',
    ];

    /** Page route => the object pickers it renders. */
    private const PAGES = [
        'firewall/addresses'         => ['addrServices', 'addrCountries'],
        'firewall/internet-services' => ['isdbServices'],
        'routing'                    => ['pbrSrcObjects', 'pbrDstObjects'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    public function testEveryObjectPickerHasASearchBoxAndANoMatchesNote(): void
    {
        foreach (self::PAGES as $route => $pickers) {
            $page = $this->withSession($this->session())->get($route);

            $page->assertOK();

            foreach ($pickers as $picker) {
                $page->assertSee('id="' . $picker . 'Search"');
                $page->assertSee('id="' . $picker . 'Empty"');
                // The select the box filters must still be there.
                $page->assertSee('id="' . $picker . '"');
            }
        }
    }

    public function testEveryPageShipsTheSharedPickerBehaviour(): void
    {
        foreach (array_keys(self::PAGES) as $route) {
            $page = $this->withSession($this->session())->get($route);

            $page->assertOK();

            $body = (string) $page->getBody();

            // A search box without the helper behind it would look present and do
            // nothing, so pin the shared implementation and the binding.
            $this->assertSame(1, substr_count($body, 'window.MicronPickerSearch'), "{$route} must include the shared picker helper exactly once");
            $this->assertStringContainsString("input.addEventListener('input'", $body, "{$route} must filter as the box is typed in");
            $this->assertStringContainsString('MicronPickerSearch.init(', $body, "{$route} must configure its pickers");
            // Enter in the box would otherwise submit and save the dialog's form.
            $this->assertStringContainsString('e.preventDefault()', $body, "{$route} must swallow Enter instead of saving the dialog");
        }
    }

    public function testEveryDialogEntryPointResetsTheFiltersBeforeItOpens(): void
    {
        $addresses = (string) $this->withSession($this->session())->get('firewall/addresses')->getBody();

        // openCreateAddressModal() and editAddress() both reach the same dialog.
        $this->assertSame(
            2,
            substr_count($addresses, "MicronPickerSearch.reset(['addrServices', 'addrCountries'])"),
            'both address dialog entry points must clear the picker filters'
        );

        $isdb = (string) $this->withSession($this->session())->get('firewall/internet-services')->getBody();

        $this->assertSame(
            1,
            substr_count($isdb, "MicronPickerSearch.reset(['isdbServices'])"),
            'the service database dialog must clear its picker filter'
        );

        $routing = (string) $this->withSession($this->session())->get('routing')->getBody();

        // The Add button and openEditPbr() both go through openModal(), so the
        // reset has to sit there rather than in either caller.
        $this->assertSame(
            1,
            substr_count($routing, "MicronPickerSearch.reset(['pbrSrcObjects', 'pbrDstObjects'])"),
            'the policy-routing dialog must clear both picker filters'
        );
    }

    public function testThePolicyRoutingPickersKeepTheAllSentinelVisible(): void
    {
        $page = $this->withSession($this->session())->get('routing');
        $body = (string) $page->getBody();

        // `all` is a row in firewall_addresses, so it is a real option here: it
        // must never be filtered away, and Enter must not auto-pick it.
        foreach (['pbrSrcObjects', 'pbrDstObjects'] as $picker) {
            $this->assertStringContainsString(
                "select: '{$picker}'",
                $body,
                "{$picker} must be registered with the shared helper"
            );
        }

        $this->assertSame(2, substr_count($body, "any: 'all'"), 'both PBR pickers must treat `all` as the sentinel');
    }

    /**
     * No view declares the picker's styling itself. The box, the list box, the
     * count / Clear toolbar and the selection chips are all the design system's,
     * so there is one implementation for the policy dialog and for the address,
     * service-database and routing pickers — not a copy per screen.
     */
    public function testNoViewDeclaresThePickersStylingItself(): void
    {
        $rules = [
            '.mno-multiselect {',
            '.mno-multiselect option:checked',
            '.mno-multiselect optgroup',
            '.mno-pick-bar {',
            '.mno-pick-count {',
            '.mno-pick-btn {',
            '.mno-chips {',
            '.mno-chip {',
        ];

        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(APPPATH . 'Views', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($rules as $rule) {
                if (str_contains($contents, $rule)) {
                    $offenders[] = str_replace(APPPATH . 'Views/', '', $file->getPathname()) . ' declares ' . $rule;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'the picker has one implementation: its styling belongs in public/css/micronos.css, not in a view'
        );
    }

    /**
     * ...and the shared sheet really does carry all of it, so a picker that stops
     * declaring its own styling still renders as a picker.
     */
    public function testTheDesignSystemOwnsTheWholePickerNotJustItsSearchBox(): void
    {
        $css = (string) file_get_contents(FCPATH . 'css/micronos.css');

        $rules = [
            '.mno-pick-search {',
            '.mno-pick-search:focus {',
            '.mno-pick-empty {',
            '.mno-pick-status {',
            'option.mno-pick-cursor {',
            '.mno-multiselect {',
            '.mno-multiselect:focus {',
            '.mno-multiselect option:checked {',
            '.mno-multiselect optgroup {',
            '.mno-pick-bar {',
            '.mno-pick-count {',
            '.mno-pick-btn {',
            '.mno-chips {',
            '.mno-chip {',
        ];

        foreach ($rules as $rule) {
            $this->assertStringContainsString($rule, $css, "the design system must style {$rule}");
        }
    }

    /**
     * A screen's generic field styling must not win over the picker's own. The
     * policy-routing dialog styles every `.rt-field input/select` and that rule is
     * more specific than `.mno-pick-search` / `.mno-multiselect`, so the picker is
     * excluded from it — otherwise the PBR pickers quietly dress as plain routing
     * fields while every other picker keeps the shared look.
     */
    public function testAGenericFieldRuleDoesNotDressThePicker(): void
    {
        $css = (string) file_get_contents(FCPATH . 'css/micronos.css');

        // The base rule has to carry both exclusions: the box *and* the list box.
        // Matching the whole selector sequence keeps this from being satisfied by
        // the `:focus` variant alone, which would leave the resting style wrong.
        $this->assertStringContainsString(
            ".rt-field input:not(.mno-pick-search),\n.rt-field select:not(.mno-multiselect),",
            $css,
            'the routing field rule must not apply to the picker'
        );

        // And nothing else may style every routing select unqualified, or it
        // would win on specificity again.
        $this->assertStringNotContainsString('.rt-field select {', $css);
        $this->assertStringNotContainsString('.rt-field input {', $css);
    }

    /**
     * Each picker's list box is the shared one: the same class, no private inline
     * styling left behind on any of them.
     */
    public function testEveryPickerListBoxUsesTheSharedStyle(): void
    {
        $pages = self::PAGES + ['firewall/rules' => ['polSources', 'polDestinations', 'polServices']];

        foreach ($pages as $route => $pickers) {
            $body = (string) $this->withSession($this->session())->get($route)->getBody();

            foreach ($pickers as $picker) {
                $this->assertSame(
                    1,
                    preg_match('/<select\b[^>]*\bid="' . preg_quote($picker, '/') . '"[^>]*>/', $body, $match),
                    "{$route}: {$picker} must be rendered as a select"
                );

                $this->assertStringContainsString('mno-multiselect', $match[0], "{$picker} must use the shared list style");
                $this->assertStringNotContainsString('style=', $match[0], "{$picker} must not carry its own inline styling");
            }
        }
    }

    /**
     * A keyboard-only operator has to be able to fill these dialogs: the arrows
     * walk a highlight through the filtered list and Space toggles the object it
     * lands on (Enter picks a match, Escape clears the query).
     */
    public function testEveryPickerIsDrivenFromTheKeyboard(): void
    {
        $routes = array_keys(self::PAGES);
        // The policy dialog's own pickers as well: they now run on the helper.
        $routes[] = 'firewall/rules';

        foreach ($routes as $route) {
            $body = (string) $this->withSession($this->session())->get($route)->getBody();

            $needles = [
                "e.key === 'ArrowDown'"     => '↓ must move the highlight, not the page scroll',
                "e.key === 'ArrowUp'"       => '↑ must move the highlight, not the page scroll',
                "e.key === ' ' || e.key === 'Spacebar'" => 'Space must toggle the highlighted object',
                'mno-pick-cursor'           => 'the highlight must be classed for styling',
                'if (next === cfg.painted) return;' => 'moving the highlight must not repaint the whole list',
                'cfg.painted.classList.remove(CURSOR_CLASS)' => 'the previous highlight must be cleared when it moves',
                'next.classList.add(CURSOR_CLASS)' => 'the highlight must be painted on the cursored row',
                'revealCursor(sel, next)'   => 'the highlight must be scrolled into view, not left off-screen',
                'toggleCursor('             => 'Space must go through the toggle helper',
                '!cfg.armed'                => 'Space must only toggle once an arrow has armed the list',
            ];

            foreach ($needles as $needle => $why) {
                $this->assertStringContainsString($needle, $body, "{$route}: {$why}");
            }
        }
    }

    /**
     * The country and service catalogues go through the same paged path as the
     * address objects. A picker that renders its whole catalogue is the thing
     * this replaced, so the wiring is pinned on each screen that used to do it:
     * the box is the partial's first page and the search box asks the shared
     * endpoint for the rest.
     */
    public function testTheCataloguePickersPageFromTheEndpoint(): void
    {
        // A picker's kind, paired with the total the box rendered into the page
        // reports. Both have to be there: the partial's page and the browser's
        // later requests have to be asking about the same catalogue, or the list
        // would be of one thing and the search of another.
        $countries = [
            '"kinds":["country"]',
            'data-picker-total="' . count(GeoIp::countries()) . '"',
        ];
        $services = [
            '"kinds":["internetService"]',
            'data-picker-total="' . count(InternetService::SERVICES) . '"',
        ];

        $pages = [
            'firewall/addresses'         => [$services, $countries],
            'firewall/internet-services' => [$services],
        ];

        foreach ($pages as $route => $pickers) {
            $body = (string) $this->withSession($this->session())->get($route)->getBody();

            $this->assertSame(
                count($pickers),
                substr_count($body, 'source: {'),
                "{$route}: every catalogue picker must page from the endpoint"
            );
            $this->assertStringContainsString('firewall/objects/search', $body, "{$route} must talk to the object search endpoint");

            foreach ($pickers as [$kind, $total]) {
                $this->assertStringContainsString($kind, $body, "{$route} must ask the endpoint for {$kind}");
                $this->assertStringContainsString($total, $body, "{$route} must render a page of the catalogue it searches");
            }
        }
    }

    /**
     * The policy dialog's service picker is paged too — the firewall's own
     * service catalogue rather than the Internet Service Database, and behind the
     * same `ALL` sentinel the box has always carried.
     */
    public function testThePolicyServicePickerPagesTheFirewallServiceCatalogue(): void
    {
        $body = (string) $this->withSession($this->session())->get('firewall/rules')->getBody();

        $this->assertStringContainsString('"kinds":["serviceGroup","service"]', $body);
        $this->assertStringContainsString('firewall/objects/search', $body);

        $start = strpos($body, 'id="polServices"');
        $this->assertNotFalse($start, 'the policy service picker must be in the page');

        $region = substr($body, $start, strpos($body, '</select>', $start) - $start);

        $this->assertStringContainsString('data-picker-sentinel="1"', $region, 'the `ALL` row is the box\'s own, not a catalogue entry');
        $this->assertStringContainsString('value="ALL"', $region);
    }

    /**
     * A server-backed picker has to keep two things straight: the sentinel is a
     * constant the markup declares (and only the pickers that carry one get it),
     * and a value the page has picked is requested back so its row — and so its
     * presence in the submitted form — cannot be lost to a query that does not
     * match it.
     */
    public function testServerBackedPickersDeclareTheirSentinelAndResolvePickedRows(): void
    {
        $rules = (string) $this->withSession($this->session())->get('firewall/rules')->getBody();

        $needles = [
            'data-picker-sentinel="1"'          => 'the sentinel has to be declared by the markup, not guessed from a value',
            "sel.dataset.pickerSentinel === '1'" => 'the client must read the declared sentinel',
            'cfg.sentinelNode'                   => 'the sentinel must be snapshotted so a query can put it back',
            'if (cfg.hasSentinel && isSentinel(cfg, { value: value })) return;' => 'only a declared sentinel may be skipped when resolving picks',
            'includeValues(cfg, sel)'            => 'picked values must ride along on every request',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $rules, $why);
        }
    }

    /**
     * Enter adds a match to the selection rather than replacing it: a policy
     * normally matches several objects, and replacing meant building a list of
     * five sources was five Ctrl-clicks. The page's own change handler still
     * applies the `all` / `ALL` exclusivity rule, and Ctrl / Cmd-click is the
     * browser's own multi-select — which is only true while the shared helper
     * binds no click handler that could interfere with it.
     */
    public function testEnterAddsAMatchWithoutClearingTheSelection(): void
    {
        $helper = (string) file_get_contents(APPPATH . 'Views/partials/picker_search.php');

        $start = strpos($helper, 'function pickCursor(id)');
        $this->assertNotFalse($start, 'the picker must handle Enter');

        $end = strpos($helper, 'function setSelection(id, values)', $start);
        $this->assertNotFalse($end, 'pickCursor must be followed by the selection API');

        $body = substr($helper, $start, $end - $start);

        $this->assertStringContainsString('target.selected = true;', $body, 'Enter must select the match it found');
        // Adding, not replacing: nothing may wipe the selection on the way in.
        $this->assertStringNotContainsString('o.selected = false', $body, 'Enter must not clear the current selection first');

        // Ctrl / Cmd-click must keep working, which is only true while the
        // helper stays out of the mouse entirely: it may listen for typing, keys
        // and scrolling, and nothing else.
        $this->assertNotSame(0, preg_match_all("/addEventListener\('([a-z]+)'/", $helper, $matches), 'the picker must bind its own listeners');

        $events = array_values(array_unique($matches[1]));
        sort($events);

        $this->assertSame(
            ['input', 'keydown', 'scroll'],
            $events,
            'Ctrl / Cmd-click must stay the browser\'s own: the picker may not bind a mouse listener'
        );
    }

    /** The highlight has to be visible, and distinct from a selected row. */
    public function testTheDesignSystemStylesTheKeyboardHighlight(): void
    {
        $css = (string) file_get_contents(FCPATH . 'css/micronos.css');

        $this->assertSame(1, preg_match('/option\.mno-pick-cursor\s*\{(.*?)\}/s', $css, $rule), 'the keyboard highlight must be styled');

        // The ring and the underline are what separate "highlighted" from a
        // plain selected row, whose fill is only a tint.
        $this->assertStringContainsString('var(--color-cyan)', $rule[1], 'the highlight must follow the active theme accent');
        $this->assertStringContainsString('box-shadow: inset 0 0 0 2px var(--color-cyan)', $rule[1], 'the highlight needs a ring a tinted row does not have');
        $this->assertStringContainsString('text-decoration: underline', $rule[1], 'the highlight must survive an engine that ignores option fills');
    }

    /**
     * The country list is 249 rows and a service catalogue synced from feeds is
     * thousands: over a list that long, re-reading and re-lower-casing every
     * option's text on every keystroke — and repainting every option just to move
     * the highlight — is what makes the box lag behind the typing.
     *
     * Filtering now runs over an index built once, an option's visibility is
     * written only when it changes, the highlight touches only the row it moved
     * to, and a server-backed page dedupes its rows through a Set rather than
     * rescanning everything it has accumulated.
     */
    public function testThePickerStaysResponsiveOverALargeCatalogue(): void
    {
        $helper = (string) file_get_contents(APPPATH . 'Views/partials/picker_search.php');

        $needles = [
            'function buildIndex(cfg, sel)'                          => 'the catalogue must be indexed once, not re-read per keystroke',
            'hay:   (opt.textContent'                                => 'the index must hold a lower-cased haystack per row',
            'cfg.index && cfg.index.count === sel.options.length'    => 'the index must be reused while the rows are unchanged',
            "if (opt.hidden === hidden && (opt.style.display === 'none') === hidden) return;" => 'an option whose visibility did not change must not be written',
            'if (next === cfg.painted) return;'                      => 'the highlight must move without repainting the list',
            'cfg.present.has(item.value)'                            => 'a server-backed page must not rescan its rows to dedupe',
            'cfg.present.add(item.value)'                            => 'the rendered values must be remembered',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $helper, $why);
        }
    }

    /**
     * A box that is only one page of a bigger catalogue hands itself over.
     *
     * `partials/pick_select.php` renders that page, says the catalogue continues
     * (`data-picker-more`) and — when the screen passes it — names the endpoint the
     * rows came from. A picker the page configured as a plain local list would
     * otherwise filter the rendered slice for the rest of its life: the operator
     * types an object's name, the box answers "no match", and the object sits one
     * page further down a catalogue the box never asks about.
     *
     * Both halves of the rule are pinned here, because each is what keeps the
     * other honest: the trigger is the partial's own count of what it could not
     * render, so a box holding the whole list stays local and asks for nothing,
     * and the endpoint has to be declared, so a slice with nowhere to ask is left
     * as it is rather than turned into a page the picker cannot extend.
     */
    public function testAPickerAdoptsTheDeclaredEndpointWhenItsListIsTruncated(): void
    {
        $helper = (string) file_get_contents(APPPATH . 'Views/partials/picker_search.php');

        $needles = [
            'function promote(cfg, sel)' => 'a local list that is really one page has to be able to take the search over',
            "if (sel.dataset.pickerMore !== '1') return false;" => 'only a catalogue the markup says continues may be taken over',
            'sel.dataset.pickerUrl' => 'the endpoint must come from the markup, never be guessed',
            'sel.dataset.pickerKinds' => 'the request has to ask about the same kinds the rows were rendered from',
            'sel.dataset.pickerPer' => 'and for the same page size, or the pages would not line up',
            'if (!cfg.source) promote(cfg, sel);' => 'the adoption has to run at load, before anything is filtered',
            'if (cfg.source) adoptRenderedPage(cfg, sel);' => 'a promoted box must adopt the rendered page exactly as a declared one does',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $helper, $why);
        }
    }

    /**
     * Every picker list box says where its rows came from, so no screen has to
     * remember to: the endpoint, the kinds asked for and the page size rendered.
     * Each screen names its own endpoint — the routing pickers search under the
     * routing permission, not the firewall's.
     */
    /**
     * A box that cannot be taken over has to say so, in the console.
     *
     * Staying local is the correct behaviour for a truncated box whose markup
     * names no endpoint — it must not pretend to be a page of something it cannot
     * page. But doing it silently is not: the operator types a name that is
     * demonstrably on the appliance, the box answers "no match", and nothing
     * anywhere says the list was only ever one page. The screen's author is the
     * one who can fix it, and the console is where they are looking, so the
     * picker writes one line naming the box, how much of the catalogue is on
     * screen, and the argument `partials/pick_select.php` was not given.
     *
     * An endpoint with no kinds is the same omission with a worse outcome — the
     * request names no kind, the server answers its default address catalogue, and
     * a list of countries quietly fills with addresses — so it is refused and
     * reported the same way rather than half-adopted.
     */
    public function testATruncatedBoxThatCannotSearchSaysSoInTheConsole(): void
    {
        $helper = (string) file_get_contents(APPPATH . 'Views/partials/picker_search.php');

        $needles = [
            'function warnUnsearchable(cfg, sel, hasEndpoint)' => 'the refusal has to be reportable, not only performed',
            'if (!url || kinds.length === 0) {' => 'both omissions leave a truncated box unable to reach its own catalogue',
            'warnUnsearchable(cfg, sel, Boolean(url));' => 'and each is reported, distinguished by what the markup did name',
            'sel.dataset.pickerTotal' => 'the notice counts what the partial could not render instead of inventing a total',
            "'#' + cfg.select" => 'it names the box, so a page of eight pickers still says which one is stuck',
            'declares no search endpoint, so the rest cannot be searched' => 'no endpoint means the rows past the page are unreachable',
            'declares no kinds to search for' => 'no kinds means the request would answer the default address catalogue',
            'declares no search endpoint, so the rest cannot be searched; pass `search`' => 'no endpoint means the rows past the page are unreachable, and which argument fixes it',
            'address catalogue instead; pass `source` to the partial that rendered it' => 'no kinds means the request would answer the default address catalogue, and which argument fixes it',
            '(partials/pick_select.php)' => 'both name the partial to fix, without spelling a view() call the caller count would pick up',
            "'MicronPickerSearch: '" => 'prefixed, so a page can tell the picker line from its own console output',
            "typeof console !== 'undefined'" => 'guarded, because the same script is driven headlessly by the Node harness',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $helper, $why);
        }
    }

    /**
     * A request that cannot be answered degrades the box; it must not disable it.
     *
     * The rows already on screen are a list worth filtering, so a dropped
     * connection turns a server-backed picker into a local one: the query in
     * flight is filtered against those rows at once, the rows carrying a picked
     * value stay visible (the form submits from those options), and typing keeps
     * narrowing them while the next request is on its way. The note has to stay
     * exact about it — "no match among the rows here" is a different statement
     * from "not found", and the operator acts on the difference — and the first
     * answer that arrives puts the whole catalogue back.
     *
     * The failure also schedules its own next attempt, on a delay that doubles to
     * a cap, so a long outage costs one request per window rather than one per
     * keystroke typed through it; the note doubles as the connection-lost
     * indicator and says when that attempt is due.
     */
    public function testAFailedSearchDegradesToTheRowsTheBoxHolds(): void
    {
        $helper = (string) file_get_contents(APPPATH . 'Views/partials/picker_search.php');

        $needles = [
            'function fail(id, err)' => 'the failure path is where the box changes hands',
            'cfg.degraded = true;' => 'a failure is the box saying it answers from its own rows',
            'if (cfg.degraded) filter(cfg.select, input.value, true);' => 'typing narrows what is loaded, without waiting on the network',
            'filter(id, query, true);' => 'the query in flight is answered from the rows in the box',
            'function keepPickedVisible(sel)' => 'a picked row stands through the local filter, as it does on the server path',
            'return sel.options.length - (cfg.hasSentinel ? 1 : 0);' => 'the note counts the objects held, not the sentinel row',
            "cfg.offline = 'Search unavailable \u{2014} connection lost ('" => 'the note names both the failure that happened and that the connection is lost',
            "'filtering the ' + rows + ' objects already loaded'" => 'and says what the box is doing instead',
            "'no match among the ' + rows + ' objects already loaded'" => 'and never reads as "the object is not there" when only the loaded rows were searched',
            'function retryLater(id)' => 'the box schedules its own next attempt instead of leaving the operator to retype',
            'var RETRY_MAX  = 15000;' => 'the wait doubles to a cap, so a long outage is not a request storm',
            'var delay = RETRY_BASE * Math.pow(2, Math.max(0, (failures || 1) - 1));' => 'and it grows, rather than repeating at one interval',
            'if (cfg.degraded) return;' => 'a keystroke while the box is degraded starts no request of its own',
            "cfg.retryTail = 'retrying in ' + Math.round(delay / 1000) + 's';" => 'the note says when the next attempt is due, so waiting looks like waiting',
            "el.classList.remove('mno-pick-empty', 'mno-pick-status', 'mno-pick-offline');" => 'a note that goes away takes its state with it',
            'filter: filter,' => 'filter stays the one entry point, degraded or not',
        ];

        foreach ($needles as $needle => $why) {
            $this->assertStringContainsString($needle, $helper, $why);
        }

        // Cleared in three places — at load, on an answered request, and when a
        // dialog reopens — so a count is what pins all three rather than the
        // presence of one line. Matched loosely, because the assignments are
        // aligned in the source.
        preg_match_all('/cfg\.degraded\s*=\s*false;/', $helper, $cleared);

        $this->assertCount(
            3,
            $cleared[0],
            'the degraded state has to be cleared at load, on an answer, and when a dialog reopens'
        );
    }

    /**
     * The catalogue the browser keeps for the next load.
     *
     * The rows the page rendered are stored on every load — free, and that is what
     * gives the record a fresh stamp; one bounded walk of the pages behind the
     * rendered one fills in the rest when the record is missing or stale; and the
     * stored rows answer a box whose request failed, so a reload into a dead link
     * still searches the objects the appliance has already named. Every part of
     * that is a rule someone can remove without the page looking broken, so each
     * one is pinned here by its source. The ordering pin is the subtle one: the
     * walk's decision reads the record as it stood *before* the load stamped it,
     * or a fresh stamp on every load means the walk never runs at all.
     */
    public function testTheCatalogueIsKeptForTheNextLoad(): void
    {
        $helper = (string) file_get_contents(APPPATH . 'Views/partials/picker_search.php');

        foreach ([
            "var SNAP_PREFIX    = 'micron-pick-cat:v1:'" => 'the record lives under its own key, and under its own version',
            "candidate.setItem(SNAP_PREFIX + 'probe', '1');" => 'storage is tried before it is trusted, so private mode is a browser without one',
            'function writeSnapshot(source, rows, total)' => 'one place merges rows into the stored catalogue',
            'if (!snap || snap.v !== 1 || !Array.isArray(snap.rows) || !snap.rows.length) return null;' => 'a record of an unknown version, or one with no rows, is not a catalogue',
            'if (!(age >= 0) || age > SNAP_TRUST_MS) return null;' => 'and a month-old record is a liability, not a fallback',
            'snap.rows = snap.rows.slice(-Math.floor(SNAP_MAX_ROWS / 2));' => 'a browser that refuses the record gets a smaller one rather than none',
            'if (snap && (Date.now() - (snap.at || 0)) < SNAP_FRESH_MS) return;' => 'a walk is only due when the record is missing or stale',
            "if (!cfg.source || sel.dataset.pickerMore !== '1' || !store()) return;" => 'and only for a page the markup says continues, when there is somewhere to put the answer',
            'var walked = {};' => 'one walk per catalogue per load, kept as a local of the load itself',
            'prefetchMissing(cfg, sel, walked);' => 'the walk comes first at load',
            'harvestRendered(cfg, sel);' => 'and the rendered rows are stored after it, so the decision was made on the older record',
            'seedSnapshot(id);' => 'what the browser remembers is added to a box whose request failed',
            'writeSnapshot(cfg.source, (data.items || []).concat(data.selected || []), cfg.total);' => 'every answer is a look at the catalogue, and is kept',
            "return ' (cached ' + ageText(Date.now() - (cfg.cached.at || 0)) + ')'" => 'and the note says when those rows came from, not only that they are local',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $helper, $why);
        }

        // The walk's decision must not be made after the load has stamped the
        // record, and the seed must land before the failed query is filtered —
        // both are orders, so a position is what pins them.
        $this->assertLessThan(
            strpos($helper, 'harvestRendered(cfg, sel);'),
            strpos($helper, 'prefetchMissing(cfg, sel, walked);'),
            'the walk decides on the record as it stood before this load stored the rendered rows'
        );

        $this->assertLessThan(
            strpos($helper, 'filter(id, query, true);'),
            strpos($helper, 'seedSnapshot(id);'),
            'the stored rows have to be in the box before the query is answered from it'
        );
    }

    public function testEveryPickerListBoxDeclaresTheEndpointItsRowsCameFrom(): void
    {
        $pages = [
            'firewall/addresses'         => 'firewall/objects/search',
            'firewall/internet-services' => 'firewall/objects/search',
            'firewall/rules'             => 'firewall/objects/search',
            'routing'                    => 'routing/objects/search',
        ];

        foreach ($pages as $route => $endpoint) {
            $body = (string) $this->withSession($this->session())->get($route)->getBody();

            $this->assertGreaterThan(
                0,
                substr_count($body, 'data-picker-url="' . base_url($endpoint) . '"'),
                "{$route} must tell its pickers where their rows came from"
            );
        }

        $addresses = (string) $this->withSession($this->session())->get('firewall/addresses')->getBody();

        foreach (['internetService', 'country'] as $kind) {
            $this->assertStringContainsString(
                'data-picker-kinds="' . $kind . '"',
                $addresses,
                'the box has to name the catalogue its rows are a page of'
            );
        }

        $this->assertStringContainsString(
            'data-picker-per="' . ObjectSearch::DEFAULT_PER . '"',
            $addresses,
            'and the page size they were rendered with'
        );

        $routing = (string) $this->withSession($this->session())->get('routing')->getBody();

        $this->assertStringNotContainsString(
            'data-picker-url="' . base_url('firewall/objects/search') . '"',
            $routing,
            'the routing pickers must not be pointed at the firewall endpoint'
        );
    }

    /**
     * `Config\View::$saveData` is on, so a variable one `pick_select` render sets is
     * still in scope for the next picker rendered in the same request. For the
     * endpoint that is the one thing that must not be left to inheritance: a box
     * inheriting it would search another screen's catalogue, behind another screen's
     * permission, while its own rows stayed unreachable. Every caller names its own,
     * `null` where the box has none to declare — the same shape `pickerSentinel` is
     * passed in, and for the same reason.
     */
    public function testEveryPickSelectCallerNamesItsOwnEndpoint(): void
    {
        $views = array_merge(
            glob(APPPATH . 'Views/*.php') ?: [],
            glob(APPPATH . 'Views/*/*.php') ?: []
        );

        $calls = 0;

        foreach ($views as $path) {
            // The partial's own doc block shows a call to itself; it is not a caller.
            if (realpath($path) === realpath(APPPATH . 'Views/partials/pick_select.php')) {
                continue;
            }

            $body = (string) file_get_contents($path);
            $at   = 0;

            while (($start = strpos($body, "view('partials/pick_select'", $at)) !== false) {
                $end = strpos($body, ']) ?>', $start);

                $this->assertNotFalse($end, basename($path) . ' must render its picker through the partial');

                $this->assertStringContainsString(
                    "'search'",
                    substr($body, $start, $end - $start),
                    basename($path) . ' must name the endpoint its picker list came from — null where there is none'
                );

                $calls++;
                $at = $end + 1;
            }
        }

        $this->assertSame(8, $calls, 'every picker list box in the UI is counted here, so a new one cannot be missed');
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }
}
