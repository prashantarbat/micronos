<?php

use App\Libraries\InstallCheckin;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Telemetry;

/**
 * The install check-in: what it sends, and that "off" sends nothing.
 *
 * @internal
 */
final class InstallCheckinTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/checkin-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/proc', 0777, true);
        mkdir($this->root . '/net/enp1s0/device', 0777, true);
        mkdir($this->root . '/net/enp2s0/device', 0777, true);
        mkdir($this->root . '/net/lo', 0777, true);
        mkdir($this->root . '/net/br0', 0777, true);
        mkdir($this->root . '/dmi', 0777, true);
        file_put_contents($this->root . '/proc/uptime', "277245.11 1000.00\n");
        file_put_contents($this->root . '/proc/meminfo', "MemTotal:        8388608 kB\nMemFree: 1 kB\n");
        file_put_contents($this->root . '/proc/cpuinfo', "processor\t: 0\nmodel name\t: Intel(R) Celeron(R) J4125\nflags\t: fpu\nprocessor\t: 1\nmodel name\t: Intel(R) Celeron(R) J4125\n");
        file_put_contents($this->root . '/dmi/sys_vendor', "QEMU\n");
        file_put_contents($this->root . '/dmi/product_name', "Standard PC\n");
    }

    private function settings(array $extra = []): array
    {
        return $extra + [
            'micron_id'         => '83E6A05D-5315-4822-D234-8C82057040AB',
            'hostname'          => 'micronos-fw01',
            'timezone'          => 'Asia/Kolkata',
            'updated_at'        => '2026-10-18 14:30:00',
            'telemetry_enabled' => 1,
            'telemetry_last_at' => '2026-10-30 10:00:00',
            'org_name'          => 'Secret Corp',
            'org_email'         => 'admin@example.com',
        ];
    }

    private function checkin(array $settings, ?callable $post = null, bool $allowed = true): InstallCheckin
    {
        $cfg = new Telemetry();
        $cfg->allowed = $allowed;

        return new class ($settings, $cfg, $post, $this->root) extends InstallCheckin {
            public function __construct(private array $s, Telemetry $cfg, ?callable $post, string $root)
            {
                parent::__construct($cfg, $post ?? static fn () => ['status' => 200, 'body' => '{"ok":true}'], $root . '/proc', $root . '/net', $root . '/dmi');
            }

            public function settings(): array
            {
                return $this->s;
            }

            public function activeRules(): ?int
            {
                return 3;
            }
        };
    }

    public function testPayloadCarriesTheDashboardFactsAndNothingPrivate(): void
    {
        $p = $this->checkin($this->settings())->payload('auto', $this->settings());

        $this->assertSame('83E6A05D-5315-4822-D234-8C82057040AB', $p['appliance_id']);
        $this->assertSame('micronos-fw01', $p['hostname']);
        $this->assertSame(277245, $p['uptime_sec']);
        $this->assertSame(3, $p['active_rules']);
        $this->assertSame('daily', $p['event']);
        $this->assertSame(2, $p['cpu_cores']);
        $this->assertSame(8192, $p['mem_total_mb']);
        $this->assertSame(2, $p['interfaces'], 'lo and the bridge are not physical ports');
        $this->assertSame('kvm', $p['virtual']);
        $this->assertMatchesRegularExpression('/^2026-10-18T\d\d:30:00Z$/', $p['config_saved_at']);

        $json = json_encode($p);
        $this->assertStringNotContainsString('Secret Corp', $json);
        $this->assertStringNotContainsString('admin@example.com', $json);
    }

    public function testFirstEverRunIsTheInstallEvent(): void
    {
        $s = $this->settings(['telemetry_last_at' => null]);
        $this->assertSame('install', $this->checkin($s)->payload('auto', $s)['event']);
    }

    public function testOffSendsNothing(): void
    {
        $called = false;
        $post   = static function () use (&$called) { $called = true; return ['status' => 200, 'body' => '']; };

        $r = $this->checkin($this->settings(['telemetry_enabled' => 0]), $post)->send();
        $this->assertFalse($r['sent']);
        $this->assertFalse($called);

        $r = $this->checkin($this->settings(), $post, false)->send();
        $this->assertFalse($r['sent'], 'the build-level switch wins over the setting');
        $this->assertFalse($called);
    }

    public function testSendPostsToTheEndpoint(): void
    {
        $seen = null;
        $post = static function (string $url, array $payload) use (&$seen) { $seen = [$url, $payload]; return ['status' => 200, 'body' => '{"ok":true}']; };

        $r = $this->checkin($this->settings(), $post)->send('manual');
        $this->assertTrue($r['sent']);
        $this->assertSame('https://micronos.microninfo.com/api/v1/checkin', $seen[0]);
        $this->assertSame('manual', $seen[1]['event']);
    }

    public function testRejectionIsReportedNotThrown(): void
    {
        $post = static fn () => ['status' => 422, 'body' => '{"ok":false,"error":"bad id"}'];
        $r    = $this->checkin($this->settings(), $post)->send();
        $this->assertFalse($r['sent']);
        $this->assertStringContainsString('422', $r['status']);
        $this->assertStringContainsString('bad id', $r['status']);

        $post = static function () { throw new RuntimeException('Could not resolve host'); };
        $r    = $this->checkin($this->settings(), $post)->send();
        $this->assertFalse($r['sent']);
        $this->assertStringContainsString('resolve host', $r['status']);
    }
}
