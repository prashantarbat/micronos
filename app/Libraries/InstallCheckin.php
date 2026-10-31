<?php

namespace App\Libraries;

use Config\Telemetry;

/**
 * Builds and sends the install check-in to micronos.microninfo.com.
 *
 * What is sent is exactly payload(): identity of the appliance (Micron
 * Appliance ID, hostname), what it runs (version, kernel, boot slot), how it is
 * doing (uptime, active rule count, last configuration save) and the size of
 * the box (CPU, memory, disk, NIC count, VM or not). Never rules, traffic,
 * logs, users, keys or organisation details. The website adds the public IP
 * the request came from and looks up its country and city.
 *
 * Every reading is best effort: a value the appliance cannot read is sent as
 * null rather than stopping the check-in.
 */
class InstallCheckin
{
    /** Uptime below this means the run is the one right after boot. */
    private const BOOT_WINDOW_SEC = 1800;

    /** @var callable(string, array): array{status: int, body: string} */
    private $post;

    public function __construct(
        private ?Telemetry $config = null,
        ?callable $post = null,
        private string $proc = '/proc',
        private string $sysNet = '/sys/class/net',
        private string $dmi = '/sys/class/dmi/id',
    ) {
        $this->config ??= config('Telemetry');
        $this->post   = $post ?? function (string $url, array $payload): array {
            $res = \Config\Services::curlrequest([], null, null, false)->post($url, [
                'json'        => $payload,
                'timeout'     => $this->config->timeout,
                'http_errors' => false,
                'headers'     => ['User-Agent' => 'MicronOS/' . AppVersion::get() . ' (install-checkin)', 'Accept' => 'application/json'],
            ]);

            return ['status' => $res->getStatusCode(), 'body' => (string) $res->getBody()];
        };
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return (new \App\Models\SystemSettingsModel())->getSettings();
    }

    /** Whether the administrator (and the build) allow check-ins. */
    public function enabled(?array $settings = null): bool
    {
        $settings ??= $this->settings();

        return $this->config->allowed && (int) ($settings['telemetry_enabled'] ?? 1) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $event = 'auto', ?array $settings = null, ?int $activeRules = null): array
    {
        $settings ??= $this->settings();
        $uptime   = $this->uptime();

        if ($event === 'auto') {
            $event = empty($settings['telemetry_last_at']) ? 'install'
                : ($uptime !== null && $uptime < self::BOOT_WINDOW_SEC ? 'boot' : 'daily');
        }

        $saved = (string) ($settings['updated_at'] ?? '');
        $savedTs = $saved !== '' ? strtotime($saved) : false;

        $boot = null;
        try {
            $boot = (new BootSlot())->detect()['label'];
        } catch (\Throwable) {
        }

        return [
            'schema'          => 1,
            'event'           => $event,
            'appliance_id'    => (string) ($settings['micron_id'] ?? ''),
            'hostname'        => (string) ($settings['hostname'] ?? ''),
            'version'         => AppVersion::get(),
            'uptime_sec'      => $uptime,
            'boot_slot'       => $boot,
            'active_rules'    => $activeRules ?? $this->activeRules(),
            'config_saved_at' => $savedTs !== false ? gmdate('Y-m-d\TH:i:s\Z', $savedTs) : null,
            'kernel'          => php_uname('r'),
            'arch'            => php_uname('m'),
            'cpu_model'       => $this->cpuModel(),
            'cpu_cores'       => $this->cpuCores(),
            'mem_total_mb'    => $this->memTotalMb(),
            'disk_total_gb'   => $this->diskTotalGb(),
            'interfaces'      => $this->physicalInterfaces(),
            'virtual'         => $this->virtualisation(),
            'timezone'        => (string) ($settings['timezone'] ?? date_default_timezone_get()),
        ];
    }

    /**
     * Sends one check-in and records the outcome on the settings row.
     *
     * @return array{sent: bool, status: string, payload: array<string, mixed>}
     */
    public function send(string $event = 'auto', bool $dryRun = false): array
    {
        $settings = $this->settings();
        $payload  = $this->payload($event, $settings);

        if (! $this->enabled($settings)) {
            return ['sent' => false, 'status' => 'Install check-in is turned off; nothing was sent.', 'payload' => $payload];
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{7,63}$/', $payload['appliance_id'])) {
            return $this->finish(false, 'Not sent: this appliance has no Micron Appliance ID yet.', $payload, $dryRun);
        }
        if ($dryRun) {
            return ['sent' => false, 'status' => 'Dry run: nothing was sent.', 'payload' => $payload];
        }

        try {
            $res = ($this->post)($this->config->endpoint, $payload);
        } catch (\Throwable $e) {
            return $this->finish(false, 'Not sent: ' . mb_substr($e->getMessage(), 0, 180), $payload);
        }

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return $this->finish(true, 'Sent (' . $payload['event'] . ').', $payload);
        }

        $why = json_decode($res['body'], true)['error'] ?? null;

        return $this->finish(false, 'Not accepted: HTTP ' . $res['status'] . (is_string($why) ? ' — ' . mb_substr($why, 0, 160) : ''), $payload);
    }

    /** @return array{sent: bool, status: string, payload: array<string, mixed>} */
    private function finish(bool $sent, string $status, array $payload, bool $dryRun = false): array
    {
        if (! $dryRun) {
            try {
                $row = ['telemetry_last_status' => date('Y-m-d H:i') . ' ' . mb_substr($status, 0, 230)];
                if ($sent) {
                    $row['telemetry_last_at'] = date('Y-m-d H:i:s');
                }
                // Straight to the table: the model would bump updated_at, which
                // is the "Last saved" time this check-in reports.
                \Config\Database::connect()->table('system_settings')->where('id', 1)->update($row);
            } catch (\Throwable $e) {
                log_message('warning', 'Install check-in: could not record the outcome: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        return ['sent' => $sent, 'status' => $status, 'payload' => $payload];
    }

    /** Same number the dashboard shows as "Active Rules": the live nftables rules. */
    public function activeRules(): ?int
    {
        try {
            $ipc = \Config\Services::ipc();
            if (! $ipc->isMock()) {
                $res = $ipc->call('firewall.list_rules');
                if (($res['success'] ?? false) === true && isset($res['rules']) && is_array($res['rules'])) {
                    return count($res['rules']);
                }
            }
        } catch (\Throwable) {
        }

        try {
            return \Config\Database::connect()->table('firewall_rules')->where('enabled', 1)->countAllResults();
        } catch (\Throwable) {
            return null;
        }
    }

    public function uptime(): ?int
    {
        $raw = @file_get_contents($this->proc . '/uptime');

        return is_string($raw) && preg_match('/^(\d+)/', $raw, $m) ? (int) $m[1] : null;
    }

    private function cpuModel(): ?string
    {
        $raw = (string) @file_get_contents($this->proc . '/cpuinfo');

        return preg_match('/^model name\s*:\s*(.+)$/m', $raw, $m) ? mb_substr(trim(preg_replace('/\s+/', ' ', $m[1])), 0, 190) : null;
    }

    private function cpuCores(): ?int
    {
        $raw = (string) @file_get_contents($this->proc . '/cpuinfo');
        $n   = preg_match_all('/^processor\s*:/m', $raw);

        return $n > 0 ? $n : null;
    }

    private function memTotalMb(): ?int
    {
        $raw = (string) @file_get_contents($this->proc . '/meminfo');

        return preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m) ? intdiv((int) $m[1], 1024) : null;
    }

    private function diskTotalGb(): ?int
    {
        $t = @disk_total_space('/');

        return $t !== false && $t > 0 ? (int) round($t / 1_000_000_000) : null;
    }

    /** NICs with a real device behind them (no lo, bridges, VLANs, tunnels). */
    private function physicalInterfaces(): ?int
    {
        $names = @scandir($this->sysNet);
        if ($names === false) {
            return null;
        }
        $n = 0;
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..' && is_dir($this->sysNet . '/' . $name . '/device')) {
                $n++;
            }
        }

        return $n;
    }

    /** 'none' on bare metal, else the hypervisor family; null when unknown. */
    private function virtualisation(): ?string
    {
        $vendor  = strtolower(trim((string) @file_get_contents($this->dmi . '/sys_vendor')));
        $product = strtolower(trim((string) @file_get_contents($this->dmi . '/product_name')));
        $both    = $vendor . ' ' . $product;

        foreach (['qemu' => 'kvm', 'kvm' => 'kvm', 'vmware' => 'vmware', 'virtualbox' => 'virtualbox', 'innotek' => 'virtualbox',
            'microsoft corporation virtual' => 'hyper-v', 'xen' => 'xen', 'proxmox' => 'kvm', 'amazon ec2' => 'aws', 'google compute' => 'gce'] as $needle => $kind) {
            if (str_contains($both, $needle)) {
                return $kind;
            }
        }
        if (str_contains($vendor, 'microsoft') && str_contains($product, 'virtual')) {
            return 'hyper-v';
        }

        $cpu = (string) @file_get_contents($this->proc . '/cpuinfo');
        if ($cpu !== '' && preg_match('/^flags\s*:.*\bhypervisor\b/m', $cpu)) {
            return 'vm';
        }

        return $vendor !== '' || $cpu !== '' ? 'none' : null;
    }
}
