<?php

namespace App\Filters;

use App\Libraries\IntendedUrl;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthFilter — enforces authentication on protected routes.
 *
 * Routes protected by this filter will redirect unauthenticated users to the
 * login page.  After authentication, the user is redirected back to the
 * originally requested URI.
 *
 * Only destinations a GET can render are remembered (see App\Libraries\IntendedUrl).
 * Remembering a POST endpoint — which is what happened when an expired session
 * submitted a form — sent the browser back to that address with a GET after
 * login, where no route exists for it:
 *
 *     Can't find a route for 'GET: vpn/wireguard/tunnels/store'
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $session = session();

        if (! $session->get('user_id')) {
            // Remember where to resume, never a POST-only address.
            IntendedUrl::remember($request, $session);
            return redirect()->to(base_url('auth/login'))->with('info', 'Please log in to continue.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
