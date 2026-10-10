<?php

namespace App\Plugins;

/**
 * Capability comparison and the absolute protected-unit list.
 *
 * A plugin declares capabilities in its manifest; the operator grants a subset
 * of them. {@see declaredButNotGranted()} is how the engine blocks a call the
 * operator never approved, and {@see isProtectedUnit()} is the hard floor that
 * even a *granted* capability cannot cross: no plugin, ever, may stop `nftables`,
 * `unbound`, `wireguard`, `strongswan`, `keepalived`, `frr`, `kea`, `suricata`,
 * `chrony`, `rsyslog` or `snmpd` (Plugin Spec §7).
 *
 * This mirrors the connector/`system.service` split in the daemon: connectors
 * are held to `CONNECTOR_SAFE_UNITS`, plugins to their granted set, and the core
 * daemons to neither.
 */
final class CapabilitySet
{
    /**
     * Base-name prefixes of units that are always protected, independent of any
     * grant. Matched against the unit's base name (before `@` and `.service`).
     */
    private const PROTECTED_UNITS = [
        'nftables', 'unbound', 'wireguard', 'strongswan', 'keepalived', 'frr',
        'kea-dhcp4-server', 'kea-ctrl-agent', 'suricata', 'chrony', 'rsyslog',
        'snmpd', 'squid', 'openvpn@server', 'c-icap', 'clamav-daemon',
        'igmpproxy', 'mdns-repeater', 'systemd-networkd', 'systemd-resolved',
    ];

    /**
     * Capabilities declared but not granted.
     *
     * @param list<string> $declared
     * @param list<string> $granted
     *
     * @return list<string>
     */
    public static function declaredButNotGranted(array $declared, array $granted): array
    {
        return array_values(array_diff($declared, $granted));
    }

    /**
     * Capabilities that must never be granted, whatever the manifest asks for.
     *
     * @param list<string> $capabilities
     *
     * @return list<string>
     */
    public static function forbidden(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            if (! is_string($capability)) {
                continue;
            }
            if (str_starts_with($capability, 'system.service:')
                && self::isProtectedUnit(substr($capability, strlen('system.service:')))) {
                $out[] = $capability;
            }
        }

        return $out;
    }

    /**
     * Whether a unit is in the always-protected core set.
     *
     * @param string $unit bare unit name, optionally with `@instance` and `.service`
     */
    public static function isProtectedUnit(string $unit): bool
    {
        $base = self::baseUnit($unit);

        return in_array($base, self::PROTECTED_UNITS, true);
    }

    /**
     * Normalise a unit name to its base form for comparison
     * (`openvpn@server.service` → `openvpn@server`).
     */
    public static function baseUnit(string $unit): string
    {
        $unit = trim($unit);
        if (str_ends_with($unit, '.service')) {
            $unit = substr($unit, 0, -strlen('.service'));
        }

        return strtolower($unit);
    }

    /**
     * @return list<string>
     */
    public static function protectedUnits(): array
    {
        return self::PROTECTED_UNITS;
    }
}
