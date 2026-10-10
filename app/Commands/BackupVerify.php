<?php

namespace App\Commands;

use App\Libraries\BackupEngine;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Checks every registered archive against the digest recorded for it.
 *
 * `microctl backup verify` drives this before an operator reaches for a
 * snapshot, and from cron as a rot detector. A backup that cannot be verified is
 * indistinguishable from no backup at all, and nothing else on the appliance
 * notices when an archive is truncated, edited or half-copied — the Backup page
 * lists the register, and `backup:restore` only ever looks at the one archive it
 * was handed.
 *
 * What can be checked without a passphrase is deliberately separated from what
 * cannot. The recorded digest covers the *payload*, not the file, so an
 * encrypted archive needs its passphrase before the digest can be recomputed.
 * The recorded byte count needs no passphrase at all, and a size mismatch is on
 * its own proof that the archive on disk is not the one that was registered —
 * so a truncated or replaced archive is caught even when nobody supplies a
 * passphrase. Anything left uncheckable is reported as such and never silently
 * counted as verified.
 */
class BackupVerify extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'backup:verify';
    protected $description = 'Checks every registered archive against the SHA-256 recorded for it.';
    protected $usage       = 'backup:verify [--passphrase <password>] [--strict]';

    /** A digest comparison that could not be made, as opposed to one that failed. */
    private const UNVERIFIABLE = 'UNVERIFIABLE';

    /** Registered against a remote destination and absent locally: not evidence of drift. */
    private const REMOTE = 'REMOTE';

    public function run(array $params)
    {
        $passphrase = $this->optionString($params, 'passphrase');
        $strict     = $this->flag($params, 'strict');

        if ($strict && $passphrase === '') {
            CLI::error(
                '--strict requires --passphrase: without it, every encrypted archive stays unverified, '
                . 'and a gate that always fails is not a gate.'
            );

            return EXIT_ERROR;
        }

        $engine = new BackupEngine();

        try {
            $rows = $engine->registeredArchives();
        } catch (\Throwable $e) {
            CLI::error('Could not read the backup register: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('=== Appliance Backup Integrity ===', 'yellow');

        if ($rows === []) {
            CLI::write('No archive is registered on this appliance, so there is nothing to verify.');

            return EXIT_SUCCESS;
        }

        CLI::write('  ' . count($rows) . ' registered archive(s)');
        CLI::newLine();

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->inspect($engine, $row, $passphrase);
        }

        $this->report($results);

        return $this->verdict($results, $strict);
    }

    /**
     * Inspect one registered archive.
     *
     * @param array<string, mixed> $row
     *
     * @return array{verdict: string, filename: string, bytes: int|null, recorded: string, actual: string, reason: string, row: array<string, mixed>}
     */
    private function inspect(BackupEngine $engine, array $row, string $passphrase): array
    {
        $filename = (string) ($row['filename'] ?? '');
        $path     = $this->locate($row);

        $result = [
            'verdict'  => 'OK',
            'filename' => $filename,
            'bytes'    => null,
            'recorded' => '',
            'actual'   => '',
            'reason'   => '',
            'row'      => $row,
        ];

        if ($path === null) {
            // A record pointing at a remote destination (sftp/s3/central) was
            // never expected to leave a local file behind, so its absence is not
            // drift. A local archive that has vanished is.
            $remote = (string) ($row['storage_destination'] ?? 'local') !== 'local';

            return array_merge($result, [
                'verdict' => $remote ? self::REMOTE : 'MISSING',
                'reason'  => $remote
                    ? 'registered against the ' . (string) $row['storage_destination'] . ' destination; nothing to verify locally'
                    : 'registered as a local archive but the file is gone',
            ]);
        }

        $raw  = (string) file_get_contents($path);
        $size = strlen($raw);

        $recordedSize = (int) ($row['file_size_bytes'] ?? 0);
        $recorded     = $engine->recordedDigest($filename);

        $result['bytes']    = $size;
        $result['recorded'] = $recorded;

        // The byte count is the only check available without a passphrase, and it
        // needs none: the register recorded the size of the archive it wrote.
        if ($recordedSize > 0 && $recordedSize !== $size) {
            // Only shown when a payload digest is genuinely available: for a
            // damaged envelope there is none, and printing the digest of its
            // bytes would sit next to the recorded payload digest looking
            // comparable when it is not.
            $result['actual'] = $this->payloadDigestOrNull($engine, $raw, $passphrase) ?? '';
            $result['reason'] = 'the archive is ' . number_format($size) . ' bytes; '
                . number_format($recordedSize) . ' bytes were recorded for it';

            return array_merge($result, ['verdict' => 'DRIFTED']);
        }

        if ($recorded === '') {
            return array_merge($result, [
                'verdict' => self::UNVERIFIABLE,
                'reason'  => 'no SHA-256 was recorded for it, so there is nothing to compare against',
            ]);
        }

        if ($this->needsPassphrase($engine, $raw) && $passphrase === '') {
            return array_merge($result, [
                'verdict' => self::UNVERIFIABLE,
                'reason'  => 'encrypted; pass --passphrase to recompute its payload digest'
                    . ' (the recorded size matches, so the file has not changed length)',
            ]);
        }

        $actual = $this->payloadDigestOrNull($engine, $raw, $passphrase);

        if ($actual === null) {
            // A passphrase was supplied and the envelope still would not open.
            // The recorded size matched, so this is far more likely to be the
            // wrong passphrase than a damaged archive — either way the digest is
            // unknown, which is not the same as knowing it is wrong.
            return array_merge($result, [
                'verdict' => self::UNVERIFIABLE,
                'reason'  => 'the supplied passphrase did not decrypt it; the file itself is unchanged in length',
            ]);
        }

        $result['actual'] = $actual;

        if (! hash_equals($recorded, $actual)) {
            return array_merge($result, [
                'verdict' => 'DRIFTED',
                'reason'  => 'the recomputed payload digest does not match the recorded one',
            ]);
        }

        return $result;
    }

    /**
     * The payload digest of an archive, or null when it cannot be computed.
     *
     * Returns the raw bytes' own digest for a plaintext archive, and decrypts
     * first for an encrypted one — the recorded digest covers the payload, so an
     * encrypted archive has no digest until it is opened.
     */
    private function payloadDigestOrNull(BackupEngine $engine, string $raw, string $passphrase): ?string
    {
        if ($engine->isEncryptedArchive($raw)) {
            if ($passphrase === '') {
                return null;
            }

            $plaintext = $engine->decryptPayload($raw, $passphrase);

            return $plaintext === null ? null : $engine->payloadDigest($plaintext);
        }

        // Not a well-formed envelope, but still carrying the envelope's marker:
        // a damaged envelope, whose bytes are not a payload. Hashing them would
        // produce a number that reads as comparable to the recorded digest when
        // it is nothing of the kind.
        if (str_contains($raw, BackupEngine::ENVELOPE_MAGIC)) {
            return null;
        }

        return $engine->payloadDigest($raw);
    }

    /**
     * Whether the archive's payload digest is behind a passphrase.
     *
     * Two detections, deliberately: a well-formed envelope answers structurally,
     * while the marker is also looked for on its own so that a *damaged* envelope
     * is still recognised as one that needs a passphrase rather than being hashed
     * as if it were a plaintext payload. `backup:restore` refuses these without a
     * passphrase for the same reason, so the two paths agree on what is encrypted.
     */
    private function needsPassphrase(BackupEngine $engine, string $raw): bool
    {
        return $engine->isEncryptedArchive($raw) || str_contains($raw, BackupEngine::ENVELOPE_MAGIC);
    }

    /**
     * The archive on disk: the path it was registered with, or its name in the
     * backup store — a store that has been relocated since the row was written
     * still verifies.
     *
     * @param array<string, mixed> $row
     */
    private function locate(array $row): ?string
    {
        $candidates = [
            (string) ($row['file_path'] ?? ''),
            rtrim(WRITEPATH . 'backups', '/\\') . '/' . basename((string) ($row['filename'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array{verdict: string, filename: string, bytes: int|null, recorded: string, actual: string, reason: string, row: array<string, mixed>}> $results
     */
    private function report(array $results): void
    {
        foreach ($results as $result) {
            CLI::write(
                sprintf(
                    '  %-13s %s%s',
                    $result['verdict'],
                    $result['filename'],
                    $result['bytes'] === null ? '' : '  (' . number_format($result['bytes']) . ' bytes)'
                ),
                $this->colorFor($result['verdict'])
            );
        }

        $problems = array_values(array_filter(
            $results,
            static fn (array $r): bool => $r['verdict'] !== 'OK' && $r['verdict'] !== self::REMOTE
        ));

        if ($problems !== []) {
            CLI::newLine();
            CLI::write('Detail:');

            foreach ($problems as $result) {
                CLI::write('  ' . $result['filename'], $this->colorFor($result['verdict']));
                CLI::write('    Registered : ' . $this->registeredAt($result['row']));
                CLI::write('    Reason     : ' . $result['reason']);

                if ($result['recorded'] !== '') {
                    CLI::write('    Recorded   : ' . $result['recorded']);
                }

                if ($result['actual'] !== '') {
                    CLI::write('    Recomputed : ' . $result['actual']);
                }
            }
        }

        CLI::newLine();

        $counts = array_count_values(array_column($results, 'verdict'));

        CLI::write(sprintf(
            '  %d verified, %d drifted, %d missing, %d unverifiable, %d stored remotely',
            $counts['OK'] ?? 0,
            $counts['DRIFTED'] ?? 0,
            $counts['MISSING'] ?? 0,
            $counts[self::UNVERIFIABLE] ?? 0,
            $counts[self::REMOTE] ?? 0
        ));
    }

    /**
     * @param list<array{verdict: string, filename: string, bytes: int|null, recorded: string, actual: string, reason: string, row: array<string, mixed>}> $results
     */
    private function verdict(array $results, bool $strict): int
    {
        $drifted = $this->filenames($results, 'DRIFTED');
        $missing = $this->filenames($results, 'MISSING');
        $unverified = $this->filenames($results, self::UNVERIFIABLE);

        if ($drifted !== [] || $missing !== []) {
            if ($drifted !== []) {
                CLI::error('Drift detected in ' . count($drifted) . ' archive(s): ' . implode(', ', $drifted));
            }

            if ($missing !== []) {
                CLI::error('Registered archive(s) missing: ' . implode(', ', $missing));
            }

            return EXIT_ERROR;
        }

        if ($unverified !== []) {
            $message = 'Not verified: ' . count($unverified) . ' archive(s) — ' . implode(', ', $unverified)
                . '. Re-run with --passphrase to check them.';

            if ($strict) {
                CLI::error($message);

                return EXIT_ERROR;
            }

            CLI::write($message, 'yellow');
        }

        if ($this->filenames($results, 'OK') === []) {
            CLI::write('No archive could be verified against a recorded digest.', 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write('Integrity verified: every checkable archive matches its recorded SHA-256.', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @param list<array{verdict: string, filename: string, bytes: int|null, recorded: string, actual: string, reason: string, row: array<string, mixed>}> $results
     *
     * @return list<string>
     */
    private function filenames(array $results, string $verdict): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['filename'],
            array_filter($results, static fn (array $r): bool => $r['verdict'] === $verdict)
        ));
    }

    private function colorFor(string $verdict): string
    {
        return match ($verdict) {
            'OK'                          => 'green',
            'DRIFTED', 'MISSING'          => 'red',
            self::UNVERIFIABLE            => 'yellow',
            default                       => 'light_gray',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function registeredAt(array $row): string
    {
        $created = (string) ($row['created_at'] ?? '');
        $by      = (string) ($row['created_by'] ?? '');

        if ($created === '') {
            return $by === '' ? 'unknown' : 'unknown by ' . $by;
        }

        return $by === '' ? $created : $created . ' by ' . $by;
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
