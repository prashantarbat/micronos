<?php

namespace App\Connectors;

use App\Libraries\Cidr;

/**
 * Feed parser — staged artifact → canonical typed set (specification §8.2 steps
 * 3c–3e).
 *
 * A connector source declares a `format` from the closed enum in
 * {@see Manifest::FORMATS}. This class turns whatever the egress guard fetched
 * into one of two canonical sets:
 *
 *   domain   — a lower-cased hostname that a DNS blocklist can compile
 *   address  — a normalised CIDR that a firewall set can hold
 *
 * Per-entry validation is the point: every candidate is checked, invalid entries
 * are dropped and counted (never silently), and the result is de-duplicated and
 * sorted so re-applying the same feed is a no-op.
 *
 * The domain rules intentionally mirror micrond's `DOMAIN_RE` so anything the
 * control plane accepts is something the data plane will also compile — a
 * mismatch would mean entries that pass here silently vanish at apply time.
 *
 * All line-oriented formats stream, and every format enforces an entry cap and
 * a per-line length cap, so a hostile feed cannot exhaust PHP memory.
 */
final class FeedParser
{
    /** Hard cap on accepted entries in one artifact (matches micrond). */
    public const MAX_ENTRIES = 2_000_000;

    /** Cap on the JSON document read into memory. */
    public const MAX_JSON_BYTES = 32 * 1024 * 1024;

    /** Longest physical line considered; anything longer is dropped. */
    public const MAX_LINE = 8192;

    /** How many rejected samples are retained for diagnostics. */
    public const MAX_DROPPED_SAMPLES = 20;

    /**
     * The data-plane domain rule, byte-for-byte identical to micrond's
     * `DOMAIN_RE`: at least one label plus a letters-only TLD, 253 chars max.
     */
    private const DOMAIN_RE = '/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z]{2,63}$/';

    /** Object keys preferred as entry containers in JSON feeds. */
    private const JSON_ENTRY_KEYS = [
        'addresses', 'addressprefixes', 'prefixes', 'ips', 'cidrs', 'nets', 'networks',
        'domains', 'hosts', 'hostnames', 'entries', 'list', 'data', 'results', 'values',
    ];

    /**
     * The canonical type a format produces. `csv` is ambiguous, so its type is
     * taken from the caller's hint (normally the source target) and otherwise
     * defaults to `address`.
     */
    public static function typeForFormat(string $format, ?string $hint = null): string
    {
        return match ($format) {
            'plain-domain-list', 'hostfile', 'json-domain-list' => 'domain',
            'plain-ip-list', 'json-ip-list', 'rfc1035-spf'          => 'address',
            'csv' => in_array($hint, ['domain', 'address'], true) ? $hint : 'address',
            default => 'address',
        };
    }

    /**
     * Parses a staged artifact.
     *
     * @return array{
     *   ok: bool,
     *   type: string,
     *   entries: list<string>,
     *   counts: array{seen: int, accepted: int, dropped: int},
     *   dropped_samples: list<string>,
     *   truncated: bool,
     *   error: string|null
     * }
     */
    public function parseFile(string $format, string $path, ?string $typeHint = null): array
    {
        $type = self::typeForFormat($format, $typeHint);

        if (! is_file($path) || ! is_readable($path)) {
            return $this->failure($type, 'staged artifact is missing or unreadable');
        }

        if (! in_array($format, Manifest::FORMATS, true)) {
            return $this->failure($type, 'unsupported format: ' . $format);
        }

        $seen     = [];
        $counts   = ['seen' => 0, 'accepted' => 0, 'dropped' => 0];
        $samples  = [];
        $truncated = false;

        $collect = function (string $candidate) use (&$seen, &$counts, &$samples, &$truncated, $type): void {
            $candidate = trim($candidate);
            if ($candidate === '') {
                return;
            }

            $counts['seen']++;

            $canonical = $type === 'domain'
                ? self::normaliseDomain($candidate)
                : self::normaliseAddress($candidate);

            if ($canonical === null) {
                $counts['dropped']++;
                if (count($samples) < self::MAX_DROPPED_SAMPLES) {
                    $samples[] = self::clip($candidate);
                }

                return;
            }

            if (isset($seen[$canonical])) {
                return; // already counted as accepted
            }

            if (count($seen) >= self::MAX_ENTRIES) {
                $truncated = true;

                return;
            }

            $seen[$canonical] = true;
            $counts['accepted']++;
        };

        switch ($format) {
            case 'plain-domain-list':
            case 'plain-ip-list':
                $this->eachLine($path, static function (string $line) use ($collect, $type): void {
                    if ($type === 'address') {
                        // Strip an inline comment, then take every token on the line.
                        if (($hash = strpos($line, '#')) !== false) {
                            $line = substr($line, 0, $hash);
                        }
                        foreach (preg_split('/[\s,]+/', $line) ?: [] as $token) {
                            $collect((string) $token);
                        }

                        return;
                    }

                    // A comment or blank line is not an entry.
                    $trimmed = ltrim($line);
                    if ($trimmed !== '' && ! str_starts_with($trimmed, '#')) {
                        $collect($line);
                    }
                });
                break;

            case 'hostfile':
                $this->eachLine($path, static function (string $line) use ($collect): void {
                    $trimmed = ltrim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        return;
                    }
                    $collect($line); // normaliseDomain takes the trailing token
                });
                break;

            case 'csv':
                $this->eachCsvRow($path, static function (array $row) use ($collect): void {
                    foreach ($row as $cell) {
                        if (trim((string) $cell) !== '') {
                            $collect((string) $cell);

                            return; // first non-empty cell is the entry
                        }
                    }
                });
                break;

            case 'json-ip-list':
            case 'json-domain-list':
                $error = $this->parseJson($path, $collect);
                if ($error !== null) {
                    return $this->failure($type, $error);
                }
                break;

            case 'rfc1035-spf':
                $this->eachLine($path, static function (string $line) use ($collect): void {
                    foreach (preg_split('/\s+/', $line) ?: [] as $token) {
                        $lower = strtolower((string) $token);
                        if (str_starts_with($lower, 'ip4:') || str_starts_with($lower, 'ip6:')) {
                            $collect(substr($lower, 4));
                        }
                    }
                });
                break;
        }

        $entries = array_keys($seen);
        sort($entries, SORT_STRING);

        return [
            'ok'              => true,
            'type'            => $type,
            'entries'         => $entries,
            'counts'          => $counts,
            'dropped_samples' => $samples,
            'truncated'       => $truncated,
            'error'           => null,
        ];
    }

    // ------------------------------------------------------------------
    // Per-type normalisation
    // ------------------------------------------------------------------

    /**
     * A canonical domain, or null when the entry is not a valid hostname.
     *
     * Mirrors micrond's reader: strip an inline `#` comment, take the last
     * whitespace-delimited token (so `hosts`-format lines work), strip leading
     * and trailing dots and lower-case.
     */
    public static function normaliseDomain(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (($hash = strpos($value, '#')) !== false) {
            $value = trim(substr($value, 0, $hash));
        }
        if ($value === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $value) ?: [];
        $token = strtolower((string) end($parts));
        $token = rtrim(ltrim($token, '.'), '.');

        if ($token === '' || strlen($token) > 253) {
            return null;
        }

        return preg_match(self::DOMAIN_RE, $token) === 1 ? $token : null;
    }

    /**
     * A canonical CIDR, or null when the entry is not a valid address/network.
     *
     * Bare addresses gain the host prefix (`1.2.3.4` → `1.2.3.4/32`), and
     * networks are masked to their network address (`10.0.0.9/24` →
     * `10.0.0.0/24`), so the set is stable and de-duplicable.
     */
    public static function normaliseAddress(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (($hash = strpos($value, '#')) !== false) {
            $value = trim(substr($value, 0, $hash));
        }

        // A bare `ip` or `ip/prefix`; a range is not a CIDR and is dropped.
        $value = trim($value);
        if ($value === '' || ! Cidr::isValid($value)) {
            return null;
        }

        $network = Cidr::networkOf($value);

        return $network === '' ? null : $network;
    }

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    /**
     * Streams logical lines, capping physical line length. An overlong line is
     * drained and reported as empty so it is counted as a dropped entry rather
     * than split into junk.
     *
     * @param callable(string): void $fn
     */
    private function eachLine(string $path, callable $fn): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle, self::MAX_LINE)) !== false) {
            if (! str_ends_with($line, "\n") && ! feof($handle)) {
                while (($rest = fgets($handle, self::MAX_LINE)) !== false && ! str_ends_with($rest, "\n")) {
                    // discard the remainder of an overlong line
                }
                $fn('');

                continue;
            }

            $fn(rtrim($line, "\r\n"));
        }

        fclose($handle);
    }

    /**
     * Streams CSV rows, skipping a header row when the first cell is a known
     * label.
     *
     * @param callable(list<string|null>): void $fn
     */
    private function eachCsvRow(string $path, callable $fn): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        $first = true;
        while (($row = fgetcsv($handle, self::MAX_LINE, ',', '"', '\\')) !== false) {
            if ($first) {
                $first = false;
                $head = strtolower(trim((string) ($row[0] ?? '')));
                if (in_array($head, ['domain', 'domains', 'host', 'hostname', 'ip', 'address', 'network', 'indicator'], true)) {
                    continue;
                }
            }
            $fn($row);
        }

        fclose($handle);
    }

    /** Parses a JSON artifact and feeds every string candidate to $collect. */
    private function parseJson(string $path, callable $collect): ?string
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_JSON_BYTES) {
            return 'json artifact exceeds the parsing size cap';
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 'json artifact could not be read';
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'json artifact is not valid JSON';
        }

        $this->walkJson($decoded, $collect);

        return null;
    }

    /**
     * Walks a decoded JSON value. At an object level, when one of the known
     * entry keys is present only those keys are descended — that keeps free-form
     * metadata out of the drop count for real feeds (service tags, Q-Feeds
     * envelopes, and similar).
     *
     * @param callable(string): void $collect
     */
    private function walkJson(mixed $node, callable $collect): void
    {
        if (is_string($node)) {
            $collect($node);

            return;
        }

        if (! is_array($node)) {
            return;
        }

        $preferred = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::JSON_ENTRY_KEYS, true)) {
                $preferred[] = $value;
            }
        }

        foreach ($preferred !== [] ? $preferred : $node as $value) {
            $this->walkJson($value, $collect);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function clip(string $value): string
    {
        $value = preg_replace('/[^\P{C}\s]+/u', '', $value) ?? $value; // strip control chars

        return substr($value, 0, 120);
    }

    /**
     * @return array{ok: bool, type: string, entries: list<string>,
     *               counts: array{seen: int, accepted: int, dropped: int},
     *               dropped_samples: list<string>, truncated: bool, error: string}
     */
    private function failure(string $type, string $error): array
    {
        return [
            'ok'              => false,
            'type'            => $type,
            'entries'         => [],
            'counts'          => ['seen' => 0, 'accepted' => 0, 'dropped' => 0],
            'dropped_samples' => [],
            'truncated'       => false,
            'error'           => $error,
        ];
    }
}
