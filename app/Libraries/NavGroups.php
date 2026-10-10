<?php

namespace App\Libraries;

/**
 * The sidebar's collapsible parents.
 *
 * The rail's shape is data, not markup: the layout draws each parent from this
 * table, and the endpoint that remembers the section an operator unfolded
 * validates against it. Keeping the table here rather than inside the layout is
 * what makes the second half possible — a value that does not name a real
 * parent can never be written to the session, and the two ends of the feature
 * cannot drift apart about what the parents are.
 *
 * The rail is an accordion: one section is unfolded at a time, because eight
 * parents and thirty-nine links in a column is more than anyone wants to scroll
 * past to reach the last of them. What the session stores is therefore the one
 * section the operator chose, or nothing at all once they have folded the last
 * one away — not a set.
 *
 * @internal
 */
final class NavGroups
{
    /**
     * Session key holding the section the operator unfolded.
     *
     * A parent key, or the empty string for "I folded everything away". A
     * session with no such key at all means the operator has not chosen yet and
     * the rail should decide for them (see `opens()`).
     */
    public const SESSION_KEY = 'nav_groups_open';

    /**
     * The parents, in rail order: the label on the toggle, the icon it draws,
     * and the routes each one owns.
     *
     * The routes are the only place "which parent does this page belong to" is
     * written down: the layout opens the parent that owns the current URL,
     * because a menu whose own entry is folded away is worse than no menu at
     * all. The icon travels with the group because in the collapsed rail the
     * toggle is all that is left of it.
     *
     * @return array<string, array{label: string, icon: string, routes: list<string>}>
     */
    public static function all(): array
    {
        $p = static fn (string $d): string =>
            '<path stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/>';

        return [
            'network' => [
                'label'  => 'Network',
                'icon'   => $p('M5 12h14M12 5l7 7-7 7'),
                'routes' => ['/interfaces', '/multicast'],
            ],
            'policy' => [
                'label'  => 'Policy & Objects',
                'icon'   => $p('M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'),
                'routes' => ['/firewall', '/nat', '/qos'],
            ],
            'routing' => [
                'label'  => 'Routing & Clustering',
                'icon'   => $p('M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'),
                'routes' => ['/ha', '/routing', '/pppoe', '/dhcp', '/dns'],
            ],
            'vpn' => [
                'label'  => 'VPN',
                'icon'   => $p('M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'),
                'routes' => ['/vpn'],
            ],
            'security' => [
                'label'  => 'Security',
                'icon'   => $p('M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'),
                'routes' => ['/webfilter', '/ids', '/appid', '/certificates', '/siem'],
            ],
            'identity' => [
                'label'  => 'Identity',
                'icon'   => $p('M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197'),
                'routes' => ['/users', '/roles'],
            ],
            'admin' => [
                'label'  => 'Administration',
                'icon'   => $p('M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z')
                    . $p('M15 12a3 3 0 11-6 0 3 3 0 016 0z'),
                'routes' => ['/backup', '/diag', '/services', '/snmp', '/cron', '/system', '/docs'],
            ],
            'plugins' => [
                'label'  => 'Plugins',
                'icon'   => $p('M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'),
                'routes' => ['/plugins'],
            ],
        ];
    }

    /**
     * The parent keys, in rail order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Whether the parent named by $key owns the page at $path. */
    public static function owns(string $path, string $key): bool
    {
        foreach (self::all()[$key]['routes'] ?? [] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a parent renders unfolded.
     *
     * The operator's own choice decides — that is what survives from page to
     * page, and honouring it is the whole point. Only until they make one does
     * the rail pick for them, opening the parent that owns the page being drawn
     * so a fresh session is never handed a rail with the current entry hidden
     * somewhere in it.
     *
     * Note that an operator who unfolds a section other than this page's own is
     * therefore shown exactly that section on arrival: an accordion cannot both
     * remember one section and always show the current one.
     */
    public static function opens(string $key, string $path): bool
    {
        $chosen = self::chosen();

        return $chosen === null ? self::owns($path, $key) : $chosen === $key;
    }

    /**
     * The section this session unfolded, or null if the operator has not chosen.
     *
     * Null and '' are different answers: the first means "you decide", the
     * second "I folded the last one away". Read back through `sanitize()`
     * rather than trusted, since a session outlives a parent being renamed.
     */
    public static function chosen(): ?string
    {
        return session()->has(self::SESSION_KEY)
            ? self::sanitize(session()->get(self::SESSION_KEY))
            : null;
    }

    /**
     * Reduce any input to one parent key, or '' for "nothing unfolded".
     *
     * Whatever arrives — the endpoint's request body, a value a session was
     * left holding, a nested array — is walked in order and the first thing that
     * names a real parent wins; the rest is discarded, because only one section
     * can be open. The answer is therefore always a known key or '', however
     * hostile the input, which is what makes it safe to store unexamined.
     *
     * Taking the first rather than the last is how a session written before the
     * rail became an accordion collapses: it could hold several parents then,
     * and this settles on the same one every time rather than at random.
     */
    public static function sanitize(mixed $value): string
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return '';
        }

        $known = array_flip(self::keys());

        foreach ($value as $key) {
            if (is_string($key) && isset($known[$key])) {
                return $key;
            }
        }

        return '';
    }
}
