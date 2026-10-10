<?php

namespace App\Libraries;

/**
 * ServiceSources — public prefix feeds for the Internet Service Database.
 * ======================================================================
 * Each Internet Service Database entry is backed by a public, machine-readable
 * list of the IP prefixes the platform publishes. This registry holds the feed
 * URL and a parser that turns that document into a list of CIDRs, so the
 * catalogue stays data-driven: adding a service is a row in this table, not a
 * change to the fetch engine.
 *
 * Feeds are deliberately vendor-neutral in name and text — only the public URL
 * of each platform's own published list appears here.
 *
 * Parser kinds:
 *   cidr_lines   one IP / CIDR per line (optionally with comments)
 *   json_ranges  a JSON document with an array of "prefix" style fields
 *   json_keyed   a JSON object whose values are arrays of prefix fields
 *   m365         the Microsoft 365 endpoint JSON (array of objects with
 *                "ips" plus an "expressRoute"/"urls" mix)
 *   cloudflare   the /client/v4/ips envelope
 *
 * A source may also declare `link_pattern`; the engine then treats the URL as
 * a landing page and downloads the newest matching link from it (used where a
 * provider re-issues its document under a dated file name).
 */
class ServiceSources
{
    /** Feed definition keyed by Internet Service Database id. */
    public const SOURCES = [
        // ── Microsoft 365 ────────────────────────────────────────────
        // A stable clientRequestId is required by the endpoint service.
        'microsoft_365' => [
            'url'    => 'https://endpoints.office.com/endpoints/worldwide?clientRequestId=b10c5ed1-bad1-445f-b386-b919946339a7',
            'parser' => 'm365',
        ],
        'teams' => [
            'url'    => 'https://endpoints.office.com/endpoints/worldwide?clientRequestId=b10c5ed1-bad1-445f-b386-b919946339a7',
            'parser' => 'm365',
        ],

        // ── Microsoft Azure ──────────────────────────────────────────
        // The published service-tag document is re-issued under a dated file
        // name, so the landing page is fetched first and the newest link
        // resolved from it.
        'microsoft_azure' => [
            'url'          => 'https://www.microsoft.com/en-us/download/details.aspx?id=56519',
            'parser'       => 'json_ranges',
            'link_pattern' => '~https://download\.microsoft\.com/download/[^"\'<>\s]+\.json~',
        ],

        // ── Google Cloud / Workspace ─────────────────────────────────
        'google_cloud' => [
            'url'    => 'https://www.gstatic.com/ipranges/cloud.json',
            'parser' => 'json_ranges',
        ],
        'google_workspace' => [
            'url'    => 'https://www.gstatic.com/ipranges/goog.json',
            'parser' => 'json_ranges',
        ],

        // ── Amazon Web Services ──────────────────────────────────────
        'aws' => [
            'url'    => 'https://ip-ranges.amazonaws.com/ip-ranges.json',
            'parser' => 'json_ranges',
        ],

        // ── Cloudflare ───────────────────────────────────────────────
        'cloudflare' => [
            'url'    => 'https://api.cloudflare.com/client/v4/ips',
            'parser' => 'cloudflare',
        ],

        // ── Conferencing ─────────────────────────────────────────────
        'zoom' => [
            'url'    => 'https://assets.zoom.us/docs/ipranges/Zoom.txt',
            'parser' => 'cidr_lines',
        ],

        // ── Developer ────────────────────────────────────────────────
        'github' => [
            'url'    => 'https://api.github.com/meta',
            'parser' => 'json_keyed',
            'fields' => ['hooks', 'web', 'api', 'git', 'pages', 'importer', 'actions'],
        ],

        // ── Threat intelligence ──────────────────────────────────────
        'tor' => [
            'url'    => 'https://check.torproject.org/torbulkexitlist',
            'parser' => 'cidr_lines',
        ],
        'botnet_c2' => [
            'url'    => 'https://feodotracker.abuse.ch/downloads/ipblocklist.txt',
            'parser' => 'cidr_lines',
        ],
    ];

    /** True when the registry has a feed for a catalogue key. */
    public static function has(string $key): bool
    {
        return isset(self::SOURCES[strtolower(trim($key))]);
    }

    /** Feed URL for a catalogue key, or null when none is registered. */
    public static function url(string $key): ?string
    {
        return self::SOURCES[strtolower(trim($key))]['url'] ?? null;
    }

    /** Parser kind for a catalogue key. */
    public static function parser(string $key): ?string
    {
        return self::SOURCES[strtolower(trim($key))]['parser'] ?? null;
    }

    /**
     * Regex that finds the real document link on a landing page, when the
     * provider publishes its feed under a rotating file name.
     */
    public static function linkPattern(string $key): ?string
    {
        return self::SOURCES[strtolower(trim($key))]['link_pattern'] ?? null;
    }

    /** Catalogue keys that have a public feed registered. */
    public static function keys(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * Extracts every IP / CIDR from a fetched document.
     *
     * @return list<string> validated, de-duplicated prefixes in original order
     */
    public static function parse(string $key, string $body): array
    {
        $key = strtolower(trim($key));
        $def = self::SOURCES[$key] ?? null;
        if ($def === null) {
            return [];
        }

        $found = match ($def['parser']) {
            'm365'         => self::parseM365($body),
            'json_ranges'  => self::parseJsonFields($body, ['prefix', 'ip_prefix', 'ipv6_prefix', 'ipv4Prefix', 'ipv6Prefix', 'addressPrefixes', 'cidr', 'network']),
            'json_keyed'   => self::parseJsonKeyed($body, $def['fields'] ?? []),
            'cloudflare'   => self::parseCloudflare($body),
            default        => self::parseCidrLines($body),
        };

        return self::uniqueValid($found);
    }

    // ------------------------------------------------------------------
    // Parsers
    // ------------------------------------------------------------------

    /** @return list<string> */
    private static function parseCidrLines(string $body): array
    {
        $out = [];
        foreach (preg_split('/[\r\n]+/', $body) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            // Some feeds append a comment after the entry.
            $line = trim((string) preg_split('/[\s#]/', $line)[0]);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * A JSON document with an array of prefix fields, at any nesting depth.
     *
     * A field may be a single string ("prefix": "10.0.0.0/8") or an array of
     * them ("addressPrefixes": ["10.0.0.0/8", …]).
     */
    private static function parseJsonFields(string $body, array $fields): array
    {
        $out = [];

        self::walk($body, function (array $node) use (&$out, $fields) {
            foreach ($fields as $field) {
                if (! isset($node[$field])) {
                    continue;
                }

                foreach ((array) $node[$field] as $value) {
                    if (is_string($value)) {
                        $out[] = $value;
                    }
                }
            }
        });

        return $out;
    }

    /** A JSON object whose named keys each hold a list of prefixes. */
    private static function parseJsonKeyed(string $body, array $fields): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            if (empty($decoded[$field]) || ! is_array($decoded[$field])) {
                continue;
            }
            foreach ($decoded[$field] as $entry) {
                if (is_string($entry)) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /** Cloudflare's /client/v4/ips wraps two arrays. */
    private static function parseCloudflare(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }

        $result = $decoded['result'] ?? $decoded;
        $out    = [];
        foreach (['ipv4_cidrs', 'ipv6_cidrs'] as $field) {
            if (! empty($result[$field]) && is_array($result[$field])) {
                foreach ($result[$field] as $entry) {
                    if (is_string($entry)) {
                        $out[] = $entry;
                    }
                }
            }
        }

        return $out;
    }

    /** Microsoft 365 endpoint JSON: array of objects, each with an "ips" list. */
    private static function parseM365(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $endpoint) {
            if (empty($endpoint['ips']) || ! is_array($endpoint['ips'])) {
                continue;
            }
            foreach ($endpoint['ips'] as $ip) {
                if (is_string($ip)) {
                    $out[] = $ip;
                }
            }
        }

        return $out;
    }

    /**
     * Decodes a JSON document and visits every associative node in it.
     */
    private static function walk(string $body, callable $visit): void
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return;
        }

        self::visitNodes($decoded, $visit);
    }

    /** @param array<mixed> $node */
    private static function visitNodes(array $node, callable $visit): void
    {
        if (! array_is_list($node)) {
            $visit($node);
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                self::visitNodes($value, $visit);
            }
        }
    }

    /**
     * Keeps only valid, unique IP/CIDR entries, preserving order.
     *
     * @param  list<string> $values
     * @return list<string>
     */
    private static function uniqueValid(array $values): array
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
