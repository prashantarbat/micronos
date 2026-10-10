<?php

declare(strict_types=1);

use App\Libraries\BackupEngine;
use App\Libraries\ConnectorKeyStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Curated plugin repositories in the configuration backup (Plugin Spec §5.2 and
 * §13, backup policy §7.6).
 *
 * A repository is a *trust anchor*: the operator declared its signing key's
 * fingerprint out of band, and the armored public key is cached inline so the
 * appliance never has to re-fetch it from the host whose metadata it is being
 * asked to trust. An archive that dropped those rows would force every Class C
 * plugin to be re-registered by hand after a restore — and on an air-gapped or
 * vendor-unreachable appliance, made it impossible.
 *
 * The rows carry no secret (the key is the vendor's *published* public key), so
 * they travel in a plaintext archive as well as an encrypted one. The
 * encrypted-envelope requirement is asserted separately: the key material must
 * be inside the ciphertext, not beside it.
 *
 * @internal
 */
final class BackupRepositoryPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PASSWORD = 'correct horse battery staple';

    /** The NetBird preset's real trust data, as the migration stores it. */
    private const FINGERPRINT = 'EFE37DF047DF7CCDF1FC54FA83F79AD029778355';
    private const ARMOR       = "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nmQGNBGFPbxEBDADYH1aQc4\n-----END PGP PUBLIC KEY BLOCK-----\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRepos();

        // A real table of our own, so a test can assert on a genuine restore
        // (truncate + insert) without coupling to a core schema.
        $probe = $this->db->prefixTable('backup_repo_policy_probe');
        $this->db->query("CREATE TABLE IF NOT EXISTS {$probe} (
            id VARCHAR(32) PRIMARY KEY,
            note VARCHAR(64)
        )");
        $this->db->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('backup_repo_policy_probe'));
        $this->db->resetDataCache();

        $this->clearRepos();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<string, mixed> the row as inserted */
    private function insertRepo(string $id = 'netbird', bool $enabled = true, string $armor = self::ARMOR): array
    {
        $row = [
            'id'              => $id,
            'url'             => 'https://pkgs.netbird.io/debian',
            'key_fingerprint' => self::FINGERPRINT,
            'enabled'         => $enabled ? 1 : 0,
            'name'            => 'NetBird (pkgs.netbird.io)',
            'suite'           => 'stable',
            'components'      => 'main',
            'architectures'   => 'amd64,arm64',
            'key_url'         => '',
            'key_armor'       => $armor,
            'last_outcome'    => '',
            'last_detail'     => '',
            'added_by'        => 'preset',
            'created_at'      => '2026-10-06 00:00:00',
        ];

        $this->db->table('plugin_repos')->insert($row);

        return $row;
    }

    /**
     * A decoded archive payload, with the tables a restore would be handed.
     *
     * @param array<string, mixed> $tables
     *
     * @return array<string, mixed>
     */
    private function payload(array $tables): array
    {
        return ['format' => 2, 'tables' => $tables];
    }

    private function clearRepos(): void
    {
        if ($this->db->tableExists('plugin_repos')) {
            $this->db->table('plugin_repos')->emptyTable();
        }
    }

    // ------------------------------------------------------------------
    // createBackup
    // ------------------------------------------------------------------

    public function testEncryptedBackupCarriesTheRepositoryRegistryAndItsSigningKey(): void
    {
        $this->insertRepo();

        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(self::PASSWORD);

        $this->assertTrue($backup['is_encrypted']);

        // The signing key really is *inside* the envelope: a raw-key search over
        // the stored bytes must find nothing, or the archive is only pretending
        // to be encrypted.
        $this->assertStringNotContainsString('BEGIN PGP PUBLIC KEY BLOCK', $backup['content']);

        $plaintext = (new BackupEngine($this->db, new ConnectorKeyStore()))
            ->decryptPayload($backup['content'], self::PASSWORD);
        $this->assertIsString($plaintext);

        $data = json_decode($plaintext, true);
        $this->assertArrayHasKey('plugin_repos', $data['tables']);

        $rows = $data['tables']['plugin_repos'];
        $this->assertCount(1, $rows);
        $this->assertSame('netbird', $rows[0]['id']);
        $this->assertSame(self::FINGERPRINT, $rows[0]['key_fingerprint']);
        $this->assertSame(self::ARMOR, $rows[0]['key_armor'], 'the armored signing key must ride with the row');
        $this->assertSame(1, (int) $rows[0]['enabled']);
    }

    /**
     * A plaintext archive carries the registry too, and for a deliberate reason:
     * the rows hold no secret, and a repository is configuration the appliance
     * cannot reproduce without the operator re-typing a fingerprint. Refusing to
     * archive it would break plaintext restores for no confidentiality gain.
     */
    public function testPlaintextBackupAlsoCarriesTheRepositoryRegistry(): void
    {
        $this->insertRepo();

        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(null);

        $this->assertFalse($backup['is_encrypted']);

        $data = json_decode($backup['content'], true);
        $this->assertArrayHasKey('plugin_repos', $data['tables']);
        $this->assertSame(self::ARMOR, $data['tables']['plugin_repos'][0]['key_armor']);

        // ...while the things that *are* secret stay out of a plaintext archive,
        // so the inclusion above is specific to the repository rows.
        $this->assertArrayNotHasKey('connector_secrets', $data['tables']);
        $this->assertArrayNotHasKey('secrets', $data);
        $this->assertFalse($data['credentials_included']);
    }

    public function testEncryptedBackupOfAnEmptyRegistryStillNamesTheTable(): void
    {
        // No rows registered: the key is present and empty, so a restore can tell
        // "the archive had no repositories" from "the archive predates them".
        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(self::PASSWORD);

        $data = json_decode(
            (new BackupEngine($this->db, new ConnectorKeyStore()))->decryptPayload($backup['content'], self::PASSWORD),
            true,
        );

        $this->assertArrayHasKey('plugin_repos', $data['tables']);
        $this->assertSame([], $data['tables']['plugin_repos']);
    }

    // ------------------------------------------------------------------
    // restore
    // ------------------------------------------------------------------

    public function testRestorePutsTheRepositoryRegistryBackWithItsKeyArmor(): void
    {
        $row = $this->insertRepo();

        // The appliance being restored onto has no repository at all.
        $this->clearRepos();
        $this->assertSame(0, $this->db->table('plugin_repos')->countAllResults());

        $result = (new BackupEngine($this->db, new ConnectorKeyStore()))
            ->restore($this->payload(['plugin_repos' => [$row]]));

        $this->assertTrue($result['success']);
        $this->assertContains('plugin_repos', $result['restored_tables'], 'the controller reconciles the daemon off this signal');

        $restored = $this->db->table('plugin_repos')->where('id', 'netbird')->get()->getRowArray();
        $this->assertNotNull($restored);
        $this->assertSame(self::FINGERPRINT, $restored['key_fingerprint']);
        $this->assertSame(self::ARMOR, $restored['key_armor'], 'the trust anchor must survive the round trip verbatim');
        $this->assertSame('stable', $restored['suite']);
    }

    /**
     * A plaintext archive produced by the appliance restores its own registry —
     * the round trip create → restore, which is how a rebuild actually goes.
     */
    public function testAnArchiveRestoresItsOwnRegistryOnToACleanAppliance(): void
    {
        $this->insertRepo('vendor-a');
        $this->insertRepo('vendor-b', false);

        $engine = new BackupEngine($this->db, new ConnectorKeyStore());
        $backup = $engine->createBackup(self::PASSWORD);

        $decoded = json_decode($engine->decryptPayload($backup['content'], self::PASSWORD), true);

        // A rebuilt appliance: empty registry.
        $this->clearRepos();

        $result = $engine->restore($decoded);

        $this->assertTrue($result['success']);
        $this->assertContains('plugin_repos', $result['restored_tables']);
        $this->assertSame(2, $this->db->table('plugin_repos')->countAllResults());

        // The enabled flag is configuration too: vendor-b was registered but not
        // applied, and a restore must not silently enable it.
        $this->assertSame(1, (int) $this->db->table('plugin_repos')->where('id', 'vendor-a')->get()->getRowArray()['enabled']);
        $this->assertSame(0, (int) $this->db->table('plugin_repos')->where('id', 'vendor-b')->get()->getRowArray()['enabled']);
    }

    public function testARestoreWithoutTheRepositoryRegistryLeavesTheRegistryAlone(): void
    {
        $this->insertRepo('keep-me');

        $engine = new BackupEngine($this->db, new ConnectorKeyStore());

        // An archive from before repositories existed: it restores real tables,
        // just none of ours.
        $result = $engine->restore($this->payload([
            'backup_repo_policy_probe' => [['id' => 'p1', 'note' => 'restored']],
        ]));

        $this->assertTrue($result['success'], implode(' ', $result['warnings']));
        $this->assertContains('backup_repo_policy_probe', $result['restored_tables']);
        $this->assertNotContains(
            'plugin_repos',
            $result['restored_tables'],
            'an archive that predates repositories must not trigger a daemon reconcile',
        );
        $this->assertSame(1, $this->db->table('plugin_repos')->where('id', 'keep-me')->countAllResults());
    }
}
