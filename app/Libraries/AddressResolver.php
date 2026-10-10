<?php

namespace App\Libraries;

/**
 * AddressResolver — offline resolution for non-literal address objects.
 * ===================================================================
 * Some firewall address objects name something that is not an address:
 *
 *   fqdn              a domain, optionally a deep wildcard
 *   internet-service  a catalogue of published SaaS/cloud prefixes
 *   geoip             a country
 *
 * The nftables compiler inlines such an object from a per-set feed file, and
 * micrond refreshes the set from DNS / the catalogue at apply time. When the
 * appliance has no daemon or no reachable resolver, the set stays empty and the
 * policy silently matches nothing.
 *
 * This library is the offline half: it resolves an object into a CIDR list and
 * writes that list to the feed file the compiler reads, so a rule keeps working
 * without DNS. `spark micron:resolve-objects` drives it.
 */
class AddressResolver
{
    /** Object types this resolver understands (i.e. not literal addresses). */
    public const RESOLVABLE_TYPES = ['fqdn', 'internet-service', 'geoip'];

    /**
     * Resolves a single address row to a CIDR list.
     *
     * Literal types (ipmask / iprange) are returned as-is so a caller can treat
     * every object uniformly.
     *
     * @param  array<string,mixed> $address A firewall_addresses row
     * @return list<string>
     */
    public function resolve(array $address): array
    {
        $type  = (string) ($address['type'] ?? '');
        $value = trim((string) ($address['value'] ?? ''));

        return match ($type) {
            'ipmask'  => $value !== '' ? [$value] : [],
            'iprange' => $this->resolveRange($value),
            'fqdn'    => $this->resolveFqdn($value),
            'internet-service' => $this->resolveInternetService($value),
            'geoip'   => $this->resolveGeoIp($value),
            default   => [],
        };
    }

    /**
     * The feed files an object writes to, relative to WRITEPATH. Each entry is
     * [directory, basename] so the caller can create the directory if needed.
     *
     * @return list<array{0:string,1:string}>
     */
    public function feedTargets(array $address): array
    {
        $type  = (string) ($address['type'] ?? '');
        $value = trim((string) ($address['value'] ?? ''));
        $out   = [];

        if ($type === 'fqdn') {
            foreach (Fqdn::split($value) as $domain) {
                $out[] = [Fqdn::dataDir(), Fqdn::setName($domain) . '.list'];
            }
        } elseif ($type === 'internet-service') {
            foreach (InternetService::keys($value) as $key) {
                $out[] = [InternetService::dataDir(), $key . '.list'];
            }
        } elseif ($type === 'geoip') {
            foreach (GeoIp::codes($value) as $cc) {
                $out[] = [GeoIp::dataDir(), strtolower($cc) . '.netset'];
            }
        }

        return $out;
    }

    /**
     * Writes a CIDR list to a feed file, one entry per line.
     *
     * @param list<string> $prefixes
     */
    public function writeFeed(string $dir, string $basename, array $prefixes): bool
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0o750, true) && ! is_dir($dir)) {
            return false;
        }

        $header = "# MicronOS — generated on " . date('c') . " by the address object resolver\n";
        $body   = $prefixes === [] ? "# no prefixes resolved\n" : implode("\n", $prefixes) . "\n";

        return @file_put_contents($dir . '/' . $basename, $header . $body) !== false;
    }

    // ------------------------------------------------------------------
    // Per-type resolution
    // ------------------------------------------------------------------

    /** "10.0.0.1-10.0.0.50" → the range endpoints (nftables accepts them). */
    private function resolveRange(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        [$start, $end] = array_pad(explode('-', $value, 2), 2, null);
        $start = trim((string) $start);

        if (! filter_var($start, FILTER_VALIDATE_IP)) {
            return [];
        }
        if ($end !== null && trim($end) !== '' && ! filter_var(trim($end), FILTER_VALIDATE_IP)) {
            return [$start];
        }

        return [$value];
    }

    /**
     * Domains (including deep wildcards) resolved to their A records.
     *
     * A wildcard is walked one level deep via DNS TXT-style sub-domain
     * enumeration is not available, so the base and its immediate children are
     * resolved; the daemon does the exhaustive walk at apply time.
     *
     * @return list<string>
     */
    private function resolveFqdn(string $value): array
    {
        $out = [];

        foreach (Fqdn::split($value) as $domain) {
            foreach (Fqdn::networks($domain) as $cidr) {
                $out[] = $cidr;
            }

            // Also fold in a previously written feed so repeated runs converge
            // rather than dropping entries a previous resolution found.
            foreach (Fqdn::feed($domain) as $cidr) {
                $out[] = $cidr;
            }
        }

        return $this->unique($out);
    }

    /** @return list<string> */
    private function resolveInternetService(string $value): array
    {
        $out = [];
        foreach (InternetService::keys($value) as $key) {
            foreach (InternetService::networks($key) as $cidr) {
                $out[] = $cidr;
            }
        }

        return $this->unique($out);
    }

    /** @return list<string> */
    private function resolveGeoIp(string $value): array
    {
        $out = [];
        foreach (GeoIp::codes($value) as $cc) {
            foreach (GeoIp::networks($cc) as $cidr) {
                $out[] = $cidr;
            }
        }

        return $this->unique($out);
    }

    /**
     * @param  list<string> $values
     * @return list<string>
     */
    private function unique(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && Cidr::isValid($value) && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
