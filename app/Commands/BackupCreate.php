<?php

namespace App\Commands;

use App\Libraries\BackupEngine;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Generates a configuration snapshot from the console.
 *
 * `microctl backup create` drives this, so the emergency console produces the
 * same archive the Backup page does — and, because it is registered on
 * `system_backups`, it is listed and restorable there as well.
 */
class BackupCreate extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'backup:create';
    protected $description = 'Generates an atomic configuration backup archive.';
    protected $usage       = 'backup:create [--encrypt] [--passphrase <password>] [--description <text>] [--out <directory>]';

    /**
     * Policed for the same reason the Web UI polices it: the passphrase is what
     * stands between a recovered archive and every connector secret it carries.
     */
    private const MIN_PASSPHRASE_LENGTH = 12;

    public function run(array $params)
    {
        $passphrase  = $this->optionString($params, 'passphrase');
        $description = $this->optionString($params, 'description') ?: 'Console Configuration Snapshot';
        $out         = $this->optionString($params, 'out');

        // A passphrase always encrypts. `--encrypt` without one is a mistake to
        // report, never a reason to quietly write a plaintext archive.
        if ($this->flag($params, 'encrypt') && $passphrase === '') {
            CLI::error('--encrypt requires --passphrase; refusing to write an unencrypted archive.');

            return EXIT_ERROR;
        }

        if ($passphrase !== '' && strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            CLI::error(
                'The backup passphrase must be at least ' . self::MIN_PASSPHRASE_LENGTH
                . ' characters; it protects connector credentials, not just configuration.'
            );

            return EXIT_ERROR;
        }

        try {
            $backup = (new BackupEngine())->createBackup($passphrase !== '' ? $passphrase : null);
        } catch (\Throwable $e) {
            CLI::error('Failed to create the backup: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        // Default to the store the Backup page reads, so a console snapshot is
        // first-class (listed and restorable) rather than a file nobody finds.
        $directory = $out !== ''
            ? rtrim(str_replace('\\', '/', $out), '/')
            : rtrim(WRITEPATH . 'backups', '/');

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            CLI::error("Could not create the backup directory: {$directory}");

            return EXIT_ERROR;
        }

        $path = $directory . '/' . $backup['filename'];

        try {
            $written = file_put_contents($path, $backup['content']);
        } catch (\Throwable $e) {
            CLI::error("Could not write the archive to {$path}: " . $e->getMessage());

            return EXIT_ERROR;
        }

        if ($written === false) {
            CLI::error("Could not write the archive to {$path}.");

            return EXIT_ERROR;
        }

        $this->register($backup, $path, $description);

        CLI::write('Backup created successfully.', 'green');
        CLI::write('  Archive   : ' . $backup['filename']);
        CLI::write('  Path      : ' . $path);
        CLI::write('  Size      : ' . number_format((int) $backup['size']) . ' bytes');
        CLI::write('  SHA256    : ' . $backup['sha256']);
        CLI::write('  Encrypted : ' . ($backup['is_encrypted'] ? 'yes' : 'no'));

        if ($backup['is_encrypted'] && ! empty($backup['credentials_included'])) {
            CLI::write('  Connector credentials and the master key are inside the encrypted envelope; store the passphrase separately.', 'yellow');
        }

        return EXIT_SUCCESS;
    }

    /**
     * Lists the archive on the Backup page. A registration failure is reported
     * but does not fail the command: the archive is already safely on disk, and
     * the operator needs to know both facts.
     *
     * @param array<string, mixed> $backup
     */
    private function register(array $backup, string $path, string $description): void
    {
        try {
            \Config\Database::connect()->table('system_backups')->insert([
                'id'                   => 'bkp_' . bin2hex(random_bytes(8)),
                'filename'             => $backup['filename'],
                'file_path'            => $path,
                'file_size_bytes'      => $backup['size'],
                'is_encrypted'         => $backup['is_encrypted'] ? 1 : 0,
                'credentials_included' => ! empty($backup['credentials_included']) ? 1 : 0,
                'sha256_checksum'      => $backup['sha256'],
                'backup_type'          => 'full',
                'storage_destination'  => 'local',
                'created_by'           => 'microctl',
                'description'          => $description,
                'created_at'           => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            CLI::write(
                '  Note: the archive was written but could not be registered on the Backup page ('
                . $e->getMessage() . ').',
                'yellow'
            );
        }
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
