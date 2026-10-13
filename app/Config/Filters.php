<?php

namespace Config;

use App\Filters\AuthFilter;
use App\Filters\PermissionFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        // MicronOS custom filters
        'auth'          => AuthFilter::class,
        'permission'    => PermissionFilter::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            // 'forcehttps', // Disabled in development — enable in production on the appliance
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            'toolbar',     // Debug Toolbar
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            'csrf' => ['except' => [
                'wpad.dat', 'wpad.da', 'proxy.pac', 'wpad.pac',
                'auth/sso/saml/acs*',
                'portal/*',
                'hotspot-detect.html', 'canonical.html',
                'generate_204', 'gen_204',
                'connecttest.txt', 'success.txt', 'ncsi.txt',
            ]],
            // Auth filter applied globally — excludes public auth, guest portal, CNA probes, and WPAD discovery routes.
            //
            // `.well-known/acme-challenge/*` is here for the same reason WPAD is:
            // the client that fetches it has no session and never will. An ACME CA
            // validating HTTP-01 makes a plain unauthenticated GET on port 80, so
            // leaving it behind the login would make every HTTP-01 order fail with
            // a redirect the CA cannot follow. The route answers only the key
            // authorization for a *pending* order token (RFC 8555 §8.3) — a value
            // the CA already knows — and nothing else, so the exemption exposes no
            // data and no capability.
            'auth' => ['except' => [
                '/',
                'auth/*', 'auth/login', 'auth/logout',
                'vpn/clientless-gateway/verify-auth*',
                'portal', 'portal/*',
                'hotspot-detect.html', 'canonical.html',
                'generate_204', 'gen_204',
                'connecttest.txt', 'success.txt', 'ncsi.txt',
                'wpad.dat', 'wpad.da', 'proxy.pac', 'wpad.pac',
                '.well-known/acme-challenge/*',
            ]],
        ],
        'after' => [
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}
