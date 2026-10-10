<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Appliance Resolver Reconciler
 * =============================
 * Drives the *dynamic* half of the DNS Server Override. The interface path —
 * `Interfaces::apply()`, `Pppoe::apply()`, `VpnOpenvpn::restart()` — calls
 * `reconcile()` after a successful apply. It asks the data plane which DNS
 * servers its dynamic links (DHCP/PPP on WAN, remote OpenVPN with Pull DNS)
 * currently hold, records them in `dynamic_dns_servers` while the override is
 * enabled, and drops them when it is not, then hands back the parameters the
 * controller applies through `dns.resolver.apply`.
 *
 * The class never talks to the socket for the *apply* itself and never decides
 * success: it returns a plain plan, so the caller keeps using the existing
 * `ipcOutcome()` interpretation. That keeps the "which writer owns
 * /etc/resolv.conf" rule intact — the management plane is the single writer.
 */
class ApplianceResolver
{
    /** The data-plane method that reports assigned dynamic resolvers. */
    public const REPORT_METHOD = 'interface.dynamic_dns';

    protected ?BaseConnection $db;
    protected ?IpcClient $ipc;

    public function __construct(?BaseConnection $db = null, ?IpcClient $ipc = null)
    {
        $this->db  = $db ?? \Config\Database::connect();
        $this->ipc = $ipc ?? \Config\Services::ipc();
    }

    public function compiler(): SystemResolverCompiler
    {
        return new SystemResolverCompiler($this->db);
    }

    public function registry(): DynamicDnsRegistry
    {
        return new DynamicDnsRegistry($this->db);
    }

    /**
     * Reads the data plane's dynamic report and reconciles the registry.
     *
     * @return array{apply: array<string, mixed>, recorded: int, servers: array<int, string>, dropped: int, override: bool}
     *         `apply` is the exact `dns.resolver.apply` parameter array for the
     *         freshly persisted state.
     */
    public function reconcile(): array
    {
        $compiler = $this->compiler();
        $override = $compiler->overrideEnabled();
        $dropped  = 0;

        if (! $override) {
            // The override is off: dynamic servers are dropped, not merely
            // ignored, so nothing stale is left to be picked up if it is
            // re-enabled before the next interface apply.
            $dropped = $this->registry()->clear();
        } else {
            $response = $this->ipc->call(self::REPORT_METHOD);
            $error    = $response['error'] ?? null;

            if (! is_array($error) || $error === []) {
                $result  = $response['result'] ?? null;
                $sources = is_array($result) && is_array($result['sources'] ?? null) ? $result['sources'] : [];

                $this->registry()->record($sources);
            }
            // If the daemon could not be asked, the existing record is left
            // untouched: a transient RPC failure must not silently drop the
            // dynamic servers the links are actually using.
        }

        return [
            'apply'    => $this->applyParams(),
            'recorded' => count($this->registry()->all()),
            'servers'  => $compiler->dynamicServers(),
            'dropped'  => $dropped,
            'override' => $override,
        ];
    }

    /**
     * The `dns.resolver.apply` parameters for the current persisted state.
     *
     * @return array{config: string, behavior: string, override: bool}
     */
    public function applyParams(): array
    {
        $compiler = $this->compiler();

        return [
            'config'   => $compiler->compile(),
            'behavior' => $compiler->behavior(),
            'override' => $compiler->overrideEnabled(),
        ];
    }
}
