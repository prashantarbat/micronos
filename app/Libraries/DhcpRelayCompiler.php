<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS DHCP Relay Compiler
 * ============================
 *
 * Turns the `dhcp_relay_destinations` / `dhcp_relay_interfaces` tables into the
 * `dhcp-helper` invocation the data plane runs. `dhcp-helper` forwards DHCP/
 * BOOTP broadcasts from the interfaces it listens on to a shared server list,
 * so relays are grouped per destination: each destination becomes one
 * `dhcp-helper@<slug>` instance listening on that destination's interfaces and
 * forwarding to its servers.
 *
 * Compilation is pure and daemon-free, so the page can show the plan and the
 * conflicts before anything is applied. One conflict is fatal: a relay binds
 * UDP/67 exactly like the DHCP server, so an interface may not run both.
 *
 * `dhcp-helper` does not implement DHCP option 82, so the model intentionally
 * carries no agent-information flag (use an agent that supports it if that is
 * required).
 */
class DhcpRelayCompiler
{
    private ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * @return array{
     *   enabled: bool,
     *   instances: list<array{name: string, slug: string, interfaces: list<string>,
     *                          servers: list<string>, unit: string, args: list<string>}>,
     *   conflicts: list<string>,
     *   warnings: list<string>
     * }
     */
    public function compile(): array
    {
        $destinations = $this->destinations();
        $serverIfaces = $this->dhcpServerInterfaces();
        $instances    = [];
        $conflicts    = [];
        $warnings     = [];

        // relays grouped by destination id
        $grouped = [];
        foreach ($this->relays() as $relay) {
            if (empty($relay['enabled'])) {
                continue;
            }

            $iface = (string) $relay['interface_id'];
            $destId = (string) ($relay['destination_id'] ?? '');

            if ($destId === '' || ! isset($destinations[$destId])) {
                $warnings[] = "Interface \"{$iface}\" is relayed but has no destination; it was skipped.";
                continue;
            }

            if (in_array($iface, $serverIfaces, true)) {
                $conflicts[] = "Interface \"{$iface}\" runs the DHCP server and a relay; a relay and the server cannot share UDP/67.";
                continue;
            }

            $grouped[$destId][] = $iface;
        }

        foreach ($grouped as $destId => $interfaces) {
            $dest    = $destinations[$destId];
            $servers = $this->validServers((string) $dest['servers']);

            if ($servers === []) {
                $warnings[] = "Destination \"{$dest['name']}\" has no valid server address; its relays were skipped.";
                continue;
            }

            $slug = $this->slug((string) $dest['name'], $destId);
            $interfaces = array_values(array_unique($interfaces));
            sort($interfaces);

            $instances[] = [
                'name'       => (string) $dest['name'],
                'slug'       => $slug,
                'interfaces' => $interfaces,
                'servers'    => $servers,
                'unit'       => 'dhcp-helper@' . $slug . '.service',
                'args'       => self::entryArgs($slug, $interfaces, $servers),
            ];
        }

        return [
            'enabled'   => $instances !== [],
            'instances' => $instances,
            'conflicts' => $conflicts,
            'warnings'  => $warnings,
        ];
    }

    /**
     * The exact argv a `dhcp-helper` instance should run.
     *
     * @param list<string> $interfaces
     * @param list<string> $servers
     *
     * @return list<string>
     */
    public static function entryArgs(string $slug, array $interfaces, array $servers): array
    {
        $args = ['/usr/sbin/dhcp-helper'];
        foreach ($interfaces as $iface) {
            $args[] = '-i';
            $args[] = $iface;
        }
        foreach ($servers as $server) {
            $args[] = '-s';
            $args[] = $server;
        }
        $args[] = '-r';
        $args[] = '/var/run/dhcp-helper-' . $slug . '.pid';

        return $args;
    }

    /**
     * @return array<string, array<string, mixed>> destination id => row
     */
    private function destinations(): array
    {
        if (! $this->db->tableExists('dhcp_relay_destinations')) {
            return [];
        }

        $out = [];
        foreach ($this->db->table('dhcp_relay_destinations')->get()->getResultArray() as $row) {
            $out[(string) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relays(): array
    {
        if (! $this->db->tableExists('dhcp_relay_interfaces')) {
            return [];
        }

        return $this->db->table('dhcp_relay_interfaces')->get()->getResultArray();
    }

    /**
     * @return list<string> interfaces with an enabled DHCP server
     */
    private function dhcpServerInterfaces(): array
    {
        if (! $this->db->tableExists('dhcp_server_settings')) {
            return [];
        }

        $rows = $this->db->table('dhcp_server_settings')->where('enabled', 1)->get()->getResultArray();

        return array_values(array_filter(array_map(
            static fn ($r) => (string) ($r['interface_id'] ?? ''),
            $rows,
        ), static fn ($v) => $v !== ''));
    }

    /**
     * @return list<string>
     */
    private function validServers(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $list) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $out[] = $candidate;
            }
        }

        return array_values(array_unique($out));
    }

    private function slug(string $name, string $fallbackId): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 24);

        return $slug !== '' ? $slug : 'dest-' . substr(preg_replace('/[^a-z0-9]/', '', $fallbackId) ?? '', 0, 6);
    }
}
