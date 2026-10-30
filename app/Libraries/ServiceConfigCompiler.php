<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Compilers for the smaller daemon configurations the management plane owns:
 * Net-SNMP (`snmpd.conf`), rsyslog SIEM forwarding, Keepalived (VRRP) and the
 * tc/CAKE traffic shaper.
 *
 * Each returns the full file text. The daemon decides where it is written,
 * checks it where a checker exists, reloads the unit and restores the previous
 * file on failure (`MANAGED_CONFIGS` in system/micrond/micrond.py). Values are
 * validated here so a stored value that would break the target syntax is
 * dropped (with a comment) rather than emitted.
 */
class ServiceConfigCompiler
{
    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // ── Net-SNMP ────────────────────────────────────────────────────────

    public function snmpd(): string
    {
        $s     = $this->row('snmp_settings');
        $lines = ['# Micron OS — compiled Net-SNMP agent configuration (do not edit)'];
        if ($s === [] || empty($s['enabled'])) {
            $lines[] = '# SNMP agent disabled: listen on loopback only, no communities.';
            $lines[] = 'agentAddress udp:127.0.0.1:161';

            return implode("\n", $lines) . "\n";
        }
        $port = max(1, min(65535, (int) ($s['listen_port'] ?? 161)));
        $lines[] = 'agentAddress udp:' . $port . ($this->ipv6Enabled() ? ',udp6:[::]:' . $port : '');
        foreach (['system_location' => 'sysLocation', 'system_contact' => 'sysContact'] as $col => $kw) {
            $v = $this->oneLine((string) ($s[$col] ?? ''));
            if ($v !== '') {
                $lines[] = "{$kw} {$v}";
            }
        }
        $lines[] = 'sysServices 76';

        $subnets = array_values(array_filter(
            preg_split('/[\s,]+/', (string) ($s['allowed_subnets'] ?? '')) ?: [],
            static fn ($c) => Cidr::isValid((string) $c)
        ));
        if ($subnets === []) {
            $subnets = ['127.0.0.1/32'];
            $lines[] = '# No allowed subnets configured: only the appliance itself may query.';
        }

        if (! empty($s['v1_v2c_enabled'])) {
            $ro = (string) ($s['read_community'] ?? '');
            if ($this->community($ro)) {
                foreach ($subnets as $net) {
                    $isV6 = str_contains($net, ':');
                    if ($isV6 && ! $this->ipv6Enabled()) {
                        continue;
                    }
                    $lines[] = ($isV6 ? 'rocommunity6 ' : 'rocommunity ') . $ro . ' ' . $net;
                }
            }
            $rw = (string) ($s['write_community'] ?? '');
            if ($rw !== '' && $this->community($rw)) {
                foreach ($subnets as $net) {
                    $isV6 = str_contains($net, ':');
                    if ($isV6 && ! $this->ipv6Enabled()) {
                        continue;
                    }
                    $lines[] = ($isV6 ? 'rwcommunity6 ' : 'rwcommunity ') . $rw . ' ' . $net;
                }
            }
        }

        if (! empty($s['v3_enabled'])) {
            $authMap = ['MD5' => 'MD5', 'SHA' => 'SHA', 'SHA-1' => 'SHA', 'SHA-224' => 'SHA-224',
                'SHA-256' => 'SHA-256', 'SHA-384' => 'SHA-384', 'SHA-512' => 'SHA-512'];
            $privMap = ['DES' => 'DES', 'AES' => 'AES', 'AES-128' => 'AES', 'AES-192' => 'AES-192', 'AES-256' => 'AES-256'];
            foreach ($this->rows('snmp_v3_users') as $u) {
                if (empty($u['enabled']) || ! preg_match('/^[A-Za-z0-9_.-]{1,32}$/', (string) $u['username'])) {
                    continue;
                }
                $level = (string) ($u['security_level'] ?? 'authPriv');
                $line  = 'createUser ' . $u['username'];
                if ($level !== 'noAuthNoPriv') {
                    $auth = $authMap[strtoupper((string) $u['auth_protocol'])] ?? 'SHA';
                    $pass = (string) $u['auth_passphrase'];
                    if (! $this->passphrase($pass)) {
                        $lines[] = '# skipped SNMPv3 user ' . $u['username'] . ': auth passphrase is not usable';
                        continue;
                    }
                    $line .= ' ' . $auth . ' "' . $pass . '"';
                    if ($level === 'authPriv') {
                        $priv  = $privMap[strtoupper((string) $u['priv_protocol'])] ?? 'AES';
                        $ppass = (string) $u['priv_passphrase'];
                        if (! $this->passphrase($ppass)) {
                            $lines[] = '# skipped SNMPv3 user ' . $u['username'] . ': privacy passphrase is not usable';
                            continue;
                        }
                        $line .= ' ' . $priv . ' "' . $ppass . '"';
                    }
                }
                $lines[] = $line;
                $min     = ['noAuthNoPriv' => 'noauth', 'authNoPriv' => 'auth', 'authPriv' => 'priv'][$level] ?? 'priv';
                $lines[] = ((($u['access_mode'] ?? 'ro') === 'rw') ? 'rwuser ' : 'rouser ') . $u['username'] . ' ' . $min;
            }
        }

        if (! empty($s['traps_enabled']) && filter_var($s['trap_receiver_ip'] ?? '', FILTER_VALIDATE_IP)) {
            $isV6 = str_contains((string) $s['trap_receiver_ip'], ':');
            if (! $isV6 || $this->ipv6Enabled()) {
                $tport = max(1, min(65535, (int) ($s['trap_receiver_port'] ?? 162)));
                $comm  = $this->community((string) ($s['trap_community'] ?? '')) ? $s['trap_community'] : 'public';
                $host  = $isV6 ? '[' . $s['trap_receiver_ip'] . ']' : $s['trap_receiver_ip'];
                $kw    = (($s['trap_version'] ?? 'v2c') === 'v1') ? 'trapsink' : 'trap2sink';
                $lines[] = "{$kw} {$host}:{$tport} {$comm}";
                if (! empty($s['trap_auth_events'])) {
                    $lines[] = 'authtrapenable 1';
                }
                if (! empty($s['trap_link_events'])) {
                    $lines[] = 'linkUpDownNotifications yes';
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    // ── rsyslog SIEM forwarding ─────────────────────────────────────────

    public function rsyslogSiem(): string
    {
        $s     = $this->row('siem_settings');
        $lines = ['# Micron OS — compiled SIEM forwarding (do not edit)'];
        if ($s === [] || empty($s['enabled'])) {
            $lines[] = '# SIEM forwarding disabled.';

            return implode("\n", $lines) . "\n";
        }
        $host = (string) ($s['server_host'] ?? '');
        if (! (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host))) {
            $lines[] = '# collector host is not valid; nothing is forwarded';

            return implode("\n", $lines) . "\n";
        }
        $port     = max(1, min(65535, (int) ($s['server_port'] ?? 514)));
        $protocol = strtolower((string) ($s['protocol'] ?? 'udp'));
        $proto    = in_array($protocol, ['tcp', 'tls'], true) ? 'tcp' : 'udp';
        $sevs     = ['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'];
        $sev      = in_array($s['severity_threshold'] ?? '', $sevs, true) ? $s['severity_threshold'] : 'info';
        $rate     = max(100, min(50000, (int) ($s['rate_limit_per_sec'] ?? 5000)));
        $spool    = max(50, min(10000, (int) ($s['disk_spool_mb'] ?? 500)));
        $format   = strtoupper((string) ($s['log_format'] ?? 'RFC5424'));
        $template = match ($format) {
            'JSON'  => 'MicronJson',
            'CEF'   => 'MicronCef',
            default => 'RSYSLOG_SyslogProtocol23Format',
        };
        $lines[] = 'template(name="MicronJson" type="list" option.jsonf="on") {';
        $lines[] = '  property(outname="@timestamp" name="timereported" dateFormat="rfc3339" format="jsonf")';
        $lines[] = '  property(outname="host" name="hostname" format="jsonf")';
        $lines[] = '  property(outname="program" name="programname" format="jsonf")';
        $lines[] = '  property(outname="severity" name="syslogseverity-text" format="jsonf")';
        $lines[] = '  property(outname="message" name="msg" format="jsonf")';
        $lines[] = '}';
        $lines[] = 'template(name="MicronCef" type="string" string="<%PRI%>%TIMESTAMP% %HOSTNAME% CEF:0|Micron|Micron OS|1.0|%programname%|%programname%|%syslogseverity%|msg=%msg:::json%\n")';

        $programs = [];
        foreach (['forward_firewall' => ['kernel'], 'forward_ids' => ['suricata'], 'forward_vpn' => ['openvpn', 'charon', 'wireguard'],
            'forward_proxy' => ['squid', 'c-icap'], 'forward_dhcp' => ['kea-dhcp4', 'dhcp-helper']] as $col => $names) {
            if (! empty($s[$col])) {
                array_push($programs, ...$names);
            }
        }
        $filter  = ! empty($s['forward_system'])
            ? '*.' . $sev
            : null;
        $action  = 'action(type="omfwd" target="' . $host . '" port="' . $port . '" protocol="' . $proto . '" template="' . $template . '"'
            . ' queue.type="LinkedList" queue.filename="micron_siem" queue.maxDiskSpace="' . $spool . 'm"'
            . ' queue.saveOnShutdown="on" action.resumeRetryCount="-1")';
        if ($filter !== null) {
            $lines[] = '# rate limit for this action: ' . $rate . ' messages/s';
            $lines[] = $filter . ' ' . $action;
        } elseif ($programs !== []) {
            $quoted  = implode(', ', array_map(static fn ($p) => '"' . $p . '"', array_unique($programs)));
            $lines[] = 'if ($syslogseverity <= ' . array_search($sev, $sevs, true) . ') and ($programname == [' . $quoted . ']) then {';
            $lines[] = '  ' . $action;
            $lines[] = '}';
        } else {
            $lines[] = '# no log sources selected for forwarding';
        }

        return implode("\n", $lines) . "\n";
    }

    // ── Keepalived ──────────────────────────────────────────────────────

    public function keepalived(): string
    {
        $s    = $this->row('ha_settings');
        $vips = $this->rows('ha_virtual_ips');
        $node = preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) ($s['node_name'] ?? '')) ? $s['node_name'] : 'micron-gw-01';
        $mode = strtoupper((string) ($s['mode'] ?? 'master')) === 'BACKUP' ? 'BACKUP' : 'MASTER';
        $prio = max(1, min(254, (int) ($s['vrrp_priority'] ?? 110)));

        $out   = [];
        $out[] = '# Micron OS — compiled Keepalived (VRRP) configuration (do not edit)';
        $out[] = 'global_defs {';
        $out[] = '    router_id ' . $node;
        $out[] = '    script_user root';
        $out[] = '    enable_script_security';
        $out[] = '}';
        if ($s === [] || empty($s['enabled'])) {
            $out[] = '# High availability is disabled: no VRRP instances.';

            return implode("\n", $out) . "\n";
        }
        // VRRPv2 is used because it is the version that supports the shared
        // authentication the HA page configures (VRRPv3 has no auth field).
        $pass = substr(preg_replace('/[^\x21-\x7e]/', '', (string) ($s['vrrp_auth_pass'] ?? '')) ?? '', 0, 8);
        foreach ($vips as $vip) {
            $iface = (string) ($vip['interface'] ?? '');
            $ip    = (string) ($vip['ip_address'] ?? '');
            $vrid  = (int) ($vip['vrrp_router_id'] ?? 0);
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', $iface) || ! filter_var($ip, FILTER_VALIDATE_IP) || $vrid < 1 || $vrid > 255) {
                $out[] = '# skipped virtual IP with an invalid interface, address or router id';
                continue;
            }
            $mask = $this->prefixLength((string) ($vip['subnet_mask'] ?? ''), str_contains($ip, ':'));
            $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $iface)) . '_VIP_' . $vrid;
            $out[] = "vrrp_instance {$name} {";
            $out[] = "    state {$mode}";
            $out[] = "    interface {$iface}";
            $out[] = "    virtual_router_id {$vrid}";
            $out[] = '    priority ' . max(1, min(254, (int) ($vip['vrrp_priority'] ?? 0) ?: $prio));
            $out[] = '    advert_int 1';
            if (empty($s['preempt'])) {
                $out[] = '    nopreempt';
            }
            if ($pass !== '' && ! str_contains($ip, ':')) {
                $out[] = '    authentication {';
                $out[] = '        auth_type PASS';
                $out[] = '        auth_pass ' . $pass;
                $out[] = '    }';
            }
            $out[] = '    virtual_ipaddress {';
            $out[] = "        {$ip}/{$mask} dev {$iface}";
            $out[] = '    }';
            $out[] = '}';
        }

        return implode("\n", $out) . "\n";
    }

    /** The same configuration with the VRRP password masked, for on-screen preview. */
    public function keepalivedPreview(): string
    {
        return preg_replace('/(auth_pass\s+)\S+/', '$1********', $this->keepalived()) ?? '';
    }

    // ── Traffic shaper (tc batch) ───────────────────────────────────────

    /**
     * A `tc -batch` script: one CAKE root qdisc per enabled shaper (egress,
     * upload bandwidth). Ingress shaping needs an IFB device, which this
     * appliance build does not create; the limitation is stated in the script
     * and in the QoS page, not hidden.
     */
    public function qos(): string
    {
        $lines = ['# Micron OS — compiled traffic shaper (tc batch)'];
        foreach ($this->rows('qos_shapers') as $sh) {
            $dev = (string) ($sh['interface'] ?? '');
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', $dev)) {
                continue;
            }
            if (empty($sh['enabled'])) {
                $lines[] = "qdisc replace dev {$dev} root fq_codel";
                continue;
            }
            $up        = max(1, (int) ($sh['bandwidth_up_mbps'] ?? 0));
            $diffserv  = in_array($sh['diffserv'] ?? '', ['diffserv3', 'diffserv4', 'diffserv8', 'besteffort'], true) ? $sh['diffserv'] : 'diffserv4';
            $isolation = in_array($sh['flow_isolation'] ?? '', ['triple-isolate', 'dual-srchost', 'dual-dsthost', 'flows', 'srchost', 'dsthost', 'hosts'], true) ? $sh['flow_isolation'] : 'triple-isolate';
            $line      = "qdisc replace dev {$dev} root cake bandwidth {$up}mbit {$diffserv} {$isolation}";
            if (! empty($sh['ack_filter'])) {
                $line .= ' ack-filter';
            }
            if ((int) ($sh['rtt_ms'] ?? 0) > 0) {
                $line .= ' rtt ' . (int) $sh['rtt_ms'] . 'ms';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n";
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

    /** `255.255.255.0` or `24` → 24; anything unusable → the family default. */
    protected function prefixLength(string $mask, bool $v6): int
    {
        $mask = trim($mask);
        if (preg_match('/^\d{1,3}$/', $mask) && (int) $mask >= 1 && (int) $mask <= ($v6 ? 128 : 32)) {
            return (int) $mask;
        }
        if (! $v6 && filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $bits = substr_count(decbin(ip2long($mask) & 0xFFFFFFFF), '1');
            if ($bits >= 1) {
                return $bits;
            }
        }

        return $v6 ? 64 : 24;
    }

    protected function community(string $v): bool
    {
        return preg_match('/^[A-Za-z0-9_.@#%+=-]{1,64}$/', $v) === 1;
    }

    protected function passphrase(string $v): bool
    {
        return preg_match('/^[\x20-\x7e]{8,64}$/', $v) === 1 && ! str_contains($v, '"');
    }

    protected function oneLine(string $v): string
    {
        return trim(preg_replace('/[^\x20-\x7e]/', '', $v) ?? '');
    }

    protected function ipv6Enabled(): bool
    {
        try {
            $row = $this->row('system_settings');

            return (int) ($row['ipv6_enabled'] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
