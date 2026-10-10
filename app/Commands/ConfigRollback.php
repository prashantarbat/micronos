<?php

namespace App\Commands;

use App\Models\ConfigTransactionModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Reloads the last verified configuration snapshot from the console.
 *
 * `microctl config rollback` drives this when the Web UI is unreachable. It is
 * the console equivalent of the layout's Rollback action: discard the
 * unconfirmed configuration transaction, then tell the daemon to reload the
 * snapshot it captured before the last apply.
 *
 * The daemon's answer is reported rather than assumed — a rollback that did not
 * happen must never look like one that did.
 */
class ConfigRollback extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'config:rollback';
    protected $description = 'Reloads the last verified configuration snapshot, discarding unconfirmed changes.';
    protected $usage       = 'config:rollback';

    public function run(array $params)
    {
        $model   = new ConfigTransactionModel();
        $pending = null;

        try {
            $pending = $model->getActivePending();

            if (is_array($pending)) {
                // Records when the rollback happened, so the console path leaves
                // the same trace on the transaction as the Web UI's Rollback.
                $model->markRolledBack((string) $pending['id']);
            }
        } catch (\Throwable $e) {
            CLI::error('Could not record the rollback: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        if (is_array($pending)) {
            CLI::write('Discarded the unconfirmed configuration transaction #' . $pending['id'] . '.', 'yellow');
        } else {
            CLI::write('No unconfirmed configuration transaction was pending.');
        }

        $response = \Config\Services::ipc()->call('system.rollback');
        $error    = $response['error'] ?? null;

        if (is_array($error)) {
            CLI::error('[micrond] ' . ($error['message'] ?? 'the daemon refused the rollback'));

            return EXIT_ERROR;
        }

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];

        if (array_key_exists('restored', $result)) {
            if ($result['restored'] === true) {
                CLI::write('The appliance reloaded the last verified configuration snapshot.', 'green');
            } else {
                CLI::write('The appliance had no verified snapshot to roll back to (nothing has been applied yet).', 'yellow');
            }
        } else {
            // A daemon that does not report an outcome (simulation mode, or an
            // older build) still accepted the request; say only what is known.
            CLI::write('The daemon accepted the rollback request.', 'green');
        }

        return EXIT_SUCCESS;
    }
}
