<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use App\Libraries\BackupEngine;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\BaseTestCase;

/**
 * `backup:verify`, the integrity sweep over every registered archive.
 *
 * The point of the command is that it can be trusted in both directions: it must
 * catch an archive that no longer matches its record, and it must not cry wolf
 * about one it simply could not check. So the tests below assert both the
 * failing and the passing verdicts, and the ones that matter most are the
 * encrypted archives — their recorded digest covers the payload rather than the
 * bytes on disk, so a passphrase is required to recompute it, while the recorded
 * byte count needs none.
 *
 * Archive creation goes through the real `backup:create` command, so the digests
 * under test are the ones the appliance actually records.
 *
 * @internal
 */
final class BackupVerifyCommandTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PASSPHRASE = 'Console-Passphrase-2026';

    /** @var list<string> */
    private array $backupIds = [];

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The test connections share one in-memory database for the whole
        // process, so the register has to be handed back as it was found.
        $this->backupIds = array_column($this->db->table('system_backups')->select('id')->get()->getResultArray(), 'id');

        $this->tmp = sys_get_temp_dir() . '/micron-backup-verify-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->db->table('system_backups')->whereNotIn('id', $this->backupIds === [] ? [''] : $this->backupIds)->delete();

        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);

        parent::tearDown();
    }

    public function testTheCommandIsRegistered(): void
    {
        $this->assertArrayHasKey('backup:verify', service('commands')->getCommands());
    }

    /**
     * The round trip that makes the command worth running: an archive the
     * appliance just wrote must verify clean.
     */
    public function testAnArchiveTheConsoleJustCreatedVerifies(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate([]));

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('1 verified', $report);
        $this->assertStringContainsString('Integrity verified', $report);
    }

    public function testAnEditedArchiveIsReportedAsDrifted(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate([]));
        $row = $this->registeredRow();

        // Same length, different bytes: only the digest can catch this.
        $path     = $this->storePath((string) $row['filename']);
        $contents = (string) file_get_contents($path);
        file_put_contents($path, str_replace('"format"', '"formXt"', $contents));

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_ERROR, $exit, 'drift must fail the command, or it is only a report');
        $this->assertStringContainsString('DRIFTED', $report);
        $this->assertStringContainsString((string) $row['filename'], $report);
        $this->assertStringContainsString((string) $row['sha256_checksum'], $report, 'the report must show what was recorded');
        $this->assertStringContainsString('Drift detected in 1 archive(s)', $report);
    }

    public function testAVerifiedEncryptedArchiveVerifiesWithItsPassphrase(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate(['encrypt' => null, 'passphrase' => self::PASSPHRASE]));
        $row = $this->registeredRow();

        $this->assertSame(1, (int) $row['is_encrypted']);

        $exit   = $this->runVerify(['passphrase' => self::PASSPHRASE]);
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('1 verified', $report);
        $this->assertStringContainsString('0 unverifiable', $report);
    }

    /**
     * Without a passphrase the payload digest is out of reach — and that must be
     * reported as *unchecked*, never as verified, and never as a failure.
     */
    public function testAnEncryptedArchiveIsUnverifiableRatherThanVerifiedWithoutItsPassphrase(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate(['encrypt' => null, 'passphrase' => self::PASSPHRASE]));
        $row = $this->registeredRow();

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit, 'an unchecked archive is not a drifted one');
        $this->assertStringContainsString('UNVERIFIABLE', $report);
        $this->assertStringContainsString('Not verified: 1 archive(s)', $report);
        $this->assertStringContainsString((string) $row['filename'], $report);
        $this->assertStringContainsString('--passphrase', $report);
    }

    /**
     * The byte count recorded at creation needs no passphrase, which is what
     * makes a truncated encrypted archive detectable on a console where the
     * passphrase is not available.
     */
    public function testATruncatedEncryptedArchiveIsCaughtWithoutAPassphrase(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate(['encrypt' => null, 'passphrase' => self::PASSPHRASE]));
        $row  = $this->registeredRow();
        $path = $this->storePath((string) $row['filename']);

        file_put_contents($path, substr((string) file_get_contents($path), 0, 200));

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('DRIFTED', $report);
        $this->assertStringContainsString('bytes were recorded for it', $report);

        // A damaged envelope has no payload digest to show, and printing the
        // digest of its surviving bytes would sit next to the recorded payload
        // digest looking comparable when it is not.
        $this->assertStringNotContainsString('Recomputed', $report);
    }

    public function testAChangedPassphraseDoesNotReportDrift(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate(['encrypt' => null, 'passphrase' => self::PASSPHRASE]));

        $exit   = $this->runVerify(['passphrase' => 'A-Completely-Different-1']);
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit, 'a wrong passphrase is not evidence that an archive drifted');
        $this->assertStringContainsString('did not decrypt it', $report);
        $this->assertStringNotContainsString('DRIFTED', $report);
    }

    /**
     * `--strict` is the cron-gate form: an archive that cannot be checked is a
     * failure there, where the default is a warning.
     */
    public function testStrictFailsOnAnUnverifiableArchive(): void
    {
        $path = $this->storePath('uncharted.mbk');
        file_put_contents($path, json_encode(['format' => 2, 'tables' => []]));
        $this->registerRow('uncharted.mbk', '', (int) filesize($path));

        $this->assertSame(
            EXIT_SUCCESS,
            $this->runVerify(['passphrase' => self::PASSPHRASE]),
            'without --strict, an unchecked archive is only a warning'
        );
        $this->assertStringContainsString('Not verified', $this->getStreamFilterBuffer());

        $exit   = $this->runVerify(['strict' => null, 'passphrase' => self::PASSPHRASE]);
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Not verified', $report);
    }

    public function testStrictRefusesToRunWithoutAPassphrase(): void
    {
        $this->runCreate(['encrypt' => null, 'passphrase' => self::PASSPHRASE]);

        $exit   = $this->runVerify(['strict' => null]);
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('--strict requires --passphrase', $report);
    }

    public function testAMissingLocalArchiveFails(): void
    {
        $this->registerRow('gone.mbk', '', 0);
        @unlink($this->storePath('gone.mbk'));

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('MISSING', $report);
        $this->assertStringContainsString('the file is gone', $report);
    }

    /**
     * A remote destination was never expected to leave a local file, so its
     * absence must not be reported as drift — a guard that always fails is a
     * guard people turn off.
     */
    public function testARemoteDestinationIsNotReportedAsDrift(): void
    {
        $this->registerRow('offsite.mbk', '', 0, 'sftp');

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('REMOTE', $report);
        $this->assertStringContainsString('1 stored remotely', $report);
    }

    public function testAnArchiveRecordedWithoutADigestIsUnverifiable(): void
    {
        $path = $this->storePath('nodigest.mbk');
        file_put_contents($path, json_encode(['format' => 2, 'tables' => []]));
        $this->registerRow('nodigest.mbk', '', (int) filesize($path));

        $exit   = $this->runVerify();
        $report = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('no SHA-256 was recorded for it', $report);
    }

    public function testAnEmptyRegisterIsNotAFailure(): void
    {
        $this->db->table('system_backups')->emptyTable();

        $exit = $this->runVerify();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('nothing to verify', $this->getStreamFilterBuffer());
    }

    /**
     * The digest is defined by the engine so the restore path and this one cannot
     * drift apart; a restore of a verified archive must still accept it.
     */
    public function testTheDigestVerifyUsesIsTheOneRestoreChecks(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->runCreate([]));
        $row = $this->registeredRow();

        $this->assertSame(
            $row['sha256_checksum'],
            (new BackupEngine())->payloadDigest((string) file_get_contents($this->storePath((string) $row['filename']))),
            'a plaintext archive must digest to exactly what the register recorded'
        );
        $this->assertSame($row['sha256_checksum'], (new BackupEngine())->recordedDigest((string) $row['filename']));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<int|string,string|null> $params
     */
    private function runCreate(array $params): int
    {
        $this->resetStreamFilterBuffer();

        return (int) service('commands')->run('backup:create', ['out' => $this->tmp] + $params);
    }

    /**
     * @param array<int|string,string|null> $params
     */
    private function runVerify(array $params = []): int
    {
        $this->resetStreamFilterBuffer();

        return (int) service('commands')->run('backup:verify', $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function registeredRow(): array
    {
        $row = $this->db->table('system_backups')
            ->like('file_path', $this->storeDir(), 'after')
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getRowArray();

        $this->assertIsArray($row, 'the archive under test must be registered');

        return $row;
    }

    /**
     * `backup:create` records the path with forward slashes, so both the rows
     * here and the lookup that finds them use the same form on every host.
     */
    private function storeDir(): string
    {
        return str_replace('\\', '/', $this->tmp);
    }

    private function storePath(string $filename): string
    {
        return $this->storeDir() . '/' . $filename;
    }

    private function registerRow(string $filename, string $sha256, int $size, string $destination = 'local'): void
    {
        $this->db->table('system_backups')->insert([
            'id'                   => 'bkp_' . bin2hex(random_bytes(8)),
            'filename'             => $filename,
            'file_path'            => $this->storePath($filename),
            'file_size_bytes'      => $size,
            'is_encrypted'         => 0,
            'credentials_included' => 0,
            'sha256_checksum'      => $sha256,
            'backup_type'          => 'full',
            'storage_destination'  => $destination,
            'created_by'           => 'test',
            'description'          => 'fixture',
            'created_at'           => date('Y-m-d H:i:s'),
        ]);
    }
}
