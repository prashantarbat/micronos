<?php

namespace App\Libraries\Network;

use CodeIgniter\Database\BaseConnection;

/**
 * The network plan: the one description of interface addressing that the OS
 * is made to match.
 *
 * Built from the configuration database (the source of truth — `interfaces`
 * plus the IPv6 master switch in `system_settings`) and consumed by
 * system/micron_netcfg.py through micrond's `network.apply` and, at boot, by
 * `micron-network` (`php spark network:plan`). The Web UI, the console CLI
 * and boot therefore all apply the same thing.
 *
 * The validation here mirrors the daemon's (which remains the authority) so
 * the operator gets an answer before anything is sent.
 */
final class NetworkPlan
{
    public const MODES = ['static', 'dhcp', 'pppoe', 'none'];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    /** Role of an interface from its id: wan*, lan*, mgmt*, dmz*, otherwise opt. */
    public static function role(string $id): string
    {
        $id = strtolower($id);
        foreach (['wan', 'lan', 'mgmt', 'dmz'] as $r) {
            if (str_starts_with($id, $r)) {
                return $r;
            }
        }

        return 'opt';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $rows = $this->db->table('interfaces')->orderBy('id', 'ASC')->get()->getResultArray();
        $ipv6 = false;
        try {
            $row  = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
            $ipv6 = (int) ($row['ipv6_enabled'] ?? 0) === 1;
        } catch (\Throwable) {
        }

        $ifaces = [];
        foreach ($rows as $r) {
            $dev = trim((string) ($r['physical_device'] ?? ''));
            if ($dev === '') {
                continue;
            }
            $mode = strtolower((string) ($r['ip_assignment_type'] ?? 'none'));
            if (! in_array($mode, self::MODES, true)) {
                $mode = 'none';
            }
            $ipv4 = null;
            if ($mode === 'static' && ! empty($r['ipv4_address'])) {
                $ipv4 = $r['ipv4_address'] . '/' . self::prefix($r['ipv4_netmask'] ?? 24);
            }
            $v6mode = strtolower((string) ($r['ipv6_assignment_type'] ?? 'disabled'));
            $v6mode = in_array($v6mode, ['static', 'slaac', 'dhcp6'], true) ? $v6mode : 'none';
            $ipv6addr = ($v6mode === 'static' && ! empty($r['ipv6_address']))
                ? $r['ipv6_address'] . '/' . (int) ($r['ipv6_prefix'] ?? 64) : null;

            $ifaces[] = [
                'id'        => (string) $r['id'],
                'role'      => self::role((string) $r['id']),
                'device'    => $dev,
                'enabled'   => (int) ($r['enabled'] ?? 1) === 1,
                'mode'      => $mode,
                'ipv4'      => $ipv4,
                'gateway'   => $mode === 'static' && ! empty($r['ipv4_gateway']) ? (string) $r['ipv4_gateway'] : null,
                'mtu'       => max(576, min(9216, (int) ($r['mtu'] ?? 1500) ?: 1500)),
                'ipv6_mode' => $ipv6 ? $v6mode : 'none',
                'ipv6'      => $ipv6 ? $ipv6addr : null,
            ];
        }

        return ['version' => 1, 'ipv6_enabled' => $ipv6, 'interfaces' => $ifaces];
    }

    /** Prefix length from an int prefix or a dotted netmask. */
    public static function prefix(mixed $mask): int
    {
        if (is_string($mask) && str_contains($mask, '.')) {
            $long = ip2long($mask);
            if ($long === false) {
                return 24;
            }

            return substr_count(decbin($long & 0xFFFFFFFF), '1');
        }
        $p = (int) $mask;

        return ($p >= 1 && $p <= 32) ? $p : 24;
    }

    /**
     * [network, prefix, address] for a CIDR, or null.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public static function cidr(?string $cidr): ?array
    {
        if ($cidr === null || ! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)) {
            return null;
        }
        $ip = ip2long($m[1]);
        $p  = (int) $m[2];
        if ($ip === false || $p < 0 || $p > 32) {
            return null;
        }
        $mask = $p === 0 ? 0 : ((0xFFFFFFFF << (32 - $p)) & 0xFFFFFFFF);

        return [long2ip($ip & $mask), $p, $m[1]];
    }

    public static function inSubnet(string $ip, string $network, int $prefix): bool
    {
        $i = ip2long($ip);
        $n = ip2long($network);
        if ($i === false || $n === false) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);

        return ($i & $mask) === ($n & $mask);
    }

    /**
     * Operator-facing validation (the daemon re-validates).
     *
     * @return list<string>
     */
    public static function validate(array $plan): array
    {
        $errors = [];
        $nets   = [];
        $devs   = [];
        $gws    = [];
        foreach ($plan['interfaces'] ?? [] as $i) {
            $id = $i['id'];
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', (string) $i['device'])) {
                $errors[] = "{$id}: invalid kernel device name '{$i['device']}'";
                continue;
            }
            if ($i['enabled'] && isset($devs[$i['device']])) {
                $errors[] = "{$id}: device {$i['device']} is already used by {$devs[$i['device']]}";
            }
            $devs[$i['device']] = $id;
            if ($i['mode'] !== 'static') {
                continue;
            }
            $c = self::cidr($i['ipv4']);
            if ($c === null) {
                $errors[] = "{$id}: static mode needs a valid IPv4 address and prefix";
                continue;
            }
            [$net, $p, $ip] = $c;
            if ($p < 8) {
                $errors[] = "{$id}: prefix /{$p} is too large for an interface";
                continue;
            }
            $bcast = long2ip(ip2long($net) | ((1 << (32 - $p)) - 1));
            if ($p < 31 && ($ip === $net || $ip === $bcast)) {
                $errors[] = "{$id}: {$ip} is the network or broadcast address of {$net}/{$p}";
                continue;
            }
            if ($i['enabled']) {
                foreach ($nets as [$oid, $onet, $op]) {
                    $min = min($p, $op);
                    if (self::inSubnet($net, $onet, $min)) {
                        $errors[] = "{$id}: subnet {$net}/{$p} overlaps {$oid} ({$onet}/{$op})";
                    }
                }
                $nets[] = [$id, $net, $p];
            }
            if (! empty($i['gateway'])) {
                if (filter_var($i['gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    $errors[] = "{$id}: gateway '{$i['gateway']}' is not an IPv4 address";
                } elseif (! self::inSubnet($i['gateway'], $net, $p)) {
                    $errors[] = "{$id}: gateway {$i['gateway']} is outside {$net}/{$p}";
                } elseif ($i['gateway'] === $ip) {
                    $errors[] = "{$id}: gateway cannot be the interface's own address";
                } elseif ($i['enabled']) {
                    $gws[] = $id;
                }
            }
        }
        if (count($gws) > 1) {
            $errors[] = 'only one interface may carry a static default gateway (found on ' . implode(', ', $gws) . '); use gateway groups for multi-WAN';
        }

        return $errors;
    }

    /**
     * Dynamic address tokens: `{lan:network}` (the interface's subnet) and
     * `{wan:address}` (its address). Policies that hold a token follow the
     * interface automatically; NftablesCompiler resolves them at compile time.
     */
    public const TOKEN_RE = '/^\{([A-Za-z0-9_-]{1,32}):(network|address)\}$/';

    public static function token(string $ifaceId, string $kind): string
    {
        return '{' . strtolower($ifaceId) . ':' . $kind . '}';
    }

    /** Human label for a token, or null when the value is not a token. */
    public static function describeToken(string $value): ?string
    {
        if (! preg_match(self::TOKEN_RE, trim($value), $m)) {
            return null;
        }

        return strtoupper($m[1]) . ($m[2] === 'network' ? ' subnet' : ' address');
    }
}
