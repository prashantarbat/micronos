<?php

namespace App\Filters;

use App\Libraries\GatewayIdentity;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ZtnaAuthFilter — protects the Clientless Gateway portal pages.
 *
 * These routes are excluded from the global `auth` filter, because a portal
 * user signs in on the gateway's own login page, not the appliance login.
 * Unauthenticated visitors are sent to the gateway login, which returns them
 * to the page they asked for.
 */
class ZtnaAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        if (GatewayIdentity::current() !== null) {
            return null;
        }

        // Only a GET can be resumed after sign-in.
        if (strtolower($request->getMethod()) === 'get') {
            session()->set('ztna_intended', uri_string());
        }

        return redirect()->to(base_url('vpn/clientless-gateway/login'))
            ->with('info', 'Please sign in to continue.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
