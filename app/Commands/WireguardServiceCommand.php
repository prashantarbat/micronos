<?php

namespace App\Commands;

use App\Libraries\WireguardCompiler;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Apply lifecycle actions to every configured WireGuard interface. */
class WireguardServiceCommand extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'wireguard:service';
    protected $description = 'Start, stop or restart all configured WireGuard tunnels.';
    protected $usage       = 'wireguard:service <start|stop|restart>';

    public function run(array $params)
    {
        $action = strtolower(trim((string) ($params[0] ?? '')));
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            CLI::error('Usage: php spark wireguard:service <start|stop|restart>');

            return 2;
        }

        $db      = \Config\Database::connect();
        $tunnels = $db->table('wireguard_tunnels')->orderBy('interface_name', 'ASC')->get()->getResultArray();

        $compiler = new WireguardCompiler($db);
        $payload  = [];
        $configBytes = 0;
        foreach ($tunnels as $tunnel) {
            $interface = $compiler->interfaceName($tunnel);
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_=+.-]{0,14}$/', $interface)) {
                CLI::error("Invalid WireGuard interface name: {$interface}");

                return 1;
            }
            $config = $compiler->compileServerConfig((string) $tunnel['id']);
            $configBytes += strlen($config);
            if ($configBytes > 8 * 1024 * 1024) {
                CLI::error('The configured WireGuard tunnels exceed the aggregate RPC size limit.');

                return 1;
            }
            $payload[] = [
                'interface' => $interface,
                'enabled'   => (int) ($tunnel['enabled'] ?? 1) === 1,
                'config'    => $config,
            ];
        }

        $response = \Config\Services::ipc()->call('vpn.wireguard.service', [
            'action'  => $action,
            'tunnels' => $payload,
        ]);
        $error  = $response['error'] ?? null;
        $result = $response['result'] ?? null;
        if (! is_array($result) || (is_array($error) && $error !== []) || ($result['success'] ?? false) !== true) {
            $message = is_array($error) ? (string) ($error['message'] ?? '') : '';
            $message = $message !== '' ? $message : (string) ($result['message'] ?? 'micrond reported a WireGuard lifecycle failure');
            CLI::error($message);

            return 1;
        }

        CLI::write((string) ($result['message'] ?? "WireGuard tunnels {$action} complete."));

        return 0;
    }
}