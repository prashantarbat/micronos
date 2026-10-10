<?php

namespace App\Controllers;

/**
 * Landing controller.
 *
 * The appliance has no public marketing page: an unauthenticated visitor
 * belongs at the sign-in screen, and an authenticated one at the dashboard.
 * (This replaced the framework's starter welcome view.)
 */
class Home extends BaseController
{
    public function index(): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url(session()->get('user_id') ? 'dashboard' : 'auth/login'));
    }
}
