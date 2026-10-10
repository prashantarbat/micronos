<?php

namespace App\Commands;

use App\Libraries\CatalogueVerifier;
use App\Libraries\PackageManager;
use App\Libraries\RemoteCatalogue;
use App\Libraries\RepoPresets;
use App\Libraries\RepoRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The plugin & package manager CLI (Plugin Spec §14).
 *
 *     php spark micron:plugin list
 *     php spark micron:plugin plan    lldpd
 *     php spark micron:plugin install lldpd
 *     php spark micron:plugin remove  lldpd [--purge]
 *     php spark micron:plugin status  lldpd
 *     php spark micron:plugin refresh
 *     php spark micron:plugin repos [--apply] [--refresh] [--test <id>]
 *     php spark micron:plugin catalogue [--sync]
 *     php spark micron:plugin sign catalogue.json private-key.pem --key-id micron-plugins-dev
 *
 * Like `micron:connector-sync`, this is a CLI surface (no session) so an
 * operator can drive the package manager over SSH. Every install/remove is
 * dispatched to `micrond`, which re-resolves the id against its own fixed
 * catalogue.
 */
class PluginManage extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:plugin';
    protected $description = 'Plugin & package manager: list, plan, install, remove, status, refresh, repos.';
    protected $usage       = 'micron:plugin <list|plan|install|remove|status|refresh|repos|catalogue|sign> [args] [--purge] [--sync] [--file <path>] [--key-id <id>] [--out <path>] [--apply] [--refresh] [--test <id>]';
    protected $options     = [
        '--purge'   => 'On remove: also delete the plugin\'s data directory (default is retain).',
        '--sync'    => 'On catalogue: fetch and verify the signed remote catalogue.',
        '--apply'   => 'On repos: reconcile the curated repositories on the appliance (writes sources, keys and pins).',
        '--refresh' => 'On repos: refresh apt metadata only.',
        '--test'    => 'On repos: verify one repository (reachable, signed, and signed by the declared key) without changing anything.',
        '--seed'    => 'On repos: register an image-shipped preset repository (disabled) by preset id.',
        '--file'   => 'On catalogue: import a signed catalogue envelope from a local file (air-gap).',
        '--key-id' => 'On sign: the key id to embed in the envelope (defaults to the key filename).',
        '--out'    => 'On sign: the envelope output path (defaults to <input>.signed).',
    ];

    public function run(array $params)
    {
        $manager = new PackageManager();
        $verb    = strtolower((string) array_shift($params));
        $id      = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';

        CLI::write('MicronOS — Plugin & Package Manager', 'yellow');
        CLI::write('==================================', 'yellow');
        CLI::write('');

        switch ($verb) {
            case 'list':
                return $this->listPlugins($manager);
            case 'plan':
                return $id === '' ? $this->missingId('plan') : $this->showPlan($manager, $id);
            case 'install':
                return $id === '' ? $this->missingId('install') : $this->install($manager, $id);
            case 'remove':
                $purge = array_key_exists('purge', $params) || CLI::getOption('purge') !== null;

                return $id === '' ? $this->missingId('remove') : $this->remove($manager, $id, $purge);
            case 'status':
                return $id === '' ? $this->missingId('status') : $this->status($manager, $id);
            case 'refresh':
                return $this->refresh($manager);
            case 'repos':
                $apply   = array_key_exists('apply', $params) || CLI::getOption('apply') !== null;
                $refresh = array_key_exists('refresh', $params) || CLI::getOption('refresh') !== null;
                $test    = $this->optionValue('test');
                $seed    = $this->optionValue('seed');

                if ($test !== null && $test !== '') {
                    return $this->testRepository($test);
                }

                if ($seed !== null && $seed !== '') {
                    return $this->seedRepository($seed);
                }

                return $this->repositories($apply, $refresh);
            case 'catalogue':
                $sync = array_key_exists('sync', $params) || CLI::getOption('sync') !== null;

                return $this->catalogue($sync);
            case 'sign':
                return $this->sign($params);
            default:
                CLI::error('Unknown verb. Use one of: list, plan, install, remove, status, refresh, repos, catalogue, sign.');

                return EXIT_ERROR;
        }
    }

    private function listPlugins(PackageManager $manager): int
    {
        foreach ($manager->catalogue() as $plugin) {
            $plan = $manager->plan($plugin['id']);
            $mark = $plan['ok'] ? 'installable' : 'BLOCKED';
            CLI::write(sprintf(
                '%-12s %-28s v%-8s class %-2s %-12s [%s]',
                $plugin['id'],
                $plugin['name'],
                $plugin['version'],
                $plugin['class'],
                $mark,
                implode(', ', $plugin['packages']) ?: '-',
            ), $plan['ok'] ? 'light_gray' : 'red');
        }

        return EXIT_SUCCESS;
    }

    private function showPlan(PackageManager $manager, string $id): int
    {
        $plan = $manager->plan($id);
        if ($plan['error'] !== null) {
            CLI::error($plan['error']);

            return EXIT_ERROR;
        }

        CLI::write("Plan for {$plan['name']} ({$plan['id']}), class {$plan['class']}", 'light_gray');
        CLI::write('  Packages : ' . (implode(', ', $plan['packages']) ?: '-'));
        CLI::write('  Units    : ' . (implode(', ', $plan['units']) ?: '-'));
        CLI::write('  Repos    : ' . (implode(', ', $plan['repos']) ?: '-'));
        CLI::write('  Grants   : ' . (implode(', ', $plan['capabilities']) ?: '-'));
        foreach ($plan['blocked'] as $reason) {
            CLI::write('  ! ' . $reason, 'red');
        }
        CLI::write($plan['ok'] ? '  → installable' : '  → BLOCKED', $plan['ok'] ? 'green' : 'red');

        return $plan['ok'] ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function install(PackageManager $manager, string $id): int
    {
        $result = $manager->install($id);

        return $this->report($result, "Installed {$id}.");
    }

    private function remove(PackageManager $manager, string $id, bool $purge): int
    {
        $result = $manager->remove($id, $purge);

        return $this->report($result, "Removed {$id}" . ($purge ? ' (purged).' : ' (data retained).'));
    }

    private function status(PackageManager $manager, string $id): int
    {
        return $this->report($manager->status($id), null);
    }

    /**
     * Verify one repository without changing anything (Plugin Spec §5.2).
     */
    private function testRepository(string $id): int
    {
        $report = (new RepoRegistry())->test($id);

        foreach ((array) ($report['steps'] ?? []) as $step) {
            CLI::write('  · ' . $step, 'light_gray');
        }
        foreach ((array) ($report['warnings'] ?? []) as $warning) {
            CLI::write('  ! ' . $warning, 'yellow');
        }

        if (($report['ok'] ?? false) !== true) {
            CLI::error((string) ($report['message'] ?? 'verification failed'));

            return EXIT_ERROR;
        }

        $release = (array) ($report['release'] ?? []);
        $signature = (array) ($report['signature'] ?? []);

        CLI::write('Verified twice over — nothing was changed.', 'green');
        CLI::write('  source   : ' . (string) ($report['release_url'] ?? ''), 'light_gray');
        CLI::write('  key      : ' . (string) ($signature['signer'] ?? '') . '  (' . (string) ($signature['mode'] ?? '') . ' signature)', 'light_gray');
        CLI::write('  release  : ' . trim(implode(' / ', array_filter([
            (string) ($release['origin'] ?? ''),
            (string) ($release['suite'] ?? ''),
            (string) ($release['codename'] ?? ''),
        ]))), 'light_gray');

        return EXIT_SUCCESS;
    }

    /**
     * The curated plugin repositories (Plugin Spec §5.2).
     *
     * `--apply` reconciles the appliance with the registered set (generated
     * sources, verified signing keys, pins, then `apt-get update`); `--refresh`
     * is metadata only. With neither, the registry and its compiled plan are
     * printed, so an operator can see what would change before touching apt.
     */
    private function repositories(bool $apply, bool $refresh): int
    {
        $registry = new RepoRegistry();

        if ($apply) {
            $result = $registry->apply();
            foreach ((array) ($result['steps'] ?? []) as $step) {
                CLI::write('  · ' . $step, 'light_gray');
            }
            foreach ((array) ($result['warnings'] ?? []) as $warning) {
                CLI::write('  ! ' . $warning, 'yellow');
            }
            if (($result['success'] ?? false) !== true) {
                CLI::error((string) ($result['message'] ?? 'repository apply failed'));

                return EXIT_ERROR;
            }
            CLI::write('Curated repositories applied.', 'green');
        } elseif ($refresh) {
            $result = $registry->refresh();
            if (($result['success'] ?? false) !== true) {
                CLI::error((string) ($result['message'] ?? 'repository refresh failed'));

                return EXIT_ERROR;
            }
            CLI::write('Repository metadata refreshed.', 'green');
        }

        $plan = $registry->plan();
        $status = $registry->daemonStatus();

        CLI::write('Registered : ' . $plan['registered'] . '  Enabled: ' . $plan['enabled'], 'light_gray');
        CLI::write('Core pin   : ' . (! empty($status['core_pinned']) ? (string) $status['core_pin'] : 'not written'), 'light_gray');
        CLI::write('');

        foreach ($registry->all() as $repo) {
            CLI::write(sprintf(
                '  %-14s %-30s %-8s %-8s %s',
                $repo['id'],
                $repo['url'],
                $repo['suite'],
                $repo['enabled'] ? 'enabled' : 'disabled',
                $repo['fingerprint'] === '' ? 'no-key' : '…' . substr($repo['fingerprint'], -16),
            ), $repo['enabled'] ? 'light_gray' : 'yellow');
        }

        foreach ($plan['conflicts'] as $conflict) {
            CLI::write('  ! ' . $conflict, 'red');
        }
        foreach ($plan['warnings'] as $warning) {
            CLI::write('  ! ' . $warning, 'yellow');
        }

        if (! $plan['ready']) {
            CLI::write('The plan is not applicable until the conflicts above are resolved.', 'red');

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    /**
     * Register an image-shipped preset repository (Plugin Spec §5.2, P3).
     *
     * Lands the row **disabled**: the operator still enables and applies it,
     * which is the point at which the appliance first talks to the vendor.
     */
    private function seedRepository(string $id): int
    {
        $registry = new RepoRegistry();
        $preset   = (new RepoPresets())->get($id);
        if ($preset === null) {
            CLI::error("Unknown preset: {$id}. Available: "
                . implode(', ', array_keys((new RepoPresets())->all())));

            return EXIT_ERROR;
        }

        $result = $registry->seedPreset($id, 'cli');
        if ($result['ok'] !== true) {
            CLI::error('Preset not registered: ' . implode(' ', $result['errors']));

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            '%-12s %-34s %s',
            (string) $preset['id'],
            (string) $preset['url'],
            ($result['created'] ?? false) ? 'registered (disabled)' : 'already registered — left untouched',
        ), 'light_gray');
        CLI::write('  key  : ' . (string) $preset['fingerprint'] . '  verified ' . (string) $preset['verified_at'], 'light_gray');
        CLI::write('  next : enable it, then `php spark micron:plugin repos --apply`', 'yellow');

        return EXIT_SUCCESS;
    }

    private function refresh(PackageManager $manager): int
    {
        return $this->report($manager->refresh(), 'Repository metadata refreshed.');
    }

    private function catalogue(bool $sync): int
    {
        $catalogue = new RemoteCatalogue();
        $file      = $this->optionValue('file');

        if ($file !== null || $sync) {
            $result = $file !== null ? $catalogue->import($file) : $catalogue->sync();

            if (($result['ok'] ?? false) !== true) {
                CLI::error((string) ($result['message'] ?? 'catalogue update failed'));

                return EXIT_ERROR;
            }
            CLI::write((string) ($result['message'] ?? 'catalogue updated'), 'green');
        }

        $status = $catalogue->status();
        CLI::write('Source URL : ' . ($status['url'] !== '' ? $status['url'] : '(not configured)'), 'light_gray');
        CLI::write('Configured : ' . ($status['configured'] ? 'yes' : 'no'), 'light_gray');

        if (! $status['available']) {
            if (! empty($status['error'])) {
                CLI::write('Status     : not verified — ' . $status['error'], 'yellow');
            } else {
                CLI::write('Status     : no verified catalogue cached', 'yellow');
            }

            return $sync ? EXIT_SUCCESS : EXIT_ERROR;
        }

        CLI::write('Feed       : v' . (string) $status['version'] . ', ' . $status['count'] . ' plugin(s)');
        CLI::write('Key        : ' . $status['key_id'], 'light_gray');
        CLI::write('Issued     : ' . (string) $status['issued_at'] . ' → ' . (string) $status['expires_at'], 'light_gray');
        CLI::write('Fetched    : ' . $status['fetched_at'] . ($status['stale'] ? '  [STALE]' : ''), $status['stale'] ? 'yellow' : 'light_gray');
        CLI::write('SHA-256    : ' . $status['sha256'], 'light_gray');
        CLI::write('');

        foreach ($catalogue->plugins() as $id => $entry) {
            CLI::write(sprintf('  %-14s v%s', $id, (string) ($entry['version'] ?? '')), 'light_gray');
        }

        return EXIT_SUCCESS;
    }

    /**
     * Publisher helper: sign a catalogue payload into an envelope the appliance
     * can verify.
     *
     * @param list<mixed> $params
     */
    private function sign(array $params): int
    {
        $args = array_values(array_filter($params, static fn ($p) => is_string($p) && ! str_starts_with($p, '--')));
        $input = $args[0] ?? '';
        $keyFile = $args[1] ?? '';

        if ($input === '' || $keyFile === '') {
            CLI::error('Usage: micron:plugin sign <catalogue.json> <private-key.pem> [--key-id <id>] [--out <path>]');

            return EXIT_ERROR;
        }
        if (! is_file($input) || ! is_file($keyFile)) {
            CLI::error('Input catalogue or private key file not found.');

            return EXIT_ERROR;
        }

        $payload = (string) file_get_contents($input);
        if (json_decode($payload, true) === null) {
            CLI::error('The catalogue payload is not valid JSON.');

            return EXIT_ERROR;
        }

        $privateKey = @openssl_pkey_get_private((string) file_get_contents($keyFile));
        if ($privateKey === false) {
            CLI::error('The private key could not be loaded.');

            return EXIT_ERROR;
        }

        $keyId = (string) ($this->optionValue('key-id') ?? pathinfo($keyFile, PATHINFO_FILENAME));
        if ($keyId === '') {
            $keyId = 'micron-plugins';
        }

        $signature = '';
        if (! @openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            CLI::error('Signing failed.');

            return EXIT_ERROR;
        }

        $envelope = [
            'schema'    => 1,
            'signature' => [
                'algorithm' => CatalogueVerifier::ALGORITHM,
                'key_id'    => $keyId,
                'value'     => base64_encode($signature),
            ],
            'payload'   => base64_encode($payload),
        ];

        $out = (string) ($this->optionValue('out') ?? ($input . '.signed'));
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($out, $json) === false) {
            CLI::error('Could not write the signed envelope.');

            return EXIT_ERROR;
        }

        CLI::write('Signed catalogue written to ' . $out, 'green');
        CLI::write('  key id   : ' . $keyId, 'light_gray');
        CLI::write('  alg      : ' . CatalogueVerifier::ALGORITHM, 'light_gray');
        CLI::write('  payload  : ' . strlen($payload) . ' bytes → sig ' . strlen($signature) . ' bytes', 'light_gray');

        return EXIT_SUCCESS;
    }

    /**
     * Reads a value-taking option in either spelling.
     *
     * CI4's parser recognises `--name value` (and `--name` as a flag) but stores
     * `--name=value` as a literal option named `name=value`. Both are honoured
     * here so the documented `--out=<path>` style works as expected.
     */
    private function optionValue(string $name): ?string
    {
        $value = CLI::getOption($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        foreach (CLI::getOptions() as $option => $ignored) {
            if (str_starts_with((string) $option, $name . '=')) {
                return substr((string) $option, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function report(array $result, ?string $successMessage): int
    {
        $success = ($result['success'] ?? false) === true;

        foreach ((array) ($result['steps'] ?? []) as $step) {
            CLI::write('  · ' . $step, 'light_gray');
        }
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            CLI::write('  ! ' . $warning, 'yellow');
        }

        if ($success) {
            if ($successMessage !== null) {
                CLI::write($successMessage, 'green');
            } else {
                CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'light_gray');
            }

            return EXIT_SUCCESS;
        }

        CLI::error((string) ($result['message'] ?? 'operation failed'));

        return EXIT_ERROR;
    }

    private function missingId(string $verb): int
    {
        CLI::error("The \"{$verb}\" verb needs a plugin id. Use `micron:plugin list` to see the catalogue.");

        return EXIT_ERROR;
    }
}
