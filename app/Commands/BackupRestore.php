<?php

namespace App\Commands;

use App\Libraries\BackupEngine;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Restores an appliance configuration archive from the console.
 *
 * `microctl backup restore <path>` drives this when the Web UI is unreachable.
 * It reuses the same engine (and therefore the same connector-key policy and
 * post-restore verification) as the Backup page, so a console restore cannot
 * land a configuration the Web path would have refused.
 *
 * Encryption is detected from the envelope's own magic rather than the file
 * extension, because an operator recovering an appliance may well have renamed
 * the archive.
 */
class BackupRestore extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'backup:restore';
    protected $description = 'Restores an appliance configuration archive from the console.';
    protected $usage       = 'backup:restore <path> [--passphrase <password>] [--replace-key] [--nic-map <src:dst,...>] [--force]';

    public function run(array $params)
    {
        $requested = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';
        if ($requested === '') {
            CLI::error('Usage: ' . $this->usage);

            return EXIT_ERROR;
        }

        $path = $this->resolve($requested);
        if ($path === null) {
            CLI::error("Archive not found: {$requested}");

            return EXIT_ERROR;
        }

        $passphrase = $this->optionString($params, 'passphrase');
        $replaceKey = $this->flag($params, 'replace-key') || $this->flag($params, 'replace_key');
        $force      = $this->flag($params, 'force');
        $nicMap     = $this->nicMap($this->optionString($params, 'nic-map') ?: $this->optionString($params, 'nic_map'));

        try {
            $raw = file_get_contents($path);
        } catch (\Throwable $e) {
            CLI::error("Could not read the archive {$path}: " . $e->getMessage());

            return EXIT_ERROR;
        }

        if ($raw === false || $raw === '') {
            CLI::error("The archive is empty or unreadable: {$path}");

            return EXIT_ERROR;
        }

        $engine = new BackupEngine();

        $plaintext = $raw;
        if (str_contains($raw, BackupEngine::ENVELOPE_MAGIC)) {
            if ($passphrase === '') {
                CLI::error('This archive is encrypted; pass --passphrase to restore it.');

                return EXIT_ERROR;
            }

            $decrypted = $engine->decryptPayload($raw, $passphrase);
            if ($decrypted === null) {
                CLI::error('Decryption failed: the passphrase is wrong, or the envelope is corrupt.');

                return EXIT_ERROR;
            }

            $plaintext = $decrypted;
        } elseif ($passphrase !== '') {
            CLI::write('Note: this archive is not encrypted; the supplied passphrase is ignored.', 'yellow');
        }

        if (! $this->checksumMatches($engine, $path, $plaintext, $force)) {
            return EXIT_ERROR;
        }

        $data = json_decode($plaintext, true);
        if (! is_array($data) || ! isset($data['tables']) || ! is_array($data['tables'])) {
            CLI::error('Corrupt or incompatible backup package format.');

            return EXIT_ERROR;
        }

        try {
            $result = $engine->restore($data, $nicMap, [], $replaceKey);
        } catch (\Throwable $e) {
            CLI::error('Restore failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $restored = $result['restored_tables'] ?? [];
        CLI::write('Restored tables (' . count($restored) . '): ' . (implode(', ', $restored) ?: 'none'), 'green');
        CLI::write('  Connector key : ' . ($result['key_action'] ?? 'none'));

        $verification = $result['verification'] ?? [];
        if (! empty($verification['checked'])) {
            $verdict = ! empty($verification['ok']) ? 'OK' : 'FAILED';
            CLI::write('  Credentials   : ' . $verification['checked'] . ' checked, ' . $verdict);
        }

        foreach ($result['warnings'] ?? [] as $warning) {
            CLI::write('  Warning: ' . $warning, 'yellow');
        }

        $repoNote = $this->reconcileRepositories($result);
        if ($repoNote !== null) {
            CLI::write('  Repositories  : ' . $repoNote['message'], $repoNote['ok'] ? 'green' : 'yellow');
        }

        $this->commitRunningConfig();

        $ok = ($result['success'] ?? false) === true
            && (($verification['ok'] ?? true) === true)
            && ($repoNote === null || $repoNote['ok']);

        CLI::write($ok ? 'Restore completed.' : 'Restore completed with warnings; review the output above.', $ok ? 'green' : 'yellow');

        return $ok ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * Resolve the archive the operator named.
     *
     * `spark` runs from the public directory (it does `chdir(FCPATH)`), so a
     * relative path is anchored to the project root instead of the process
     * working directory — `writable/backups/x.mbk` must not depend on where the
     * console happened to be. A bare filename is looked up in the backup store.
     */
    private function resolve(string $path): ?string
    {
        $candidates = [
            $path,
            rtrim(ROOTPATH, '/\\') . '/' . $path,
            rtrim(WRITEPATH . 'backups', '/') . '/' . $path,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Re-apply the curated plugin repositories a restore brought back.
     *
     * Restoring the `plugin_repos` rows restores the *registry*; the appliance's
     * apt state is files the daemon generates, so a restore that skips the
     * reconcile leaves a repository that reads as enabled while the sources file
     * its manifest needs does not exist.
     *
     * @param array<string, mixed> $result
     *
     * @return array{ok: bool, message: string}|null
     */
    private function reconcileRepositories(array $result): ?array
    {
        if (! in_array('plugin_repos', $result['restored_tables'] ?? [], true)) {
            return null;
        }

        try {
            $applied = \Config\Services::repoRegistry()->apply();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'the curated plugin repositories could not be re-applied: ' . $e->getMessage()];
        }

        if (($applied['success'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'restored but NOT applied to the appliance (' . ($applied['message'] ?? 'the daemon refused the plan') . ')'];
        }

        $count = count($applied['repos'] ?? []);

        return [
            'ok'      => true,
            'message' => $count === 0
                ? 'no enabled curated plugin repository was archived.'
                : $count . ' curated plugin repositor' . ($count === 1 ? 'y' : 'ies') . ' re-applied to the appliance.',
        ];
    }

    /**
     * Confirm the running configuration with the daemon. A daemon that is
     * unreachable (or an older daemon without the method) is reported, but does
     * not fail the restore: the archived configuration is already in the
     * database, and the operator needs to know the running ruleset is unconfirmed.
     */
    private function commitRunningConfig(): void
    {
        try {
            $response = \Config\Services::ipc()->call('system.commit');
        } catch (\Throwable $e) {
            CLI::write('  Note: the running configuration could not be committed: ' . $e->getMessage(), 'yellow');

            return;
        }

        if (! empty($response['error'])) {
            CLI::write(
                '  Note: the daemon rejected the commit ('
                . ($response['error']['message'] ?? 'unknown error')
                . '); the configuration is restored but the running ruleset was not confirmed.',
                'yellow'
            );
        }
    }

    /**
     * Compare the archive against the SHA-256 the Backup page recorded for it.
     *
     * The recorded digest is over the *plaintext* payload, so it is checked after
     * decryption. An archive that is not registered has nothing to compare
     * against and is restored as-is.
     */
    private function checksumMatches(BackupEngine $engine, string $path, string $plaintext, bool $force): bool
    {
        // Defined by the engine so `backup:verify` and this path can never
        // disagree about what the recorded digest covers.
        $recorded = $engine->recordedDigest($path);
        if ($recorded === '') {
            return true;
        }

        $actual = $engine->payloadDigest($plaintext);
        if (hash_equals($recorded, $actual)) {
            CLI::write('Integrity: SHA-256 matches the recorded snapshot.', 'green');

            return true;
        }

        if ($force) {
            CLI::write('WARNING: the archive does not match the recorded SHA-256; restoring anyway (--force).', 'yellow');

            return true;
        }

        CLI::error(
            'Integrity check failed: ' . basename($path)
            . ' does not match the SHA-256 recorded for it. Re-run with --force to restore it anyway.'
        );

        return false;
    }

    /**
     * Parse `--nic-map eth0:ens18,eth1:ens19` into a source => target map.
     *
     * @return array<string, string>
     */
    private function nicMap(string $spec): array
    {
        $map = [];

        foreach (array_filter(array_map('trim', explode(',', $spec))) as $pair) {
            [$source, $target] = array_pad(explode(':', $pair, 2), 2, '');
            $source            = trim($source);
            $target            = trim($target);

            if ($source !== '' && $target !== '') {
                $map[$source] = $target;
            }
        }

        return $map;
    }

    /**
     * @param array<int|string,string|null> $params
     */
    private function flag(array $params, string $name): bool
    {
        if (array_key_exists($name, $params)) {
            return true;
        }

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                $value = substr($key, strlen($name) + 1);

                return ! in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
            }
        }

        $option = CLI::getOption($name);

        return $option !== null && ! in_array(strtolower((string) $option), ['0', 'false', 'no', 'off'], true);
    }

    /**
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
