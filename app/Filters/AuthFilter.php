<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthFilter — enforces authentication on protected routes.
 *
 * Routes protected by this filter will redirect unauthenticated users to the
 * login page.  After authentication, the user is redirected back to the
 * originally requested URI.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $session = session();

        if (! $session->get('user_id')) {
            // Store the intended URL so we can redirect after login
            $session->set('redirect_url', current_url());
            return redirect()->to(base_url('auth/login'))->with('info', 'Please log in to continue.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
