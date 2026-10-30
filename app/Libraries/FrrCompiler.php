<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Compiles /etc/frr/frr.conf from the dynamic-routing tables.
 *
 * The Routing pages stored BGP, OSPF, RIP, IS-IS and BFD configuration and then
 * asked the daemon to "reload" FRR — but nothing ever turned those rows into an
 * FRR configuration, so the daemon had nothing to load. This compiler is that
 * missing step. The daemon (`frr.*.reload` → managed config "frr") checks the
 * result with `vtysh --dryrun` and restores the previous file when FRR rejects it.
 *
 * Every value is re-validated here: names and passwords that would break the
 * line-oriented FRR syntax are dropped with a `! skipped` comment rather than
 * emitted.
 */
class FrrCompiler
{
    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function compile(): string
    {
        $hostname = 'micron-os';
        $settings = $this->row('system_settings');
        if (! empty($settings['hostname']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,62}$/', (string) $settings['hostname'])) {
            $hostname = (string) $settings['hostname'];
        }

        $out   = [];
        $out[] = '! Micron OS — compiled FRRouting configuration';
        $out[] = '! Generated ' . gmdate('Y-m-d H:i:s') . ' UTC — edits are overwritten on the next apply';
        $out[] = 'frr defaults traditional';
        $out[] = 'hostname ' . $hostname;
        $out[] = 'log syslog informational';
        $out[] = 'ip nht resolve-via-default';
        $out[] = 'service integrated-vtysh-config';
        $out[] = '!';

        $ospfSettings = $this->row('frr_ospf_settings');
        $bgpSettings  = $this->row('frr_bgp_settings');
        $globalRouterId = ($this->ipv4($ospfSettings['router_id'] ?? '')) ? $ospfSettings['router_id']
            : (($this->ipv4($bgpSettings['router_id'] ?? '')) ? $bgpSettings['router_id'] : null);
        if ($globalRouterId !== null) {
            $out[] = 'ip router-id ' . $globalRouterId;
            $out[] = '!';
        }

        // Prefix lists, access lists, and route maps are shared by every protocol.
        $out[] = rtrim(\App\Controllers\Routing::compileOspfPolicy(
            $this->rows('frr_prefix_lists'),
            $this->rows('frr_route_maps'),
            $this->rows('frr_ospf_distribute_lists'),
            $this->rows('frr_ospf_area_filters'),
            $this->rows('frr_access_lists')
        ));
        $out[] = '!';

        foreach ([$this->ospf(), $this->bgp(), $this->rip(), $this->isis(), $this->bfd()] as $block) {
            if ($block !== []) {
                array_push($out, ...$block);
                $out[] = '!';
            }
        }

        $out[] = 'line vty';
        $out[] = '!';

        return implode("\n", $out) . "\n";
    }

    // ── Protocols ───────────────────────────────────────────────────────

    /** @return list<string> */
    protected function bgp(): array
    {
        $s = $this->row('frr_bgp_settings');
        if ($s === [] || empty($s['enabled']) || ! $this->asn($s['local_as'] ?? null)) {
            return [];
        }

        $l   = ['router bgp ' . (int) $s['local_as']];
        if ($this->ipv4($s['router_id'] ?? '')) {
            $l[] = ' bgp router-id ' . $s['router_id'];
        }
        if (! empty($s['log_neighbor_changes'])) {
            $l[] = ' bgp log-neighbor-changes';
        }
        if (! empty($s['graceful_restart'])) {
            $l[] = ' bgp graceful-restart';
        }
        if (! empty($s['deterministic_med'])) {
            $l[] = ' bgp deterministic-med';
        }
        if (! empty($s['always_compare_med'])) {
            $l[] = ' bgp always-compare-med';
        }
        if (! empty($s['bestpath_as_path_ignore'])) {
            $l[] = ' bgp bestpath as-path ignore';
        }
        $ka   = (int) ($s['keepalive_interval'] ?? 0);
        $hold = (int) ($s['hold_time'] ?? 0);
        if ($ka > 0 && $hold >= 3 * $ka) {
            $l[] = " timers bgp {$ka} {$hold}";
        }

        $neighbours = array_filter($this->rows('frr_bgp_neighbors'), static fn ($n) => ! empty($n['enabled']));
        $af         = [];
        foreach ($neighbours as $n) {
            $ip = (string) ($n['neighbor_ip'] ?? '');
            if (! $this->ip($ip) || ! $this->asn($n['remote_as'] ?? null)) {
                $l[] = ' ! skipped neighbour with an invalid address or AS: ' . $this->comment($ip);
                continue;
            }
            $l[] = " neighbor {$ip} remote-as " . (int) $n['remote_as'];
            if (($d = $this->comment($n['description'] ?? '')) !== '') {
                $l[] = " neighbor {$ip} description {$d}";
            }
            if (($p = (string) ($n['password'] ?? '')) !== '' && $this->token($p)) {
                $l[] = " neighbor {$ip} password {$p}";
            }
            if (($src = (string) ($n['update_source'] ?? '')) !== '' && ($this->ip($src) || $this->ifname($src))) {
                $l[] = " neighbor {$ip} update-source {$src}";
            }
            if (! empty($n['bfd_enabled'])) {
                $l[] = " neighbor {$ip} bfd";
            }
            $af[] = $n;
        }

        $l[] = ' !';
        $l[] = ' address-family ipv4 unicast';
        foreach (array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($s['networks'] ?? '')) ?: [])) as $net) {
            if (Cidr::family($net) === 'ipv4') {
                $l[] = '  network ' . $net;
            }
        }
        foreach (['connected', 'static', 'ospf'] as $proto) {
            if (! empty($s['redistribute_' . $proto])) {
                $rmCol = 'redistribute_' . $proto . '_rm';
                $rm    = (string) ($s[$rmCol] ?? '');
                if ($rm !== '' && $this->name($rm)) {
                    $l[] = '  redistribute ' . $proto . ' route-map ' . $rm;
                } else {
                    $l[] = '  redistribute ' . $proto;
                }
            }
        }
        if (($m = (int) ($s['max_paths_ebgp'] ?? 0)) > 1) {
            $l[] = '  maximum-paths ' . min($m, 64);
        }
        if (($m = (int) ($s['max_paths_ibgp'] ?? 0)) > 1) {
            $l[] = '  maximum-paths ibgp ' . min($m, 64);
        }
        foreach ($af as $n) {
            $ip = $n['neighbor_ip'];
            if (! empty($n['next_hop_self'])) {
                $l[] = "  neighbor {$ip} next-hop-self";
            }
            if (! empty($n['soft_reconfigure'])) {
                $l[] = "  neighbor {$ip} soft-reconfiguration inbound";
            }
            foreach (['in' => 'route_map_in', 'out' => 'route_map_out'] as $dir => $col) {
                if (($rm = (string) ($n[$col] ?? '')) !== '' && $this->name($rm)) {
                    $l[] = "  neighbor {$ip} route-map {$rm} {$dir}";
                }
            }
            foreach (['in' => 'prefix_list_in', 'out' => 'prefix_list_out'] as $dir => $col) {
                if (($pl = (string) ($n[$col] ?? '')) !== '' && $this->name($pl)) {
                    $l[] = "  neighbor {$ip} prefix-list {$pl} {$dir}";
                }
            }
            foreach (['in' => 'filter_list_in', 'out' => 'filter_list_out'] as $dir => $col) {
                if (($fl = (string) ($n[$col] ?? '')) !== '' && $this->name($fl)) {
                    $l[] = "  neighbor {$ip} filter-list {$fl} {$dir}";
                }
            }
        }
        $l[] = ' exit-address-family';

        return $l;
    }

    /** @return list<string> */
    protected function ospf(): array
    {
        $s        = $this->row('frr_ospf_settings');
        $networks = array_filter($this->rows('frr_ospf_networks'), static fn ($n) => ! empty($n['enabled']));
        if (($s === [] || empty($s['enabled'])) && $networks === []) {
            return [];
        }

        $l = [];
        // Interface-level OSPF parameters come first, as FRR writes them.
        foreach ($this->rows('frr_ospf_ifaces') as $if) {
            $name = (string) ($if['interface'] ?? '');
            if (! $this->ifname($name)) {
                continue;
            }
            $l[] = 'interface ' . $name;
            if (($desc = trim((string) ($if['description'] ?? ''))) !== '') {
                $cleanDesc = trim(str_replace(["\r", "\n", '"'], '', $desc));
                if (! str_starts_with(strtolower($cleanDesc), 'ospfd:') && ! str_starts_with(strtolower($cleanDesc), 'ospf:')) {
                    $cleanDesc = 'ospfd: ' . $cleanDesc;
                }
                $l[] = ' description "' . $cleanDesc . '"';
            }
            if (in_array($if['network_type'] ?? '', ['broadcast', 'point-to-point', 'non-broadcast', 'point-to-multipoint'], true)) {
                $l[] = ' ip ospf network ' . $if['network_type'];
            }
            if ((int) ($if['metric'] ?? 0) > 0) {
                $l[] = ' ip ospf cost ' . (int) $if['metric'];
            }
            // Standard OSPF timer defaults:
            // hello_interval: 10, dead_interval: 40, retransmit_interval: 5, transmit_delay: 1, router_priority: 1
            // Do not emit timers into frr.conf unless changed from defaults
            $defaultTimers = [
                'hello_interval'      => 10,
                'dead_interval'       => 40,
                'retransmit_interval' => 5,
                'transmit_delay'      => 1,
            ];
            foreach ($defaultTimers as $col => $defaultVal) {
                $val = (int) ($if[$col] ?? 0);
                if ($val > 0 && $val !== $defaultVal) {
                    $kw = str_replace('_', '-', $col);
                    $l[] = " ip ospf {$kw} {$val}";
                }
            }
            if (isset($if['router_priority']) && $if['router_priority'] !== '' && $if['router_priority'] !== null) {
                $prio = max(0, min(255, (int) $if['router_priority']));
                if ($prio !== 1) {
                    $l[] = ' ip ospf priority ' . $prio;
                }
            }
            if (! empty($if['minimal_hello']) && (int) $if['minimal_hello'] > 0) {
                $l[] = ' ip ospf dead-interval minimal hello-multiplier ' . (int) $if['minimal_hello'];
            }
            if (($if['auth_type'] ?? '') === 'md5' && $this->token((string) ($if['auth_password'] ?? ''))) {
                $l[] = ' ip ospf authentication message-digest';
                $l[] = ' ip ospf message-digest-key 1 md5 ' . $if['auth_password'];
            } elseif (($if['auth_type'] ?? '') === 'simple' && $this->token((string) ($if['auth_password'] ?? ''))) {
                $l[] = ' ip ospf authentication';
                $l[] = ' ip ospf authentication-key ' . substr((string) $if['auth_password'], 0, 8);
            }
            if (! empty($if['bfd_enabled'])) {
                $l[] = ' ip ospf bfd';
            }
            if (! empty($if['ignore_mtu'])) {
                $l[] = ' ip ospf mtu-ignore';
            }
            $area = trim((string) ($if['area'] ?? ''));
            if ($area === '' && ! empty($s['default_area'])) {
                $area = trim((string) $s['default_area']);
            }
            if ($area !== '' && $this->area($area)) {
                $l[] = ' ip ospf area ' . $area;
            }
            $l[] = '!';
        }

        $l[] = 'router ospf';
        if ($this->ipv4($s['router_id'] ?? '')) {
            $l[] = ' ospf router-id ' . $s['router_id'];
        }
        if (! empty($s['log_adj_changes'])) {
            $l[] = ' log-adjacency-changes detail';
        }
        $refBw = (int) ($s['reference_bandwidth'] ?? 0);
        if ($refBw > 0 && $refBw !== 100) {
            $l[] = ' auto-cost reference-bandwidth ' . $refBw;
        }
        if (! empty($s['passive_default'])) {
            $l[] = ' passive-interface default';
        }
        foreach ($this->rows('frr_ospf_ifaces') as $if) {
            if (! empty($if['passive']) && $this->ifname((string) ($if['interface'] ?? '')) && empty($s['passive_default'])) {
                $l[] = ' passive-interface ' . $if['interface'];
            }
        }
        foreach ($networks as $n) {
            if (Cidr::family((string) ($n['network'] ?? '')) === 'ipv4' && $this->area($n['area'] ?? '')) {
                $l[] = ' network ' . $n['network'] . ' area ' . $n['area'];
            } else {
                $l[] = ' ! skipped network ' . $this->comment((string) ($n['network'] ?? ''));
            }
        }
        foreach ($this->rows('frr_ospf_areas') as $a) {
            if (! $this->area($a['area_id'] ?? '')) {
                continue;
            }
            if (($a['area_type'] ?? '') === 'stub') {
                $l[] = ' area ' . $a['area_id'] . ' stub' . (! empty($a['no_summary']) ? ' no-summary' : '');
            } elseif (($a['area_type'] ?? '') === 'nssa') {
                $l[] = ' area ' . $a['area_id'] . ' nssa' . (! empty($a['no_summary']) ? ' no-summary' : '');
            }
            if (($a['auth_type'] ?? '') === 'md5') {
                $l[] = ' area ' . $a['area_id'] . ' authentication message-digest';
            }
        }
        foreach (array_filter($this->rows('frr_ospf_neighbors'), static fn ($n) => ! empty($n['enabled'])) as $n) {
            if ($this->ipv4($n['neighbor_ip'] ?? '')) {
                $l[] = ' neighbor ' . $n['neighbor_ip'] . ((int) ($n['priority'] ?? 0) > 0 ? ' priority ' . (int) $n['priority'] : '');
            }
        }
        foreach (['connected', 'static', 'bgp', 'rip'] as $proto) {
            if (! empty($s['redistribute_' . $proto])) {
                $rm  = (string) ($s['redistribute_' . $proto . '_rm'] ?? '');
                $l[] = ' redistribute ' . $proto . ($rm !== '' && $this->name($rm) ? ' route-map ' . $rm : '');
            }
        }
        $maxPaths = (int) ($s['max_paths'] ?? 0);
        if ($maxPaths > 1 && $maxPaths !== 4) {
            $l[] = ' maximum-paths ' . min($maxPaths, 64);
        }

        return $l;
    }

    /** @return list<string> */
    protected function rip(): array
    {
        $s = $this->row('frr_rip_settings');
        if ($s === [] || empty($s['enabled'])) {
            return [];
        }
        $l   = ['router rip'];
        $l[] = ' version ' . (((int) ($s['version'] ?? 2)) === 1 ? 1 : 2);
        foreach (array_filter($this->rows('frr_rip_networks'), static fn ($n) => ! empty($n['enabled'])) as $n) {
            if (Cidr::family((string) ($n['network'] ?? '')) === 'ipv4') {
                $l[] = ' network ' . $n['network'];
            }
        }
        foreach (array_filter($this->rows('frr_rip_neighbors'), static fn ($n) => ! empty($n['enabled'])) as $n) {
            if ($this->ipv4($n['neighbor_ip'] ?? '')) {
                $l[] = ' neighbor ' . $n['neighbor_ip'];
            }
        }
        if (! empty($s['passive_default'])) {
            $l[] = ' passive-interface default';
        }
        foreach (array_filter($this->rows('frr_rip_passive_interfaces'), static fn ($n) => ! empty($n['enabled'])) as $p) {
            if ($this->ifname((string) ($p['interface'] ?? ''))) {
                $l[] = ' passive-interface ' . $p['interface'];
            }
        }
        foreach (['connected', 'static', 'bgp', 'ospf'] as $proto) {
            if (! empty($s['redistribute_' . $proto])) {
                $rm  = (string) ($s['redistribute_' . $proto . '_rm'] ?? '');
                $l[] = ' redistribute ' . $proto . ($rm !== '' && $this->name($rm) ? ' route-map ' . $rm : '');
            }
        }
        if (! empty($s['default_information'])) {
            $l[] = ' default-information originate';
        }
        $u = (int) ($s['timers_update'] ?? 0);
        $t = (int) ($s['timers_timeout'] ?? 0);
        $g = (int) ($s['timers_garbage'] ?? 0);
        if ($u > 0 && $t > 0 && $g > 0) {
            $l[] = " timers basic {$u} {$t} {$g}";
        }

        return $l;
    }

    /** @return list<string> */
    protected function isis(): array
    {
        $s = $this->row('frr_isis_settings');
        if ($s === [] || empty($s['enabled'])) {
            return [];
        }
        $tag = (string) ($s['area_tag'] ?? 'MICRON');
        if (! $this->name($tag) || ! preg_match('/^[0-9a-fA-F]{2}(\.[0-9a-fA-F]{4}){3,9}\.00$/', (string) ($s['net'] ?? ''))) {
            return ['! IS-IS skipped: area tag or NET is not valid'];
        }
        $l = [];
        foreach (array_filter($this->rows('frr_isis_interfaces'), static fn ($i) => ! empty($i['enabled'])) as $if) {
            if (! $this->ifname((string) ($if['interface'] ?? ''))) {
                continue;
            }
            $l[] = 'interface ' . $if['interface'];
            $l[] = ' ip router isis ' . $tag;
            if (in_array($if['circuit_type'] ?? '', ['level-1', 'level-1-2', 'level-2-only'], true)) {
                $l[] = ' isis circuit-type ' . $if['circuit_type'];
            }
            if (($if['network_type'] ?? '') === 'point-to-point') {
                $l[] = ' isis network point-to-point';
            }
            if (! empty($if['bfd_enabled'])) {
                $l[] = ' isis bfd';
            }
            $l[] = '!';
        }
        $l[] = 'router isis ' . $tag;
        $l[] = ' net ' . $s['net'];
        if (in_array($s['is_type'] ?? '', ['level-1', 'level-1-2', 'level-2-only'], true)) {
            $l[] = ' is-type ' . $s['is_type'];
        }
        if (in_array($s['metric_style'] ?? '', ['narrow', 'wide', 'transition'], true)) {
            $l[] = ' metric-style ' . $s['metric_style'];
        }
        if (! empty($s['log_adjacency'])) {
            $l[] = ' log-adjacency-changes';
        }
        foreach (['connected', 'static', 'bgp', 'ospf'] as $proto) {
            if (! empty($s['redistribute_' . $proto])) {
                $l[] = ' redistribute ipv4 ' . $proto . ' level-2';
            }
        }

        return $l;
    }

    /** @return list<string> */
    protected function bfd(): array
    {
        $peers = array_filter($this->rows('frr_bfd_peers'), static fn ($p) => ! empty($p['enabled']));
        $hasIfaceBfd = false;
        foreach ($this->rows('frr_ospf_ifaces') as $if) {
            if (! empty($if['bfd_enabled'])) {
                $hasIfaceBfd = true;
                break;
            }
        }
        foreach ($this->rows('frr_bgp_neighbors') as $n) {
            if (! empty($n['bfd_enabled'])) {
                $hasIfaceBfd = true;
                break;
            }
        }
        if ($peers === [] && ! $hasIfaceBfd) {
            return [];
        }
        $l = ['bfd'];
        foreach ($peers as $p) {
            if (! $this->ip($p['peer_ip'] ?? '')) {
                continue;
            }
            $line = ' peer ' . $p['peer_ip'];
            if ($this->ip($p['local_ip'] ?? '')) {
                $line .= ' local-address ' . $p['local_ip'];
            }
            if ($this->ifname((string) ($p['interface'] ?? ''))) {
                $line .= ' interface ' . $p['interface'];
            }
            $l[] = $line;
            if ((int) ($p['detect_multiplier'] ?? 0) > 0) {
                $l[] = '  detect-multiplier ' . min(255, (int) $p['detect_multiplier']);
            }
            if ((int) ($p['receive_interval'] ?? 0) > 0) {
                $l[] = '  receive-interval ' . (int) $p['receive_interval'];
            }
            if ((int) ($p['transmit_interval'] ?? 0) > 0) {
                $l[] = '  transmit-interval ' . (int) $p['transmit_interval'];
            }
            if (! empty($p['echo_mode'])) {
                $l[] = '  echo-mode';
            }
            $l[] = ' exit';
        }

        return $l;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    protected function rows(string $table): array
    {
        if ($this->db === null || ! $this->db->tableExists($table)) {
            return [];
        }

        return $this->db->table($table)->get()->getResultArray();
    }

    protected function row(string $table): array
    {
        return $this->rows($table)[0] ?? [];
    }

    protected function ip(mixed $v): bool
    {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false;
    }

    protected function ipv4(mixed $v): bool
    {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    protected function asn(mixed $v): bool
    {
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 4294967295;
    }

    protected function area(mixed $v): bool
    {
        return is_scalar($v) && (preg_match('/^\d{1,10}$/', (string) $v) === 1 || $this->ipv4((string) $v));
    }

    protected function ifname(string $v): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,14}$/', $v) === 1;
    }

    protected function name(string $v): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $v) === 1;
    }

    /** A password FRR can parse: printable, no whitespace. */
    protected function token(string $v): bool
    {
        return preg_match('/^[\x21-\x7e]{1,80}$/', $v) === 1;
    }

    protected function comment(mixed $v): string
    {
        return trim(preg_replace('/[^\x20-\x7e]/', '', (string) $v) ?? '');
    }
}
