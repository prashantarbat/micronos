<?php

use App\Filters\AuthFilter;
use App\Libraries\IntendedUrl;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockIncomingRequest;
use Config\Modules;
use Config\Routing;
use Config\Services;

/**
 * Post-login destination handling.
 *
 * Regression: an expired session that submitted the WireGuard "Save Tunnel"
 * form remembered the POST address, so the browser replayed it with a GET after
 * sign-in and hit a routing 404:
 *
 *     Can't find a route for 'GET: vpn/wireguard/tunnels/store'
 *
 * These tests pin the rule that only a GET-renderable page can be remembered,
 * and that a destination is re-validated on the way out.
 *
 * @internal
 */
final class IntendedUrlTest extends CIUnitTestCase
{
    /** Fixed host so the assertions do not depend on the ambient HTTP_HOST. */
    private const HOST = 'localhost';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockSession();
        Services::superglobals()->setServer('HTTP_HOST', self::HOST);

        // A real request has its route table loaded before any filter runs.
        service('routes')->loadRoutes();
    }

    protected function tearDown(): void
    {
        $globals = Services::superglobals();
        $globals->unsetServer('HTTP_REFERER');
        $globals->unsetServer('REQUEST_METHOD');
        $globals->unsetServer('REQUEST_URI');
        $globals->unsetServer('HTTP_HOST');

        parent::tearDown();
    }

    /**
     * Build a request that looks like the real one and make it the request the
     * framework hands to helpers such as current_url().
     *
     * $refererPath is a site-relative path; it is turned into an absolute URL on
     * the fixed test host, exactly like a browser would send it.
     */
    private function request(string $method, string $path, string $refererPath = ''): MockIncomingRequest
    {
        // The request caches the superglobals on first use, so drive them
        // through the Superglobals service rather than $_SERVER directly, and
        // set them before the request object is built.
        $globals = Services::superglobals();
        $globals->setServer('REQUEST_METHOD', $method);
        $globals->setServer('REQUEST_URI', $path);
        $globals->setServer('HTTP_HOST', self::HOST);

        if ($refererPath === '') {
            $globals->unsetServer('HTTP_REFERER');
        } else {
            $globals->setServer('HTTP_REFERER', 'http://' . self::HOST . '/' . ltrim($refererPath, '/'));
        }

        $request = new MockIncomingRequest(
            config('App'),
            new SiteURI(config('App'), $path, self::HOST, 'http'),
            null,
            new UserAgent(),
        );

        Services::injectMock('request', $request);

        return $request;
    }

    // ---------------------------------------------------------------------
    // The reported failure
    // ---------------------------------------------------------------------

    public function testExpiredSessionSubmittingTheSaveTunnelFormReturnsToThePage(): void
    {
        $request = $this->request('POST', '/vpn/wireguard/tunnels/store', 'vpn/wireguard');

        IntendedUrl::remember($request, session());

        $this->assertSame(
            base_url('vpn/wireguard'),
            session()->get(IntendedUrl::SESSION_KEY),
            'A POST must remember the page that hosted the form, never its own address.',
        );

        // What the login controller hands to the browser: this is what used to
        // be replayed with a GET and 404ed.
        $this->assertSame(base_url('vpn/wireguard'), IntendedUrl::take(session()));
    }

    public function testAuthFilterSendsAnExpiredPostToLoginAndRemembersTheFormPage(): void
    {
        $request = $this->request('POST', '/vpn/wireguard/tunnels/store', 'vpn/wireguard');

        $response = (new AuthFilter())->before($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(base_url('auth/login'), $response->getHeaderLine('Location'));
        $this->assertSame(base_url('vpn/wireguard'), session()->get(IntendedUrl::SESSION_KEY));
    }

    public function testPostWithoutARefererRemembersNothing(): void
    {
        $request = $this->request('POST', '/vpn/wireguard/tunnels/store');

        IntendedUrl::remember($request, session());

        $this->assertNull(session()->get(IntendedUrl::SESSION_KEY));
        $this->assertSame(base_url('dashboard'), IntendedUrl::take(session()));
    }

    public function testOffsiteRefererIsNotRemembered(): void
    {
        $request = $this->request('POST', '/vpn/wireguard/tunnels/store');
        Services::superglobals()->setServer('HTTP_REFERER', 'https://evil.example/vpn/wireguard');

        IntendedUrl::remember($request, session());

        $this->assertNull(session()->get(IntendedUrl::SESSION_KEY));
    }

    public function testAjaxRequestIsNotRememberedAsADestination(): void
    {
        $request = $this->request('GET', '/vpn/wireguard');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');

        IntendedUrl::remember($request, session());

        $this->assertNull(session()->get(IntendedUrl::SESSION_KEY));
    }

    // ---------------------------------------------------------------------
    // A stale value from an older build still cannot 404
    // ---------------------------------------------------------------------

    public function testTakeRefusesActionEndpointsRememberedByAnOlderBuild(): void
    {
        $endpoints = [
            'vpn/wireguard/tunnels/store',
            'vpn/wireguard/tunnels/wg0/update',
            'vpn/wireguard/tunnels/wg0/toggle',
            'vpn/wireguard/tunnels/wg0/delete',
            'vpn/wireguard/peers/store',
            'vpn/wireguard/peers/abc123/delete',
            'interfaces/apply',
            'system/reboot',
        ];

        foreach ($endpoints as $endpoint) {
            session()->set(IntendedUrl::SESSION_KEY, base_url($endpoint));

            $this->assertSame(
                base_url('dashboard'),
                IntendedUrl::take(session()),
                "A remembered POST endpoint ({$endpoint}) must not be replayed as a GET.",
            );
        }
    }

    public function testTakeRejectsOffsiteAndSignInDestinations(): void
    {
        foreach (['https://evil.example/dashboard', base_url('auth/login')] as $poisoned) {
            session()->set(IntendedUrl::SESSION_KEY, $poisoned);
            $this->assertSame(base_url('dashboard'), IntendedUrl::take(session()));
        }
    }

    public function testTakeConsumesTheRememberedValue(): void
    {
        session()->set(IntendedUrl::SESSION_KEY, base_url('vpn/wireguard'));

        $this->assertSame(base_url('vpn/wireguard'), IntendedUrl::take(session()));
        $this->assertNull(session()->get(IntendedUrl::SESSION_KEY));
    }

    // ---------------------------------------------------------------------
    // GET pages are still remembered
    // ---------------------------------------------------------------------

    public function testGetPageIsRememberedAndReturned(): void
    {
        $request = $this->request('GET', '/vpn/wireguard');

        IntendedUrl::remember($request, session());

        $this->assertSame(base_url('vpn/wireguard'), session()->get(IntendedUrl::SESSION_KEY));
        $this->assertSame(base_url('vpn/wireguard'), IntendedUrl::take(session()));
    }

    public function testSignInPageIsNeverRemembered(): void
    {
        $request = $this->request('GET', '/auth/login');

        IntendedUrl::remember($request, session());

        $this->assertNull(session()->get(IntendedUrl::SESSION_KEY));
    }

    // ---------------------------------------------------------------------
    // URL helpers
    // ---------------------------------------------------------------------

    public function testLocalUrlAcceptsSameHostAndRejectsForeignHosts(): void
    {
        $this->assertSame('http://localhost/vpn/wireguard', IntendedUrl::localUrl('http://localhost/vpn/wireguard'));
        $this->assertSame('http://localhost/vpn/wireguard', IntendedUrl::localUrl('/vpn/wireguard'));
        $this->assertSame('http://localhost/vpn/wireguard', IntendedUrl::localUrl('vpn/wireguard'));

        $this->assertNull(IntendedUrl::localUrl('https://evil.example/vpn/wireguard'));
        $this->assertNull(IntendedUrl::localUrl(''));
    }

    public function testIsActionEndpointMatchesStandardActionRoutes(): void
    {
        foreach (['tunnels/store', 'x/update', 'x/delete', 'x/toggle', 'x/apply', 'x/reboot'] as $path) {
            $this->assertTrue(IntendedUrl::isActionEndpoint(base_url($path)), $path);
        }

        foreach (['vpn/wireguard', 'dashboard', 'vpn/wireguard/peers'] as $path) {
            $this->assertFalse(IntendedUrl::isActionEndpoint(base_url($path)), $path);
        }
    }

    public function testReachabilityFollowsTheRealRouteTable(): void
    {
        // Pages the router answers with a GET.
        foreach (['vpn/wireguard', 'vpn/wireguard/peers', 'dashboard', 'users'] as $path) {
            $this->assertTrue(IntendedUrl::isReachableWithGet(base_url($path)), $path);
        }

        // Addresses that exist only as POST actions.
        foreach (
            [
                'vpn/wireguard/tunnels/store',
                'vpn/wireguard/keygen',
                'plugins/refresh',
                'system/saveTheme',
                'siem/test-event',
            ] as $path
        ) {
            $this->assertFalse(IntendedUrl::isReachableWithGet(base_url($path)), $path);
        }

        // Nothing routes this at all.
        $this->assertFalse(IntendedUrl::isReachableWithGet(base_url('no/such/page/here')));
    }

    public function testReachabilityMatchesParameterisedGetRoutes(): void
    {
        $this->assertTrue(IntendedUrl::isReachableWithGet(base_url('vpn/wireguard/tunnels/wg0/config')));
        $this->assertTrue(IntendedUrl::isReachableWithGet(base_url('users/edit/7')));
    }

    // ---------------------------------------------------------------------
    // Invariant: no POST-only route behind the login can be replayed with a GET
    // ---------------------------------------------------------------------

    public function testNoProtectedPostOnlyRouteIsReachableWithGet(): void
    {
        // A private collection keeps the shared router untouched for other tests.
        $collection = new RouteCollection(
            service('locator'),
            config(Modules::class),
            config(Routing::class),
        );
        $collection->loadRoutes((string) (realpath(__DIR__ . '/../../../app/Config/Routes.php') ?: APPPATH . 'Config/Routes.php'));

        $gets  = array_keys($collection->getRoutes('GET'));
        $posts = array_keys($collection->getRoutes('POST'));

        $this->assertGreaterThan(100, count($gets), 'Routes should have loaded; a lower count means this check is inspecting nothing.');

        $public = config(\Config\Filters::class)->globals['before']['auth']['except'];

        $checked  = 0;
        $leaked   = [];

        foreach ($posts as $route) {
            if (str_contains($route, '([^/]+)') || str_contains($route, '(:')) {
                continue; // a concrete path cannot be built for a parameterised route
            }

            if (in_array($route, $gets, true)) {
                continue; // also reachable with a GET — a valid destination
            }

            if ($this->isPublicRoute($route, $public)) {
                continue; // not behind the auth filter, so never a remembered destination
            }

            $checked++;

            if (IntendedUrl::isReachableWithGet(base_url($route))) {
                $leaked[] = $route;
            }
        }

        $this->assertGreaterThan(50, $checked, 'Expected a meaningful number of auth-protected POST-only routes.');

        $this->assertSame(
            [],
            $leaked,
            "POST-only routes behind the login that a GET replay would be sent to:\n" . implode("\n", $leaked),
        );
    }

    /** True when the route is exempted from the global auth filter. */
    private function isPublicRoute(string $route, array $except): bool
    {
        $route = trim($route, '/');

        foreach ($except as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(trim($pattern, '/'), '#')) . '$#';

            if (preg_match($regex, $route) === 1) {
                return true;
            }
        }

        return false;
    }
}
