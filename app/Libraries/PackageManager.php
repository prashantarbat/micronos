<?php

namespace App\Libraries;

use App\Plugins\CapabilitySet;
use App\Plugins\PluginManifest;
use App\Plugins\PluginRegistry;

/**
 * The management-plane side of the plugin package manager.
 *
 * This library never shells out. It resolves a plugin **id** to its manifest,
 * builds the human-readable install plan, refuses protected packages and
 * capabilities, and forwards the operation to `micrond` over the IPC bridge.
 * The daemon re-resolves the id against its own fixed catalogue, so a caller can
 * never name an arbitrary package or unit (Plugin Spec §5, PL1).
 *
 * `plan()` is deliberately local and daemon-free so the CLI and the UI can show
 * what an install would do — and why it is blocked — before anything is
 * attempted.
 */
final class PackageManager
{
    /**
     * Packages shipped in the appliance image. A plugin that requires one of
     * these is refused outright: an extension must never be able to remove or
     * replace a core dependency (Plugin Spec §5.3).
     */
    public const PROTECTED_PACKAGES = [
        'nftables', 'unbound', 'wireguard-tools', 'strongswan', 'frr', 'keepalived',
        'suricata', 'squid', 'kea-dhcp4-server', 'kea-ctrl-agent', 'chrony',
        'rsyslog', 'snmpd', 'openvpn', 'c-icap', 'clamav', 'clamav-daemon',
        'igmpproxy', 'mdns-repeater', 'iproute2', 'systemd',
    ];

    private IpcClient $ipc;

    private PluginRegistry $registry;

    private ?RepoRegistry $repoRegistry;

    public function __construct(
        ?IpcClient $ipc = null,
        ?PluginRegistry $registry = null,
        ?RepoRegistry $repoRegistry = null,
    ) {
        $this->ipc          = $ipc ?? new IpcClient();
        $this->registry     = $registry ?? new PluginRegistry();
        $this->repoRegistry = $repoRegistry;
    }

    /**
     * The curated repository registry (`requires.repos` resolution and the
     * Repositories screen).
     */
    public function repositories(): RepoRegistry
    {
        return $this->repoRegistry ??= new RepoRegistry($this->ipc);
    }

    public function registry(): PluginRegistry
    {
        return $this->registry;
    }

    /**
     * The shipped plugin catalogue, as a flat array the CLI/UI can render.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->registry->all() as $manifest) {
            $out[] = [
                'id'           => $manifest->id(),
                'name'         => $manifest->name(),
                'version'      => $manifest->version(),
                'class'        => $manifest->class(),
                'packages'     => $manifest->packages(),
                'units'        => $manifest->units(),
                'repos'        => $manifest->repos(),
                'capabilities' => $manifest->capabilities(),
            ];
        }

        return $out;
    }

    /**
     * Build the local, daemon-free install plan for a plugin.
     *
     * @return array{ok: bool, id: string, name: string, class: string,
     *               packages: list<string>, units: list<string>, repos: list<string>,
     *               capabilities: list<string>, blocked: list<string>,
     *               preset_hint: string|null, error: string|null}
     */
    public function plan(string $id): array
    {
        $manifest = $this->registry->get($id);
        if ($manifest === null) {
            return [
                'ok' => false, 'id' => $id, 'name' => '', 'class' => '',
                'packages' => [], 'units' => [], 'repos' => [], 'capabilities' => [],
                'blocked' => [], 'preset_hint' => null, 'error' => "unknown plugin: {$id}",
            ];
        }

        $blocked = [];

        foreach ($manifest->packages() as $package) {
            if (in_array($package, self::PROTECTED_PACKAGES, true)) {
                $blocked[] = "package \"{$package}\" is a protected core package";
            }
        }

        foreach (CapabilitySet::forbidden($manifest->capabilities()) as $capability) {
            $blocked[] = "capability \"{$capability}\" names a protected unit";
        }

        // `requires.repos` is the manifest naming a curated repository. A
        // plugin whose repository is not registered and enabled here cannot
        // resolve its packages, so it is blocked rather than half-installed.
        //
        // A Class C plugin is *expected* to be blocked on a fresh appliance:
        // its vendor repository ships as a preset precisely so the operator can
        // adopt it deliberately. The hint says which preset, so "blocked" is a
        // next step rather than a dead end.
        $repos = $manifest->repos();
        $hint = null;
        if ($repos !== []) {
            $missing = $this->repositories()->satisfied($repos)['missing'];
            foreach ($missing as $id) {
                $blocked[] = "repository \"{$id}\" is required by the manifest but is not registered and enabled";
            }

            $presets = array_filter(
                (new RepoPresets())->for($manifest->id()),
                static fn (array $preset): bool => in_array((string) $preset['id'], $missing, true),
            );
            if ($presets !== []) {
                $names = array_map(static fn (array $preset): string => (string) $preset['name'], $presets);
                $hint  = 'This is a Class C plugin: it installs from a third-party repository. '
                    . 'Register the ' . implode(' and ', $names) . ' preset on the Repositories page, '
                    . 'then enable and apply it.';
            }
        }

        return [
            'ok'           => $blocked === [],
            'id'           => $manifest->id(),
            'name'         => $manifest->name(),
            'class'        => $manifest->class(),
            'packages'     => $manifest->packages(),
            'units'        => $manifest->units(),
            'repos'        => $repos,
            'capabilities' => $manifest->capabilities(),
            'blocked'      => $blocked,
            'preset_hint'  => $hint,
            'error'        => null,
        ];
    }

    /**
     * Install a plugin. Grants default to the manifest's declared capabilities
     * when none are supplied; the daemon is the final authority on what may be
     * granted.
     *
     * @param list<string> $grants
     *
     * @return array<string, mixed>
     */
    public function install(string $id, array $grants = []): array
    {
        $plan = $this->plan($id);
        if (! $plan['ok']) {
            return ['success' => false, 'status' => 'blocked']
                + $plan
                + ['message' => $plan['error'] ?? implode('; ', $plan['blocked'])];
        }

        if ($grants === []) {
            $grants = $plan['capabilities'];
        }

        return $this->call('plugin.install', ['id' => $id, 'grants' => array_values($grants)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(string $id, bool $purge = false): array
    {
        return $this->call('plugin.remove', ['id' => $id, 'purge' => $purge]);
    }

    /**
     * @return array<string, mixed>
     */
    public function upgrade(string $id): array
    {
        return $this->call('plugin.upgrade', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function service(string $id, string $action): array
    {
        return $this->call('plugin.service', ['id' => $id, 'action' => $action]);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $id): array
    {
        return $this->call('plugin.status', ['id' => $id]);
    }

    /**
     * Recent journal lines for the plugin's own unit (the daemon control
     * widget's LED click-through).
     *
     * @return array<string, mixed>
     */
    public function logs(string $id, int $lines = 30): array
    {
        return $this->call('plugin.logs', ['id' => $id, 'lines' => $lines]);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        return $this->call('plugin.repo.refresh', []);
    }

    /**
     * Run an iperf3 throughput test through the plugin's own daemon surface
     * (`plugin.iperf3.run`). The daemon validates every parameter and fixes the
     * plugin id, so a caller can only choose a well-formed iperf3 argv.
     *
     * @param array<string, mixed> $params mode, target, port, duration, protocol,
     *                                     direction, streams
     *
     * @return array<string, mixed>
     */
    public function iperf3Run(array $params): array
    {
        return $this->call('plugin.iperf3.run', $params);
    }

    /**
     * NetBird overlay status (`plugin.netbird.status`).
     *
     * @return array<string, mixed>
     */
    public function netbirdStatus(): array
    {
        return $this->call('plugin.netbird.status', []);
    }

    /**
     * Connect an enrolled NetBird peer (`plugin.netbird.up`).
     *
     * @return array<string, mixed>
     */
    public function netbirdUp(string $managementUrl = ''): array
    {
        return $this->call('plugin.netbird.up', ['management_url' => $managementUrl]);
    }

    /**
     * Disconnect the NetBird peer (`plugin.netbird.down`).
     *
     * @return array<string, mixed>
     */
    public function netbirdDown(): array
    {
        return $this->call('plugin.netbird.down', []);
    }

    /**
     * Enrol the peer with a setup key (`plugin.netbird.enroll`).
     *
     * The key is a secret: it is forwarded to the daemon, which stages it as a
     * 0600 file the agent reads, and is never persisted or logged here.
     *
     * @return array<string, mixed>
     */
    public function netbirdEnroll(string $setupKey, string $managementUrl = ''): array
    {
        return $this->call('plugin.netbird.enroll', [
            'setup_key'      => $setupKey,
            'management_url' => $managementUrl,
        ]);
    }

    /**
     * ZeroTier overlay status (`plugin.zerotier.status`).
     *
     * @return array<string, mixed>
     */
    public function zerotierStatus(): array
    {
        return $this->call('plugin.zerotier.status', []);
    }

    /**
     * Join a ZeroTier network (`plugin.zerotier.join`).
     *
     * The daemon fixes the plugin id and validates the network id, so a caller
     * can only ever name a well-formed 16-character hex network.
     *
     * @return array<string, mixed>
     */
    public function zerotierJoin(string $networkId): array
    {
        return $this->call('plugin.zerotier.join', ['nwid' => $networkId]);
    }

    /**
     * Leave a ZeroTier network (`plugin.zerotier.leave`).
     *
     * @return array<string, mixed>
     */
    public function zerotierLeave(string $networkId): array
    {
        return $this->call('plugin.zerotier.leave', ['nwid' => $networkId]);
    }

    /**
     * Apply the NAT64/DNS64 configuration (`plugin.nat64.apply`).
     *
     * Every value is validated daemon-side before anything is written or any
     * command runs; this passes the operator's form through unchanged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function nat64Apply(array $params): array
    {
        return $this->call('plugin.nat64.apply', $params);
    }

    /**
     * Read-only NAT64/DNS64 state for the plugin page (`plugin.nat64.status`).
     *
     * @return array<string, mixed>
     */
    public function nat64Status(): array
    {
        return $this->call('plugin.nat64.status', []);
    }

    /**
     * Apply the darkstat analyser's settings (`plugin.darkstat.apply`).
     *
     * Every value is validated daemon-side before it is written into the
     * generated systemd drop-in; this passes the operator's form through
     * unchanged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function darkstatApply(array $params): array
    {
        return $this->call('plugin.darkstat.apply', $params);
    }

    /**
     * Read-only darkstat state for the plugin page (`plugin.darkstat.status`).
     *
     * @return array<string, mixed>
     */
    public function darkstatStatus(): array
    {
        return $this->call('plugin.darkstat.status', []);
    }

    /**
     * Apply the lightsquid reporter's settings (`plugin.lightsquid.apply`).
     *
     * Every value is validated daemon-side before the generated config file and
     * cron drop-in are written; this passes the operator's form through
     * unchanged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function lightsquidApply(array $params): array
    {
        return $this->call('plugin.lightsquid.apply', $params);
    }

    /**
     * Read-only lightsquid state for the plugin page (`plugin.lightsquid.status`).
     *
     * @return array<string, mixed>
     */
    public function lightsquidStatus(): array
    {
        return $this->call('plugin.lightsquid.status', []);
    }

    /**
     * Run the lightsquid parser now (`plugin.lightsquid.refresh`).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function lightsquidRefresh(array $params): array
    {
        return $this->call('plugin.lightsquid.refresh', $params);
    }

    /**
     * Apply the LLDP daemon's settings (`plugin.lldpd.apply`).
     *
     * Every value is validated daemon-side before the generated systemd drop-in
     * and `/etc/lldpd.conf` are written; this passes the operator's form through
     * unchanged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function lldpdApply(array $params): array
    {
        return $this->call('plugin.lldpd.apply', $params);
    }

    /**
     * Read-only lldpd state for the plugin page (`plugin.lldpd.status`).
     *
     * @return array<string, mixed>
     */
    public function lldpdStatus(): array
    {
        return $this->call('plugin.lldpd.status', []);
    }

    /**
     * Read-only neighbour table (`plugin.lldpd.neighbours`).
     *
     * `format` is `text` (the daemon's own human-readable listing, the default)
     * or `json` (one structured row per neighbour, keyed by the *local*
     * interface).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function lldpdNeighbours(array $params = []): array
    {
        return $this->call('plugin.lldpd.neighbours', $params);
    }

    /**
     * Apply (or tear down) the 6in4 tunnel (`plugin.tunnel.apply`).
     *
     * `update_key` is a secret: it is forwarded to the daemon, which stages it
     * as a 0600 file beside its own state, and it is never persisted or logged
     * here.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function tunnelApply(array $params): array
    {
        return $this->call('plugin.tunnel.apply', $params);
    }

    /**
     * Read-only tunnel state (`plugin.tunnel.status`) — never the update key.
     *
     * @return array<string, mixed>
     */
    public function tunnelStatus(): array
    {
        return $this->call('plugin.tunnel.status', []);
    }

    /**
     * Tell the broker this appliance's current IPv4 endpoint
     * (`plugin.tunnel.update`). The key stays daemon-side; the response only
     * carries the broker's dyndns2-style answer.
     *
     * @return array<string, mixed>
     */
    public function tunnelUpdate(): array
    {
        return $this->call('plugin.tunnel.update', []);
    }

    /**
     * Dispatch an RPC and flatten the JSON-RPC envelope into a result array,
     * preserving the daemon's own `success`/`status` fields when present.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $response = $this->ipc->call($method, $params);

        $error = $response['error'] ?? null;
        if (is_array($error) && ! empty($error)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => (string) ($error['message'] ?? 'the daemon rejected the request'),
            ];
        }

        $result = $response['result'] ?? null;
        if (! is_array($result)) {
            return ['success' => false, 'status' => 'error', 'message' => 'the daemon returned no result'];
        }

        return $result;
    }
}
