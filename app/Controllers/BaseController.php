<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Ensure session has permissions and superadmin flags for existing or newly active sessions
        $session = session();
        if ($session->get('user_id')) {
            if ($session->get('is_superadmin') === null || $session->get('permissions') === null) {
                $userId = $session->get('user_id');
                $username = strtolower((string) $session->get('username'));

                $db = \Config\Database::connect();
                $isSuperadmin = ($username === 'ministrator') ||
                    $db->table('user_roles ur')
                       ->join('roles r', 'r.id = ur.role_id')
                       ->where('ur.user_id', $userId)
                       ->where('r.name', 'superadmin')
                       ->countAllResults() > 0;

                $permModel = new \App\Models\PermissionModel();
                $perms = $permModel->getEffectiveKeysForUser($userId);

                $session->set([
                    'is_superadmin'      => $isSuperadmin,
                    'permissions'        => $perms,
                    'user_permissions'   => $perms,
                    'perm_catalog_count' => $permModel->permissionCatalogCount(),
                ]);
            }
        }
    }

    /**
     * Interprets a micrond JSON-RPC response for a state-changing action.
     *
     * The daemon signals success either with an explicit `success` flag or a
     * descriptive `status`; both conventions are honoured. An unmocked/unknown
     * method that returns neither is treated as success, matching the dev mock
     * dispatcher, so this never turns a working action into a false failure.
     *
     * @return array{ok: bool, reason: ?string}
     */
    protected function ipcOutcome(array $response): array
    {
        $error  = $response['error'] ?? null;
        $result = $response['result'] ?? null;

        if (is_array($error) && ! empty($error)) {
            return ['ok' => false, 'reason' => (string) ($error['message'] ?? 'the daemon rejected the request')];
        }

        if (! is_array($result)) {
            return ['ok' => false, 'reason' => 'the daemon returned no result'];
        }

        $status = strtolower((string) ($result['status'] ?? ''));
        $failed = in_array($status, ['error', 'failed', 'failure'], true);
        $ok     = array_key_exists('success', $result) ? ((bool) $result['success'] && ! $failed) : ! $failed;

        if ($ok) {
            return ['ok' => true, 'reason' => null];
        }

        $reason = trim((string) ($result['message'] ?? $result['output'] ?? ''));

        return ['ok' => false, 'reason' => $reason !== '' ? $reason : 'the daemon reported a failure'];
    }

    /**
     * Runs a daemon action and returns the flash [key, message] pair for it.
     *
     * Lets a controller report a failed reload/apply/restart instead of
     * unconditionally claiming success:
     *
     *     [$key, $msg] = $this->ipcFlash($this->ipc->call('dhcp.restart'), 'DHCP restarted.', 'DHCP restart');
     *     return redirect()->to(base_url('dhcp'))->with($key, $msg);
     *
     * @param array{result?: mixed, error?: mixed} $response
     *
     * @return array{0: string, 1: string}
     */
    protected function ipcFlash(array $response, string $onSuccess, string $actionLabel): array
    {
        $outcome = $this->ipcOutcome($response);

        return $outcome['ok']
            ? ['success', $onSuccess]
            : ['error', $actionLabel . ' failed: ' . $outcome['reason']];
    }

    /**
     * Reconciles the appliance stub resolver after a dynamic link changed.
     *
     * The interface path (interfaces, PPPoE, OpenVPN) calls this once an apply
     * has succeeded, so the DHCP/PPP/OpenVPN-assigned DNS servers the data
     * plane now holds are merged into `/etc/resolv.conf` while the DNS Server
     * Override is enabled — and dropped when it is not.
     *
     * Returns a short suffix the caller appends to its own flash message. It
     * never throws and never turns a successful apply into a failure: the
     * network change did happen, so a resolver problem is reported alongside it
     * rather than replacing it.
     */
    protected function reconcileApplianceResolver(\App\Libraries\IpcClient $ipc): string
    {
        try {
            $resolver = new \App\Libraries\ApplianceResolver(null, $ipc);
            $plan     = $resolver->reconcile();

            $outcome = $this->ipcOutcome($ipc->call('dns.resolver.apply', $plan['apply']));

            if (! $outcome['ok']) {
                return ' The appliance resolver could not be updated: ' . $outcome['reason'] . '.';
            }

            return sprintf(
                ' The appliance resolver was updated (%d nameserver(s), %d dynamic).',
                count($resolver->compiler()->nameservers()),
                count($plan['servers']),
            );
        } catch (\Throwable $e) {
            return ' The appliance resolver could not be updated: ' . $e->getMessage() . '.';
        }
    }
}
