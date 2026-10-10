<?php

namespace App\Libraries;

/**
 * Fqdn — validation and helpers for domain-name firewall objects.
 * ==============================================================
 * A firewall address object of type `fqdn` holds one or more domain names.
 * Domains are not addresses, so the nftables compiler cannot match them
 * directly: each domain becomes a named, interval set (`@fqdn_<name>`) that
 * micrond repopulates from DNS. A wildcard domain (`*.example.com`) is
 * expanded by the resolver, not by the packet filter, so it too becomes a set
 * of the currently resolved A records.
 *
 * Accepted forms per entry (comma / space / newline separated):
 *   example.com          exact host
 *   *.example.com        any sub-domain of example.com, at any depth
 *                        (a.example.com and a.b.example.com both match)
 *   www.*.example.com    `*` matches one or more labels in any position
 *
 * The deep match is why a wildcard must sit on the left of its domain: the
 * resolver walks every sub-domain, so `*.example.com` covers the whole tree.
 * A `*` that has a fixed label to its left (`a.*.example.com`) is therefore
 * rejected as ambiguous — there is no single label to walk from.
 *
 * A leading scheme (`https://`) and a trailing dot (`example.com.`) are
 * stripped, and the result is lower-cased, so what is stored is canonical.
 *
 * Optional inline feeds let a set be populated even before micrond has
 * resolved it, mirroring the GeoIP feed convention:
 *
 *   writable/fqdn/fqdn_example_com.list   # one IP or CIDR per line
 */
class Fqdn
{
    /** Maximum length of a full domain name (RFC 1035). */
    private const MAX_LENGTH = 253;

    /** Maximum length of a single label. */
    private const MAX_LABEL = 63;

    /**
     * Splits a comma/space/newline separated list of domains into trimmed,
     * non-empty, lower-cased entries.
     *
     * @return list<string>
     */
    public static function split(string $list): array
    {
        $parts = preg_split('/[\s,;]+/', $list) ?: [];
        $out   = [];

        foreach ($parts as $part) {
            $part = self::normalise((string) $part);
            if ($part !== '' && ! in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /** True when the entry contains a wildcard label. */
    public static function isWildcard(string $value): bool
    {
        return str_contains(self::strip($value), '*');
    }

    /**
     * The fixed domain a wildcard sits on, i.e. "*.example.com" → "example.com".
     * Returns '' for a non-wildcard entry.
     */
    public static function wildcardBase(string $value): string
    {
        $value = self::strip($value);
        if (! str_contains($value, '*')) {
            return '';
        }

        $labels = explode('.', $value);
        if ($labels[0] !== '*') {
            return '';
        }

        return implode('.', array_slice($labels, 1));
    }

    /**
     * Canonical stored form: lower-case, scheme and trailing dot removed.
     * Returns '' when the value cannot be read as a domain.
     */
    public static function normalise(string $value): string
    {
        return self::strip($value);
    }

    /** Strips a scheme, any path/port, surrounding whitespace and a trailing dot. */
    private static function strip(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Drop a scheme and anything after the authority (path, port, query).
        $value = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $value) ?? $value;
        $value = preg_replace('~[/?#].*$~', '', $value) ?? $value;
        $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        $value = rtrim($value, '.');
        $value = strtolower($value);

        return $value;
    }

    /**
     * Validates a single domain, optionally containing `*` labels.
     */
    public static function isValid(string $value): bool
    {
        $value = self::strip($value);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        $labels = explode('.', $value);

        // A wildcard matches one or more labels, which is only meaningful when
        // it is the left-most label (the resolver then walks every sub-domain).
        // A bare "*" has no domain to match against, and a `*` to the right of
        // a fixed label is ambiguous, so both are rejected.
        $hasWildcard = in_array('*', $labels, true);
        if ($hasWildcard) {
            if (count($labels) < 2 || $labels[0] !== '*') {
                return false;
            }
        } elseif (count($labels) < 2) {
            // A single label is still usable as a plain intranet hostname.
            return self::isValidLabel($labels[0]);
        }

        foreach ($labels as $label) {
            if ($label === '*') {
                continue;
            }
            if (! self::isValidLabel($label)) {
                return false;
            }
        }

        return true;
    }

    /** A single DNS label: 1–63 chars, alphanumeric with interior hyphens. */
    private static function isValidLabel(string $label): bool
    {
        return $label !== ''
            && strlen($label) <= self::MAX_LABEL
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) === 1;
    }

    /**
     * True when every entry in a comma/space separated list is a valid domain.
     * An empty list is valid (the field is optional).
     */
    public static function isValidList(string $list): bool
    {
        $entries = self::split($list);
        if ($entries === []) {
            return trim($list) === '';
        }

        foreach ($entries as $entry) {
            if (! self::isValid($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * nftables set identifier for a domain, e.g.
     *   example.com    → fqdn_example_com
     *   *.example.com  → fqdn_wild_example_com
     */
    public static function setName(string $value): string
    {
        $value = self::strip($value);
        $value = str_replace('*', 'wild', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        return 'fqdn_' . $value;
    }

    /** Directory that holds optional per-domain network feeds. */
    public static function dataDir(): string
    {
        return WRITEPATH . 'fqdn';
    }

    /**
     * Resolves a domain to its IPv4 addresses.
     *
     * A wildcard cannot be resolved literally, so it returns []. The set for a
     * wildcard is populated by micrond (or from the local feed) instead — the
     * daemon walks every sub-domain of the base and collects the A records, so
     * `*.example.com` covers `a.example.com` and `a.b.example.com` alike.
     *
     * @return list<string>
     */
    public static function resolve(string $value): array
    {
        $value = self::strip($value);
        if ($value === '' || str_contains($value, '*') || ! self::isValid($value)) {
            return [];
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($value, DNS_A);
            foreach ((array) $records as $record) {
                if (! empty($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $record['ip'];
                }
            }
        }

        if ($ips === []) {
            $ip = gethostbyname($value);
            if ($ip !== $value && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /** Absolute path of a domain's feed file, whether or not it exists yet. */
    public static function feedFile(string $value): string
    {
        return self::dataDir() . '/' . self::setName($value) . '.list';
    }

    /**
     * Networks for a domain from the local feed, when present. Inlined into the
     * nftables set so matching works even before micrond has resolved the name.
     *
     * @return list<string>
     */
    public static function feed(string $value): array
    {
        $file = self::feedFile($value);
        if (! is_file($file)) {
            return [];
        }

        $out = [];
        $raw = (string) @file_get_contents($file);
        foreach (preg_split('/[\s,]+/', $raw) as $entry) {
            $entry = trim((string) $entry);
            if ($entry !== '' && strpos($entry, '#') !== 0 && Cidr::isValid($entry)) {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Addresses to inline into a domain's nftables set: the local feed first,
     * otherwise a live resolution. Empty when the domain is a wildcard with no
     * feed (micrond populates the set at apply time).
     *
     * @return list<string>
     */
    public static function networks(string $value): array
    {
        $feed = self::feed($value);
        if ($feed !== []) {
            return $feed;
        }

        return self::resolve($value);
    }
}
