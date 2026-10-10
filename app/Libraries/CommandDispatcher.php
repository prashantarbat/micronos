<?php

namespace App\Libraries;

/**
 * CommandDispatcher — High-level facade over IpcClient.
 *
 * Each public method corresponds to a domain action that the web UI needs.
 * Controllers call this class rather than IpcClient directly, so the RPC
 * method naming convention is isolated in one place.
 */
class CommandDispatcher
{
    private IpcClient $ipc;

    public function __construct()
    {
        $this->ipc = new IpcClient();
    }

    // =========================================================================
    // System
    // =========================================================================

    public function systemInfo(): array
    {
        return $this->ipc->callOrFail('system.info');
    }

    public function systemReboot(int $delaySec = 3): array
    {
        return $this->ipc->callOrFail('system.reboot', ['delay_sec' => $delaySec]);
    }

    public function systemHalt(int $delaySec = 3): array
    {
        return $this->ipc->callOrFail('system.halt', ['delay_sec' => $delaySec]);
    }

    /**
     * Enable or fully disable the IPv6 stack appliance-wide.
     * Disabling sets net.ipv6.conf.{all,default}.disable_ipv6=1, removes IPv6
     * addresses/routes, stops services listening on :: and drops IPv6 traffic.
     */
    public function systemIpv6Set(bool $enabled): array
    {
        return $this->ipc->callOrFail('system.ipv6.set', ['enabled' => $enabled]);
    }

    // =========================================================================
    // Interfaces
    // =========================================================================

    public function interfaceList(): array
    {
        $res = $this->ipc->callOrFail('interface.list');
        return $res['interfaces'] ?? [];
    }

    public function interfaceSet(array $config): array
    {
        return $this->ipc->callOrFail('interface.set', $config);
    }

    public function interfaceStatus(string $device): array
    {
        return $this->ipc->callOrFail('interface.status', ['device' => $device]);
    }

    // =========================================================================
    // Firewall
    // =========================================================================

    public function firewallListRules(): array
    {
        $res = $this->ipc->callOrFail('firewall.list_rules');
        return $res['rules'] ?? [];
    }

    public function firewallApplyRules(): array
    {
        return $this->ipc->callOrFail('firewall.apply_rules');
    }

    public function firewallFlush(): array
    {
        return $this->ipc->callOrFail('firewall.flush');
    }

    // =========================================================================
    // NAT
    // =========================================================================

    public function natList(): array
    {
        $res = $this->ipc->callOrFail('nat.list');
        return $res['rules'] ?? [];
    }

    public function natApply(array $rules): array
    {
        return $this->ipc->callOrFail('nat.apply', ['rules' => $rules]);
    }

    // =========================================================================
    // Routing
    // =========================================================================

    public function routeTable(): array
    {
        $res = $this->ipc->callOrFail('route.table');
        return $res['routes'] ?? [];
    }

    public function routeAdd(string $destination, string $gateway, string $iface, int $metric = 10): array
    {
        return $this->ipc->callOrFail('route.add', compact('destination', 'gateway', 'iface', 'metric'));
    }

    public function routeDelete(string $destination): array
    {
        return $this->ipc->callOrFail('route.del', ['destination' => $destination]);
    }

    // =========================================================================
    // VPN — WireGuard
    // =========================================================================

    public function wireguardStatus(): array
    {
        return $this->ipc->callOrFail('vpn.wireguard.status');
    }

    public function wireguardPeers(): array
    {
        $res = $this->ipc->callOrFail('vpn.wireguard.peers');
        return $res['peers'] ?? [];
    }

    // =========================================================================
    // VPN — OpenVPN
    // =========================================================================

    public function openvpnStatus(): array
    {
        return $this->ipc->callOrFail('vpn.openvpn.status');
    }

    // =========================================================================
    // DHCP
    // =========================================================================

    public function dhcpLeases(): array
    {
        $res = $this->ipc->callOrFail('dhcp.leases');
        return $res['leases'] ?? [];
    }

    public function dhcpRestart(): array
    {
        return $this->ipc->callOrFail('dhcp.restart');
    }

    // =========================================================================
    // DNS
    // =========================================================================

    public function dnsStatus(): array
    {
        return $this->ipc->callOrFail('dns.status');
    }

    public function dnsFlushCache(): array
    {
        return $this->ipc->callOrFail('dns.flush');
    }

    // =========================================================================
    // Squid / Web Filter
    // =========================================================================

    public function squidStatus(): array
    {
        return $this->ipc->callOrFail('squid.status');
    }

    public function squidReload(): array
    {
        return $this->ipc->callOrFail('squid.reload');
    }

    // =========================================================================
    // Backup
    // =========================================================================

    public function backupCreate(bool $encrypt = true, ?string $passphrase = null): array
    {
        return $this->ipc->callOrFail('backup.create', [
            'encrypt'    => $encrypt,
            'passphrase' => $passphrase,
        ]);
    }

    public function backupRestore(string $filename, ?string $passphrase = null): array
    {
        return $this->ipc->callOrFail('backup.restore', [
            'filename'   => $filename,
            'passphrase' => $passphrase,
        ]);
    }
}
