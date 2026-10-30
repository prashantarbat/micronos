<?php

namespace App\Libraries;

/**
 * CommandDispatcher — high-level facade over IpcClient.
 *
 * Every method here maps to an RPC the daemon actually implements
 * (`system/micrond/micrond.py`). The previous facade also offered
 * `firewall.flush`, `nat.list`, `route.table`, `dhcp.leases`, `interface.set`,
 * `backup.create` … — none of which existed in the daemon, so on an appliance
 * they could only ever fail. They were removed rather than faked; the
 * contract test (`tests/test_micrond_ops.py`) now fails the build when a
 * method string in `app/` has no daemon handler.
 *
 * The client is resolved through `Services::ipc()` so tests can inject a fake.
 */
class CommandDispatcher
{
    private IpcClient $ipc;

    public function __construct(?IpcClient $ipc = null)
    {
        $this->ipc = $ipc ?? \Config\Services::ipc();
    }

    public function isMock(): bool
    {
        return $this->ipc->isMock();
    }

    // ── System ──────────────────────────────────────────────────────────

    public function systemInfo(): array
    {
        return (array) $this->ipc->callOrFail('system.info');
    }

    /** Real /proc and /sys telemetry (uptime, CPU, memory, disks, links, conntrack). */
    public function systemHealth(): array
    {
        return (array) $this->ipc->callOrFail('system.health');
    }

    /** SQLite database integrity, WAL size, and snapshot telemetry. */
    public function databaseHealth(): array
    {
        return (array) $this->ipc->callOrFail('database.health');
    }

    /** On-demand WAL checkpointing (PASSIVE, FULL, RESTART, TRUNCATE). */
    public function databaseCheckpoint(string $mode = 'TRUNCATE'): array
    {
        return (array) $this->ipc->callOrFail('database.checkpoint', ['mode' => $mode]);
    }

    /** Block device hardware and S.M.A.R.T. health telemetry. */
    public function storageHealth(): array
    {
        return (array) $this->ipc->callOrFail('storage.health');
    }

    /** Live systemd state of every managed unit, keyed by service id. */
    public function servicesStatus(): array
    {
        $res = (array) $this->ipc->callOrFail('system.services_status');

        return (array) ($res['services'] ?? []);
    }

    public function systemReboot(): array
    {
        return (array) $this->ipc->callOrFail('system.reboot');
    }

    public function systemHalt(): array
    {
        return (array) $this->ipc->callOrFail('system.halt');
    }

    public function systemIpv6Set(bool $enabled): array
    {
        return (array) $this->ipc->callOrFail('system.ipv6.set', ['enabled' => $enabled]);
    }

    // ── Interfaces & routing ────────────────────────────────────────────

    public function interfaceList(): array
    {
        $res = (array) $this->ipc->callOrFail('interface.list');

        return (array) ($res['interfaces'] ?? $res);
    }

    public function routingTable(): array
    {
        $res = (array) $this->ipc->callOrFail('diag.routing_table');

        return (array) ($res['routes'] ?? []);
    }

    // ── Firewall ────────────────────────────────────────────────────────

    /** Live nftables rules with hit counters. */
    public function firewallListRules(): array
    {
        $res = (array) $this->ipc->callOrFail('firewall.list_rules');

        return (array) ($res['rules'] ?? []);
    }

    public function firewallStatus(): array
    {
        return (array) $this->ipc->callOrFail('firewall.status');
    }

    public function conntrackStates(int $limit = 50): array
    {
        return (array) $this->ipc->callOrFail('diag.conntrack_states', ['limit' => $limit]);
    }

    public function firewallLogs(int $limit = 20): array
    {
        return (array) $this->ipc->callOrFail('diag.firewall_logs', ['limit' => $limit]);
    }

    // ── VPN ─────────────────────────────────────────────────────────────

    public function wireguardStatus(string $interface = 'wg0'): array
    {
        return (array) $this->ipc->callOrFail('vpn.wireguard.status', ['interface' => $interface]);
    }

    public function openvpnStatus(): array
    {
        return (array) $this->ipc->callOrFail('vpn.openvpn.status');
    }

    public function ipsecStatus(): array
    {
        return (array) $this->ipc->callOrFail('vpn.ipsec.status');
    }

    // ── Services ────────────────────────────────────────────────────────

    public function dhcpStatus(): array
    {
        return (array) $this->ipc->callOrFail('dhcp.status');
    }

    public function dnsStatus(): array
    {
        return (array) $this->ipc->callOrFail('dns.status');
    }

    public function dnsFlushCache(): array
    {
        return (array) $this->ipc->callOrFail('dns.cache.flush');
    }

    public function squidStatus(): array
    {
        return (array) $this->ipc->callOrFail('squid.status');
    }

    public function squidReload(): array
    {
        return (array) $this->ipc->callOrFail('squid.reload');
    }

    public function sdwanProbeStatus(): array
    {
        return (array) $this->ipc->callOrFail('sdwan.probe_status');
    }
}
