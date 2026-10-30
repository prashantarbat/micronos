<?php

namespace App\Libraries;

/**
 * Real appliance telemetry for the dashboard, shaped the way the dashboard
 * view already renders it.
 *
 * Source of truth is the daemon (`system.health`, `interface.list`,
 * `system.services_status`, `diag.firewall_logs`, `diag.conntrack_states`),
 * which reads /proc and /sys at call time. Throughput is derived from byte
 * counters between two samples (the previous sample is kept in the cache), so
 * the first view after a restart shows 0 rather than a guess. Any value the
 * kernel does not expose is null and the view prints "—".
 *
 * Returns null from snapshot() when the daemon does not answer: the
 * controller then shows an explicit "telemetry unavailable" state, never
 * sample numbers.
 */
final class LiveTelemetry
{
    private const CACHE_KEY = 'micron_live_iface_counters';

    public function __construct(private IpcClient $ipc)
    {
    }

    /** @return array<string, mixed>|null */
    public function snapshot(): ?array
    {
        $health = $this->read('system.health');
        if ($health === null) {
            return null;
        }

        $cores   = max(1, (int) ($health['cpu_count'] ?? 1));
        $load    = array_map('floatval', (array) ($health['load_avg'] ?? [0, 0, 0])) + [0.0, 0.0, 0.0];
        $memT    = (int) ($health['mem_total_mb'] ?? 0);
        $memU    = (int) ($health['mem_used_mb'] ?? 0);
        $root    = ((array) ($health['disks'] ?? []))[0] ?? null;
        $ctCount = $health['conntrack_count'] ?? null;
        $ctMax   = $health['conntrack_max'] ?? null;

        return [
            'uptime_sec'   => (int) ($health['uptime_sec'] ?? 0),
            'load'         => array_slice($load, 0, 3),
            'cpu_cores'    => $cores,
            'cpu_pct'      => (int) min(100, round($load[0] / $cores * 100)),
            'cpu_model'    => $health['cpu_model'] ?? null,
            'mem_total'    => $memT,
            'mem_used'     => $memU,
            'mem_pct'      => $memT > 0 ? (int) round($memU / $memT * 100) : 0,
            'swap_total'   => (int) ($health['swap_total_mb'] ?? 0),
            'swap_used'    => (int) ($health['swap_used_mb'] ?? 0),
            'disk_total'   => $root ? (float) $root['total_gb'] : 0.0,
            'disk_used'    => $root ? (float) $root['used_gb'] : 0.0,
            'disk_pct'     => $root ? (int) round((float) $root['used_pct']) : 0,
            'states'       => $ctCount !== null ? (int) $ctCount : null,
            'states_max'   => $ctMax !== null ? (int) $ctMax : null,
            'temperature'  => $health['temperature_c'] ?? null,
            'interfaces'   => $this->interfaces((array) ($health['interfaces'] ?? [])),
            'storage'      => (array) ($health['storage'] ?? []),
            'database'     => (array) ($health['database'] ?? []),
        ];
    }

    /**
     * Interfaces in the dashboard's port shape, from /sys counters plus the
     * configured name/zone/address in the database.
     *
     * @return list<array<string, mixed>>
     */
    private function interfaces(array $sysIfaces): array
    {
        $db = [];
        try {
            foreach (\Config\Database::connect()->table('interfaces')->get()->getResultArray() as $row) {
                if (! empty($row['physical_device'])) {
                    $db[(string) $row['physical_device']] = $row;
                }
            }
        } catch (\Throwable) {
        }

        $addrs = [];
        $list  = $this->read('interface.list');
        foreach ((array) ($list['interfaces'] ?? []) as $link) {
            foreach ((array) ($link['addr_info'] ?? []) as $a) {
                if (($a['family'] ?? '') === 'inet' && ! isset($addrs[$link['ifname'] ?? ''])) {
                    $addrs[(string) $link['ifname']] = [(string) $a['local'], (int) $a['prefixlen']];
                }
            }
        }

        $now   = microtime(true);
        $cache = cache();
        $prev  = $cache ? (array) ($cache->get(self::CACHE_KEY) ?? []) : [];
        $next  = [];
        $ports = [];
        $num   = 0;

        foreach ($sysIfaces as $i) {
            $dev = (string) ($i['name'] ?? '');
            if ($dev === '' || $dev === 'lo') {
                continue;
            }
            $row  = $db[$dev] ?? [];
            $rx   = $i['rx_bytes'] ?? null;
            $tx   = $i['tx_bytes'] ?? null;
            $next[$dev] = ['t' => $now, 'rx' => $rx, 'tx' => $tx];
            $rate = static function (?int $cur, string $k) use ($prev, $dev, $now): float {
                $p = $prev[$dev] ?? null;
                if ($cur === null || ! is_array($p) || $p[$k] === null || $now <= $p['t'] || $cur < $p[$k]) {
                    return 0.0;
                }

                return round(($cur - $p[$k]) * 8 / ($now - $p['t']) / 1e6, 2);
            };
            $up       = ($i['operstate'] ?? '') === 'up' || (($i['operstate'] ?? '') === 'unknown' && ! empty($i['carrier']));
            $name     = (string) ($row['name'] ?? $dev);
            $id       = (string) ($row['id'] ?? $dev);
            $isWan    = strtolower($id) === 'wan' || str_starts_with(strtolower($id), 'wan') || stripos($name, 'wan') !== false;
            $kind     = (string) ($i['kind'] ?? (! empty($i['virtual']) ? 'virtual' : 'ethernet'));
            $wireless = ! empty($i['wireless']) || $kind === 'wireless';
            $physical = in_array($kind, ['ethernet', 'wireless'], true) && empty($i['virtual']);
            $portType = (string) ($i['port_type'] ?? 'unknown');
            $isFibre  = in_array($portType, ['fibre', 'da'], true);
            if ($isWan && $physical) {
                $bay = 'wan';
            } elseif (! $physical) {
                $bay = 'virtual';
            } elseif ($wireless) {
                $bay = 'wireless';
            } elseif ($isFibre) {
                $bay = 'sfp';
            } else {
                $bay = 'lan';
            }
            [$ip, $mask] = $addrs[$dev] ?? ['—', 0];
            $port = [
                'id'         => strtoupper($id),
                'name'       => $name,
                'device'     => $dev,
                'status'     => $up ? 'up' : 'down',
                'carrier'    => ! empty($i['carrier']) ? 1 : 0,
                'ip'         => $ip,
                'mask'       => $mask,
                'ipv6'       => '—',
                'mac'        => (string) ($i['mac'] ?? '—'),
                'speed'      => $i['speed_mbps'] !== null ? (string) $i['speed_mbps'] : '—',
                'media'      => $i['speed_mbps'] !== null ? $i['speed_mbps'] . ' Mb/s' : (! empty($i['virtual']) ? 'virtual' : 'unknown'),
                'duplex'     => '—',
                'mtu'        => (int) ($i['mtu'] ?? 0),
                'rx_mbps'    => $rate($rx, 'rx'),
                'tx_mbps'    => $rate($tx, 'tx'),
                'rx_packets' => $i['rx_packets'] !== null ? number_format((int) $i['rx_packets']) : '—',
                'tx_packets' => $i['tx_packets'] !== null ? number_format((int) $i['tx_packets']) : '—',
                'rx_pps'     => 0,
                'tx_pps'     => 0,
                'in_bytes'   => self::bytes($rx),
                'out_bytes'  => self::bytes($tx),
                'errors'     => (int) ($i['rx_errors'] ?? 0) + (int) ($i['tx_errors'] ?? 0),
                'drops'      => 0,
                'zone'       => $isWan ? 'WAN' : ($physical ? ($wireless ? 'WLAN' : (isset($db[$dev]) ? strtoupper($id) : 'UNASSIGNED')) : strtoupper($kind)),
                'bay'        => $bay,
                'kind'       => $kind,
                'port_type'  => $wireless ? 'wireless' : ($physical ? $portType : 'virtual'),
                'wireless'   => $wireless,
                'driver'     => (string) ($i['driver'] ?? ''),
                'slot'       => (string) ($i['slot'] ?? ''),
                'virtual'    => ! $physical,
                'assigned'   => isset($db[$dev]),
            ];
            if ($physical) {
                $port['port_num'] = ++$num;
            }
            $ports[] = $port;
        }
        if ($cache) {
            $cache->save(self::CACHE_KEY, $next, 3600);
        }

        // WAN first, so the faceplate's primary socket is the uplink; then the
        // ports in kernel (bus) order, which is the order they sit on the panel.
        usort($ports, static function ($a, $b) {
            $w = ($b['bay'] === 'wan') <=> ($a['bay'] === 'wan');

            return $w !== 0 ? $w : strnatcmp((string) $a['device'], (string) $b['device']);
        });
        $num = 0;
        foreach ($ports as &$p) {
            if (! $p['virtual']) {
                $p['port_num'] = ++$num;
            }
        }
        unset($p);

        return $ports;
    }

    /**
     * Managed services in the dashboard's card shape (installed units only).
     *
     * @return list<array{name: string, title: string, desc: string, status: string, can_restart: bool}>|null
     */
    public function services(): ?array
    {
        $r = $this->read('system.services_status');
        if ($r === null) {
            return null;
        }
        $titles = ['nftables' => 'Firewall Engine', 'kea-dhcp4' => 'DHCP Server', 'unbound' => 'DNS Resolver',
            'wireguard' => 'WireGuard VPN', 'openvpn' => 'OpenVPN Server', 'frr' => 'Dynamic Routing (FRR)',
            'squid' => 'Web Proxy', 'keepalived' => 'High Availability (VRRP)', 'chrony' => 'NTP Time Daemon',
            'suricata' => 'IDS / IPS', 'strongswan' => 'IPsec VPN', 'snmpd' => 'SNMP Agent', 'rsyslog' => 'System Logging'];
        $out = [];
        foreach ((array) ($r['services'] ?? []) as $id => $s) {
            if (empty($s['installed'])) {
                continue;
            }
            $out[] = [
                'name'        => (string) $id,
                'title'       => $titles[$id] ?? (string) $id,
                'desc'        => 'systemd unit ' . ($s['unit'] ?? $id) . (! empty($s['since']) ? ' · since ' . $s['since'] : ''),
                'status'      => (string) ($s['state'] ?? 'stopped'),
                'can_restart' => true,
            ];
        }

        return $out;
    }

    /** @return list<array<string, string>>|null */
    public function firewallLog(int $limit = 6): ?array
    {
        $r = $this->read('diag.firewall_logs', ['limit' => $limit]);
        if ($r === null) {
            return null;
        }
        $out = [];
        foreach (array_slice((array) ($r['events'] ?? []), 0, $limit) as $e) {
            $out[] = [
                'time'   => (string) ($e['time'] ?? ''),
                'action' => strtolower((string) ($e['action'] ?? '')) === 'block' ? 'block' : 'pass',
                'iface'  => strtoupper((string) ($e['iface'] ?? '')),
                'proto'  => strtoupper((string) ($e['proto'] ?? '')),
                'src'    => trim(($e['src'] ?? '') . (! empty($e['sport']) ? ':' . $e['sport'] : '')),
                'dst'    => trim(($e['dst'] ?? '') . (! empty($e['dport']) ? ':' . $e['dport'] : '')),
                'desc'   => (string) ($e['rule'] ?? $e['prefix'] ?? ''),
            ];
        }

        return $out;
    }

    private function read(string $method, array $params = []): ?array
    {
        $resp   = $this->ipc->call($method, $params);
        $result = $resp['result'] ?? null;
        if (! empty($resp['error']) || ! is_array($result) || isset($result['_mock'])
            || ($result['success'] ?? true) === false) {
            return null;
        }

        return $result;
    }

    public static function bytes(?int $n): string
    {
        if ($n === null) {
            return '—';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $f = (float) $n;
        while ($f >= 1024 && $i < 4) {
            $f /= 1024;
            $i++;
        }

        return round($f, 1) . ' ' . $u[$i];
    }
}
