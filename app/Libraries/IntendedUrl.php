<?php

namespace App\Libraries;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Session\Session;

/**
 * IntendedUrl — remembers where an operator was heading before they were asked
 * to sign in, and hands back a destination that a GET request can render.
 *
 * Why this exists: after a successful login the browser replays the remembered
 * destination with a GET. Remembering the address of a POST-only action endpoint
 * (`…/tunnels/store`, `…/delete`, `…/toggle`) therefore produced a routing 404
 * immediately after sign-in:
 *
 *     Can't find a route for 'GET: vpn/wireguard/tunnels/store'
 *
 * because only POST is routed there. The session-expiry case made that easy to
 * hit: an operator with an expired session submits the "Save Tunnel" form, the
 * POST address is remembered, they sign in, and the replay 404s instead of
 * returning them to their work.
 *
 * Rules enforced here:
 *   * A GET request's own URL is remembered — that is a page and can be replayed.
 *   * When a non-GET request is interrupted, the page that hosted the form (the
 *     local referer) is remembered instead, so the operator lands back on the
 *     form with a fresh CSRF token and can submit again.
 *   * Off-site, unparseable, and sign-in URLs are discarded. The sign-in page is
 *     never a destination, which also prevents a redirect loop.
 *   * The destination is re-validated on the way out against the router's real
 *     GET routes, so a value written by an older build (or by another code path)
 *     still cannot produce a 404.
 */
final class IntendedUrl
{
    /** Session key used by the auth filter and the login controller. */
    public const SESSION_KEY = 'redirect_url';

    /**
     * Path suffixes that exist only as POST routes, kept as a cheap pre-check
     * for the case where the router is not available (CLI utilities).
     */
    private const ACTION_SUFFIXES = [
        '/store',
        '/update',
        '/delete',
        '/toggle',
        '/restart',
        '/genkey',
        '/keygen',
        '/apply',
        '/apply-script',
        '/reboot',
        '/halt',
    ];

    /**
     * Remember a useful post-login destination for this request, if there is one.
     */
    public static function remember(RequestInterface $request, Session $session): void
    {
        $session->remove(self::SESSION_KEY);

        if (! $request->is('get')) {
            // The interrupted request cannot be replayed with a GET. Send the
            // operator back to the page that hosted the form instead.
            $referer = self::localUrl((string) ($request->getServer('HTTP_REFERER') ?? ''));

            if ($referer !== null && ! self::isSignInPage($referer) && self::isReachableWithGet($referer)) {
                $session->set(self::SESSION_KEY, $referer);
            }

            return;
        }

        // An AJAX poll is not somewhere to land after signing in.
        if (method_exists($request, 'isAJAX') && $request->isAJAX()) {
            return;
        }

        $current = current_url();

        if (! self::isSignInPage($current) && self::isReachableWithGet($current)) {
            $session->set(self::SESSION_KEY, $current);
        }
    }

    /**
     * The destination to use once the operator is authenticated. Always returns
     * something routable; the stored value is consumed either way.
     */
    public static function take(Session $session): string
    {
        $stored = (string) ($session->get(self::SESSION_KEY) ?? '');
        $session->remove(self::SESSION_KEY);

        $local = self::localUrl($stored);

        if ($local === null || self::isSignInPage($local) || ! self::isReachableWithGet($local)) {
            return base_url('dashboard');
        }

        return $local;
    }

    /**
     * True when the router really would answer a GET for this URL.
     *
     * This is the guarantee that matters — "the browser can render it" — and it
     * is checked against the loaded route table rather than a hand-kept list of
     * action names, so a newly added POST-only endpoint is refused automatically.
     */
    public static function isReachableWithGet(string $url): bool
    {
        $path = self::routePath($url);

        $routes    = service('routes');
        $getKeys   = self::normalizedKeys($routes->getRoutes('GET'));
        $postKeys  = self::normalizedKeys($routes->getRoutes('POST'));

        if ($getKeys === [] && $postKeys === []) {
            // No route table in this context (e.g. a CLI utility): fall back to
            // the deny-list rather than refusing every destination.
            return ! self::isActionEndpoint($path);
        }

        // An address registered as a POST endpoint, and not as a GET page, is
        // exactly the address that produced the original 404.
        if (in_array($path, $postKeys, true) && ! in_array($path, $getKeys, true)) {
            return false;
        }

        foreach ($getKeys as $key) {
            if ($key === '') {
                if ($path === '') {
                    return true;
                }

                continue;
            }

            // Route keys are already regex fragments (e.g. `([^/]+)`).
            if (preg_match('#^' . $key . '/?$#u', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The site-relative route path of a URL, without a sub-directory prefix. */
    private static function routePath(string $url): string
    {
        $path = trim((string) (new URI($url))->getPath(), '/');

        // The appliance may be served from a sub-path; compare within the site.
        $basePath = trim((string) (new URI(base_url()))->getPath(), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $routes
     *
     * @return list<string>
     */
    private static function normalizedKeys(array $routes): array
    {
        return array_values(array_map(
            static fn ($key): string => trim((string) $key, '/'),
            array_keys($routes),
        ));
    }

    /**
     * Accept only an absolute URL on this appliance. A relative path is resolved
     * against the site root; anything pointing elsewhere is rejected.
     */
    public static function localUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $base = new URI(base_url());

        if (str_starts_with($url, (string) $base)) {
            return $url;
        }

        $uri = new URI($url);

        if ($uri->getHost() === '') {
            // Relative path (also covers a bare "vpn/wireguard").
            return rtrim((string) $base, '/') . '/' . ltrim($url, '/');
        }

        if ($uri->getHost() !== $base->getHost()) {
            return null;
        }

        // Compare ports only when both are explicit: an operator may reach the
        // appliance on a port the configured base URL does not spell out.
        if ($base->getPort() !== null && $uri->getPort() !== null && $base->getPort() !== $uri->getPort()) {
            return null;
        }

        return (string) $uri;
    }

    /** True when the URL addresses a POST-only action endpoint. */
    public static function isActionEndpoint(string $url): bool
    {
        $path = strtolower(rtrim((string) (new URI($url))->getPath(), '/'));

        foreach (self::ACTION_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** True when the URL is the sign-in page (never a post-login destination). */
    public static function isSignInPage(string $url): bool
    {
        $path = strtolower(trim((string) (new URI($url))->getPath(), '/'));

        return $path === 'auth/login' || str_ends_with($path, '/auth/login');
    }
}
