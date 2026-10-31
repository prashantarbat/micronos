<?php

namespace App\Commands;

use App\Libraries\InstallCheckin;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Tells micronos.microninfo.com that this appliance exists.
 *
 *     php spark micron:checkin              send now (what the timer runs)
 *     php spark micron:checkin --show       print what would be sent, send nothing
 *     php spark micron:checkin --disable    turn check-ins off (same as the switch in System > Networking)
 *     php spark micron:checkin --enable     turn them back on
 *     php spark micron:checkin --status     on/off and the last outcome
 *
 * Run by micron-checkin.timer a few minutes after boot and then daily. When the
 * check-in is off, nothing at all is sent. A failed send exits 0: the next run
 * tries again, and a firewall without internet must not show a failing unit.
 */
class InstallCheckinCommand extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:checkin';
    protected $description = 'Send the install check-in (appliance ID, hostname, version, uptime, boot slot, rule count, hardware) to micronos.microninfo.com, or turn it on/off.';
    protected $usage       = 'micron:checkin [--show] [--status] [--enable|--disable] [--event=auto|boot|daily|manual]';
    protected $options     = [
        '--show'    => 'Print the exact JSON that would be sent and send nothing.',
        '--status'  => 'Show whether check-ins are on and the last outcome.',
        '--enable'  => 'Turn check-ins on.',
        '--disable' => 'Turn check-ins off. Nothing is sent afterwards.',
        '--event'   => 'What triggered this run (default: auto — install, boot or daily).',
    ];

    public function run(array $params)
    {
        $checkin = new InstallCheckin();

        if ($this->flag($params, 'enable') || $this->flag($params, 'disable')) {
            $on = $this->flag($params, 'enable');
            \Config\Database::connect()->table('system_settings')->where('id', 1)->update(['telemetry_enabled' => $on ? 1 : 0]);
            CLI::write('Install check-in is now ' . ($on ? 'on.' : 'off. Nothing will be sent.'), $on ? 'green' : 'yellow');

            return EXIT_SUCCESS;
        }

        if ($this->flag($params, 'status')) {
            $s = $checkin->settings();
            CLI::write('Install check-in: ' . ($checkin->enabled($s) ? 'on' : 'off'));
            CLI::write('Endpoint:         ' . config('Telemetry')->endpoint);
            CLI::write('Last sent:        ' . ($s['telemetry_last_at'] ?: 'never'));
            CLI::write('Last outcome:     ' . ($s['telemetry_last_status'] ?: '—'));

            return EXIT_SUCCESS;
        }

        $event = (string) ($params['event'] ?? CLI::getOption('event') ?? 'auto');
        if (! in_array($event, ['auto', 'boot', 'daily', 'manual'], true)) {
            $event = 'auto';
        }

        if ($this->flag($params, 'show')) {
            CLI::write(json_encode($checkin->payload($event === 'auto' ? 'manual' : $event), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            CLI::write('Sent to ' . config('Telemetry')->endpoint . ' (plus the public IP it arrives from). Check-in is ' . ($checkin->enabled() ? 'on.' : 'off.'), 'light_gray');

            return EXIT_SUCCESS;
        }

        $r = $checkin->send($event);
        CLI::write($r['status'], $r['sent'] ? 'green' : 'yellow');

        return EXIT_SUCCESS;
    }

    private function flag(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null || in_array('--' . $name, $params, true);
    }
}
