<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * A seeded or pre-set cron job is a promise that the appliance will run
 * something at a given time.
 *
 * That promise has two ways to be worthless, and the seeded
 * `cron_backup_nightly` job managed both at once: it named
 * `/usr/local/bin/micron-backup`, a helper shipped nowhere in the tree (so the
 * line compiled into `/etc/cron.d/micronos` could only ever fail), and its seed
 * row reported `last_status: success` over a `/var/backups/*.embk` archive no
 * code could produce. The Cron page therefore displayed a successful nightly
 * encrypted backup on an appliance that had never taken one.
 *
 * These tests hold the two halves of the fix in place for *every* schedule, not
 * just that one row:
 *
 *  - a command must name something the appliance actually has: a binary staged
 *    from `bin/`, a binary from the base image, or a registered `php spark`
 *    command;
 *  - a job that has never run must not claim it did.
 *
 * The three remaining helper binaries the seeds reference are pinned below with
 * their reasons rather than quietly tolerated, so a *new* ghost fails this test
 * until somebody either ships the helper or writes down why it is acceptable.
 *
 * @internal
 */
final class CronSeedCommandTest extends CIUnitTestCase
{
    private const MIGRATIONS = 'app/Database/Migrations/*.php';

    private const CRON_VIEW = 'app/Views/cron/index.php';

    /**
     * The Cron controller, whose run-history fallback also names commands. It
     * hid a third copy of the retired helper: a sample log entry announcing a
     * `micron-backup` archive with "Encryption verified".
     */
    private const CRON_CONTROLLER = 'app/Controllers/Cron.php';

    private const COMMANDS_DIR = 'app/Commands';

    private const SHIPPED_BIN_DIR = 'bin';

    /** The command this appliance ships for scheduled snapshots. */
    private const BACKUP_COMMAND = 'php spark backup:create';

    /**
     * The helper this suite exists to keep retired. It shipped nowhere, yet the
     * seed compiled it into the schedule and reported it as a success.
     */
    private const RETIRED_HELPER = '/usr/local/bin/micron-backup';

    /**
     * Binaries the Debian base image provides, so the tree cannot ship them.
     *
     * @var array<string, string>
     */
    private const OS_PROVIDED_BINARIES = [
        '/usr/sbin/logrotate' => 'logrotate, installed from the ISO package manifest',
        '/usr/sbin/ip'        => 'iproute2, part of the Debian base image',
    ];

    /**
     * Presets that name a helper this tree does not build yet.
     *
     * These are known-broken the same way the backup row was, and pinning them
     * with a reason is deliberate: it makes the debt visible instead of silent.
     * Shipping any of them means deleting its pin here (the non-rot test below
     * fails once a pin stops being referenced).
     *
     * @var array<string, string>
     */
    private const UNSHIPPED_HELPERS = [
        '/usr/local/bin/dnsbl-update'       => 'DNSBL feed compilation has no helper binary; the scheduled job can never run',
        '/usr/local/bin/dyndns-update'      => 'Dynamic DNS has no helper binary; the scheduled job can never run',
        '/usr/local/bin/micron-cert-renew'  => 'Certificate renewal checking has no helper binary; the scheduled job can never run',
        '/usr/local/bin/micron-dnsbl-update' => 'the same unimplemented DNSBL sync as /usr/local/bin/dnsbl-update, named by the run-history fallback',
    ];

    public function testEveryScheduledCommandNamesSomethingTheApplianceHas(): void
    {
        $commands = $this->scheduledCommands();

        // Vacuity guard: an empty or near-empty sweep would pass by scanning nothing.
        $this->assertGreaterThanOrEqual(8, count($commands), 'expected the seeded and preset schedules to be collected');
        $this->assertNotEmpty($this->matched($commands, 'php spark '), 'no php spark schedule was collected');

        $shipped = $this->shippedBinaries();
        $registered = $this->registeredSparkCommands();
        $this->assertGreaterThanOrEqual(10, count($registered), 'the command list is too small to be the real one');

        $unknown = [];

        foreach ($commands as $command) {
            foreach ($this->binariesIn($command) as $binary) {
                if (isset($shipped[$binary])
                    || isset(self::OS_PROVIDED_BINARIES[$binary])
                    || isset(self::UNSHIPPED_HELPERS[$binary])) {
                    continue;
                }

                $unknown[] = "{$command} → {$binary}";
            }

            if (preg_match('/php spark ([a-z0-9:_-]+)/i', $command, $matches) === 1
                && ! isset($registered[$matches[1]])) {
                $unknown[] = "{$command} → no such command: {$matches[1]}";
            }
        }

        $this->assertSame([], $unknown, 'a scheduled command names something no appliance can run');
    }

    public function testTheRetiredBackupHelperIsGoneFromEverySchedule(): void
    {
        // Nothing may compile it back in: not the seeds, not the presets, and
        // not by "fixing" this test — the retired helper must never be pinned as
        // acceptable, because its entire defect was that it was never shipped.
        $this->assertArrayNotHasKey(self::RETIRED_HELPER, self::UNSHIPPED_HELPERS);
        $this->assertArrayNotHasKey(self::RETIRED_HELPER, self::OS_PROVIDED_BINARIES);
        $this->assertArrayNotHasKey(self::RETIRED_HELPER, $this->shippedBinaries());

        $referencing = array_values(array_filter(
            $this->scheduledCommands(),
            fn (string $command): bool => str_contains($command, self::RETIRED_HELPER)
        ));

        $this->assertSame([], $referencing, 'the retired backup helper is back in a schedule');
    }

    public function testTheUnshippedHelpersArePinnedWithAReason(): void
    {
        $commands = $this->scheduledCommands();

        foreach (self::UNSHIPPED_HELPERS as $binary => $reason) {
            $this->assertNotSame('', trim($reason), "{$binary} is pinned without a reason");

            $this->assertTrue(
                (bool) array_filter($commands, fn (string $command): bool => str_contains($command, $binary)),
                "{$binary} is pinned as unshipped but nothing references it any more — delete its pin"
            );
        }
    }

    public function testTheNightlyBackupSeedIsDisabledAndCarriesNoRunHistory(): void
    {
        $seed = $this->backupSeedRow();

        $this->assertSame(self::BACKUP_COMMAND, $seed['command']);
        $this->assertStringNotContainsString('/usr/local/bin/', $seed['command'], 'the seed must name no unshipped helper');
        $this->assertSame('0', $seed['enabled'], 'the job must not be compiled into the schedule until it can run');
        $this->assertSame('pending', $seed['last_status'], 'a job that never ran must not report a result');
        $this->assertFalse($seed['has_last_run_at'], 'the seed must not invent a run time');
        $this->assertFalse($seed['has_last_output'], 'the seed must not invent output');
    }

    public function testTheCorrectiveMigrationDeclaresTheSameBackupJob(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Database/Migrations/2026-10-06-000099_FixNightlyBackupCronJobSeed.php');
        $this->assertIsString($source);

        // Existing installs already hold the bad row, so `000028` cannot be
        // edited into correctness for them; the corrective migration has to
        // declare the same values, or fresh and existing installs diverge.
        $seed = $this->backupSeedRow();

        $this->assertSame(1, preg_match("/private const COMMAND = '([^']*)'/", $source, $command));
        $this->assertSame(1, preg_match("/private const DESCRIPTION = '([^']*)'/", $source, $description));

        $this->assertSame($seed['command'], $command[1], 'the two migrations must declare the same command');
        $this->assertSame($seed['description'], $description[1], 'the two migrations must declare the same description');

        $this->assertSame(1, preg_match("/'enabled'\s*=>\s*0/", $source), 'the corrective migration must disable the job');
        $this->assertSame(1, preg_match("/'last_status'\s*=>\s*'pending'/", $source), 'the corrective migration must clear the fabricated result');
        $this->assertSame(1, preg_match("/'last_output'\s*=>\s*null/", $source), 'the corrective migration must clear the fabricated output');
        $this->assertSame(1, preg_match("/'last_run_at'\s*=>\s*null/", $source), 'the corrective migration must clear the fabricated run time');
    }

    public function testTheCronPagePresetMatchesTheSeededBackupCommand(): void
    {
        $preset = $this->backupPreset();

        // The seed and the preset are two doors onto one job. They were both
        // wrong together, so they are asserted equal here.
        $this->assertSame($this->backupSeedRow()['command'], $preset['command'], 'the Cron page preset and the seed must not drift');
        $this->assertSame($this->backupSeedRow()['description'], $preset['description'], 'the Cron page preset and the seed must not drift');

        $this->assertStringContainsString(
            self::BACKUP_COMMAND,
            $preset['option'],
            'the preset option must show the command it will actually write'
        );
    }

    /**
     * Every command the appliance would run or report: each `command` literal of
     * the seed migrations, each `cmd:` of the Cron page's task presets, and the
     * commands named by the Cron controller's run-history fallback.
     *
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $commands = [];

        foreach (array_merge(glob(ROOTPATH . self::MIGRATIONS) ?: [], [ROOTPATH . self::CRON_CONTROLLER]) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all("/'command'\s*=>\s*'([^']*)'/", $source, $matches) > 0) {
                $commands = array_merge($commands, $matches[1]);
            }
        }

        return array_values(array_unique(array_merge($commands, array_column($this->presets(), 'command'))));
    }

    /**
     * The Cron page's JavaScript task presets.
     *
     * @return list<array{name: string, command: string, description: string}>
     */
    private function presets(): array
    {
        $source = (string) file_get_contents(ROOTPATH . self::CRON_VIEW);

        preg_match_all(
            "/(\w+):\s*\{\s*desc:\s*'([^']*)',\s*cmd:\s*'([^']*)'/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        return array_map(
            static fn (array $m): array => ['name' => $m[1], 'description' => $m[2], 'command' => $m[3]],
            $matches
        );
    }

    /**
     * @return array{command: string, description: string, option: string}
     */
    private function backupPreset(): array
    {
        foreach ($this->presets() as $preset) {
            if ($preset['name'] === 'backup') {
                $view = (string) file_get_contents(ROOTPATH . self::CRON_VIEW);
                $this->assertSame(1, preg_match('/<option value="backup">([^<]*)<\/option>/', $view, $option));

                return [
                    'command'     => $preset['command'],
                    'description' => $preset['description'],
                    'option'      => $option[1],
                ];
            }
        }

        $this->fail('the Cron page no longer offers a backup task preset');
    }

    /**
     * The `cron_backup_nightly` entry as the seed migration declares it.
     *
     * @return array{command: string, description: string, enabled: string, last_status: string, has_last_run_at: bool, has_last_output: bool}
     */
    private function backupSeedRow(): array
    {
        $path = ROOTPATH . 'app/Database/Migrations/2026-10-02-000028_CreateServicesAndCronTables.php';
        $source = (string) file_get_contents($path);

        $start = strpos($source, "'cron_backup_nightly'");
        $this->assertNotFalse($start, 'the nightly backup job is no longer seeded');

        // The row ends at the closing bracket of its own array element.
        $end = strpos($source, '],', (int) $start);
        $this->assertNotFalse($end, 'could not find the end of the seeded backup row');

        $row = substr($source, (int) $start, $end - (int) $start);

        $this->assertSame(1, preg_match("/'command'\s*=>\s*'([^']*)'/", $row, $command));
        $this->assertSame(1, preg_match("/'description'\s*=>\s*'([^']*)'/", $row, $description));
        $this->assertSame(1, preg_match("/'enabled'\s*=>\s*(\d+)/", $row, $enabled));
        $this->assertSame(1, preg_match("/'last_status'\s*=>\s*'([^']*)'/", $row, $status));

        return [
            'command'         => $command[1],
            'description'     => $description[1],
            'enabled'         => $enabled[1],
            'last_status'     => $status[1],
            'has_last_run_at' => preg_match("/'last_run_at'\s*=>/", $row) === 1,
            'has_last_output' => preg_match("/'last_output'\s*=>/", $row) === 1,
        ];
    }

    /**
     * Absolute `/usr/...` paths a command would execute.
     *
     * @return list<string>
     */
    private function binariesIn(string $command): array
    {
        preg_match_all('#/usr/(?:local/)?s?bin/[A-Za-z0-9_.@-]+#', $command, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Binaries this tree ships into the image, keyed by the path a cron line
     * would use. `packaging/live-build/stage-overlay.sh` stages `bin/*` onto
     * `/usr/local/bin/`, so the source tree is the answer to "is it shipped?".
     *
     * @return array<string, string>
     */
    private function shippedBinaries(): array
    {
        $shipped = [];

        foreach (glob(ROOTPATH . self::SHIPPED_BIN_DIR . '/*') ?: [] as $binary) {
            $shipped['/usr/local/bin/' . basename($binary)] = basename($binary);
        }

        return $shipped;
    }

    /**
     * `php spark` command names this application registers.
     *
     * @return array<string, string>
     */
    private function registeredSparkCommands(): array
    {
        $registered = [];

        foreach (glob(ROOTPATH . self::COMMANDS_DIR . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/protected\s+\\\$name\s*=\s*'([^']+)'/", $source, $matches) === 1) {
                $registered[$matches[1]] = basename($file);
            }
        }

        return $registered;
    }

    /**
     * @param list<string> $commands
     */
    private function matched(array $commands, string $needle): array
    {
        return array_values(array_filter($commands, fn (string $command): bool => str_contains($command, $needle)));
    }
}
