<?php

namespace App\Commands;

use App\Connectors\ConnectorRunner;
use App\Connectors\ManifestRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Runs a shipped connector through the connector engine.
 *
 *     php spark micron:connector-sync mdns-reflection
 *     php spark micron:connector-sync --all
 *     php spark micron:connector-sync --list
 *
 * This is the reachable entry point for the engine skeleton. It is a CLI
 * command, not a web route, because connector syncs run without a session (see
 * the connector specification §8.1); eventual cron wiring reuses the existing
 * `cron_jobs` engine.
 *
 * Instance-scoped scheduling (`connector_instances`) arrives in Phase 2. Until
 * then the command addresses manifests directly, which is exactly what the
 * skeleton needs to exercise the target adapters.
 */
class ConnectorSync extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:connector-sync';
    protected $description = 'Runs connector manifests through the connector engine (service target skeleton).';
    protected $usage       = 'micron:connector-sync <connector-id> [--all] [--list]';
    protected $options     = [
        '--all'  => 'Run every shipped connector manifest.',
        '--list' => 'List the shipped connectors and exit.',
    ];

    public function run(array $params)
    {
        $registry = new ManifestRegistry();
        $list     = array_key_exists('list', $params) || CLI::getOption('list') !== null;
        $all      = array_key_exists('all', $params) || CLI::getOption('all') !== null;

        CLI::write('MicronOS — Connector engine', 'yellow');
        CLI::write('===========================', 'yellow');
        CLI::write('');

        if ($list) {
            foreach ($registry->all() as $manifest) {
                $targets = implode(', ', array_unique(array_column($manifest->sources(), 'target')));
                CLI::write(sprintf('%-20s %-28s [%s]', $manifest->id(), $manifest->name(), $targets));
            }

            return EXIT_SUCCESS;
        }

        $ids = $all ? $registry->ids() : array_values(array_filter([
            isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '',
        ], static fn ($id) => $id !== ''));

        if ($ids === []) {
            CLI::error('No connector id given. Use --list to see the shipped connectors, or --all to run them all.');

            return EXIT_ERROR;
        }

        $runner = new ConnectorRunner($registry);
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $summary = $runner->run($id);
            } catch (\Throwable $e) {
                CLI::error("{$id} → " . $e->getMessage());
                $failed++;
                continue;
            }

            CLI::write($summary['name'] . ' (' . $summary['connector'] . ')', 'light_gray');
            foreach ($summary['sources'] as $source) {
                $label = sprintf('  %-12s %-10s', $source['source_id'], $source['target']);
                if ($source['status'] === 'applied') {
                    CLI::write($label . '→ applied (' . ($source['ref'] ?: '-') . ')', 'green');
                } elseif ($source['status'] === 'staged') {
                    CLI::write($label . '→ staged ' . ($source['ref'] ?: '-') . ': ' . $source['message'], 'cyan');
                } elseif ($source['status'] === 'skipped') {
                    CLI::write($label . '→ skipped: ' . $source['message'], 'yellow');
                } else {
                    CLI::write($label . '→ ' . $source['message'], 'red');
                }
                foreach ($source['warnings'] as $warning) {
                    CLI::write('      ! ' . $warning, 'yellow');
                }
            }

            if (! $summary['success']) {
                $failed++;
            }
            CLI::write('');
        }

        $exit = $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        CLI::write($failed > 0 ? "{$failed} connector(s) failed." : 'All connectors applied.', $failed > 0 ? 'red' : 'green');

        return $exit;
    }
}
