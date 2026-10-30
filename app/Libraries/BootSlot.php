<?php

namespace App\Libraries;

/**
 * BootSlot — Reports which A/B root slot the running system booted from.
 *
 * The installer formats the two root partitions as MICRON_ROOT_A and
 * MICRON_ROOT_B, and GRUB boots one of them with `root=UUID=…`. Comparing the
 * device behind the kernel's root= argument with the two labelled devices tells
 * us the running slot. The GRUB environment block (`micron_active_slot`) says
 * which slot boots next, which differs from the running one after an update has
 * been staged but before the reboot.
 *
 * Everything read here is world-readable, so no daemon call is needed.
 */
class BootSlot
{
    public function __construct(
        private string $procCmdline = '/proc/cmdline',
        private string $mountInfo = '/proc/self/mountinfo',
        private string $devDisk = '/dev/disk',
        private string $grubEnv = '/boot/grub/grubenv',
        private string $liveMarker = '/run/live/medium',
    ) {
    }

    /**
     * @return array{slot: string, label: string, next: ?string, device: ?string}
     *   slot  'A', 'B', 'live' or 'unknown'
     *   label Human text for the dashboard, e.g. "Slot A"
     *   next  Slot GRUB will boot next ('A'/'B') when it differs from slot, else null
     */
    public function detect(): array
    {
        $cmdline = (string) @file_get_contents($this->procCmdline);

        if (preg_match('/(^|\s)boot=live(\s|$)/', $cmdline) || is_dir($this->liveMarker)) {
            return ['slot' => 'live', 'label' => 'Live (not installed)', 'next' => null, 'device' => null];
        }

        $rootDev = $this->rootDevice($cmdline);
        $slot = 'unknown';
        if ($rootDev !== null) {
            foreach (['A', 'B'] as $s) {
                $labelDev = @realpath($this->devDisk . '/by-label/MICRON_ROOT_' . $s);
                if ($labelDev !== false && $labelDev === $rootDev) {
                    $slot = $s;
                    break;
                }
            }
        }

        $next = $this->nextSlot();
        return [
            'slot'   => $slot,
            'label'  => $slot === 'unknown' ? 'Unknown' : 'Slot ' . $slot,
            'next'   => ($slot !== 'unknown' && $next !== null && $next !== $slot) ? $next : null,
            'device' => $rootDev,
        ];
    }

    /** Resolve the root block device from root= on the command line, else from mountinfo. */
    private function rootDevice(string $cmdline): ?string
    {
        if (preg_match('/(?:^|\s)root=(\S+)/', $cmdline, $m)) {
            $spec = $m[1];
            $path = match (true) {
                str_starts_with($spec, 'UUID=')     => $this->devDisk . '/by-uuid/' . substr($spec, 5),
                str_starts_with($spec, 'LABEL=')    => $this->devDisk . '/by-label/' . substr($spec, 6),
                str_starts_with($spec, 'PARTUUID=') => $this->devDisk . '/by-partuuid/' . substr($spec, 9),
                str_starts_with($spec, '/dev/')     => $spec,
                default                             => null,
            };
            if ($path !== null && basename($path) !== '' && !str_contains(basename($path), '..')) {
                $real = @realpath($path);
                if ($real !== false) {
                    return $real;
                }
            }
        }

        // mountinfo: "... <mount point> ... - <fstype> <source> <options>"
        foreach (@file($this->mountInfo, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = explode(' ', $line);
            if (($parts[4] ?? '') !== '/') {
                continue;
            }
            $sep = array_search('-', $parts, true);
            $source = $sep !== false ? ($parts[$sep + 2] ?? '') : '';
            if (str_starts_with($source, '/dev/')) {
                $real = @realpath($source);
                return $real !== false ? $real : $source;
            }
        }

        return null;
    }

    /** micron_active_slot from the GRUB environment block, as 'A'/'B'. */
    private function nextSlot(): ?string
    {
        $env = (string) @file_get_contents($this->grubEnv);
        if (preg_match('/^micron_active_slot=([ab])\s*$/mi', $env, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }
}
