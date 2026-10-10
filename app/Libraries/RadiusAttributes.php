<?php

namespace App\Libraries;

/**
 * Normalises the RADIUS reply attributes recorded for a PPPoE session.
 *
 * The attributes arrive as whatever the RADIUS server sent: an unordered map of
 * `Attribute-Name => value`, written by pppd's radattr plugin. The names are not
 * uniform across implementations — a rate limit is `Filter-Id` to one vendor and
 * a vendor-specific attribute to the next — so this reduces the map to the few
 * values an operator actually reads, while keeping the remainder for diagnosis.
 *
 * It is deliberately a pure function over the attribute map: no session, no
 * database, no I/O. The daemon reads the file, this decides what it means, and
 * the controller decides how to show it.
 */
class RadiusAttributes
{
    /**
     * Attribute names that carry a per-session rate limit, in priority order.
     *
     * `Filter-Id` is the standard one; the others are vendor attributes that
     * appliances commonly return instead of or alongside it.
     */
    public const RATE_LIMIT_NAMES = [
        'Filter-Id',
        'Mikrotik-Rate-Limit',
        'SN-Rate-Limit',
        'Cisco-AVPair',
    ];

    /** WISPr splits a limit into two attributes, in bits per second. */
    public const WISPR_DOWN = 'WISPr-Bandwidth-Max-Down';
    public const WISPR_UP = 'WISPr-Bandwidth-Max-Up';

    /**
     * Reduce a reply-attribute map to the values worth showing.
     *
     * @param  array<string,mixed> $attributes attribute name => value, as read
     * @return array{
     *     session_timeout: int|null,
     *     idle_timeout: int|null,
     *     interim_interval: int|null,
     *     framed_ip: string|null,
     *     rate_limit: string|null,
     *     others: array<string,string>,
     *     all: array<string,string>
     * }|array{}
     */
    public static function describe(array $attributes): array
    {
        // Lower-cased lookup, so a server's casing cannot hide an attribute.
        $lookup  = [];
        $display = [];
        foreach ($attributes as $name => $value) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $key = strtolower($name);
            $lookup[$key]  = trim((string) $value);
            $display[$key] = $name;
        }

        if ($lookup === []) {
            return [];
        }

        $consumed = [];

        // Finds the first present, non-empty name without claiming it: an
        // attribute is only consumed once its value has been understood, so one
        // that cannot be interpreted stays visible for diagnosis.
        $peek = static function (array $names) use ($lookup): ?string {
            foreach ($names as $name) {
                $key = strtolower($name);
                if (isset($lookup[$key]) && $lookup[$key] !== '') {
                    return $key;
                }
            }

            return null;
        };

        $use = static function (string $key) use ($lookup, &$consumed): string {
            $consumed[$key] = true;

            return $lookup[$key];
        };

        $integer = static function (array $names) use ($peek, $lookup, $use): ?int {
            $key = $peek($names);

            return ($key !== null && ctype_digit($lookup[$key])) ? (int) $use($key) : null;
        };

        $address = static function (array $names) use ($peek, $lookup, $use): ?string {
            $key = $peek($names);

            return ($key !== null && self::isIp($lookup[$key])) ? $use($key) : null;
        };

        $result = [
            'session_timeout'  => $integer(['Session-Timeout']),
            'idle_timeout'     => $integer(['Idle-Timeout']),
            // The accounting cadence the server asked for. Present only when the
            // server returns it, because pppd sends no interim records without it.
            'interim_interval' => $integer(['Acct-Interim-Interval']),
            'framed_ip'        => $address(['Framed-IP-Address']),
            'rate_limit'       => self::rateLimit($peek, $use, $lookup),
        ];

        $others = [];
        foreach ($lookup as $key => $value) {
            if ($value === '' || isset($consumed[$key])) {
                continue;
            }
            $others[$display[$key]] = $value;
        }

        $all = [];
        foreach ($lookup as $key => $value) {
            $all[$display[$key]] = $value;
        }

        return [
            'session_timeout'  => $result['session_timeout'],
            'idle_timeout'     => $result['idle_timeout'],
            'interim_interval' => $result['interim_interval'],
            'framed_ip'        => $result['framed_ip'],
            'rate_limit'       => $result['rate_limit'],
            'others'           => $others,
            'all'              => $all,
        ];
    }

    /**
     * The rate limit, from whichever attribute the server used.
     *
     * @param callable(list<string>):?string $peek returns the key without claiming it
     * @param callable(string):string        $use  claims a key and returns its value
     * @param array<string,string>           $lookup
     */
    private static function rateLimit(callable $peek, callable $use, array $lookup): ?string
    {
        if (($key = $peek(self::RATE_LIMIT_NAMES)) !== null) {
            return $use($key);
        }

        // WISPr returns the two directions as separate bit/second attributes.
        $downKey = $peek([self::WISPR_DOWN]);
        $upKey   = $peek([self::WISPR_UP]);
        if ($downKey !== null || $upKey !== null) {
            $down = $downKey !== null ? $use($downKey) : null;
            $up   = $upKey !== null ? $use($upKey) : null;

            return sprintf('%s/%s kbps', self::kbps($down), self::kbps($up));
        }

        // Any other vendor attribute that names itself a rate limit.
        foreach ($lookup as $key => $value) {
            if ($value !== '' && str_contains($key, 'rate-limit')) {
                return $use($key);
            }
        }

        return null;
    }

    /** Bits per second, as a whole number of kbps; empty when absent. */
    private static function kbps(?string $bits): string
    {
        if ($bits === null || ! ctype_digit($bits)) {
            return '';
        }

        return (string) max(0, (int) round(((int) $bits) / 1000));
    }

    private static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
