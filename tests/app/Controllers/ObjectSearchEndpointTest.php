<?php

use App\Libraries\GeoIp;
use App\Libraries\InternetService;
use App\Libraries\ObjectSearch;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * Server-side object search.
 *
 * The policy and policy-routing pickers used to render every address object
 * into the dialog and filter them in the DOM — thousands of `<option>` nodes on
 * a real install, and a filter that walked the whole list on every keystroke.
 * `firewall/objects/search` (mirrored as `routing/objects/search`) now answers
 * *one page* of a query instead, and the picker fetches the rest as the
 * operator types.
 *
 * The country and service catalogues go through the same path. A country picker
 * used to carry all 249 `<option>` nodes, the Internet Service Database 26 and
 * the firewall's own services another 47 — into every dialog that opens. They
 * are paged from here now, and the two catalogues that live in code rather than
 * in a table (`GeoIp::COUNTRIES`, `InternetService::SERVICES`) are matched
 * against that code instead of a query.
 *
 * These tests pin that contract: the answer is a bounded page rather than the
 * table, `total` still reports the full match count so the UI can say "showing
 * 200 of 4,118", the objects a rule already carries come back resolved so the
 * form cannot silently lose them, and the endpoint is covered by each screen's
 * own permission.
 *
 * @internal
 */
final class ObjectSearchEndpointTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** Every row these tests create is named with this, so cleanup is exact. */
    private const PREFIX = 'zz-search-';

    private BaseConnection $conn;

    private string $prefix;

    /** Ids of rows inserted with their literal name (e.g. the object `all`). */
    private array $literalIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = \Config\Database::connect();

        // A random tail means a row from a previous run can never be mistaken
        // for one of ours, and a mid-test failure cannot leak an object into
        // another test's result set.
        $this->prefix = self::PREFIX . bin2hex(random_bytes(3)) . '-';

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        // By the stable prefix, not just this run's random tail, so a crash
        // earlier still leaves the database as it was found.
        $this->conn->table('firewall_addresses')->like('name', self::PREFIX, 'after')->delete();
        $this->conn->table('firewall_address_groups')->like('name', self::PREFIX, 'after')->delete();
        $this->conn->table('firewall_services')->like('name', self::PREFIX, 'after')->delete();
        $this->conn->table('firewall_service_groups')->like('name', self::PREFIX, 'after')->delete();

        if ($this->literalIds !== []) {
            $this->conn->table('firewall_addresses')->whereIn('id', $this->literalIds)->delete();
            $this->literalIds = [];
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The picker source definition
    // ------------------------------------------------------------------

    public function testPickerSourceCarriesTheUrlKindsAndPageSize(): void
    {
        $source = ObjectSearch::pickerSource('addressObjects', 'http://example.test/firewall/objects/search');

        $this->assertSame('http://example.test/firewall/objects/search', $source['url']);
        $this->assertSame(['group', 'address'], $source['kinds']);
        $this->assertSame(ObjectSearch::DEFAULT_PER, $source['per']);
    }

    /**
     * Every picker that draws from a catalogue names its own source, and the
     * source is what decides which rows the server sends back.
     */
    public function testEachCatalogueHasItsOwnPickerSource(): void
    {
        $countries = ObjectSearch::pickerSource('countries', '/firewall/objects/search');
        $this->assertSame(['country'], $countries['kinds']);

        $services = ObjectSearch::pickerSource('internetServices', '/firewall/objects/search');
        $this->assertSame(['internetService'], $services['kinds']);

        // Groups before services, the order the dialog's optgroups have always
        // been drawn in.
        $firewall = ObjectSearch::pickerSource('firewallServices', '/firewall/objects/search');
        $this->assertSame(['serviceGroup', 'service'], $firewall['kinds']);
    }

    public function testUnknownPickerSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ObjectSearch::pickerSource('nope', '/x');
    }

    // ------------------------------------------------------------------
    // Paging
    // ------------------------------------------------------------------

    public function testSearchReturnsOnePageAndReportsTheFullMatchCount(): void
    {
        $this->seed(5, 1200);

        $page = ObjectSearch::search(['q' => $this->prefix, 'per' => 200, 'page' => 1], $this->conn);

        // A bounded page, not the table: this is the whole point of the change.
        $this->assertCount(200, $page['items']);
        $this->assertSame(1205, $page['total']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(200, $page['per']);
        $this->assertTrue($page['more']);

        $second = ObjectSearch::search(['q' => $this->prefix, 'per' => 200, 'page' => 2], $this->conn);

        $this->assertCount(200, $second['items']);
        $this->assertTrue($second['more']);

        // Pages must not overlap, or the picker would show the same object twice.
        $firstValues = array_column($page['items'], 'value');
        $secondValues = array_column($second['items'], 'value');
        $this->assertSame([], array_intersect($firstValues, $secondValues));

        // The last page is short and says there is nothing after it.
        $last = ObjectSearch::search(['q' => $this->prefix, 'per' => 200, 'page' => 7], $this->conn);

        $this->assertCount(5, $last['items']);
        $this->assertFalse($last['more']);
    }

    public function testPagesWalkGroupsFirstThenAddresses(): void
    {
        $this->seed(3, 3);

        $page = ObjectSearch::search(['q' => $this->prefix, 'per' => 200], $this->conn);

        $groups = array_filter($page['items'], static fn ($i) => $i['group'] === 'Address Groups');

        $this->assertCount(3, $groups);
        // Group rows come first, matching the optgroup order the dialog used.
        $this->assertSame('Address Groups', $page['items'][0]['group']);
    }

    public function testPageSizeIsCappedAndBlankPagesFallBackToTheFirst(): void
    {
        $this->seed(0, 3);

        $capped = ObjectSearch::search(['q' => $this->prefix, 'per' => 100000], $this->conn);
        $this->assertSame(ObjectSearch::MAX_PER, $capped['per']);

        $blank = ObjectSearch::search(['q' => $this->prefix, 'page' => '0', 'per' => 'junk'], $this->conn);
        $this->assertSame(1, $blank['page']);
        $this->assertSame(ObjectSearch::DEFAULT_PER, $blank['per']);
    }

    // ------------------------------------------------------------------
    // The catalogues behind the other pickers
    // ------------------------------------------------------------------

    /**
     * 249 countries were rendered into the address dialog and filtered by
     * walking every row in the DOM. They come from the same paged endpoint now,
     * so the box carries a page and says there is more.
     */
    public function testTheCountryCatalogueIsPagedRatherThanRenderedWhole(): void
    {
        $countries = GeoIp::countries();

        $page = ObjectSearch::search(['kinds' => 'country'], $this->conn);

        $this->assertCount(ObjectSearch::DEFAULT_PER, $page['items'], 'the country picker may only carry a page');
        $this->assertSame(count($countries), $page['total']);
        $this->assertTrue($page['more'], 'a catalogue larger than a page must be able to page on');

        $second = ObjectSearch::search(['kinds' => 'country', 'page' => 2], $this->conn);

        $this->assertCount(count($countries) - ObjectSearch::DEFAULT_PER, $second['items']);
        $this->assertFalse($second['more']);
        $this->assertSame([], array_intersect(
            array_column($page['items'], 'value'),
            array_column($second['items'], 'value'),
        ), 'pages of the country catalogue must not overlap');

        // The box shows one section, and a page boundary must not split it.
        $this->assertSame(['Countries'], array_values(array_unique(array_column($page['items'], 'group'))));
    }

    public function testTheCountryCatalogueIsSearchableByNameAndByCode(): void
    {
        $byName = ObjectSearch::search(['kinds' => 'country', 'q' => 'united kingdom'], $this->conn);

        $this->assertSame(1, $byName['total']);
        $this->assertSame('GB', $byName['items'][0]['value']);
        // The label is the one the option carried before the list was paged.
        $this->assertSame('United Kingdom (GB)', $byName['items'][0]['label']);

        // An operator who knows the code and not the name can search on it: the
        // code is what the address object stores, so it is what comes back.
        $byCode = ObjectSearch::search(['kinds' => 'country', 'q' => 'gb'], $this->conn);

        $this->assertContains('GB', array_column($byCode['items'], 'value'));
        $this->assertContains('GB', array_column(
            ObjectSearch::search(['kinds' => 'country', 'include' => ['GB', 'CA']], $this->conn)['selected'],
            'value',
        ), 'a stored code must resolve to a row the query need not match');
    }

    public function testTheInternetServiceCatalogueIsPagedAndSearchableByCategory(): void
    {
        $page = ObjectSearch::search(['kinds' => 'internetService'], $this->conn);

        // The whole catalogue is shorter than a page, so it arrives complete —
        // through the same path, and with the same labels it always had.
        $this->assertSame(count(InternetService::SERVICES), $page['total']);
        $this->assertFalse($page['more']);

        $labels = array_column($page['items'], 'label');
        $this->assertContains('Microsoft 365 (microsoft_365)', $labels);
        $this->assertContains('Zoom (zoom)', $labels);

        // The category an entry sits under is part of the query — the in-page
        // filter matched the optgroup heading, and the picker still has to.
        $conferencing = ObjectSearch::search(['kinds' => 'internetService', 'q' => 'conferencing'], $this->conn);

        $this->assertSame(3, $conferencing['total']);
        $this->assertSame(['Conferencing'], array_values(array_unique(array_column($conferencing['items'], 'group'))));

        // ...and a category's rows have to stay together, or a page boundary
        // would split the section the picker draws for it: one run per category.
        $groups = array_column($page['items'], 'group');
        $runs   = 0;

        foreach ($groups as $index => $group) {
            if ($index === 0 || $group !== $groups[$index - 1]) {
                $runs++;
            }
        }

        $this->assertSame(count(array_unique($groups)), $runs, 'each category must be one contiguous section of the page');

        $byKey = ObjectSearch::search(['kinds' => 'internetService', 'q' => 'zoom'], $this->conn);

        $this->assertSame('zoom', $byKey['items'][0]['value']);
    }

    /**
     * The firewall's own services and service groups are rows in a table, so
     * this kind is the address-object kind with different tables and labels —
     * including the protocol and ports the dialog has always shown.
     */
    public function testTheFirewallServiceCatalogueIsPagedFromItsTables(): void
    {
        $this->seedServices();

        $page = ObjectSearch::search(['kinds' => 'serviceGroup,service', 'q' => $this->prefix], $this->conn);

        $this->assertSame(5, $page['total']);

        // Groups first, then the services: the order the dialog's two optgroups
        // were rendered in.
        $this->assertSame('Service Groups', $page['items'][0]['group']);
        $this->assertSame('📦', $page['items'][0]['icon']);

        $byValue = array_column($page['items'], null, 'value');

        $this->assertSame('Known Standard Services', $byValue[$this->prefix . 'web']['group']);
        // The label is the one the option carried before the list was paged.
        $this->assertSame($this->prefix . 'web (TCP TCP:443)', $byValue[$this->prefix . 'web']['label']);
        $this->assertSame($this->prefix . 'dns (UDP UDP:53)', $byValue[$this->prefix . 'dns']['label']);

        // A service is searchable by its protocol and its ports as well as its
        // name — all of which are on screen, so all of which a query must reach.
        $byProtocol = array_column(ObjectSearch::search(['kinds' => 'service', 'q' => 'tcp'], $this->conn)['items'], 'value');
        $this->assertContains($this->prefix . 'web', $byProtocol);
        $this->assertContains($this->prefix . 'alt-http', $byProtocol);
        $this->assertNotContains($this->prefix . 'dns', $byProtocol);

        $byPort = array_column(ObjectSearch::search(['kinds' => 'service', 'q' => '8080'], $this->conn)['items'], 'value');
        $this->assertContains($this->prefix . 'alt-http', $byPort);
        $this->assertNotContains($this->prefix . 'dns', $byPort);

        // The two kinds are reachable on their own, too.
        $this->assertSame(2, ObjectSearch::search(['kinds' => 'serviceGroup', 'q' => $this->prefix], $this->conn)['total']);
        $this->assertSame(3, ObjectSearch::search(['kinds' => 'service', 'q' => $this->prefix], $this->conn)['total']);
    }

    /**
     * The endpoint a picker actually talks to answers the catalogues too, under
     * the same firewall permission as the address objects.
     */
    public function testTheEndpointAnswersTheCatalogueQueries(): void
    {
        $countries = $this->json($this->withSession($this->session())
            ->get('firewall/objects/search?kinds=country&q=united%20kingdom&per=5'));

        $this->assertSame(1, $countries['total']);
        $this->assertSame('GB', $countries['items'][0]['value']);
        $this->assertFalse($countries['more']);

        $services = $this->json($this->withSession($this->session())
            ->get('firewall/objects/search?kinds=internetService&q=conferencing'));

        $this->assertSame(3, $services['total']);
        $this->assertSame('Conferencing', $services['items'][0]['group']);
    }

    // ------------------------------------------------------------------
    // Matching
    // ------------------------------------------------------------------

    public function testSearchMatchesTheNameAndTheStoredValue(): void
    {
        $this->insertAddress('by-name-host', 'ipmask', '10.9.9.9/32');
        $this->insertAddress('other-host', 'ipmask', '172.31.7.7/32');

        $byName = ObjectSearch::search(['q' => $this->prefix . 'by-name', 'kinds' => 'address'], $this->conn);
        $this->assertSame(1, $byName['total']);
        $this->assertSame($this->prefix . 'by-name-host', $byName['items'][0]['value']);

        // The stored value is searchable too — operators know the network, not
        // always the object's name.
        $byValue = ObjectSearch::search(['q' => '172.31.7.7', 'kinds' => 'address'], $this->conn);
        $this->assertSame(1, $byValue['total']);
        $this->assertSame($this->prefix . 'other-host', $byValue['items'][0]['value']);
        $this->assertStringContainsString('172.31.7.7/32', $byValue['items'][0]['label']);
    }

    /**
     * A `%` in the query is a character an operator typed, not a wildcard: a
     * search for "50%" must not match every object on the appliance.
     */
    public function testWildcardsInTheQueryAreLiteral(): void
    {
        $this->insertAddress('fifty', 'ipmask', '10.0.0.0/8');
        $this->insertAddress('fifty-pct', 'ipmask', '50%');

        $hits = ObjectSearch::search(['q' => '50%', 'kinds' => 'address'], $this->conn);

        $this->assertSame(1, $hits['total']);
        $this->assertSame($this->prefix . 'fifty-pct', $hits['items'][0]['value']);
    }

    public function testKindsRestrictTheSearch(): void
    {
        $this->seed(2, 2);

        $addressesOnly = ObjectSearch::search(['q' => $this->prefix, 'kinds' => 'address'], $this->conn);

        $this->assertSame(2, $addressesOnly['total']);
        foreach ($addressesOnly['items'] as $item) {
            $this->assertSame('Individual Addresses & FQDNs', $item['group']);
        }

        // An unrecognised kind is ignored rather than turning into an empty
        // list, and an empty selection falls back to every kind.
        $fallback = ObjectSearch::search(['q' => $this->prefix, 'kinds' => 'bogus'], $this->conn);
        $this->assertSame(4, $fallback['total']);
    }

    // ------------------------------------------------------------------
    // Selected objects
    // ------------------------------------------------------------------

    public function testIncludeResolvesPickedObjectsTheQueryDoesNotMatch(): void
    {
        $this->insertAddress('picked-host', 'fqdn', 'picked.example.com');
        $this->insertGroup('picked-group');

        $page = ObjectSearch::search([
            'q'       => 'no-such-object-anywhere',
            'include' => [$this->prefix . 'picked-host', $this->prefix . 'picked-group', 'not-a-real-object'],
        ], $this->conn);

        // Nothing matched the query itself...
        $this->assertSame([], $page['items']);
        $this->assertFalse($page['more']);
        $this->assertSame(0, $page['total']);

        // ...but the objects the rule carries come back, in the order asked
        // for, so the picker can keep a row (and the form can submit one) for
        // each of them.
        $this->assertSame(
            [$this->prefix . 'picked-host', $this->prefix . 'picked-group'],
            array_column($page['selected'], 'value'),
        );
    }

    public function testIncludeIsDeduplicatedAndBounded(): void
    {
        $this->insertAddress('once', 'ipmask', '10.1.1.1/32');

        $page = ObjectSearch::search([
            'include' => [$this->prefix . 'once', $this->prefix . 'once', ''],
        ], $this->conn);

        $this->assertCount(1, $page['selected']);
        $this->assertSame($this->prefix . 'once', $page['selected'][0]['value']);

        // A hand-written `include` cannot make one request resolve an unbounded
        // number of values: the list is cut at MAX_INCLUDE, so a picked object
        // pushed past the cut is simply not resolved here (the picker asks
        // again next time, with a shorter list).
        $many = [];
        for ($i = 0; $i < ObjectSearch::MAX_INCLUDE + 50; $i++) {
            $many[] = $this->prefix . 'missing-' . $i;
        }
        $many[] = $this->prefix . 'once';

        $this->assertSame([], ObjectSearch::search(['include' => $many], $this->conn)['selected']);

        // Put the same value inside the bound and it resolves.
        array_unshift($many, $this->prefix . 'once');
        $this->assertSame($this->prefix . 'once', ObjectSearch::search(['include' => $many], $this->conn)['selected'][0]['value']);
    }

    // ------------------------------------------------------------------
    // The HTTP endpoint
    // ------------------------------------------------------------------

    public function testTheEndpointAnswersAPageAsJson(): void
    {
        $this->seed(2, 20);

        $result = $this->withSession($this->session())->get('firewall/objects/search?kinds=group,address&q=' . $this->prefix . '&per=5');

        $result->assertStatus(200);

        $data = $this->json($result);

        $this->assertCount(5, $data['items']);
        $this->assertSame(22, $data['total']);
        $this->assertSame(5, $data['per']);
        $this->assertSame(1, $data['page']);
        $this->assertTrue($data['more']);
        $this->assertArrayHasKey('selected', $data);
    }

    public function testTheSameEndpointServesTheRoutingDialog(): void
    {
        $this->seed(0, 3);

        $routing = $this->withSession($this->session())->get('routing/objects/search?q=' . $this->prefix);

        $routing->assertStatus(200);

        $data = $this->json($routing);
        $this->assertSame(3, $data['total']);
    }

    /**
     * Each screen is covered by its own permission: a routing operator must not
     * need firewall-rule rights to pick the object a PBR rule matches on, and a
     * firewall operator must not gain routing rights from this endpoint.
     */
    public function testEachSearchRouteIsCoveredByItsOwnPermission(): void
    {
        $denied = $this->withSession($this->nobody())
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('firewall/objects/search');

        $denied->assertStatus(403);
        $this->assertStringContainsString('firewall.rules.view', $this->rawBody($denied));

        // The routing scope needs routing.table.view, not the firewall grant:
        // the cached firewall grant is honoured, the absent routing key is not.
        $firewallOperator = [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => ['firewall.rules.view'],
            'user_permissions' => ['firewall.rules.view'],
        ];

        $this->withSession($firewallOperator)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('routing/objects/search')
            ->assertStatus(403);

        $this->withSession($firewallOperator)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('firewall/objects/search?per=1')
            ->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // The rendered dialog
    // ------------------------------------------------------------------

    /**
     * The page itself must ship one page of objects, not the whole table: the
     * dialog used to carry every object the appliance knows, which is exactly
     * what made it slow to open on a large install.
     */
    public function testTheRenderedDialogShipsOnePageNotTheWholeObjectTable(): void
    {
        $this->seed(1, 300);

        $page = $this->withSession($this->session())->get('firewall/rules');
        $page->assertOK();

        $body   = (string) $page->getBody();
        $region = $this->selectRegion($body, 'polSources');

        $this->assertNotSame('', $region, 'the source picker must be in the page');

        // 300+ objects exist, but the box holds a page and says there is more.
        $this->assertStringContainsString('data-picker-more="1"', $region);
        $this->assertSame(ObjectSearch::DEFAULT_PER + 1, substr_count($region, '<option '), 'only a page (and the sentinel) may be rendered');

        // The sentinel is a real object here (an address named "all"), so it
        // must be emitted once, from the partial, not twice.
        $this->assertSame(1, substr_count($region, 'value="all"'));

        // The real total is still reported, so the UI can say "showing 200 of N".
        $this->assertSame(1, preg_match('/data-picker-total="(\d+)"/', $region, $m));
        $this->assertGreaterThan(300, (int) $m[1]);
    }

    /**
     * The address dialog used to carry the country catalogue and the service
     * catalogue in its markup — 249 options and 26 more, sorted and filtered in
     * the DOM. Both are now the partial's first page.
     */
    public function testTheAddressDialogShipsOnePageOfTheCatalogues(): void
    {
        $page = $this->withSession($this->session())->get('firewall/addresses');
        $page->assertOK();

        $body = (string) $page->getBody();

        $countries = $this->selectRegion($body, 'addrCountries');

        $this->assertNotSame('', $countries, 'the country picker must be in the page');
        $this->assertStringContainsString('data-picker-more="1"', $countries, 'with more countries than a page, the box must say so');
        $this->assertSame(ObjectSearch::DEFAULT_PER, substr_count($countries, '<option '), 'only a page of the country catalogue may be rendered');
        $this->assertSame(1, preg_match('/data-picker-total="(\d+)"/', $countries, $m));
        $this->assertSame(count(GeoIp::countries()), (int) $m[1]);

        // The service catalogue is shorter than a page, so it arrives complete —
        // but through the same partial, and so does the service-database dialog's.
        $services = $this->selectRegion($body, 'addrServices');

        $this->assertNotSame('', $services);
        $this->assertSame(count(InternetService::SERVICES), substr_count($services, '<option '));
        $this->assertStringNotContainsString('data-picker-more', $services);
    }

    /**
     * Every object the reported total counts must have exactly one row.
     *
     * Two different things could break this. A policy picker that re-renders its
     * sentinel as well as the server's copy of that object would show it twice;
     * the PBR pickers carry no sentinel at all, and `all` is an ordinary address
     * object there that must not be dropped from the list (it would become
     * unpickable, and with the row gone its selection would be lost from the
     * form).
     */
    public function testEachPickerRendersExactlyOneRowPerReportedObject(): void
    {
        // 2 groups + 5 ordinary addresses + the object named `all`, which is an
        // ordinary row in the table and (on a real appliance) the value the
        // policy pickers' sentinel stands in for.
        $this->seed(2, 5);
        $this->insertLiteralAddress('all', 'ipmask', '0.0.0.0/0');
        $expected = 8;

        $rules   = (string) $this->withSession($this->session())->get('firewall/rules')->getBody();
        $routing = (string) $this->withSession($this->session())->get('routing')->getBody();

        $policy = $this->selectRegion($rules, 'polSources');
        $pbr    = $this->selectRegion($routing, 'pbrSrcObjects');

        $this->assertSame(1, preg_match('/data-picker-total="(\d+)"/', $policy, $pm));
        $this->assertSame(1, preg_match('/data-picker-total="(\d+)"/', $pbr, $rm));
        $this->assertSame($expected, (int) $pm[1]);
        $this->assertSame($expected, (int) $rm[1]);

        // The policy picker declares its sentinel, which stands in for the
        // object named `all` — so every counted object still has one row.
        $this->assertStringContainsString('data-picker-sentinel="1"', $policy);
        $this->assertSame($expected, substr_count($policy, '<option '), 'a policy picker must show each object once, sentinel included');

        // The PBR pickers have no sentinel: `all` is an object like any other,
        // and dropping its row would leave it unpickable.
        $this->assertStringNotContainsString('data-picker-sentinel', $pbr);
        $this->assertSame($expected, substr_count($pbr, '<option '), 'a PBR picker must not drop the `all` object');
    }

    /**
     * The page has to register the pickers against the endpoint and export the
     * selection API the edit path calls — a picker that renders a page but never
     * pages or preselects would look right and behave wrong.
     */
    public function testTheRenderedDialogsWireThePickerToTheEndpoint(): void
    {
        $rules   = (string) $this->withSession($this->session())->get('firewall/rules')->getBody();
        $routing = (string) $this->withSession($this->session())->get('routing')->getBody();

        // Two server-backed pickers on each screen.
        $this->assertSame(3, substr_count($rules, 'source: {'), 'every policy picker must page from the endpoint');
        $this->assertSame(2, substr_count($routing, 'source: {'), 'both PBR address pickers must page from the endpoint');

        $this->assertStringContainsString('firewall/objects/search', $rules);
        $this->assertStringContainsString('routing/objects/search', $routing);

        // The helper exports the selection API and the edit paths use it.
        $this->assertStringContainsString('setSelection: setSelection', $rules);
        $this->assertSame(3, substr_count($rules, 'MicronPickerSearch.setSelection('), 'editPolicy must resolve each rule\'s objects');
        $this->assertSame(1, substr_count($routing, 'MicronPickerSearch.setSelection('), 'openEditPbr must resolve a rule\'s objects');
    }

    // ------------------------------------------------------------------
    // Scale
    // ------------------------------------------------------------------

    /**
     * The cost of a keystroke has to be bounded by the page size, not by the
     * number of objects on the appliance. With thousands of rows the answer is
     * still one page of `per`, and still comes back quickly.
     */
    public function testAKeystrokeStaysBoundedOnAVeryLargeObjectTable(): void
    {
        $this->seed(0, 3000);

        $started = microtime(true);
        $page    = ObjectSearch::search(['q' => $this->prefix, 'per' => 200, 'page' => 1], $this->conn);
        $elapsed = microtime(true) - $started;

        $this->assertCount(200, $page['items'], 'a page must never grow with the table');
        $this->assertSame(3000, $page['total'], 'the full match count is still reported');
        $this->assertTrue($page['more']);
        $this->assertLessThan(1.5, $elapsed, 'one page must answer in bounded time however many objects exist');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The raw response body. `TestResponse::getBody()` is proxied to the DOM
     * parser, which re-serialises a JSON body as an HTML document — for a JSON
     * endpoint the response itself is what has to be read.
     */
    private function rawBody($result): string
    {
        return (string) $result->response()->getBody();
    }

    /** @return array<string,mixed> */
    private function json($result): array
    {
        $data = json_decode($this->rawBody($result), true);

        $this->assertIsArray($data, 'the endpoint must answer with JSON');

        return $data;
    }

    /** The `<select>` a picker id renders, from its id attribute to its close. */
    private function selectRegion(string $body, string $id): string
    {
        $start = strpos($body, 'id="' . $id . '"');
        if ($start === false) {
            return '';
        }

        $end = strpos($body, '</select>', $start);

        return $end === false ? '' : substr($body, $start, $end - $start);
    }

    private function seed(int $groups, int $addresses): void
    {
        for ($i = 0; $i < $groups; $i++) {
            $this->insertGroup(sprintf('group-%04d', $i));
        }

        if ($addresses === 0) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];

        for ($i = 0; $i < $addresses; $i++) {
            $rows[] = [
                'id'         => 'addr_' . bin2hex(random_bytes(6)),
                'name'       => $this->prefix . sprintf('host-%04d', $i),
                'type'       => 'ipmask',
                'value'      => '10.20.' . intdiv($i, 256) . '.' . ($i % 256) . '/32',
                'interface'  => 'any',
                'color'      => '#000',
                'comment'    => '',
                'is_system'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->conn->table('firewall_addresses')->insertBatch($rows);
    }

    private function insertAddress(string $suffix, string $type, string $value): void
    {
        $this->insertRow($this->prefix . $suffix, $type, $value);
    }

    /**
     * An address whose name is exactly what is given — the only way to create
     * the object `all`, which is the value the policy pickers' sentinel stands
     * in for and which the PBR pickers must still list as an object.
     */
    private function insertLiteralAddress(string $name, string $type, string $value): void
    {
        $this->literalIds[] = $this->insertRow($name, $type, $value);
    }

    private function insertRow(string $name, string $type, string $value): string
    {
        $now = date('Y-m-d H:i:s');
        $id  = 'addr_' . bin2hex(random_bytes(6));

        $this->conn->table('firewall_addresses')->insert([
            'id'         => $id,
            'name'       => $name,
            'type'       => $type,
            'value'      => $value,
            'interface'  => 'any',
            'color'      => '#000',
            'comment'    => '',
            'is_system'  => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** The firewall's own service catalogue: two groups and three services. */
    private function seedServices(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (['group-a', 'group-b'] as $suffix) {
            $this->conn->table('firewall_service_groups')->insert([
                'id'         => 'svcg_' . bin2hex(random_bytes(6)),
                'name'       => $this->prefix . $suffix,
                'members'    => json_encode([]),
                'comment'    => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([['web', 'TCP', '443', null], ['dns', 'UDP', null, '53'], ['alt-http', 'TCP', '8080', null]] as [$suffix, $protocol, $tcp, $udp]) {
            $this->conn->table('firewall_services')->insert([
                'id'         => 'svc_' . bin2hex(random_bytes(6)),
                'name'       => $this->prefix . $suffix,
                'protocol'   => $protocol,
                'tcp_port'   => $tcp,
                'udp_port'   => $udp,
                'category'   => 'General',
                'is_system'  => 0,
                'comment'    => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function insertGroup(string $suffix): void
    {
        $now = date('Y-m-d H:i:s');

        $this->conn->table('firewall_address_groups')->insert([
            'id'         => 'agrp_' . bin2hex(random_bytes(6)),
            'name'       => $this->prefix . $suffix,
            'members'    => json_encode([]),
            'color'      => '#000',
            'comment'    => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => ['firewall.rules.view', 'routing.table.view'],
            'user_permissions' => ['firewall.rules.view', 'routing.table.view'],
        ];
    }

    private function nobody(): array
    {
        return [
            'user_id'          => 'usr_nobody_' . bin2hex(random_bytes(3)),
            'username'         => 'Nobody',
            'is_superadmin'    => 0,
            'permissions'      => [],
            'user_permissions' => [],
        ];
    }
}
