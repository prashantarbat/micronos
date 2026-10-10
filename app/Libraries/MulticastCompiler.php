<?php

namespace App\Libraries;

/**
 * MulticastCompiler — renders MicronOS multicast settings into the concrete
 * configuration each data-plane component consumes:
 *
 *   compileIgmpProxy()  → /etc/igmpproxy.conf            (igmpproxy daemon)
 *   compilePimCommands()→ vtysh command script           (FRR pimd: IGMP + PIM)
 *   compileMdnsCommand()→ mdns-repeater argv             (mDNS/Bonjour reflection)
 *   snoopingSysfs()     → /sys/class/net/<br>/bridge/... (bridge snooping/querier)
 *
 * Everything is validated before it is emitted: interface names, IPs/CIDRs and
 * multicast ranges are rejected rather than written, so a malformed settings
 * row can never inject arbitrary directives into a daemon config.
 */
class MulticastCompiler
{
    /** A Linux network-interface name. */
    private const IF_RE = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/';

    /**
     * All compiled artifacts for a settings row.
     *
     * @param array<string,mixed> $s
     *
     * @return array{igmp_proxy:string, pim:string, mdns:array{command:string, interfaces:list<string>}, snooping:array{bridge:?string, sysfs:array<string,string>}, warnings:list<string>}
     */
    public function compile(array $s): array
    {
        $warnings = [];
        $igmp     = $this->compileIgmpProxy($s, $warnings);
        $pim      = $this->compilePimCommands($s, $warnings);
        $mdns     = $this->compileMdns($s, $warnings);
        $snooping = $this->snoopingSysfs($s, $warnings);

        return [
            'igmp_proxy' => $igmp,
            'pim'        => $pim,
            'mdns'       => $mdns,
            'snooping'   => $snooping,
            'warnings'   => $warnings,
        ];
    }

    // ------------------------------------------------------------------
    // 1. igmpproxy
    // ------------------------------------------------------------------

    /**
     * @param list<string> $warnings
     */
    public function compileIgmpProxy(array $s, array &$warnings = []): string
    {
        if (empty($s['enabled']) || empty($s['igmp_proxy_enabled'])) {
            return '';
        }

        $upstream = trim((string) ($s['igmp_upstream'] ?? ''));
        if (! $this->isIfName($upstream)) {
            $warnings[] = 'IGMP proxy needs a valid upstream interface.';

            return '';
        }

        $downstreams = array_values(array_filter(
            $this->toList((string) ($s['igmp_downstreams'] ?? '')),
            fn ($if) => $this->isIfName($if) && $if !== $upstream,
        ));
        if ($downstreams === []) {
            $warnings[] = 'IGMP proxy needs at least one valid downstream interface.';

            return '';
        }

        $lines = [
            '# MicronOS IGMP Proxy configuration — generated, do not edit by hand.',
            '# Upstream: ' . $upstream,
        ];

        if (! empty($s['igmp_quickleave'])) {
            $lines[] = 'quickleave';
        }
        $lines[] = '';

        $lines[] = 'phyint ' . $upstream . ' upstream ratelimit 0 threshold 1';
        $lines[] = '        altnet 0.0.0.0/0;';

        // Extra allowed source networks on the upstream side.
        foreach ($this->toList((string) ($s['igmp_altnets'] ?? '')) as $net) {
            if ($this->isCidrOrIp($net)) {
                $lines[] = '        altnet ' . $net . ';';
            } else {
                $warnings[] = "Ignoring invalid upstream altnet: {$net}";
            }
        }

        foreach ($downstreams as $if) {
            $lines[] = '';
            $lines[] = 'phyint ' . $if . ' downstream ratelimit 0 threshold 1';
            // Downstream: the multicast service ranges handed to LAN clients.
            $lines[] = '        altnet 224.0.0.0/4;';
            $lines[] = '        altnet 239.0.0.0/8;';
        }

        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------
    // 2. FRR pimd (multicast routing + IGMP querier)
    // ------------------------------------------------------------------

    /**
     * A `vtysh` command script that (re)builds the multicast configuration:
     * the PIM router block plus per-interface IGMP/PIM enablement.
     *
     * @param list<string> $warnings
     */
    public function compilePimCommands(array $s, array &$warnings = []): string
    {
        if (empty($s['enabled']) || empty($s['pim_enabled'])) {
            return '';
        }

        $commands = ['configure terminal', 'router pim'];

        $mode = ($s['pim_mode'] ?? 'sparse') === 'ssm' ? 'ssm' : 'sparse';

        $rp = trim((string) ($s['pim_rp_address'] ?? ''));
        if ($mode === 'sparse') {
            if ($this->isIp($rp)) {
                $commands[] = ' ip pim rp ' . $rp;
            } else {
                $warnings[] = 'PIM sparse mode without a valid rendezvous-point address; RP not configured.';
            }
        }

        $ssm = trim((string) ($s['pim_ssm_range'] ?? ''));
        if ($mode === 'ssm' && $ssm !== '') {
            if ($this->isCidrOrIp($ssm)) {
                $commands[] = ' ip pim ssm range ' . $ssm;
            } else {
                $warnings[] = "Ignoring invalid SSM range: {$ssm}";
            }
        }

        $commands[] = 'exit';

        $interfaces = array_values(array_filter(
            $this->toList((string) ($s['pim_interfaces'] ?? '')),
            fn ($if) => $this->isIfName($if),
        ));

        foreach ($interfaces as $if) {
            $commands[] = 'interface ' . $if;
            $commands[] = ' ip pim';
            if (! empty($s['igmp_querier_enabled'])) {
                $version = in_array((int) ($s['igmp_version'] ?? 3), [1, 2, 3], true) ? (int) $s['igmp_version'] : 3;
                $commands[] = ' ip igmp';
                $commands[] = ' ip igmp version ' . $version;
            }
            $commands[] = 'exit';
        }

        if ($interfaces === []) {
            $warnings[] = 'PIM enabled but no interfaces selected; no interface will run PIM/IGMP.';
        }

        $commands[] = 'end';
        $commands[] = 'write memory';

        return implode("\n", $commands) . "\n";
    }

    // ------------------------------------------------------------------
    // 3. Bridge snooping + mDNS reflection
    // ------------------------------------------------------------------

    /**
     * @param list<string> $warnings
     *
     * @return array{bridge:?string, sysfs:array<string,string>}
     */
    public function snoopingSysfs(array $s, array &$warnings = []): array
    {
        $empty = ['bridge' => null, 'sysfs' => []];
        if (empty($s['enabled']) || empty($s['snooping_enabled'])) {
            return $empty;
        }

        $bridge = trim((string) ($s['snooping_bridge'] ?? ''));
        if (! $this->isIfName($bridge)) {
            $warnings[] = 'Bridge snooping requested without a valid bridge interface.';

            return $empty;
        }

        // Relative to /sys/class/net/<bridge>/bridge/ on the appliance.
        $sysfs = [
            'multicast_snooping' => '1',
        ];

        if (! empty($s['snooping_querier'])) {
            $interval = max(1, (int) ($s['snooping_query_interval'] ?? 125));
            $sysfs['multicast_querier']         = '1';
            $sysfs['multicast_query_interval']  = (string) (int) ($interval * 100); // centiseconds
            $sysfs['multicast_startup_querier'] = '1';
        }

        return ['bridge' => $bridge, 'sysfs' => $sysfs];
    }

    /**
     * @param list<string> $warnings
     *
     * @return array{command:string, interfaces:list<string>}
     */
    public function compileMdns(array $s, array &$warnings = []): array
    {
        $empty = ['command' => '', 'interfaces' => []];
        if (empty($s['enabled']) || empty($s['mdns_enabled'])) {
            return $empty;
        }

        $interfaces = array_values(array_filter(
            $this->toList((string) ($s['mdns_interfaces'] ?? '')),
            fn ($if) => $this->isIfName($if),
        ));

        if (count($interfaces) < 2) {
            $warnings[] = 'mDNS reflection needs at least two interfaces to reflect between.';

            return $empty;
        }

        $mode = ($s['mdns_mode'] ?? 'reflect') === 'relay' ? 'relay' : 'reflect';

        $command = 'mdns-repeater -f ' . implode(' ', $interfaces);
        if ($mode === 'relay') {
            $command .= ' -F /etc/mdns-repeater.filter';
        }

        return ['command' => $command, 'interfaces' => $interfaces];
    }

    // ------------------------------------------------------------------
    // Validation helpers
    // ------------------------------------------------------------------

    public function isIfName(string $value): bool
    {
        return $value !== '' && preg_match(self::IF_RE, $value) === 1;
    }

    public function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /** An IPv4/IPv6 address or CIDR block. */
    public function isCidrOrIp(string $value): bool
    {
        if ($this->isIp($value)) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$net, $bits] = explode('/', $value, 2);
        if ($bits === '' || ! ctype_digit($bits)) {
            return false;
        }

        if ($this->isIp($net)) {
            $max = str_contains($net, ':') ? 128 : 32;

            return (int) $bits >= 0 && (int) $bits <= $max;
        }

        return false;
    }

    /**
     * Splits a textarea into a clean list (newlines and commas both accepted).
     *
     * @return list<string>
     */
    private function toList(string $text): array
    {
        $parts = preg_split('/[\s,]+/', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));
    }
}
