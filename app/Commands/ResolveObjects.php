<?php

namespace App\Commands;

use App\Libraries\ResolveObjects as ResolveService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Resolves non-literal address objects (fqdn, internet-service, geoip) into a
 * CIDR list and stores it offline, so firewall rules keep matching the right
 * networks when DNS or the catalogue feed is unreachable.
 *
 *     php spark micron:resolve-objects
 *     php spark micron:resolve-objects --name=Microsoft_365_Access
 *     php spark micron:resolve-objects --type=fqdn --dry-run
 *
 * Delegates to App\Libraries\ResolveObjects, the same engine the web UI's
 * "Resolve Now" action uses. Idempotent: with nothing to resolve it reports so.
 */
class ResolveObjects extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:resolve-objects';
    protected $description = 'Resolves FQDN / internet-service / geography address objects into an offline CIDR list.';
    protected $usage       = 'micron:resolve-objects [--name=<object>] [--type=<fqdn|internet-service|geoip>] [--dry-run]';
    protected $options     = [
        '--name'    => 'Only resolve the address object with this exact name.',
        '--type'    => 'Only resolve objects of this type (fqdn, internet-service, geoip).',
        '--dry-run' => 'Report what would be resolved without writing anything.',
    ];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $name   = $params['name'] ?? CLI::getOption('name');
        $type   = $params['type'] ?? CLI::getOption('type');

        CLI::write('MicronOS — address object resolver', 'yellow');
        CLI::write('==================================', 'yellow');
        if ($dryRun) {
            CLI::write('Dry run — no files or rows will be written.', 'light_gray');
        }
        CLI::write('');

        $summary = (new ResolveService())->run(
            ['name' => $name ?? '', 'type' => $type ?? ''],
            $dryRun,
        );

        foreach ($summary['objects'] as $object) {
            $label = sprintf('%-28s [%s]', $object['name'], $object['type']);

            switch ($object['status']) {
                case 'stored':
                    CLI::write($label . ' → ' . $object['count'] . ' prefix(es) stored', 'green');
                    break;
                case 'would-store':
                    CLI::write($label . ' → ' . $object['count'] . ' prefix(es) would be written');
                    foreach ($object['targets'] as $target) {
                        CLI::write('    ' . $this->relativePath($target), 'dark_gray');
                    }
                    break;
                case 'unresolvable':
                    CLI::write($label . ' → no prefixes resolved (set left to micrond)', 'light_gray');
                    break;
                default:
                    CLI::write($label . ' → could not write feed file', 'red');
            }
        }

        CLI::write('');
        CLI::write(
            sprintf(
                'Objects: %d processed, %d stored, %d unresolvable, %d failed.',
                $summary['processed'],
                $summary['stored'],
                $summary['unresolvable'],
                $summary['failed'],
            ),
            $summary['failed'] > 0 ? 'yellow' : 'green',
        );

        if ($summary['processed'] === 0) {
            CLI::write('No matching address objects to resolve.', 'light_gray');
        }

        return $summary['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /** Renders an absolute path relative to the project root for readable output. */
    private function relativePath(string $path): string
    {
        $root = rtrim(ROOTPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
