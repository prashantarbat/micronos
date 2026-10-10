<?php

namespace App\Connectors;

use App\Libraries\ConnectorEgressGuard;

/**
 * Manual DNSBL feed ingestion (specification §9.1; threat model F3).
 *
 * The operator-supplied rows in `dns_feeds` are a live SSRF surface in their own
 * right: they are typed by a human, not authored first-party. This class is the
 * only place those URLs are fetched, and it does so exclusively through
 * {@see ConnectorEgressGuard} — the same scheme allowlist, resolve-and-pin
 * denylist, per-hop redirect validation and stream/ratio caps that guard
 * manifest-defined sources.
 *
 * Each enabled feed is fetched into the staging directory, parsed into domains
 * (the `hostfile` format is a superset of the seeded feeds: it reads both
 * `0.0.0.0 domain` lines and plain domain-per-line lists), and reduced to a
 * canonical `.set`. The raw untrusted body is never retained. A feed that is
 * refused by the guard, fails to parse, or yields no valid entries is reported
 * as failed and contributes nothing — so an outage can never blank a live
 * blocklist.
 */
final class ManualFeedIngestor
{
    /** The parser used for operator feeds. */
    public const FORMAT = 'hostfile';

    private ConnectorEgressGuard $guard;
    private FeedParser $parser;
    private string $stageDir;

    public function __construct(ConnectorEgressGuard $guard, ?FeedParser $parser = null, ?string $stageDir = null)
    {
        $this->guard    = $guard;
        $this->parser   = $parser ?? new FeedParser();
        $root           = defined('WRITEPATH') ? WRITEPATH . 'connectors' : sys_get_temp_dir() . '/micron-connectors';
        $this->stageDir = rtrim($stageDir ?? $root . '/manual', '/\\');
    }

    /**
     * Fetches and parses every enabled feed row.
     *
     * @param list<array<string, mixed>> $feeds `dns_feeds` rows
     *
     * @return array{
     *   feeds: list<array{id: string, name: string, ok: bool, status: string, entries: int, dropped: int, error: string|null}>,
     *   entries: list<string>,
     *   accepted: int,
     *   dropped: int,
     *   synced: int,
     *   failed: int,
     *   staged: list<string>
     * }
     */
    public function ingest(array $feeds): array
    {
        $results  = [];
        $staged   = [];
        $combined = [];
        $accepted = 0;
        $dropped  = 0;
        $failed   = 0;

        if (! is_dir($this->stageDir) && ! @mkdir($this->stageDir, 0750, true) && ! is_dir($this->stageDir)) {
            foreach ($feeds as $feed) {
                $results[] = $this->row($feed, false, 'failed', 0, 0, 'cannot create the connector staging directory');
            }

            return $this->summary($results, [], $accepted, $dropped, 0, count($feeds), $staged);
        }

        foreach ($feeds as $feed) {
            $name = (string) ($feed['name'] ?? '');
            $url  = (string) ($feed['url'] ?? '');
            $safe = $this->safeName((string) ($feed['id'] ?? 'feed'));

            $feedPath = $this->stageDir . '/' . $safe . '.feed';
            $fetch    = $this->guard->fetch($url, ['destination' => $feedPath]);

            if (($fetch['ok'] ?? false) !== true) {
                $failed    = $failed + 1;
                $results[] = $this->row($feed, false, 'failed', 0, 0, 'egress refused or fetch failed: ' . ($fetch['error'] ?? 'unknown error'));
                continue;
            }

            $parsed = $this->parser->parseFile(self::FORMAT, $feedPath, 'domain');

            // The raw body is never retained, whether or not parsing succeeded.
            if (is_file($feedPath)) {
                @unlink($feedPath);
            }

            if (($parsed['ok'] ?? false) !== true) {
                $failed    = $failed + 1;
                $results[] = $this->row($feed, false, 'failed', 0, 0, 'parse failed: ' . ($parsed['error'] ?? 'unknown error'));
                continue;
            }

            $feedAccepted = (int) $parsed['counts']['accepted'];
            if ($feedAccepted === 0) {
                $failed    = $failed + 1;
                $results[] = $this->row($feed, false, 'failed', 0, (int) $parsed['counts']['dropped'], 'no valid entries after parsing');
                continue;
            }

            $setPath = $this->stageDir . '/' . $safe . '.set';
            if (! $this->writeAtomic($setPath, $this->renderSet($feed, $parsed))) {
                $failed    = $failed + 1;
                $results[] = $this->row($feed, false, 'failed', 0, 0, 'cannot write the typed set');
                continue;
            }

            $staged     = [...$staged, $setPath];
            $accepted   = $accepted + $feedAccepted;
            $dropped    = $dropped + (int) $parsed['counts']['dropped'];
            $results[]  = $this->row($feed, true, 'synced', $feedAccepted, (int) $parsed['counts']['dropped'], null);

            foreach ($parsed['entries'] as $entry) {
                $combined[(string) $entry] = true;
            }
        }

        $entries = array_keys($combined);
        sort($entries, SORT_STRING);

        // A combined artifact is the natural input for `dnsbl.apply`; it is only
        // written when there is something in it, so an all-failed run leaves no
        // empty set that could be mistaken for "block nothing".
        if ($entries !== []) {
            $combinedPath = $this->stageDir . '/manual-feeds.set';
            if ($this->writeAtomic($combinedPath, $this->renderCombined($entries, $accepted, $dropped))) {
                $staged = [...$staged, $combinedPath];
            }
        }

        $synced = count($feeds) - $failed;

        return $this->summary($results, $entries, $accepted, $dropped, $synced, $failed, $staged);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $feed
     * @param array<string, mixed> $parsed
     */
    private function renderSet(array $feed, array $parsed): string
    {
        $lines = [
            '# MicronOS manual DNSBL feed set — generated, do not edit',
            sprintf('# feed: %s  %s', (string) ($feed['id'] ?? ''), (string) ($feed['name'] ?? '')),
            sprintf('# entries: %d  dropped: %d', (int) $parsed['counts']['accepted'], (int) $parsed['counts']['dropped']),
        ];

        foreach ($parsed['entries'] as $entry) {
            $lines[] = (string) $entry;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $entries
     */
    private function renderCombined(array $entries, int $accepted, int $dropped): string
    {
        $lines = [
            '# MicronOS manual DNSBL combined set — generated, do not edit',
            sprintf('# feeds: %d entries  dropped: %d  unique: %d', $accepted, $dropped, count($entries)),
        ];

        foreach ($entries as $entry) {
            $lines[] = $entry;
        }

        return implode("\n", $lines) . "\n";
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    private function safeName(string $name): string
    {
        return substr(preg_replace('/[^a-z0-9_-]/', '_', strtolower($name)) ?? 'x', 0, 64);
    }

    /**
     * @param array<string, mixed> $feed
     *
     * @return array{id: string, name: string, ok: bool, status: string, entries: int, dropped: int, error: string|null}
     */
    private function row(array $feed, bool $ok, string $status, int $entries, int $dropped, ?string $error): array
    {
        return [
            'id'      => (string) ($feed['id'] ?? ''),
            'name'    => (string) ($feed['name'] ?? ''),
            'ok'      => $ok,
            'status'  => $status,
            'entries' => $entries,
            'dropped' => $dropped,
            'error'   => $error,
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param list<string>               $entries
     * @param list<string>               $staged
     *
     * @return array{feeds: list<array<string, mixed>>, entries: list<string>, accepted: int,
     *               dropped: int, synced: int, failed: int, staged: list<string>}
     */
    private function summary(array $results, array $entries, int $accepted, int $dropped, int $synced, int $failed, array $staged): array
    {
        return [
            'feeds'    => $results,
            'entries'  => $entries,
            'accepted' => $accepted,
            'dropped'  => $dropped,
            'synced'   => $synced,
            'failed'   => $failed,
            'staged'   => $staged,
        ];
    }
}
