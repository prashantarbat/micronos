<?php

namespace App\Libraries;

/**
 * ObjectTip — builds hover-detail tooltips for firewall object names.
 *
 * Every method returns a `data-fw-tip="…"` attribute string holding a small
 * HTML card (title + label/value rows). The shared JS in
 * `app/Views/firewall/_obj_tooltip.php` renders the attribute in a floating
 * panel on hover or keyboard focus. Keeping the content server-side means the
 * tooltip reflects the exact row the page already loaded, with no extra
 * request, and works identically inside modals.
 */
class ObjectTip
{
    /**
     * Builds the `data-fw-tip` attribute from a title and ordered [label => value] rows.
     * Empty values are skipped so the card only shows populated fields.
     */
    public static function attr(string $title, array $rows, string $badge = ''): string
    {
        $html = '<div class="fw-tip-title">' . esc($title);
        if ($badge !== '') {
            $html .= '<span class="fw-tip-badge">' . esc($badge) . '</span>';
        }
        $html .= '</div>';

        $body = '';
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $body .= '<dt>' . esc((string) $label) . '</dt><dd>' . esc((string) $value) . '</dd>';
        }
        if ($body !== '') {
            $html .= '<dl class="fw-tip-rows">' . $body . '</dl>';
        }

        return ' data-fw-tip="' . esc($html, 'attr') . '"';
    }

    // ------------------------------------------------------------------
    // Addresses
    // ------------------------------------------------------------------

    public static function address(array $a): string
    {
        return self::attr(
            (string) ($a['name'] ?? ''),
            [
                'Object'    => 'Address',
                'Type'      => self::addressType($a),
                'Value'     => self::addressValue($a),
                'Interface' => strtoupper((string) ($a['interface'] ?? 'any')),
                'Comment'   => $a['comment'] ?? '',
                'Source'    => ! empty($a['is_system']) ? 'System object (protected)' : 'User-defined',
            ],
            'ADDRESS'
        );
    }

    public static function addressType(array $a): string
    {
        return match ($a['type'] ?? '') {
            'geoip'             => 'Geography',
            'internet-service'  => 'Internet Service',
            'fqdn'              => 'FQDN',
            'iprange'           => 'IP Range',
            'ipmask'            => 'Subnet',
            'wildcard'          => 'Wildcard FQDN',
            default             => ucfirst((string) ($a['type'] ?? 'ipaddress')),
        };
    }

    public static function addressValue(array $a): string
    {
        $type  = (string) ($a['type'] ?? '');
        $value = (string) ($a['value'] ?? '');

        if ($type === 'geoip') {
            return GeoIp::label($value) ?: $value;
        }
        if ($type === 'internet-service') {
            return InternetService::describe($value) ?: $value;
        }

        return $value;
    }

    public static function addressGroup(array $g, array $addressMap = []): string
    {
        $members = self::members($g['members'] ?? '');
        $listed  = [];
        foreach ($members as $m) {
            $obj     = $addressMap[$m] ?? null;
            $listed[] = $obj ? $m . ' (' . self::addressType($obj) . ': ' . self::addressValue($obj) . ')' : $m;
        }

        return self::attr(
            (string) ($g['name'] ?? ''),
            [
                'Object'  => 'Address Group',
                'Members' => count($members) . ($listed ? ' — ' . implode(', ', array_slice($listed, 0, 10)) . (count($listed) > 10 ? ' …' : '') : ' (empty)'),
                'Comment' => $g['comment'] ?? '',
            ],
            'GROUP'
        );
    }

    // ------------------------------------------------------------------
    // Services
    // ------------------------------------------------------------------

    public static function service(array $s): string
    {
        $ports = [];
        if (! empty($s['tcp_port'])) {
            $ports[] = 'TCP ' . $s['tcp_port'];
        }
        if (! empty($s['udp_port'])) {
            $ports[] = 'UDP ' . $s['udp_port'];
        }

        return self::attr(
            (string) ($s['name'] ?? ''),
            [
                'Object'   => 'Service',
                'Protocol' => strtoupper((string) ($s['protocol'] ?? 'tcp')),
                'Ports'    => implode(' · ', $ports) ?: 'Any',
                'Category' => $s['category'] ?? '',
                'Comment'  => $s['comment'] ?? '',
                'Source'   => ! empty($s['is_system']) ? 'System service (protected)' : 'User-defined',
            ],
            'SERVICE'
        );
    }

    public static function serviceGroup(array $g, array $serviceMap = []): string
    {
        $members = self::members($g['members'] ?? '');
        $listed  = [];
        foreach ($members as $m) {
            $obj = $serviceMap[$m] ?? null;
            if ($obj) {
                $parts = [];
                if (! empty($obj['tcp_port'])) {
                    $parts[] = 'TCP ' . $obj['tcp_port'];
                }
                if (! empty($obj['udp_port'])) {
                    $parts[] = 'UDP ' . $obj['udp_port'];
                }
                $listed[] = $m . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
            } else {
                $listed[] = $m;
            }
        }

        return self::attr(
            (string) ($g['name'] ?? ''),
            [
                'Object'  => 'Service Group',
                'Members' => count($members) . ($listed ? ' — ' . implode(', ', array_slice($listed, 0, 10)) . (count($listed) > 10 ? ' …' : '') : ' (empty)'),
                'Comment' => $g['comment'] ?? '',
            ],
            'GROUP'
        );
    }

    // ------------------------------------------------------------------
    // Internet Service Database entries
    // ------------------------------------------------------------------

    public static function internetService(array $s): string
    {
        $synced = ! empty($s['last_synced_at'])
            ? date('Y-m-d H:i', strtotime((string) $s['last_synced_at']))
            : (! empty($s['has_feed']) ? 'Never synced' : 'Manual feed');

        return self::attr(
            (string) ($s['name'] ?? ''),
            [
                'Object'    => 'Internet Service',
                'ID'        => $s['id'] ?? '',
                'Category'  => $s['category'] ?? '',
                'Prefixes'  => ! empty($s['prefix_count']) ? (int) $s['prefix_count'] . ' CIDRs' : 'Not synced',
                'nftables'  => ! empty($s['id']) ? '@' . InternetService::setName((string) $s['id']) : '',
                'Last Sync' => $synced,
                'Feed'      => $s['feed_url'] ?? ($s['source_url'] ?? ''),
                'Status'    => ! empty($s['enabled']) ? 'Enabled' : 'Disabled',
                'Description' => $s['description'] ?? '',
            ],
            'ISDB'
        );
    }

    // ------------------------------------------------------------------
    // Schedules
    // ------------------------------------------------------------------

    public static function schedule(array $s): string
    {
        if (strtolower((string) ($s['name'] ?? '')) === 'always') {
            return self::attr('always (Any Time)', ['Schedule' => 'Always active (24/7/365)'], 'SCHEDULE');
        }

        $type = $s['type'] ?? 'recurring';
        $rows = ['Object' => 'Schedule', 'Type' => ucfirst((string) $type)];
        if ($type === 'onetime') {
            $rows['Starts'] = ! empty($s['start_datetime']) ? date('M d, Y H:i', strtotime((string) $s['start_datetime'])) : '';
            $rows['Ends']   = ! empty($s['end_datetime']) ? date('M d, Y H:i', strtotime((string) $s['end_datetime'])) : '';
        } else {
            $rows['Days'] = self::daysLabel((string) ($s['days_of_week'] ?? 'all'));
            $rows['Time'] = substr((string) ($s['start_time'] ?? '00:00:00'), 0, 5)
                . ' – ' . substr((string) ($s['end_time'] ?? '23:59:59'), 0, 5);
        }
        $rows['Description'] = $s['description'] ?? '';

        return self::attr((string) ($s['name'] ?? ''), $rows, 'SCHEDULE');
    }

    private static function daysLabel(string $days): string
    {
        $days = strtolower($days);
        if ($days === '' || $days === 'all') {
            return 'Every day';
        }
        if ($days === 'mon,tue,wed,thu,fri') {
            return 'Mon–Fri';
        }
        if ($days === 'sat,sun') {
            return 'Sat–Sun';
        }

        return implode(', ', array_map('ucfirst', array_map('trim', explode(',', $days))));
    }

    // ------------------------------------------------------------------
    // Interfaces
    // ------------------------------------------------------------------

    public static function iface(array $i): string
    {
        $ipv4 = '';
        if (! empty($i['ipv4_address'])) {
            $ipv4 = (string) $i['ipv4_address'];
            if (! empty($i['ipv4_netmask'])) {
                $ipv4 .= '/' . $i['ipv4_netmask'];
            }
        }

        return self::attr(
            strtoupper((string) ($i['name'] ?? $i['physical_device'] ?? '')),
            [
                'Interface' => $i['name'] ?? '',
                'Device'    => $i['physical_device'] ?? '',
                'IPv4'      => $ipv4 !== '' ? $ipv4 : 'unassigned',
                'Gateway'   => $i['ipv4_gateway'] ?? '',
                'Type'      => strtoupper((string) ($i['ip_assignment_type'] ?? '')),
                'State'     => ! empty($i['enabled']) ? 'Enabled' : 'Disabled',
            ],
            'INTERFACE'
        );
    }

    /** Decodes a JSON member list (or passes through an array/string). @return list<string> */
    private static function members(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw), static fn ($m) => $m !== ''));
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn ($m) => $m !== '')) : [];
        }

        return [];
    }
}
