<?php

namespace App\Filters;

use App\Libraries\DefaultCredentials;
use App\Libraries\Eula;
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

        // The licence agreement comes first: until an administrator accepts
        // the current version, only the agreement page is open.
        if (Eula::enforced() && $session->get(Eula::SESSION_FLAG) !== Eula::VERSION) {
            if (Eula::isAccepted((new \App\Models\SystemSettingsModel())->getSettings())) {
                $session->set(Eula::SESSION_FLAG, Eula::VERSION);
            } elseif (! Eula::isOpenWhilePending(uri_string())) {
                IntendedUrl::remember($request, $session);

                return redirect()->to(base_url('eula'));
            }
        }

        // Signed in with the factory password: only the password change is open.
        if ($session->get(DefaultCredentials::SESSION_FLAG)
            && ! DefaultCredentials::isAllowedWhileForced(uri_string())) {
            return redirect()->to(base_url('profile'))->with('warning',
                'Change the factory default password before using the appliance.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
