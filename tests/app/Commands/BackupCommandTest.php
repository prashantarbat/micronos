<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use App\Libraries\BackupEngine;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\BaseTestCase;

/**
 * The console half of Backup & Restore (`backup:create` / `backup:restore`).
 *
 * `bin/microctl backup create|restore` drives these when the Web UI is
 * unreachable, so the console must not be able to do anything the Web path
 * would refuse: an encrypted archive needs its passphrase, a registered archive
 * must still match the SHA-256 the Backup page recorded for it, and a corrupt
 * package is rejected rather than half-applied.
 *
 * Archives are written to a temporary directory so the suite never touches the
 * appliance's real backup store.
 *
 * @internal
 */
final class BackupCommandTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PASSPHRASE = 'Console-Passphrase-2026';

    /** @var list<string> */
    private array $backupIds = [];

    /** @var array<int, array<string, mixed>> */
    private array $dynRows = [];

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The test connections share one in-memory database for the whole
        // process, so every row this test creates has to be given back.
        $this->backupIds = array_column($this->db->table('system_backups')->select('id')->get()->getResultArray(), 'id');
        $this->dynRows   = $this->db->table('dynamic_dns_servers')->get()->getResultArray();

        $this->tmp = sys_get_temp_dir() . '/micron-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->db->table('system_backups')->whereNotIn('id', $this->backupIds === [] ? [''] : $this->backupIds)->delete();

        $this->db->table('dynamic_dns_servers')->emptyTable();
        if ($this->dynRows !== []) {
            $this->db->table('dynamic_dns_servers')->insertBatch($this->dynRows);
        }

        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);

        parent::tearDown();
    }

    public function testTheCommandsAreRegistered(): void
    {
        $commands = service('commands')->getCommands();

        $this->assertArrayHasKey('backup:create', $commands);
        $this->assertArrayHasKey('backup:restore', $commands);
    }

    public function testCreateWritesAndRegistersAnUnencryptedSnapshot(): void
    {
        $exit = $this->runCreate(['out' => $this->tmp]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('Backup created successfully.', $this->getStreamFilterBuffer());

        $row = $this->latestRow();
        $this->assertNotNull($row, 'the console archive must be listed on the Backup page');
        $this->assertSame(0, (int) $row['is_encrypted']);
        $this->assertSame('microctl', $row['created_by']);
        $this->assertFileExists($this->tmp . '/' . $row['filename']);
    }

    public function testCreateRefusesEncryptWithoutAPassphrase(): void
    {
        $exit = $this->runCreate(['out' => $this->tmp, 'encrypt' => null]);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertNull($this->latestRow(), 'an --encrypt request without a passphrase must write nothing');
        $this->assertSame([], glob($this->tmp . '/*') ?: []);
    }

    public function testCreateRefusesAPassphraseShorterThanTheWebPolicy(): void
    {
        $exit = $this->runCreate(['out' => $this->tmp, 'passphrase' => 'Secret123!']);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertNull($this->latestRow());
        $this->assertStringContainsString('at least 12 characters', $this->getStreamFilterBuffer());
    }

    public function testCreateWritesAnEncryptedArchiveThatDecryptsWithItsPassphrase(): void
    {
        $exit = $this->runCreate(['out' => $this->tmp, 'encrypt' => null, 'passphrase' => self::PASSPHRASE]);

        $this->assertSame(EXIT_SUCCESS, $exit);

        $row = $this->latestRow();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['is_encrypted']);

        $content = (string) file_get_contents($this->tmp . '/' . $row['filename']);
        $this->assertStringContainsString('MICRON_ENC_V1', $content, 'the archive must be an encrypted envelope');

        $plaintext = (new BackupEngine())->decryptPayload($content, self::PASSPHRASE);
        $this->assertNotNull($plaintext, 'the passphrase that created the archive must open it');

        $decoded = json_decode((string) $plaintext, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tables', $decoded);

        // The recorded digest is over the plaintext payload, which is what the
        // restore path re-checks.
        $this->assertSame((string) $row['sha256_checksum'], hash('sha256', (string) $plaintext));
    }

    public function testRestoreRefusesAnEncryptedArchiveWithoutItsPassphrase(): void
    {
        $this->runCreate(['out' => $this->tmp, 'encrypt' => null, 'passphrase' => self::PASSPHRASE]);
        $row = $this->latestRow();

        $exit = $this->runRestore($this->tmp . '/' . $row['filename']);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('encrypted; pass --passphrase', $this->getStreamFilterBuffer());
    }

    public function testRestoreRefusesAWrongPassphrase(): void
    {
        $this->runCreate(['out' => $this->tmp, 'encrypt' => null, 'passphrase' => self::PASSPHRASE]);
        $row = $this->latestRow();

        $exit = $this->runRestore($this->tmp . '/' . $row['filename'], ['passphrase' => 'Wrong-Passphrase-2026']);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Decryption failed', $this->getStreamFilterBuffer());
    }

    public function testRestoreRejectsAnArchiveThatIsNotAConfigurationPackage(): void
    {
        $path = $this->tmp . '/not-a-backup.mbk';
        file_put_contents($path, json_encode(['hello' => 'world']));

        $exit = $this->runRestore($path);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Corrupt or incompatible backup package format', $this->getStreamFilterBuffer());
    }

    public function testRestoreRefusesAnArchiveThatNoLongerMatchesItsRecordedChecksum(): void
    {
        $path = $this->archive(['dynamic_dns_servers' => [$this->dynamicRow('10.9.9.9')]], 'tampered.mbk');
        $this->registerArchive('tampered.mbk', hash('sha256', 'a digest of something else entirely'));

        $exit = $this->runRestore($path);

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Integrity check failed', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->db->table('dynamic_dns_servers')->countAllResults(), 'a tampered archive must not be applied');
    }

    public function testRestoreAppliesAnArchiveWhoseChecksumMatchesAndHonoursForce(): void
    {
        $archive = json_encode([
            'format' => 2,
            'tables' => ['dynamic_dns_servers' => [$this->dynamicRow('10.9.9.9')]],
        ]);
        $path = $this->tmp . '/verified.mbk';
        file_put_contents($path, (string) $archive);
        $this->registerArchive('verified.mbk', hash('sha256', (string) $archive));

        // The digest the Backup page recorded is checked before anything is written.
        $exit = $this->runRestore($path);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('Integrity: SHA-256 matches the recorded snapshot.', $this->getStreamFilterBuffer());
        $this->assertSame('10.9.9.9', $this->firstDynamicAddress());
    }

    public function testRestoreRefusesATamperedArchiveUnlessForced(): void
    {
        $archive = json_encode([
            'format' => 2,
            'tables' => ['dynamic_dns_servers' => [$this->dynamicRow('10.7.7.7')]],
        ]);
        $path = $this->tmp . '/force.mbk';
        file_put_contents($path, (string) $archive);
        $this->registerArchive('force.mbk', hash('sha256', 'not the digest of this archive'));

        $this->assertSame(EXIT_ERROR, $this->runRestore($path));

        // --force is the documented override for an archive the operator knows
        // was edited out of band.
        $exit = $this->runRestore($path, ['force' => null]);

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('restoring anyway (--force)', $this->getStreamFilterBuffer());
        $this->assertSame('10.7.7.7', $this->firstDynamicAddress());
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

        return (int) service('commands')->run('backup:create', $params);
    }

    /**
     * @param array<int|string,string|null> $params
     */
    private function runRestore(string $path, array $params = []): int
    {
        $this->resetStreamFilterBuffer();

        return (int) service('commands')->run('backup:restore', [$path] + $params);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $tables
     */
    private function archive(array $tables, string $name): string
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, json_encode(['format' => 2, 'tables' => $tables]));

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestRow(): ?array
    {
        $row = $this->db->table('system_backups')->where('created_by', 'microctl')->orderBy('created_at', 'DESC')->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    private function registerArchive(string $filename, string $sha256): void
    {
        $this->db->table('system_backups')->insert([
            'id'                   => 'bkp_' . bin2hex(random_bytes(8)),
            'filename'             => $filename,
            'file_path'            => $this->tmp . '/' . $filename,
            'file_size_bytes'      => 1,
            'is_encrypted'         => 0,
            'credentials_included' => 0,
            'sha256_checksum'      => $sha256,
            'backup_type'          => 'full',
            'storage_destination'  => 'local',
            'created_by'           => 'test',
            'description'          => 'fixture',
            'created_at'           => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dynamicRow(string $address): array
    {
        return [
            'id'            => 'dyn_test_' . substr(hash('sha256', $address), 0, 12),
            'source'        => 'dhcp',
            'interface_id'  => 'wan',
            'interface_name' => 'WAN',
            'address'       => $address,
            'assigned_at'   => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
    }

    private function firstDynamicAddress(): string
    {
        $row = $this->db->table('dynamic_dns_servers')->orderBy('address', 'ASC')->get()->getRowArray();

        return is_array($row) ? (string) $row['address'] : '';
    }
}
