<?php

namespace App\Commands;

use App\Libraries\ThreatIntelEngine;
use App\Models\ThreatFeedModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ThreatIntelSync extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'threat-intel:sync';
    protected $description = 'Ingests and synchronizes external threat intelligence feeds into nftables sets.';
    protected $usage       = 'threat-intel:sync [--feed=<slug>] [--compile-only]';
    protected $options     = [
        '--feed'         => 'Sync only the specified feed by slug.',
        '--compile-only' => 'Only re-compile nftables ruleset without downloading feeds.',
    ];

    public function run(array $params)
    {
        CLI::write('Micron OS — Dynamic Threat Intelligence Engine', 'yellow');
        CLI::write('=================================================', 'yellow');

        $engine = new ThreatIntelEngine();
        $feedModel = new ThreatFeedModel();

        $compileOnly = CLI::getOption('compile-only');
        if ($compileOnly) {
            CLI::write('Compiling nftables ruleset from active indicators...', 'cyan');
            $engine->compileNftablesConfig();
            CLI::write('Threat intelligence ruleset compiled successfully.', 'green');
            return EXIT_SUCCESS;
        }

        $specificSlug = CLI::getOption('feed');
        if (! empty($specificSlug)) {
            $feed = $feedModel->where('slug', $specificSlug)->first();
            if (! $feed) {
                CLI::error("Feed with slug '{$specificSlug}' not found.");
                return EXIT_ERROR;
            }

            CLI::write("Syncing feed '{$feed['name']}'...", 'cyan');
            try {
                $count = $engine->syncFeed($feed);
                $engine->compileNftablesConfig();
                CLI::write("Successfully ingested {$count} indicators for '{$feed['name']}'.", 'green');
                return EXIT_SUCCESS;
            } catch (\Exception $e) {
                CLI::error("Failed to sync '{$feed['name']}': " . $e->getMessage());
                return EXIT_ERROR;
            }
        }

        CLI::write('Syncing all enabled threat intelligence feeds...', 'cyan');
        $results = $engine->syncAllFeeds();

        CLI::write("Total feeds evaluated: {$results['total_feeds']}");
        CLI::write("Successful feeds:      {$results['successful_feeds']}", 'green');
        if ($results['failed_feeds'] > 0) {
            CLI::write("Failed feeds:          {$results['failed_feeds']}", 'red');
            foreach ($results['errors'] as $slug => $err) {
                CLI::write("  - [{$slug}] {$err}", 'red');
            }
        }
        CLI::write("Total active indicators: {$results['total_indicators']}", 'cyan');
        CLI::write('nftables threat intelligence ruleset successfully updated.', 'green');

        return EXIT_SUCCESS;
    }
}
