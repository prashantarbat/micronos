<?php

namespace App\Commands;

use App\Libraries\TerminalClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Console view of the browser terminal service (micron-terminald).
 *
 *   php spark terminal status
 *   php spark terminal sessions [--user <id>]
 *   php spark terminal kill <session-id>
 *   php spark terminal revoke <user-id>        end every session of a Web UI user
 *
 * Talks to the same control socket the Web UI uses; root (or www-data) only.
 */
class TerminalCommand extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'terminal';
    protected $description = 'Inspect and manage browser terminal sessions.';
    protected $usage       = 'terminal <status|sessions|kill <id>|revoke <user-id>>';

    public function run(array $params)
    {
        $client = \Config\Services::terminalClient();
        $action = (string) ($params[0] ?? 'status');
        $status = $client->status();
        if ($status === null) {
            CLI::error('micron-terminald is not running (or this account cannot reach /run/micron/terminal.sock).');

            return 3;
        }

        switch ($action) {
            case 'status':
                CLI::write("micron-terminald {$status['version']} — up {$status['uptime']}s, {$status['sessions']} session(s)");
                foreach ((array) $status['limits'] as $k => $v) {
                    CLI::write(sprintf('  %-24s %s', $k, (string) $v));
                }
                if (empty($status['standard_user_present'])) {
                    CLI::write('  warning: the micron-term account is missing; standard terminals cannot start', 'yellow');
                }

                return 0;

            case 'sessions':
                $user = CLI::getOption('user');
                $list = $client->sessions(is_string($user) ? $user : null) ?? [];
                if ($list === []) {
                    CLI::write('No terminal sessions.');

                    return 0;
                }
                foreach ($list as $s) {
                    CLI::write(sprintf('%s  %-8s %-16s pid %-7d %s  %s', $s['id'], strtoupper($s['level']), $s['username'],
                        $s['pid'], date('Y-m-d H:i', (int) $s['created']), $s['attached'] ? 'attached' : 'detached'));
                }

                return 0;

            case 'kill':
                $id = (string) ($params[1] ?? '');
                if (preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
                    CLI::error('usage: php spark terminal kill <session-id>');

                    return 1;
                }
                $ok = $client->kill($id, null, 'terminated from the console');
                CLI::write($ok ? "Session {$id} terminated." : "No session {$id}.", $ok ? 'green' : 'red');

                return $ok ? 0 : 3;

            case 'revoke':
                $uid = (string) ($params[1] ?? '');
                if ($uid === '') {
                    CLI::error('usage: php spark terminal revoke <user-id>');

                    return 1;
                }
                $client->revokeUser($uid, 'revoked from the console');
                CLI::write("Every terminal session of user {$uid} was ended.", 'green');

                return 0;
        }

        CLI::error('Unknown action. ' . $this->usage);

        return 1;
    }
}
