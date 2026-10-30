<?php

namespace App\Libraries\Network;

use CodeIgniter\Database\BaseConnection;

/**
 * Keeps every setting that depends on an interface's address in step with it.
 *
 * When an interface's IPv4 address, prefix or gateway changes, the settings
 * that were *derived* from the old values are updated (DHCP pool, router and
 * DNS options, gateway monitoring, local DNS records that name the firewall),
 * and policies that spell the old address out literally are either rewritten
 * to a dynamic reference that follows the interface from now on —
 * `{lan:network}`, `{wan:address}` — or, when that is not wanted, listed for
 * the administrator. References whose intent cannot be inferred (an internal
 * host inside the old subnet, a DHCP reservation, an HA virtual IP) are never
 * rewritten; they are reported for review.
 *
 * Runs inside the caller's database transaction, so it is committed only if
 * the OS accepted the change (see NetworkTransaction).
 */
final class AddressChangeSync
{
    /** @var list<array{what: string, from: string, to: string}> */
    private array $updated = [];

    /** @var list<array{what: string, value: string, reason: string}> */
    private array $review = [];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    /**
     * @param array<string, mixed> $before interface row before the change
     * @param array<string, mixed> $after  interface row after the change
     *
     * @return array{updated: list<array<string, string>>, review: list<array<string, string>>}
     */
    public function sync(array $before, array $after, bool $updateReferences = true): array
    {
        $this->updated = [];
        $this->review  = [];

        $id   = strtolower((string) ($after['id'] ?? $before['id'] ?? ''));
        $old  = $this->v4($before);
        $new  = $this->v4($after);
        $oldGw = ($before['ip_assignment_type'] ?? '') === 'static' ? trim((string) ($before['ipv4_gateway'] ?? '')) : '';
        $newGw = ($after['ip_assignment_type'] ?? '') === 'static' ? trim((string) ($after['ipv4_gateway'] ?? '')) : '';

        if ($old !== null && ($new === null || $old !== $new)) {
            [$oNet, $oP, $oIp] = $old;
            $this->dhcp($id, $old, $new);
            $this->dnsHosts($oIp, $new[2] ?? null);
            $this->literalReferences($id, $oNet, $oP, $oIp, $updateReferences);
            $this->reviewInsideOldSubnet($id, $oNet, $oP, $oIp, $new);
        }
        if ($oldGw !== '' && $oldGw !== $newGw) {
            $this->gateways($id, (string) ($after['physical_device'] ?? ''), $oldGw, $newGw);
        }

        return ['updated' => $this->updated, 'review' => $this->review];
    }

    /** [network, prefix, address] of a static row, else null. */
    private function v4(array $row): ?array
    {
        if (($row['ip_assignment_type'] ?? '') !== 'static' || empty($row['ipv4_address'])) {
            return null;
        }

        return NetworkPlan::cidr($row['ipv4_address'] . '/' . NetworkPlan::prefix($row['ipv4_netmask'] ?? 24));
    }

    private function has(string $table): bool
    {
        try {
            return $this->db->tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function note(string $what, string $from, string $to): void
    {
        $this->updated[] = ['what' => $what, 'from' => $from, 'to' => $to];
    }

    private function flag(string $what, string $value, string $reason): void
    {
        $this->review[] = ['what' => $what, 'value' => $value, 'reason' => $reason];
    }

    /** Re-base an address from the old subnet into the new one (same host part), or null. */
    private static function rebase(string $ip, array $old, array $new): ?string
    {
        [$oNet, $oP] = $old;
        [$nNet, $nP] = $new;
        if (! NetworkPlan::inSubnet($ip, $oNet, $oP)) {
            return null;
        }
        $host  = ip2long($ip) - ip2long($oNet);
        $size  = 1 << (32 - $nP);
        if ($host <= 0 || $host >= $size - 1) {
            return null;
        }

        return long2ip(ip2long($nNet) + $host);
    }

    private function dhcp(string $id, array $old, ?array $new): void
    {
        if (! $this->has('dhcp_server_settings')) {
            return;
        }
        $row = $this->db->table('dhcp_server_settings')->where('interface_id', $id)->get()->getRowArray();
        if (! $row) {
            return;
        }
        if ($new === null) {
            if (! empty($row['enabled'])) {
                $this->flag('DHCP server on ' . strtoupper($id), 'enabled', 'the interface no longer has a static address, so it cannot serve DHCP');
            }

            return;
        }
        [$nNet, $nP, $nIp] = $new;
        $set = [];
        foreach (['gateway', 'dns_1', 'dns_2', 'dns_3', 'dns_4'] as $col) {
            if (($row[$col] ?? '') === $old[2]) {
                $set[$col] = $nIp;
            }
        }
        $from = (string) ($row['range_from'] ?? '');
        $to   = (string) ($row['range_to'] ?? '');
        $rFrom = $from !== '' ? self::rebase($from, $old, $new) : null;
        $rTo   = $to !== '' ? self::rebase($to, $old, $new) : null;
        $fits  = $rFrom !== null && $rTo !== null && ip2long($rFrom) <= ip2long($rTo)
            && ! (ip2long($nIp) >= ip2long($rFrom) && ip2long($nIp) <= ip2long($rTo));
        if (($from !== '' && ! NetworkPlan::inSubnet($from, $nNet, $nP)) || ($to !== '' && ! NetworkPlan::inSubnet($to, $nNet, $nP))
            || ($from !== '' && ip2long($nIp) >= ip2long($from) && ip2long($nIp) <= ip2long($to))) {
            if (! $fits) {
                // Same offsets do not fit the new subnet: the upper part of it.
                $hosts = (1 << (32 - $nP)) - 2;
                $base  = ip2long($nNet);
                $start = $hosts >= 254 ? 100 : max(2, intdiv($hosts, 2) + 1);
                $end   = $hosts >= 254 ? 200 : $hosts;
                $rFrom = long2ip($base + $start);
                $rTo   = long2ip($base + $end);
                if (ip2long($nIp) >= ip2long($rFrom) && ip2long($nIp) <= ip2long($rTo)) {
                    $rFrom = long2ip(ip2long($nIp) + 1);
                }
            }
            $set['range_from'] = $rFrom;
            $set['range_to']   = $rTo;
        }
        if ($set !== []) {
            $set['updated_at'] = date('Y-m-d H:i:s');
            $this->db->table('dhcp_server_settings')->where('id', $row['id'])->update($set);
            foreach ($set as $col => $val) {
                if ($col !== 'updated_at') {
                    $this->note('DHCP ' . strtoupper($id) . ' ' . $col, (string) ($row[$col] ?? ''), (string) $val);
                }
            }
        }

        if ($this->has('dhcp_reservations')) {
            $res = $this->db->table('dhcp_reservations')->groupStart()->where('interface_id', $id)->orWhere('interface_id', null)->groupEnd()->get()->getResultArray();
            foreach ($res as $r) {
                if (! NetworkPlan::inSubnet((string) $r['ip_address'], $nNet, $nP)) {
                    $this->flag('DHCP reservation ' . ($r['hostname'] ?: $r['mac_address']), (string) $r['ip_address'],
                        "outside the new subnet {$nNet}/{$nP}; it is left out of the DHCP server until it is changed");
                }
            }
        }
    }

    private function dnsHosts(string $oldIp, ?string $newIp): void
    {
        if ($newIp === null || ! $this->has('dns_hosts')) {
            return;
        }
        foreach ($this->db->table('dns_hosts')->where('ip_address', $oldIp)->get()->getResultArray() as $h) {
            $this->db->table('dns_hosts')->where('id', $h['id'])->update(['ip_address' => $newIp, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->note('DNS record ' . $h['hostname'], $oldIp, $newIp);
        }
    }

    private function gateways(string $id, string $dev, string $oldGw, string $newGw): void
    {
        if ($this->has('wan_gateways')) {
            $rows = $this->db->table('wan_gateways')->where('gateway_ip', $oldGw)->get()->getResultArray();
            foreach ($rows as $g) {
                $iface = strtolower((string) ($g['interface'] ?? ''));
                if ($iface !== '' && $iface !== $id && $iface !== strtolower($dev)) {
                    continue;
                }
                if ($newGw === '') {
                    $this->flag('Gateway ' . $g['name'], $oldGw, 'the interface no longer has a static gateway');
                    continue;
                }
                $set = ['gateway_ip' => $newGw, 'updated_at' => date('Y-m-d H:i:s')];
                if (($g['monitor_ip'] ?? '') === $oldGw) {
                    $set['monitor_ip'] = $newGw;
                }
                $this->db->table('wan_gateways')->where('id', $g['id'])->update($set);
                $this->note('Gateway ' . $g['name'], $oldGw, $newGw);
            }
        }
        if ($this->has('static_routes') && $newGw !== '') {
            foreach ($this->db->table('static_routes')->where('gateway', $oldGw)->get()->getResultArray() as $r) {
                $this->db->table('static_routes')->where('id', $r['id'])->update(['gateway' => $newGw, 'updated_at' => date('Y-m-d H:i:s')]);
                $this->note('Static route ' . $r['destination'], $oldGw, $newGw);
            }
        }
    }

    /**
     * Literal copies of the old subnet/address in firewall rules, address
     * objects and NAT become dynamic references (or are listed).
     */
    private function literalReferences(string $id, string $oNet, int $oP, string $oIp, bool $rewrite): void
    {
        $netLit  = ["{$oNet}/{$oP}"];
        $addrLit = [$oIp, "{$oIp}/32"];
        $netTok  = NetworkPlan::token($id, 'network');
        $addrTok = NetworkPlan::token($id, 'address');
        $map = static function (string $v) use ($netLit, $addrLit, $netTok, $addrTok): ?string {
            $v = trim($v);
            if (in_array($v, $netLit, true)) {
                return $netTok;
            }
            if (in_array($v, $addrLit, true)) {
                return $addrTok;
            }

            return null;
        };

        if ($this->has('firewall_rules')) {
            foreach ($this->db->table('firewall_rules')->get()->getResultArray() as $r) {
                $set   = [];
                $label = 'Firewall rule ' . ($r['name'] ?: $r['description'] ?: $r['id']);
                foreach (['source_value', 'dest_value'] as $col) {
                    $t = $map((string) ($r[$col] ?? ''));
                    if ($t !== null) {
                        $set[$col] = $t;
                    }
                }
                foreach (['sources', 'destinations'] as $col) {
                    $list = json_decode((string) ($r[$col] ?? ''), true);
                    if (! is_array($list)) {
                        continue;
                    }
                    $changed = false;
                    foreach ($list as $k => $v) {
                        $t = is_string($v) ? $map($v) : null;
                        if ($t !== null) {
                            $list[$k] = $t;
                            $changed  = true;
                        }
                    }
                    if ($changed) {
                        $set[$col] = json_encode(array_values($list));
                    }
                }
                $this->rewriteOrFlag('firewall_rules', $r, $set, $label, $rewrite);
            }
        }
        if ($this->has('firewall_addresses')) {
            foreach ($this->db->table('firewall_addresses')->get()->getResultArray() as $a) {
                $t = in_array($a['type'] ?? 'ipmask', ['ipmask', 'ip', 'subnet', 'host'], true) ? $map((string) $a['value']) : null;
                $this->rewriteOrFlag('firewall_addresses', $a, $t !== null ? ['value' => $t] : [], 'Address object ' . $a['name'], $rewrite);
            }
        }
        if ($this->has('nat_dnat')) {
            foreach ($this->db->table('nat_dnat')->get()->getResultArray() as $d) {
                $t = in_array(trim((string) $d['external_ip']), $addrLit, true) ? $addrTok : null;
                $this->rewriteOrFlag('nat_dnat', $d, $t !== null ? ['external_ip' => $t] : [], 'Port forward ' . $d['name'], $rewrite);
            }
        }
        if ($this->has('nat_snat_pools')) {
            foreach ($this->db->table('nat_snat_pools')->get()->getResultArray() as $p) {
                $s = trim((string) $p['start_ip']);
                $e = trim((string) ($p['end_ip'] ?? ''));
                $t = ($s === $oIp && ($e === '' || $e === $oIp)) ? ['start_ip' => $addrTok, 'end_ip' => null] : [];
                $this->rewriteOrFlag('nat_snat_pools', $p, $t, 'SNAT pool ' . $p['name'], $rewrite);
            }
        }
    }

    private function rewriteOrFlag(string $table, array $row, array $set, string $label, bool $rewrite): void
    {
        if ($set === []) {
            return;
        }
        if ($rewrite) {
            $this->db->table($table)->where('id', $row['id'])->update($set + ['updated_at' => date('Y-m-d H:i:s')]);
            foreach ($set as $col => $val) {
                $this->note($label . ' (' . $col . ')', (string) ($row[$col] ?? ''), (string) $val);
            }

            return;
        }
        foreach ($set as $col => $val) {
            $this->flag($label . ' (' . $col . ')', (string) ($row[$col] ?? ''), 'still names the old address; it can follow the interface as ' . $val);
        }
    }

    /** Things inside the old subnet whose intent cannot be inferred. */
    private function reviewInsideOldSubnet(string $id, string $oNet, int $oP, string $oIp, ?array $new): void
    {
        $inOld = static fn (string $ip): bool => $ip !== '' && $ip !== $oIp
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && NetworkPlan::inSubnet($ip, $oNet, $oP)
            && ($new === null || ! NetworkPlan::inSubnet($ip, $new[0], $new[1]));

        if ($this->has('nat_dnat')) {
            foreach ($this->db->table('nat_dnat')->get()->getResultArray() as $d) {
                if ($inOld((string) $d['mapped_ip'])) {
                    $this->flag('Port forward ' . $d['name'], (string) $d['mapped_ip'], 'internal host is in the old ' . strtoupper($id) . ' subnet');
                }
            }
        }
        if ($this->has('static_routes')) {
            foreach ($this->db->table('static_routes')->get()->getResultArray() as $r) {
                if ($inOld((string) $r['gateway'])) {
                    $this->flag('Static route ' . $r['destination'], (string) $r['gateway'], 'next hop is in the old ' . strtoupper($id) . ' subnet');
                }
            }
        }
        if ($this->has('ha_virtual_ips')) {
            foreach ($this->db->table('ha_virtual_ips')->get()->getResultArray() as $v) {
                if ($inOld((string) $v['ip_address'])) {
                    $this->flag('HA virtual IP', (string) $v['ip_address'], 'is in the old ' . strtoupper($id) . ' subnet');
                }
            }
        }
        if ($this->has('firewall_rules')) {
            foreach ($this->db->table('firewall_rules')->get()->getResultArray() as $r) {
                foreach (['source_value', 'dest_value'] as $col) {
                    $v = explode('/', trim((string) ($r[$col] ?? '')))[0];
                    if ($inOld($v)) {
                        $this->flag('Firewall rule ' . ($r['name'] ?: $r['description'] ?: $r['id']), (string) $r[$col], 'host in the old ' . strtoupper($id) . ' subnet');
                    }
                }
            }
        }
    }

    /**
     * One-time reconciliation for existing installations: the factory rules
     * and DHCP defaults that were seeded with literal 10.10.10.x values are
     * turned into dynamic references when they still match the LAN as it is
     * configured now. Anything an administrator wrote is left alone.
     *
     * @return list<string> what was changed
     */
    public function reconcileFactoryDefaults(): array
    {
        $done = [];
        if (! $this->has('interfaces')) {
            return $done;
        }
        $lan = $this->db->table('interfaces')->where('id', 'lan')->get()->getRowArray();
        $c   = $lan ? $this->v4($lan) : null;
        if ($this->has('firewall_rules')) {
            $factory = ['rule_lan_webgui_access', 'rule_lan_dns_access'];
            foreach ($this->db->table('firewall_rules')->whereIn('id', $factory)->get()->getResultArray() as $r) {
                $set = [];
                foreach (['source_value' => 'network', 'dest_value' => 'address'] as $col => $kind) {
                    $v = trim((string) ($r[$col] ?? ''));
                    if (preg_match(NetworkPlan::TOKEN_RE, $v)) {
                        continue;
                    }
                    $matches = $kind === 'network'
                        ? ($v === '10.10.10.0/24' || ($c !== null && $v === "{$c[0]}/{$c[1]}"))
                        : ($v === '10.10.10.10' || ($c !== null && $v === $c[2]));
                    if ($matches) {
                        $set[$col] = NetworkPlan::token('lan', $kind);
                    }
                }
                if ($set !== []) {
                    $this->db->table('firewall_rules')->where('id', $r['id'])->update($set);
                    $done[] = "firewall rule {$r['id']}: " . implode(', ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($set), $set));
                }
            }
        }
        if ($c !== null && $this->has('dhcp_server_settings')) {
            $row = $this->db->table('dhcp_server_settings')->where('interface_id', 'lan')->get()->getRowArray();
            $seed = ['10.10.10.0', 24, '10.10.10.10'];
            if ($row && ($c[0] !== $seed[0] || $c[1] !== $seed[1])) {
                $set = [];
                foreach (['gateway', 'dns_1'] as $col) {
                    if (($row[$col] ?? '') === '10.10.10.10') {
                        $set[$col] = $c[2];
                    }
                }
                if (($row['range_from'] ?? '') === '10.10.10.100' && ($row['range_to'] ?? '') === '10.10.10.200') {
                    $sync = new self($this->db);
                    $sync->dhcp('lan', $seed, $c);
                    $done[] = 'DHCP pool moved into the configured LAN subnet';
                }
                if ($set !== []) {
                    $this->db->table('dhcp_server_settings')->where('id', $row['id'])->update($set);
                    $done[] = 'DHCP router/DNS options follow the LAN address';
                }
            }
        }

        return $done;
    }
}
