<?php

namespace App\Libraries;

/**
 * Cidr — validation and normalisation for address/network lists
 * =============================================================
 * Every VPN in MicronOS accepts lists of addresses or networks, because a
 * tunnel is rarely carrying just one:
 *
 *   WireGuard interface  Address = 10.9.0.1/24, fd00::1/64
 *   WireGuard peer       AllowedIPs = 10.9.0.2/32, 192.168.20.0/24, fd00::20::/64
 *   OpenVPN              route/iroute/push route lines, one per network
 *   IPsec                local_ts = 192.168.1.0/24, 192.168.5.0/24
 *
 * The UI stores those as comma- (or newline-) separated strings, so the same
 * parse/validate rules are needed in three controllers and three compilers.
 * Keeping them here avoids each one drifting into its own idea of what a valid
 * entry is.
 *
 * Accepted forms per entry:
 *   10.0.0.0/8        10.9.0.1       (bare address → host prefix)
 *   fd00::/64         fd00::1        (bare address → host prefix)
 *   "10.0.0.0 255.255.255.0" is NOT accepted here — that is an OpenVPN compiler
 *   concern, not a CIDR.
 */
class Cidr
{
    /**
     * Splits a comma/newline separated list into trimmed, non-empty entries.
     *
     * @return list<string>
     */
    public static function split(string $list): array
    {
        $parts = preg_split('/[,\n\r]+/', $list) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
    }

    /**
     * Normalises a list: trimmed, de-duplicated, original order preserved.
     *
     * Separators are normalised to ", " so a stored value always reads the same
     * way regardless of how it was typed.
     */
    public static function normalise(string $list): string
    {
        $seen  = [];
        $parts = [];

        foreach (self::split($list) as $entry) {
            $key = strtolower($entry);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $parts[]    = $entry;
        }

        return implode(', ', $parts);
    }

    /**
     * True when every entry is a valid IPv4/IPv6 address or CIDR network.
     * An empty list is considered valid (the field is optional).
     */
    public static function isValidList(string $list): bool
    {
        foreach (self::split($list) as $entry) {
            if (! self::isValid($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates a single "address" or "address/prefix" entry.
     */
    public static function isValid(string $cidr): bool
    {
        if (str_contains($cidr, '/')) {
            [$address, $prefix] = explode('/', $cidr, 2);
            if ($prefix === '' || ! ctype_digit($prefix)) {
                return false;
            }
            $bits = str_contains($address, ':') ? 128 : 32;

            return filter_var($address, FILTER_VALIDATE_IP) !== false
                && (int) $prefix >= 0 && (int) $prefix <= $bits;
        }

        return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * True when the entry names a whole network (prefix shorter than the host
     * prefix) rather than a single address.
     */
    public static function isNetwork(string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $cidr, 2);

        return (int) $prefix < (str_contains($address, ':') ? 128 : 32);
    }

    /**
     * "10.9.0.1" → "10.9.0.1/32", "fd00::1" → "fd00::1/128", CIDRs unchanged.
     */
    public static function withHostPrefix(string $entry): string
    {
        if (str_contains($entry, '/')) {
            return $entry;
        }

        return $entry . (str_contains($entry, ':') ? '/128' : '/32');
    }

    /**
     * "10.9.0.1" with no prefix → "10.9.0.1/<prefix>".
     *
     * Used for interface addresses, where the prefix is entered in its own field.
     */
    public static function withPrefix(string $entry, int $prefix): string
    {
        return str_contains($entry, '/') ? $entry : $entry . '/' . $prefix;
    }

    /**
     * The address part of an entry, without the prefix length.
     */
    public static function addressOf(string $cidr): string
    {
        return str_contains($cidr, '/') ? explode('/', $cidr)[0] : $cidr;
    }

    /**
     * The prefix length of an entry, defaulting to the host prefix.
     */
    public static function prefixOf(string $cidr): int
    {
        if (str_contains($cidr, '/')) {
            return (int) explode('/', $cidr, 2)[1];
        }

        return str_contains($cidr, ':') ? 128 : 32;
    }

    /**
     * The network address of a CIDR, e.g. "10.9.0.1/24" → "10.9.0.0/24".
     * Returns '' when the entry cannot be parsed.
     */
    public static function networkOf(string $cidr): string
    {
        $address = self::addressOf($cidr);
        $prefix  = self::prefixOf($cidr);

        if (str_contains($address, ':')) {
            $packed = @inet_pton($address);
            if ($packed === false) {
                return '';
            }
            $bytes = $prefix >> 3;
            for ($i = $bytes + 1; $i < 16; $i++) {
                $packed[$i] = "\x00";
            }
            if (($rem = $prefix % 8) !== 0 && $bytes < 16) {
                $packed[$bytes] = chr(ord($packed[$bytes]) & (0xFF << (8 - $rem)));
            }

            return inet_ntop($packed) . '/' . $prefix;
        }

        $long = ip2long($address);
        if ($long === false) {
            return '';
        }
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));

        return long2ip($long & $mask) . '/' . $prefix;
    }

    /**
     * The first host address of a network, e.g. "10.8.0.0/24" → "10.8.0.1".
     *
     * Used where an appliance takes the first usable address of its tunnel
     * network (the OpenVPN server, for instance).
     */
    public static function firstHost(string $cidr): string
    {
        $network = self::networkOf($cidr);
        if ($network === '') {
            return '';
        }

        $address = self::addressOf($network);
        $prefix  = self::prefixOf($network);

        if (str_contains($address, ':')) {
            $packed = @inet_pton($address);
            if ($packed === false) {
                return '';
            }
            $packed[15] = chr(ord($packed[15]) + 1);

            return inet_ntop($packed);
        }

        $long = ip2long($address);
        if ($long === false) {
            return '';
        }

        return long2ip($long + 1);
    }

    /**
     * 'ipv4' or 'ipv6' for an entry; '' when unparseable.
     */
    public static function family(string $cidr): string
    {
        $address = self::addressOf($cidr);
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'ipv4';
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'ipv6';
        }

        return '';
    }
}
