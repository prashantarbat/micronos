<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * ServiceDatabase — periodic refresh engine for the Internet Service Database.
 * ==========================================================================
 * The Internet Service Database is a catalogue of well-known SaaS / cloud /
 * threat platforms, each backed by a public list of published IP prefixes. This
 * engine downloads each entry's feed, extracts the CIDRs, writes them to the
 * per-service feed file the nftables compiler inlines, and caches the list on
 * the `internet_services` row together with a `last_synced_at` timestamp.
 *
 * It also refreshes geography feeds: a country object resolves to the networks
 * a national internet registry publishes for that country, fetched on demand
 * and written to `writable/geoip/<cc>.netset`.
 *
 * Both the scheduled job (`spark micron:sync-services`) and the web UI's
 * "Synchronize" button drive this class, so an automatic refresh and a manual
 * one cannot drift apart.
 */
class ServiceDatabase
{
    /** Default country feed template — %s is the lower-case ISO code. */
    public const COUNTRY_URL_TEMPLATE = 'https://www.ipdeny.com/ipblocks/data/aggregated/%s-aggregated.zone';

    private BaseConnection $db;

    private AddressResolver $resolver;

    /** @var callable|null Overrides the HTTP fetch (used by tests). */
    private $fetcher;

    public function __construct(?BaseConnection $db = null, ?callable $fetcher = null, ?AddressResolver $resolver = null)
    {
        $this->db       = $db ?? \Config\Database::connect();
        $this->fetcher  = $fetcher;
        $this->resolver = $resolver ?? new AddressResolver();
    }

    /**
     * Refreshes every enabled service that has a registered public feed.
     *
     * @param  list<string> $keys   Restrict to these catalogue keys (empty = all)
     * @return array{processed:int,synced:int,skipped:int,failed:int,services:list<array>}
     */
    public function syncServices(array $keys = [], bool $dryRun = false): array
    {
        $summary = ['processed' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'services' => []];

        $builder = $this->db->table('internet_services')->orderBy('id', 'ASC');
        if ($keys !== []) {
            $builder->whereIn('id', $keys);
        }

        foreach ($builder->get()->getResultArray() as $row) {
            $key = (string) $row['id'];

            if (empty($row['enabled'])) {
                continue;
            }

            $url = ServiceSources::url($key) ?: ($row['source_url'] ?: null);
            if ($url === null || $url === '') {
                $summary['skipped']++;
                $summary['services'][] = $this->entry($row, 'no-source', 'No public feed registered for this service.');

                continue;
            }

            $summary['processed']++;
            $body = $this->fetch($url);

            if ($body === null) {
                $summary['failed']++;
                $summary['services'][] = $this->entry($row, 'failed', 'Feed could not be downloaded.');

                continue;
            }

            // Some providers re-issue their document under a dated file name;
            // the configured URL is then a landing page and the real link is
            // resolved from it.
            if (($pattern = ServiceSources::linkPattern($key)) !== null) {
                $resolved = $this->resolveLink($pattern, $body);
                if ($resolved !== null) {
                    $body = $this->fetch($resolved) ?? '';
                    $url  = $resolved;
                }
            }

            $prefixes = ServiceSources::parse($key, $body);

            if ($prefixes === []) {
                // Never wipe a good list because a feed was empty or changed shape.
                $summary['failed']++;
                $summary['services'][] = $this->entry($row, 'failed', 'Feed contained no usable prefixes.');

                continue;
            }

            $entry = $this->entry($row, $dryRun ? 'dry-run' : 'synced', null, count($prefixes));

            if ($dryRun) {
                $summary['synced']++;
                $summary['services'][] = $entry;

                continue;
            }

            $ok = $this->resolver->writeFeed(InternetService::dataDir(), $key . '.list', $prefixes);

            if (! $ok) {
                $summary['failed']++;
                $summary['services'][] = $this->entry($row, 'failed', 'Could not write the service feed file.');

                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('internet_services')->where('id', $key)->update([
                'prefixes'       => json_encode($prefixes),
                'source_url'     => $url,
                'last_synced_at' => $now,
                'updated_at'     => $now,
            ]);

            $summary['synced']++;
            $summary['services'][] = $entry;
        }

        return $summary;
    }

    /**
     * Refreshes geography feeds for the given ISO 3166-1 alpha-2 codes.
     *
     * @param  list<string> $codes
     * @return array{processed:int,synced:int,skipped:int,failed:int,countries:list<array>}
     */
    public function syncCountries(array $codes = [], bool $dryRun = false): array
    {
        $summary = ['processed' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'countries' => []];

        foreach (GeoIp::codes(implode(',', $codes)) as $code) {
            $url = sprintf(self::COUNTRY_URL_TEMPLATE, strtolower($code));
            $summary['processed']++;

            $body = $this->fetch($url);
            if ($body === null) {
                $summary['failed']++;
                $summary['countries'][] = ['code' => $code, 'status' => 'failed', 'count' => 0, 'error' => 'Feed could not be downloaded.'];

                continue;
            }

            $prefixes = $this->parseCountry($body);
            if ($prefixes === []) {
                $summary['failed']++;
                $summary['countries'][] = ['code' => $code, 'status' => 'failed', 'count' => 0, 'error' => 'Feed contained no usable prefixes.'];

                continue;
            }

            if ($dryRun) {
                $summary['synced']++;
                $summary['countries'][] = ['code' => $code, 'status' => 'dry-run', 'count' => count($prefixes), 'error' => null];

                continue;
            }

            $ok = $this->resolver->writeFeed(GeoIp::dataDir(), strtolower($code) . '.netset', $prefixes);
            if (! $ok) {
                $summary['failed']++;
                $summary['countries'][] = ['code' => $code, 'status' => 'failed', 'count' => 0, 'error' => 'Could not write the country feed file.'];

                continue;
            }

            $summary['synced']++;
            $summary['countries'][] = ['code' => $code, 'status' => 'synced', 'count' => count($prefixes), 'error' => null];
        }

        return $summary;
    }

    /**
     * Every ISO code named by an existing `geoip` address object.
     *
     * @return list<string>
     */
    public function countriesInUse(): array
    {
        $out = [];
        $rows = $this->db->table('firewall_addresses')->where('type', 'geoip')->get()->getResultArray();

        foreach ($rows as $row) {
            foreach (GeoIp::codes((string) ($row['value'] ?? '')) as $code) {
                if (! in_array($code, $out, true)) {
                    $out[] = $code;
                }
            }
        }

        sort($out);

        return $out;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /**
     * Downloads a URL, returning the body or null on any failure.
     *
     * Uses cURL when the extension is present and falls back to a stream
     * wrapper, so the engine works on a minimal appliance image too.
     */
    public function fetch(string $url)
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        if (function_exists('curl_init')) {
            return $this->fetchWithCurl($url);
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 30,
                'user_agent'    => 'MicronOS-ServiceDatabase/1.0',
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);

        return $body === false ? null : $body;
    }

    private function fetchWithCurl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'MicronOS-ServiceDatabase/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code >= 400 || $code === 0) {
            return null;
        }

        return (string) $body;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Finds the newest document link on a landing page.
     */
    private function resolveLink(string $pattern, string $body): ?string
    {
        if (! preg_match_all($pattern, $body, $matches) || $matches[0] === []) {
            return null;
        }

        $links = array_values(array_unique($matches[0]));
        sort($links);

        return $links[count($links) - 1];
    }

    /** Parses a country registry feed: one CIDR per line. */
    private function parseCountry(string $body): array
    {
        $out = [];
        foreach (preg_split('/[\r\n]+/', $body) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (Cidr::isValid($line) && ! in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function entry(array $row, string $status, ?string $error = null, int $count = 0): array
    {
        return [
            'id'       => (string) $row['id'],
            'name'     => (string) $row['name'],
            'source'   => ServiceSources::url((string) $row['id']) ?? ($row['source_url'] ?? null),
            'status'   => $status,
            'count'    => $count,
            'error'    => $error,
        ];
    }
}
