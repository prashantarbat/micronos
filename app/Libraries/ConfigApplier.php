<?php

namespace App\Libraries;

/**
 * Compile-and-apply for every subsystem whose configuration the daemon loads.
 *
 * Before this class, controllers saved rows and then called RPCs such as
 * `frr.bgp.reload`, `ha.sync`, `snmp.sync_users` or `dns.hosts.reload` with no
 * payload. The daemon could not regenerate anything (it has no database
 * access), and most of those methods did not exist at all, so the change never
 * reached the data plane while the UI reported success.
 *
 * Each method compiles the subsystem's file from the database and sends it in
 * the RPC. It returns the raw IpcClient response (`['result' => …, 'error' => …]`)
 * so callers keep using `BaseController::ipcFlash()` / `ipcOutcome()` to report
 * the daemon's real outcome. A compiler exception becomes an error response,
 * never a silent success.
 */
class ConfigApplier
{
    private IpcClient $ipc;

    public function __construct(?IpcClient $ipc = null)
    {
        $this->ipc = $ipc ?? \Config\Services::ipc();
    }

    public function dns(): array
    {
        return $this->send('dns.config.reload', fn () => ['config' => (new UnboundDnsCompiler())->compile()]);
    }

    /** @param 'bgp'|'ospf'|'rip'|'isis'|'bfd' $protocol */
    public function frr(string $protocol = 'bgp'): array
    {
        $protocol = in_array($protocol, ['bgp', 'ospf', 'rip', 'isis', 'bfd'], true) ? $protocol : 'bgp';

        return $this->send("frr.{$protocol}.reload", fn () => ['config' => (new FrrCompiler())->compile()]);
    }

    public function haSync(): array
    {
        return $this->send('ha.sync', fn () => ['config' => (new ServiceConfigCompiler())->keepalived()]);
    }

    public function haFailover(string $target): array
    {
        return $this->send('ha.failover', fn () => [
            'target' => $target,
            'config' => (new ServiceConfigCompiler())->keepalived(),
        ]);
    }

    public function snmp(string $method = 'snmp.reconfigure'): array
    {
        $method = in_array($method, ['snmp.reconfigure', 'snmp.sync_traps', 'snmp.sync_users'], true) ? $method : 'snmp.reconfigure';

        return $this->send($method, fn () => ['config' => (new ServiceConfigCompiler())->snmpd()]);
    }

    public function siem(): array
    {
        return $this->send('siem.reconfigure', fn () => ['config' => (new ServiceConfigCompiler())->rsyslogSiem()]);
    }

    public function qos(): array
    {
        return $this->send('qos.apply', fn () => ['script' => (new ServiceConfigCompiler())->qos()]);
    }

    public function ids(): array
    {
        return $this->send('ids.apply', function () {
            $c = new SuricataCompiler();

            return ['files' => [
                'yaml'      => $c->compile(),
                'whitelist' => $c->compileWhitelistRules(),
                'blacklist' => $c->compileBlacklistRules(),
                'geoip'     => $c->compileGeoipRules(),
            ]];
        });
    }

    /** The first enabled server-mode OpenVPN instance drives `openvpn@server`. */
    public function openvpn(): array
    {
        $server = \Config\Database::connect()->table('openvpn_servers')
            ->where('enabled', 1)->where('mode', 'server')->get()->getRowArray();
        if (! $server) {
            // Nothing to serve: say so plainly instead of pushing an empty file.
            return ['result' => ['success' => true, 'status' => 'skipped',
                'message' => 'no enabled OpenVPN server instance — nothing to apply'], 'error' => null];
        }

        return $this->send('vpn.openvpn.sync', fn () => ['config' => (new OpenvpnCompiler())->compileServerConfig($server)]);
    }

    public function vrfs(): array
    {
        return $this->send('network.vrf.sync', function () {
            $rows = \Config\Database::connect()->table('routing_vrfs')->where('enabled', 1)->get()->getResultArray();
            $vrfs = [];
            foreach ($rows as $r) {
                $members = json_decode((string) ($r['interfaces'] ?? '[]'), true);
                if (! is_array($members)) {
                    $members = array_filter(array_map('trim', explode(',', (string) ($r['interfaces'] ?? ''))));
                }
                $vrfs[] = ['name' => (string) $r['name'], 'table_id' => (int) $r['table_id'], 'interfaces' => array_values($members)];
            }

            return ['vrfs' => $vrfs];
        });
    }

    /** Policy-based routing + multi-WAN gateway steering (one nftables + ip rule apply). */
    public function routingPolicy(string $method = 'routing.apply'): array
    {
        $method = in_array($method, ['routing.apply', 'network.pbr.sync', 'network.multiwan.sync'], true) ? $method : 'routing.apply';

        return $this->send($method, function () {
            $compiler = new NftablesCompiler();
            $policy   = $compiler->compileRoutingPolicy();

            return [
                'ruleset'   => $compiler->compile(),
                'rules'     => $policy['rules'],
                'routes'    => $policy['routes'],
                'safe_mode' => true,
            ];
        });
    }

    /** Compile and apply the appliance nftables ruleset. */
    public function firewall(): array
    {
        return $this->send('firewall.apply', fn () => [
            'ruleset' => (new NftablesCompiler())->compile(),
        ]);
    }

    /** Install a stored certificate as the Web GUI certificate. */
    public function webguiCertificate(array $cert): array
    {
        $pem = (string) ($cert['certificate'] ?? '');
        $key = (string) ($cert['private_key'] ?? '');

        return $this->send('cert.install', fn () => ['cert_pem' => $pem, 'key_pem' => $key, 'service' => 'webgui']);
    }

    public function crontab(string $crontab): array
    {
        return $this->send('system.crontab_sync', fn () => ['crontab' => $crontab]);
    }

    /** Apply OpenSSH daemon configuration (port, authentication, root login). */
    public function ssh(array $settings): array
    {
        return $this->send('system.ssh_apply', fn () => [
            'enabled'        => !empty($settings['ssh_enabled']),
            'port'           => (int) ($settings['ssh_port'] ?? 22),
            'root_login'     => !empty($settings['ssh_root_login']),
            'auth_methods'   => $settings['ssh_auth_methods'] ?? 'key,password',
            'max_auth_tries' => (int) ($settings['ssh_max_auth_tries'] ?? 3),
        ]);
    }

    /**
     * @param callable(): array<string, mixed> $build
     */
    private function send(string $method, callable $build): array
    {
        try {
            $params = $build();
        } catch (\Throwable $e) {
            return ['result' => ['success' => false, 'status' => 'error',
                'message' => 'the configuration could not be compiled: ' . $e->getMessage()], 'error' => null];
        }

        return $this->ipc->call($method, $params);
    }
}
