<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * ResolveObjects — the refresh engine behind `micron:resolve-objects`.
 * ==================================================================
 * Resolves non-literal address objects (fqdn, internet-service, geoip) into an
 * offline CIDR list, writes it to the per-set feed file the nftables compiler
 * inlines, and caches it on the row.
 *
 * Both the CLI command and the web UI call this, so a "Resolve Now" button and
 * a scheduled run cannot drift apart.
 */
class ResolveObjects
{
    /** Freshness window before an object's cached prefixes are "stale". */
    public const STALE_AFTER = 86400; // 24 hours

    private BaseConnection $db;

    private AddressResolver $resolver;

    public function __construct(?BaseConnection $db = null, ?AddressResolver $resolver = null)
    {
        $this->db       = $db ?? \Config\Database::connect();
        $this->resolver = $resolver ?? new AddressResolver();
    }

    /**
     * Resolves every matching object.
     *
     * @param  array{name?:string,type?:string} $filter
     * @return array{processed:int,stored:int,unresolvable:int,failed:int,objects:list<array>}
     */
    public function run(array $filter = [], bool $dryRun = false): array
    {
        $name = $filter['name'] ?? null;
        $type = $filter['type'] ?? null;

        $rows = $this->db->table('firewall_addresses')->orderBy('name', 'ASC')->get()->getResultArray();

        $summary = ['processed' => 0, 'stored' => 0, 'unresolvable' => 0, 'failed' => 0, 'objects' => []];

        foreach ($rows as $row) {
            $rowType = (string) ($row['type'] ?? '');

            if (! in_array($rowType, AddressResolver::RESOLVABLE_TYPES, true)) {
                continue;
            }
            if ($name !== null && $name !== '' && $row['name'] !== $name) {
                continue;
            }
            if ($type !== null && $type !== '' && $rowType !== $type) {
                continue;
            }

            $summary['processed']++;
            $prefixes = $this->resolver->resolve($row);
            $targets  = $this->resolver->feedTargets($row);

            $entry = [
                'id'       => $row['id'],
                'name'     => $row['name'],
                'type'     => $rowType,
                'count'    => count($prefixes),
                'targets'  => array_map(static fn ($t) => $t[0] . '/' . $t[1], $targets),
            ];

            if ($prefixes === []) {
                $summary['unresolvable']++;
                $entry['status'] = 'unresolvable';
                $summary['objects'][] = $entry;

                continue;
            }

            if ($dryRun) {
                $entry['status'] = 'would-store';
                $summary['objects'][] = $entry;

                continue;
            }

            $ok = true;
            foreach ($targets as [$dir, $base]) {
                $ok = $this->resolver->writeFeed($dir, $base, $prefixes) && $ok;
            }

            if (! $ok) {
                $summary['failed']++;
                $entry['status'] = 'failed';
                $summary['objects'][] = $entry;

                continue;
            }

            $this->db->table('firewall_addresses')->where('id', $row['id'])->update([
                'resolved_prefixes' => json_encode($prefixes),
                'resolved_at'       => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            $summary['stored']++;
            $entry['status'] = 'stored';
            $summary['objects'][] = $entry;
        }

        return $summary;
    }

    /**
     * Freshness of an address row's cached prefixes.
     *
     * @param  array<string,mixed> $address
     * @return array{state:string,label:string,age:?int} state is one of
     *         'literal' (nothing to resolve), 'never', 'stale', 'fresh'.
     */
    public function freshness(array $address): array
    {
        $type = (string) ($address['type'] ?? '');

        if (! in_array($type, AddressResolver::RESOLVABLE_TYPES, true)) {
            return ['state' => 'literal', 'label' => '', 'age' => null];
        }

        // Prefer the newest of the DB cache and the feed files on disk, so a
        // scheduled run that wrote feeds but was interrupted is still seen.
        $newest = null;

        if (! empty($address['resolved_at'])) {
            $ts = strtotime((string) $address['resolved_at']);
            if ($ts !== false) {
                $newest = $ts;
            }
        }

        foreach ($this->resolver->feedTargets($address) as [$dir, $base]) {
            $file = $dir . '/' . $base;
            if (is_file($file)) {
                $mtime = @filemtime($file);
                if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                    $newest = $mtime;
                }
            }
        }

        if ($newest === null) {
            return ['state' => 'never', 'label' => 'Never resolved', 'age' => null];
        }

        $age = time() - $newest;

        if ($age > self::STALE_AFTER) {
            return ['state' => 'stale', 'label' => 'Stale — ' . $this->humanAge($age) . ' ago', 'age' => $age];
        }

        return ['state' => 'fresh', 'label' => 'Resolved ' . $this->humanAge($age) . ' ago', 'age' => $age];
    }

    /** @return array<string,array{state:string,label:string,age:?int}> keyed by address id */
    public function freshnessMap(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            if (! empty($address['id'])) {
                $out[$address['id']] = $this->freshness($address);
            }
        }

        return $out;
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . 'h';
        }

        return floor($seconds / 86400) . 'd';
    }
}
