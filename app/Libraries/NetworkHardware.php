<?php

namespace App\Libraries;

/**
 * The network ports this machine physically has, as micrond reads them from
 * /sys/class/net (`hardware.nics`): Ethernet ports and Wi-Fi radios with their
 * real kernel names (eno1, enp7s0, wlp2s0 ...), driver, bus and connector.
 *
 * Pages use it to offer only what the hardware supports — e.g. Wi-Fi settings
 * appear only when a wireless NIC is installed. When the daemon does not
 * answer, nics() returns null ("unknown"), which callers treat as "nothing
 * detected" rather than inventing ports.
 */
final class NetworkHardware
{
    /** @var list<array<string, mixed>>|null|false false = not read yet */
    private array|null|false $nics = false;

    public function __construct(private IpcClient $ipc)
    {
    }

    /** @return list<array<string, mixed>>|null */
    public function nics(): ?array
    {
        if ($this->nics !== false) {
            return $this->nics;
        }

        $resp   = $this->ipc->call('hardware.nics');
        $result = $resp['result'] ?? null;
        if (! empty($resp['error']) || ! is_array($result) || ($result['success'] ?? false) !== true
            || ! is_array($result['nics'] ?? null)) {
            return $this->nics = null;
        }

        $out = [];
        foreach ($result['nics'] as $nic) {
            $name = (string) ($nic['name'] ?? '');
            if (! is_array($nic) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $name) !== 1) {
                continue;
            }
            $nic['name']     = $name;
            $nic['wireless'] = ! empty($nic['wireless']) || ($nic['kind'] ?? '') === 'wireless';
            $out[]           = $nic;
        }

        return $this->nics = $out;
    }

    /** @return list<string> every physical port name, in kernel order */
    public function names(): array
    {
        return array_values(array_map(static fn ($n) => (string) $n['name'], $this->nics() ?? []));
    }

    /** @return list<string> the Wi-Fi radios present */
    public function wirelessNames(): array
    {
        return array_values(array_map(
            static fn ($n) => (string) $n['name'],
            array_filter($this->nics() ?? [], static fn ($n) => $n['wireless'])
        ));
    }

    public function hasWireless(): bool
    {
        return $this->wirelessNames() !== [];
    }

    /** A one-line description for a port picker: "enp7s0 — igc, copper, 1 Gb/s". */
    public static function describe(array $nic): string
    {
        $bits = [];
        if (! empty($nic['driver'])) {
            $bits[] = (string) $nic['driver'];
        }
        $type = $nic['wireless'] ? 'Wi-Fi' : match ((string) ($nic['port_type'] ?? '')) {
            'tp'    => 'copper',
            'fibre' => 'fibre',
            'da'    => 'direct-attach',
            default => 'Ethernet',
        };
        $bits[] = $type;
        if (! empty($nic['speed_mbps'])) {
            $sp     = (int) $nic['speed_mbps'];
            $bits[] = $sp >= 1000 ? rtrim(rtrim(number_format($sp / 1000, 1), '0'), '.') . ' Gb/s' : $sp . ' Mb/s';
        } elseif (isset($nic['carrier']) && ! $nic['carrier']) {
            $bits[] = 'no link';
        }

        return $nic['name'] . ' — ' . implode(', ', $bits);
    }
}
