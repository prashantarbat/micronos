<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Dynamic DNS Server Registry
 * ===========================
 * Records the DNS servers that a dynamic link handed to the appliance — a
 * DHCP lease on a WAN interface, a PPPoE/PPP peer, or a remote OpenVPN server
 * with Pull DNS — so `SystemResolverCompiler` can merge them into the
 * appliance stub resolver while the **DNS Server Override** is enabled (and
 * ignore them when it is not).
 *
 * The interface path owns this store: on a successful interface/PPPoE/OpenVPN
 * apply, `ApplianceResolver` reads the data plane's current report and calls
 * `record()` with it. `record()` replaces the whole table, so a link that goes
 * down is dropped on the next reconcile rather than lingering. `clear()` drops
 * everything, which is what happens when the operator turns the override off.
 *
 * Deliberately separate from `SystemResolverCompiler`: the compiler stays a
 * reader/compiler of DNS state, while this class is the one writer of the
 * dynamic record. Both read the same `dynamic_dns_servers` table.
 */
class DynamicDnsRegistry
{
    /** Which dynamic link assigned the server, in WAN-first merge order. */
    public const SOURCES = ['dhcp', 'ppp', 'openvpn'];

    /** A resolv.conf never carries more than a handful of servers. */
    private const MAX_SERVERS = 8;

    protected ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /** True when the table exists (a fresh, unmigrated install tolerates its absence). */
    public function available(): bool
    {
        return $this->db !== null && $this->db->tableExists('dynamic_dns_servers');
    }

    /** True when a source is one of the accepted kinds. */
    public static function isValidSource(string $source): bool
    {
        return in_array(strtolower(trim($source)), self::SOURCES, true);
    }

    /**
     * Replaces the entire dynamic record with the data plane's current report.
     *
     * @param array<int, array{source?: mixed, interface?: mixed, interface_id?: mixed, servers?: mixed}> $sources
     * @return int rows stored
     */
    public function record(array $sources): int
    {
        if (! $this->available()) {
            return 0;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];
        $seen = [];

        foreach ($sources as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $source = strtolower(trim((string) ($entry['source'] ?? '')));
            if (! self::isValidSource($source)) {
                continue;
            }

            $interfaceName = trim((string) ($entry['interface'] ?? $entry['interface_name'] ?? ''));
            $interfaceId   = trim((string) ($entry['interface_id'] ?? ''));

            foreach ((array) ($entry['servers'] ?? []) as $address) {
                $address = trim((string) $address);
                if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
                    continue;
                }

                // A server is stored once per source; a duplicate inside one
                // report (or an address repeated across sources) is a no-op.
                $key = $source . '|' . $address;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if (count($rows) >= self::MAX_SERVERS) {
                    break 2;
                }

                $rows[] = [
                    'id'             => 'dyn_' . substr(hash('sha256', $key . '|' . $now), 0, 24),
                    'source'         => $source,
                    'interface_id'   => $interfaceId !== '' ? $interfaceId : null,
                    'interface_name' => $interfaceName !== '' ? $interfaceName : null,
                    'address'        => $address,
                    'assigned_at'    => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        $this->db->table('dynamic_dns_servers')->emptyTable();
        if ($rows !== []) {
            $this->db->table('dynamic_dns_servers')->insertBatch($rows);
        }

        return count($rows);
    }

    /** Drops every recorded dynamic server. Returns how many rows were removed. */
    public function clear(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $count = $this->db->table('dynamic_dns_servers')->countAllResults();
        $this->db->table('dynamic_dns_servers')->emptyTable();

        return $count;
    }

    /**
     * Every stored row, WAN-first by source then address.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->db->table('dynamic_dns_servers')
            ->orderBy('source', 'ASC')
            ->orderBy('address', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Every stored address, deduplicated, WAN-first.
     *
     * @return array<int, string>
     */
    public function servers(): array
    {
        $priority = array_flip(self::SOURCES);

        $rows = $this->all();
        usort($rows, static function (array $a, array $b) use ($priority): int {
            $pa = $priority[strtolower((string) ($a['source'] ?? ''))] ?? PHP_INT_MAX;
            $pb = $priority[strtolower((string) ($b['source'] ?? ''))] ?? PHP_INT_MAX;

            return $pa <=> $pb ?: strcmp((string) ($a['address'] ?? ''), (string) ($b['address'] ?? ''));
        });

        $servers = [];
        foreach ($rows as $row) {
            $address = (string) ($row['address'] ?? '');
            if ($address !== '' && ! in_array($address, $servers, true)) {
                $servers[] = $address;
            }
        }

        return array_slice($servers, 0, self::MAX_SERVERS);
    }
}
