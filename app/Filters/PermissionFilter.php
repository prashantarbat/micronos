<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PermissionModel;

/**
 * PermissionFilter — enforces a specific permission key on a route.
 *
 * Usage in Routes.php:
 *   $routes->get('/firewall/rules', 'Firewall::index', ['filter' => 'permission:firewall.rules.view']);
 *   $routes->post('/firewall/rules', 'Firewall::store', ['filter' => 'permission:firewall.rules.edit']);
 *
 * The filter checks whether the currently logged-in user holds the required
 * permission key (resolved from all their assigned roles).
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $session = session();
        $userId  = $session->get('user_id');

        // Not logged in — defer to AuthFilter redirect
        if (! $userId) {
            return redirect()->to(base_url('auth/login'));
        }

        // No permission argument specified — pass through
        if (empty($arguments)) {
            return null;
        }

        $requiredPerm   = $arguments[0];
        $permModel      = new PermissionModel();

        if (! $permModel->userCan($userId, $requiredPerm)) {
            // AJAX / API request → return JSON 403
            if ($request->isAJAX() || str_starts_with($request->getHeaderLine('Accept'), 'application/json')) {
                return service('response')
                    ->setStatusCode(403)
                    ->setJSON(['error' => 'Forbidden', 'permission_required' => $requiredPerm]);
            }

            // Prevent infinite redirect loop if already on dashboard or root
            $currentPath = trim($request->getUri()->getPath(), '/');
            if ($currentPath === 'dashboard' || $currentPath === '') {
                return service('response')
                    ->setStatusCode(403)
                    ->setBody('<h1>403 Forbidden</h1><p>Access denied. Required permission: <code>' . esc($requiredPerm) . '</code></p><p><a href="' . base_url('auth/logout') . '">Sign Out</a></p>');
            }

            // Web request → flash message + redirect to dashboard
            return redirect()
                ->to(base_url('dashboard'))
                ->with('error', "Access denied. Required permission: <code>{$requiredPerm}</code>");
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
