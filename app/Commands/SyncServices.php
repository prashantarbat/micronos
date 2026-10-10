<?php

namespace App\Commands;

use App\Libraries\ServiceDatabase;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Downloads the public prefix feeds behind the Internet Service Database and
 * refreshes the offline feed files the nftables compiler inlines. Optionally
 * refreshes geography feeds for the countries referenced by address objects.
 *
 *     php spark micron:sync-services
 *     php spark micron:sync-services --key=aws
 *     php spark micron:sync-services --geo
 *     php spark micron:sync-services --dry-run
 *
 * Designed to be run from cron (see the seeded `cron_service_sync` job) so the
 * catalogue stays current without an admin remembering to press Synchronize.
 */
class SyncServices extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:sync-services';
    protected $description = 'Downloads public prefix feeds for the Internet Service Database and geography feeds.';
    protected $usage       = 'micron:sync-services [--key=aws,github] [--geo] [--dry-run]';
    protected $options     = [
        '--key'     => 'Comma-separated Internet Service Database ids to sync (default: all).',
        '--geo'     => 'Also refresh geography feeds for countries used by address objects.',
        '--dry-run' => 'Report what would be downloaded without writing anything.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $geo    = array_key_exists('geo', $params) || CLI::getOption('geo') !== null;

        // CI4's option parser keeps one value per option, so --key accepts a
        // comma-separated list rather than being repeatable. It also does not
        // split `--key=value`; that arrives as an option literally named
        // `key=value`, so both spellings are accepted here.
        $keys = [];
        foreach (preg_split('/[\s,]+/', $this->optionString($params, 'key')) as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        CLI::write('MicronOS — Internet Service Database sync', 'yellow');
        CLI::write('=========================================', 'yellow');
        if ($dryRun) {
            CLI::write('Dry run — no files or rows will be written.', 'light_gray');
        }
        CLI::write('');

        $engine  = new ServiceDatabase();
        $summary = $engine->syncServices($keys, $dryRun);

        foreach ($summary['services'] as $svc) {
            $label = sprintf('%-22s', $svc['name']);
            switch ($svc['status']) {
                case 'synced':
                    CLI::write($label . ' → ' . $svc['count'] . ' prefix(es) stored', 'green');
                    break;
                case 'dry-run':
                    CLI::write($label . ' → ' . $svc['count'] . ' prefix(es) would be written');
                    break;
                case 'no-source':
                    CLI::write($label . ' → no public feed registered', 'light_gray');
                    break;
                default:
                    CLI::write($label . ' → ' . ($svc['error'] ?? 'failed'), 'red');
            }
        }

        CLI::write('');
        CLI::write(
            sprintf(
                'Services: %d processed, %d synced, %d skipped, %d failed.',
                $summary['processed'],
                $summary['synced'],
                $summary['skipped'],
                $summary['failed'],
            ),
            $summary['failed'] > 0 ? 'yellow' : 'green',
        );

        $exit = $summary['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;

        if ($geo) {
            $codes = $engine->countriesInUse();
            CLI::write('');

            if ($codes === []) {
                CLI::write('No geography address objects reference a country feed.', 'light_gray');
            } else {
                $geoSummary = $engine->syncCountries($codes, $dryRun);
                foreach ($geoSummary['countries'] as $country) {
                    $label = sprintf('%-4s', $country['code']);
                    if ($country['status'] === 'synced' || $country['status'] === 'dry-run') {
                        CLI::write($label . ' → ' . $country['count'] . ' prefix(es) ' . ($country['status'] === 'dry-run' ? 'would be written' : 'stored'), $country['status'] === 'dry-run' ? 'white' : 'green');
                    } else {
                        CLI::write($label . ' → ' . ($country['error'] ?? 'failed'), 'red');
                    }
                }

                CLI::write('');
                CLI::write(
                    sprintf('Countries: %d synced, %d failed.', $geoSummary['synced'], $geoSummary['failed']),
                    $geoSummary['failed'] > 0 ? 'yellow' : 'green',
                );

                if ($geoSummary['failed'] > 0) {
                    $exit = EXIT_ERROR;
                }
            }
        }

        return $exit;
    }

    /**
     * Reads an option value given as either `--key value` or `--key=value`.
     *
     * @param array<int|string,string|null> $params
     */
    private function optionString(array $params, string $name): string
    {
        if (isset($params[$name]) && is_string($params[$name])) {
            return $params[$name];
        }

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return substr($key, strlen($name) + 1);
            }
        }

        $option = CLI::getOption($name);

        return is_string($option) ? $option : '';
    }
}
