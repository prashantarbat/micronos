<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * ObjectSearch — server-side search and paging for the object pickers.
 * ===================================================================
 * A firewall policy's source / destination pickers used to render *every*
 * address object the appliance knows into the dialog and filter them in the
 * DOM. On a real install that is thousands of `<option>` nodes, a multi-megabyte
 * page, and a filter that walks the whole list on every keystroke.
 *
 * This library answers one page of a query instead, and the database does the
 * work: `LIKE ... LIMIT / OFFSET` plus one `COUNT(*)` per kind, so the cost of a
 * keystroke is bounded by `per` — not by the size of the object table.
 *
 * The same path serves every other catalogue the UI picks from: the 249-country
 * geography list, the Internet Service Database, and the firewall's own
 * services and service groups. The country and service catalogues live in code
 * rather than in a table (`GeoIp::COUNTRIES`, `InternetService::SERVICES`), so
 * those kinds are matched and sliced in memory — they are fixed, bounded lists,
 * and what has to stay bounded is the *page*. A picker that used to carry its
 * whole catalogue in the markup carries one page of it, from here.
 *
 * A page is filled by walking the kinds in order (address groups, then
 * individual addresses — the order the old optgroups used), so one page can span
 * kinds without ever loading a whole kind into memory.
 *
 * `total` counts every match across the searched kinds and `more` says whether
 * another page exists, which is what lets the picker tell an operator "showing
 * 200 of 4,118 matches — keep typing to narrow".
 *
 * `include` resolves values the operator has already picked, so a row for each
 * of them exists in the list even when the current query does not match them.
 * The form submits from those options, so this is not cosmetic: losing them
 * would silently drop objects from the policy that gets saved.
 */
final class ObjectSearch
{
    /** Rows per request when a caller does not say. */
    public const DEFAULT_PER = 200;

    /** Hard ceiling, so a hand-written `?per=100000` cannot defeat the paging. */
    public const MAX_PER = 500;

    /** Values `include` will resolve in one request. */
    public const MAX_INCLUDE = 100;

    /**
     * What each picker searches. The same definition drives the server-rendered
     * first page and the browser's later requests, so the two cannot drift.
     *
     * @var array<string, array{kinds:list<string>, per:int}>
     */
    public const PICKER_SOURCES = [
        'addressObjects'   => ['kinds' => ['group', 'address'], 'per' => self::DEFAULT_PER],
        'countries'        => ['kinds' => ['country'], 'per' => self::DEFAULT_PER],
        'internetServices' => ['kinds' => ['internetService'], 'per' => self::DEFAULT_PER],
        'firewallServices' => ['kinds' => ['serviceGroup', 'service'], 'per' => self::DEFAULT_PER],
    ];

    /**
     * The kinds this library can search, in page order. The order is what one
     * page walks, so a kind's rows are always contiguous — a page boundary can
     * never split a section in two.
     */
    private const KINDS = ['group', 'address', 'serviceGroup', 'service', 'country', 'internetService'];

    /**
     * What a request that names no kind gets. Only the address objects: a
     * hand-written `?kinds=` should not silently mix countries and services into
     * a list of firewall objects.
     */
    private const DEFAULT_KINDS = ['group', 'address'];

    /** Table and display rules per kind. `match` lists the columns a query is compared against. */
    private const TABLES = [
        'group'        => [
            'table'   => 'firewall_address_groups',
            'columns' => 'id, name',
            'match'   => ['name'],
        ],
        'address'      => [
            'table'   => 'firewall_addresses',
            'columns' => 'id, name, type, value',
            'match'   => ['name', 'value'],
        ],
        'serviceGroup' => [
            'table'   => 'firewall_service_groups',
            'columns' => 'id, name',
            'match'   => ['name'],
        ],
        'service'      => [
            'table'   => 'firewall_services',
            'columns' => 'id, name, protocol, tcp_port, udp_port',
            // The label shows the protocol and the ports, so those are what the
            // operator can see and therefore what a query has to reach: typing
            // "tcp" or "443" must narrow the list, not empty it.
            'match'   => ['name', 'protocol', 'tcp_port', 'udp_port'],
        ],
    ];

    /**
     * Kinds answered from a catalogue in code rather than from a table; see
     * `catalogue()` for the entries themselves.
     */
    private const CATALOGUES = ['country', 'internetService'];

    /**
     * The client-side config for `MicronPickerSearch.init()`: where to ask, what
     * to ask for, and how big a page is. Emitted by the views so the browser and
     * the server-rendered first page always agree on the page size.
     *
     * @return array{url:string, kinds:list<string>, per:int}
     */
    public static function pickerSource(string $name, string $url): array
    {
        if (! isset(self::PICKER_SOURCES[$name])) {
            throw new \InvalidArgumentException('Unknown picker source: ' . $name);
        }

        $source = self::PICKER_SOURCES[$name];

        return ['url' => $url, 'kinds' => $source['kinds'], 'per' => $source['per']];
    }

    /**
     * One page of matches.
     *
     * @param array{kinds?:string|list<string>, q?:string, page?:int|string|null,
     *              per?:int|string|null, include?:string|list<string>|null} $params
     * @return array{items:list<array>, selected:list<array>, total:int, page:int, per:int, more:bool}
     */
    public static function search(array $params, ?BaseConnection $db = null): array
    {
        $db = $db ?? \Config\Database::connect();

        $kinds   = self::kinds($params['kinds'] ?? null);
        $q       = trim((string) ($params['q'] ?? ''));
        $per     = self::positiveInt($params['per'] ?? null, self::DEFAULT_PER, self::MAX_PER);
        $page    = self::positiveInt($params['page'] ?? null, 1);
        $include = self::values($params['include'] ?? null);

        $items = [];
        $total = 0;
        $left  = $per;
        $skip  = ($page - 1) * $per;   // rows of the concatenated result already paged past

        foreach ($kinds as $kind) {
            $matches = self::countMatches($db, $kind, $q);
            $total  += $matches;

            if ($left <= 0 || $skip >= $matches) {
                $skip = max(0, $skip - $matches);
                continue;
            }

            $take = min($left, $matches - $skip);

            foreach (self::page($db, $kind, $q, $take, $skip) as $row) {
                $items[] = self::item($kind, $row);
            }

            $left -= $take;
            $skip  = 0;
        }

        return [
            'items'    => $items,
            'selected' => self::resolve($db, $include),
            'total'    => $total,
            'page'     => $page,
            'per'      => $per,
            'more'     => ($page * $per) < $total,
        ];
    }

    /**
     * The kinds a request asked for, in page order, with anything unknown
     * dropped. An empty selection falls back to the default kinds rather than to
     * an empty list, which is what a hand-typed `?kinds=` should mean.
     *
     * @return list<string>
     */
    private static function kinds(mixed $raw): array
    {
        $wanted = is_array($raw) ? $raw : explode(',', (string) $raw);
        $wanted = array_map(static fn ($k) => strtolower(trim((string) $k)), $wanted);
        $kinds  = [];

        foreach (self::KINDS as $kind) {
            if (in_array(strtolower($kind), $wanted, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds === [] ? self::DEFAULT_KINDS : $kinds;
    }

    /** True for a kind that is served from a catalogue in code, not from a table. */
    private static function isCatalogue(string $kind): bool
    {
        return in_array($kind, self::CATALOGUES, true);
    }

    /**
     * A builder carrying the match condition. Wildcards in the operator's own
     * text are escaped (`$escape = true`), so a search for "50%" looks for those
     * three characters instead of matching every object on the appliance.
     */
    private static function builder(BaseConnection $db, string $kind, string $q): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $db->table(self::TABLES[$kind]['table']);

        if ($q !== '') {
            // The builder appends `ESCAPE '!'` but leaves the value itself
            // alone, so the operator's `%`, `_` and `!` are escaped here.
            // escapeLikeString() would also double quotes, which the bound
            // value then has doubled a second time.
            $esc     = $db->likeEscapeChar;
            $literal = str_replace([$esc, '%', '_'], [$esc . $esc, $esc . '%', $esc . '_'], $q);

            $builder->groupStart();

            foreach (self::TABLES[$kind]['match'] as $index => $column) {
                $index === 0
                    ? $builder->like($column, $literal, 'both', true)
                    : $builder->orLike($column, $literal, 'both', true);
            }

            $builder->groupEnd();
        }

        return $builder;
    }

    /** How many rows a kind's query matches — one `COUNT(*)`, or a walk of a catalogue. */
    private static function countMatches(BaseConnection $db, string $kind, string $q): int
    {
        return self::isCatalogue($kind)
            ? count(self::catalogueMatches($kind, $q))
            : self::builder($db, $kind, $q)->countAllResults();
    }

    /**
     * One bounded slice of a kind's matches.
     *
     * @return list<array<string,mixed>>
     */
    private static function page(BaseConnection $db, string $kind, string $q, int $limit, int $offset): array
    {
        if (self::isCatalogue($kind)) {
            return array_slice(self::catalogueMatches($kind, $q), $offset, $limit);
        }

        return self::builder($db, $kind, $q)
            ->select(self::TABLES[$kind]['columns'])
            ->orderBy('name', 'ASC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();
    }

    /**
     * A catalogue kind's entries, in the shape a picker shows them.
     *
     * Countries and Internet Service Database entries live in code, not in a
     * table, and both are fixed, bounded lists (a few hundred rows at most):
     * matching them is a walk over plain strings rather than a query, which is
     * cheap where an unbounded table was not. `hay` is the lower-cased text a
     * query is compared against and is dropped before the entry is returned.
     *
     * @return list<array{value:string, label:string, icon:string, group:string, hay:string}>
     */
    private static function catalogue(string $kind): array
    {
        static $cache = [];

        if (isset($cache[$kind])) {
            return $cache[$kind];
        }

        $entries = [];

        if ($kind === 'country') {
            foreach (GeoIp::countries() as $code => $name) {
                $label     = $name . ' (' . $code . ')';
                $entries[] = [
                    'value' => (string) $code,
                    'label' => $label,
                    'icon'  => '',
                    'group' => 'Countries',
                    'hay'   => self::hay($label, (string) $code, 'Countries'),
                ];
            }
        } elseif ($kind === 'internetService') {
            // `grouped()` is already alphabetical by category, and a category's
            // rows have to stay together or a page boundary would split the
            // section the picker draws for it.
            foreach (InternetService::grouped() as $category => $services) {
                foreach ($services as $key => $service) {
                    $label     = $service['label'] . ' (' . $key . ')';
                    $entries[] = [
                        'value' => (string) $key,
                        'label' => $label,
                        'icon'  => '',
                        'group' => (string) $category,
                        'hay'   => self::hay($label, (string) $key, (string) $category),
                    ];
                }
            }
        }

        return $cache[$kind] = $entries;
    }

    /**
     * The catalogue entries a query matches.
     *
     * @return list<array{value:string, label:string, icon:string, group:string, hay:string}>
     */
    private static function catalogueMatches(string $kind, string $q): array
    {
        $entries = self::catalogue($kind);

        if ($q === '') {
            return $entries;
        }

        $needle = strtolower($q);

        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => str_contains($entry['hay'], $needle),
        ));
    }

    /**
     * The text a catalogue entry is matched against: its own label and value,
     * plus the heading it sits under, so typing a category ("Cloud") reveals the
     * services in it — exactly what the in-page filter did.
     *
     * A NUL never occurs in a query, and separates the three parts so they cannot
     * bleed into one another: "foo\0bar" is not "foobar".
     */
    private static function hay(string $label, string $value, string $group): string
    {
        return strtolower($label . "\0" . $value . "\0" . $group);
    }

    /**
     * Resolve already-picked values to rows. Addresses win over groups when both
     * carry the same name, matching the order the kinds are paged in.
     *
     * @param  list<string> $values
     * @return list<array>
     */
    private static function resolve(BaseConnection $db, array $values): array
    {
        if ($values === []) {
            return [];
        }

        // A picked value is a catalogue entry's value (a country code, a service
        // key) or an object's name, so membership has to be O(1) — a `resolve()`
        // rides on every page of every query.
        $wanted = array_flip($values);
        $found  = [];

        foreach (self::KINDS as $kind) {
            if (self::isCatalogue($kind)) {
                foreach (self::catalogue($kind) as $entry) {
                    if (isset($wanted[$entry['value']]) && ! isset($found[$entry['value']])) {
                        $found[$entry['value']] = self::item($kind, $entry);
                    }
                }

                continue;
            }

            $rows = $db->table(self::TABLES[$kind]['table'])
                ->whereIn('name', $values)
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $item = self::item($kind, $row);
                if (! isset($found[$item['value']])) {
                    $found[$item['value']] = $item;
                }
            }
        }

        $items = [];
        foreach ($values as $value) {
            if (isset($found[$value])) {
                $items[] = $found[$value];
            }
        }

        return $items;
    }

    /**
     * A stored object as the picker shows it. The label is the same in every
     * picker, so the same object cannot read two different ways on two screens.
     *
     * @param  array<string,mixed> $row
     * @return array{value:string, label:string, icon:string, group:string}
     */
    private static function item(string $kind, array $row): array
    {
        // A catalogue entry is already an item, bar the match text.
        if (self::isCatalogue($kind)) {
            unset($row['hay']);

            return $row;
        }

        $name = (string) ($row['name'] ?? '');

        if ($kind === 'group') {
            return ['value' => $name, 'label' => $name, 'icon' => '📁', 'group' => 'Address Groups'];
        }

        if ($kind === 'serviceGroup') {
            return ['value' => $name, 'label' => $name, 'icon' => '📦', 'group' => 'Service Groups'];
        }

        if ($kind === 'service') {
            return [
                'value' => $name,
                'label' => self::serviceLabel($row),
                'icon'  => '',
                'group' => 'Known Standard Services',
            ];
        }

        $type = (string) ($row['type'] ?? '');
        $icon = $type === 'fqdn' ? '🌐' : ($type === 'geoip' ? '🌍' : '🖥️');

        return [
            'value' => $name,
            'label' => $name . ' (' . (string) ($row['value'] ?? '') . ')',
            'icon'  => $icon,
            'group' => 'Individual Addresses & FQDNs',
        ];
    }

    /**
     * A service row as the policy dialog has always shown it: the name with the
     * protocol and whichever ports it carries, e.g. `HTTP (TCP TCP:80)`.
     */
    private static function serviceLabel(array $row): string
    {
        $label = (string) ($row['name'] ?? '') . ' (' . (string) ($row['protocol'] ?? '');

        foreach (['tcp_port' => 'TCP', 'udp_port' => 'UDP'] as $column => $protocol) {
            $port = trim((string) ($row[$column] ?? ''));
            if ($port !== '') {
                $label .= ' ' . $protocol . ':' . $port;
            }
        }

        return $label . ')';
    }

    /**
     * `include` as a clean list: arrays from `include[]=` and comma-joined
     * strings are both accepted, duplicates and blanks dropped, and the list cut
     * to a size one request can resolve.
     *
     * @return list<string>
     */
    private static function values(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);

        $values = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return array_slice($values, 0, self::MAX_INCLUDE);
    }

    /** A number from request input: blank, zero and junk fall back to `$default`. */
    private static function positiveInt(mixed $raw, int $default, ?int $max = null): int
    {
        $value = (int) $raw;

        if ($value < 1) {
            $value = $default;
        }

        return $max !== null ? min($value, $max) : $value;
    }
}
