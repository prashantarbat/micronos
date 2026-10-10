<?php

declare(strict_types=1);

use App\Libraries\BackupEngine;
use App\Libraries\ConnectorKeyStore;
use App\Libraries\SecretsVault;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The connector backup policy (specification §7.6), implemented in
 * {@see BackupEngine}:
 *
 *  - a plaintext backup excludes both the master key and the ciphertext rows;
 *  - an encrypted backup carries the key and rows inside the envelope;
 *  - restore installs / matches / explicitly replaces the key, skips connector
 *    ciphertext when no usable key exists, and decryption-verifies afterwards.
 *
 * The key file lives in a temporary directory so the real appliance key is
 * never touched.
 *
 * @internal
 */
final class BackupConnectorPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private string $keyDir;
    private string $keyPath;
    private ConnectorKeyStore $keyStore;
    private BackupEngine $engine;

    private const KEY_A = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10"
        . "\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20";
    private const KEY_B = "\x21\x22\x23\x24\x25\x26\x27\x28\x29\x2a\x2b\x2c\x2d\x2e\x2f\x30"
        . "\x31\x32\x33\x34\x35\x36\x37\x38\x39\x3a\x3b\x3c\x3d\x3e\x3f\x40";

    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyDir  = sys_get_temp_dir() . '/micron_keys_' . bin2hex(random_bytes(4));
        mkdir($this->keyDir, 0700, true);
        $this->keyPath = $this->keyDir . '/secrets.key';

        $this->keyStore = new ConnectorKeyStore($this->keyPath);
        $this->engine   = new BackupEngine($this->db, $this->keyStore);

        // The Phase 2 connector tables do not exist yet; create minimal ones so
        // the policy can be exercised against real rows. Raw SQL must use the
        // configured table prefix (the builder does it automatically).
        $instances = $this->db->prefixTable('connector_instances');
        $secrets   = $this->db->prefixTable('connector_secrets');

        $this->db->query("CREATE TABLE IF NOT EXISTS {$instances} (
            id VARCHAR(36) PRIMARY KEY,
            connector_id VARCHAR(64),
            name VARCHAR(128),
            enabled TINYINT DEFAULT 0,
            last_status VARCHAR(16) DEFAULT 'never'
        )");
        $this->db->query("CREATE TABLE IF NOT EXISTS {$secrets} (
            id VARCHAR(36) PRIMARY KEY,
            instance_id VARCHAR(36) NOT NULL,
            field_key VARCHAR(64) NOT NULL,
            ciphertext TEXT NOT NULL,
            nonce VARCHAR(32) NOT NULL,
            tag VARCHAR(32) NOT NULL,
            key_version INTEGER NOT NULL DEFAULT 1
        )");

        // The connection cached the table list when migrations ran; the new
        // tables must be visible to tableExists()/fieldExists().
        $this->db->resetDataCache();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('connector_secrets'));
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('connector_instances'));
        $this->db->resetDataCache();

        @unlink($this->keyPath);
        @rmdir($this->keyDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Inserts a connector secret row encrypted under $key. */
    private function insertSecretRow(string $key, string $id = 'sec_1', string $value = 'super-secret-token'): array
    {
        $row = (new SecretsVault($this->keyStore))->encrypt($value, $key);
        $record = $row + ['id' => $id, 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $this->db->table('connector_secrets')->insert($record);

        return $record + ['id' => $id];
    }

    /**
     * @param array<string, mixed> $tables
     *
     * @return array<string, mixed>
     */
    private function payload(array $tables, ?string $masterKey): array
    {
        $payload = ['format' => 2, 'tables' => $tables];

        if ($masterKey !== null) {
            $payload['secrets'] = ['master_key' => bin2hex($masterKey), 'key_version' => 1];
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // createBackup: R1 / R2
    // ------------------------------------------------------------------

    public function testPlaintextBackupExcludesKeyAndCiphertextRows(): void
    {
        $this->keyStore->write(self::KEY_A);
        $this->insertSecretRow(self::KEY_A);

        $backup = $this->engine->createBackup(null);

        $this->assertFalse($backup['is_encrypted']);
        $this->assertFalse($backup['credentials_included']);

        $data = json_decode($backup['content'], true);
        $this->assertArrayNotHasKey('secrets', $data, 'plaintext backup must not carry the master key');
        $this->assertArrayNotHasKey('connector_secrets', $data['tables'], 'plaintext backup must not carry ciphertext rows');
        $this->assertFalse($data['credentials_included']);

        // Non-secret connector configuration is still archived.
        $this->assertArrayHasKey('connector_instances', $data['tables']);
    }

    public function testEncryptedBackupCarriesKeyAndCiphertextRowsInsideTheEnvelope(): void
    {
        $this->keyStore->write(self::KEY_A);
        $this->insertSecretRow(self::KEY_A);

        $backup = $this->engine->createBackup(self::PASSWORD);

        $this->assertTrue($backup['is_encrypted']);
        $this->assertTrue($backup['credentials_included']);

        $plaintext = $this->engine->decryptPayload($backup['content'], self::PASSWORD);
        $this->assertIsString($plaintext);

        $data = json_decode($plaintext, true);
        $this->assertSame(bin2hex(self::KEY_A), $data['secrets']['master_key']);
        $this->assertSame(1, $data['secrets']['key_version']);
        $this->assertArrayHasKey('connector_secrets', $data['tables']);
        $this->assertTrue($data['credentials_included']);

        // The key is deliberately outside `tables`, so it can never be inserted
        // into a database table by a restore.
        $this->assertArrayNotHasKey('secrets', $data['tables']);
    }

    public function testEncryptedBackupWithoutAnyKeyStillReportsConfigOnly(): void
    {
        // No key file, no connector rows → nothing credential-bearing to include.
        $backup = $this->engine->createBackup(self::PASSWORD);

        $this->assertTrue($backup['is_encrypted']);
        $this->assertFalse($backup['credentials_included']);

        $data = json_decode($this->engine->decryptPayload($backup['content'], self::PASSWORD), true);
        $this->assertArrayNotHasKey('secrets', $data);
    }

    // ------------------------------------------------------------------
    // restore: key lifecycle + verification
    // ------------------------------------------------------------------

    public function testRestoreInstallsKeyWhenApplianceHasNone(): void
    {
        $secret = (new SecretsVault($this->keyStore))->encrypt('token-b', self::KEY_B);
        $secret += ['id' => 'sec_b', 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $result = $this->engine->restore($this->payload(['connector_secrets' => [$secret]], self::KEY_B));

        $this->assertTrue($result['success']);
        $this->assertSame('installed', $result['key_action']);
        $this->assertFalse($result['needs_credentials']);
        $this->assertTrue($result['verification']['ok']);
        $this->assertTrue(hash_equals(self::KEY_B, (string) $this->keyStore->read()));
        $this->assertSame(1, $this->db->table('connector_secrets')->countAllResults());
    }

    public function testRestoreWithSameKeyLeavesItUnchanged(): void
    {
        $this->keyStore->write(self::KEY_A);
        $secret = (new SecretsVault($this->keyStore))->encrypt('token-a', self::KEY_A);
        $secret += ['id' => 'sec_a', 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $result = $this->engine->restore($this->payload(['connector_secrets' => [$secret]], self::KEY_A));

        $this->assertSame('unchanged', $result['key_action']);
        $this->assertTrue($result['verification']['ok']);
        $this->assertTrue(hash_equals(self::KEY_A, (string) $this->keyStore->read()));
    }

    public function testRestoreRefusesToReplaceADifferentKeyWithoutConfirmation(): void
    {
        $this->keyStore->write(self::KEY_A);
        $this->db->table('connector_instances')->insert(['id' => 'inst_1', 'name' => 'Existing', 'last_status' => 'success']);

        $secret = (new SecretsVault($this->keyStore))->encrypt('token-b', self::KEY_B);
        $secret += ['id' => 'sec_b', 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $result = $this->engine->restore($this->payload([
            'connector_instances' => [['id' => 'inst_1', 'name' => 'FromArchive', 'last_status' => 'success']],
            'connector_secrets'   => [$secret],
        ], self::KEY_B));

        $this->assertSame('skipped_conflict', $result['key_action']);
        $this->assertTrue($result['needs_credentials']);
        $this->assertTrue(hash_equals(self::KEY_A, (string) $this->keyStore->read()), 'key must not be replaced without confirmation');
        $this->assertNotContains('connector_secrets', $result['restored_tables']);
        $this->assertNotContains('connector_instances', $result['restored_tables']);
        $this->assertSame(0, $this->db->table('connector_secrets')->countAllResults());

        // The existing instance is flagged for credential re-entry.
        $row = $this->db->table('connector_instances')->where('id', 'inst_1')->get()->getRowArray();
        $this->assertSame('needs_credentials', $row['last_status']);
    }

    public function testRestoreReplacesAKeyOnlyWhenConfirmed(): void
    {
        $this->keyStore->write(self::KEY_A);
        $secret = (new SecretsVault($this->keyStore))->encrypt('token-b', self::KEY_B);
        $secret += ['id' => 'sec_b', 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $result = $this->engine->restore(
            $this->payload(['connector_secrets' => [$secret]], self::KEY_B),
            [],
            [],
            true,
        );

        $this->assertSame('replaced', $result['key_action']);
        $this->assertFalse($result['needs_credentials']);
        $this->assertTrue($result['verification']['ok']);
        $this->assertTrue(hash_equals(self::KEY_B, (string) $this->keyStore->read()));
        $this->assertSame(1, $this->db->table('connector_secrets')->countAllResults());
    }

    public function testRestoreWithoutAnyKeyMarksCredentialsNeedingReEntry(): void
    {
        $this->keyStore->write(self::KEY_A);
        $secret = (new SecretsVault($this->keyStore))->encrypt('token-a', self::KEY_A);
        $secret += ['id' => 'sec_a', 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $result = $this->engine->restore($this->payload(['connector_secrets' => [$secret]], null));

        $this->assertSame('absent', $result['key_action']);
        $this->assertTrue($result['needs_credentials']);
        $this->assertNotContains('connector_secrets', $result['restored_tables']);
    }

    public function testPostRestoreVerificationRejectsUndecryptableRows(): void
    {
        $this->keyStore->write(self::KEY_A);

        // Row encrypted under a different key but presented as if keyed by KEY_A.
        $secret = (new SecretsVault($this->keyStore))->encrypt('token-b', self::KEY_B);
        $secret += ['id' => 'sec_bad', 'instance_id' => 'inst_1', 'field_key' => 'api_token'];

        $result = $this->engine->restore($this->payload(['connector_secrets' => [$secret]], self::KEY_A));

        $this->assertFalse($result['verification']['ok']);
        $this->assertSame(1, $result['verification']['checked']);
        $this->assertContains('sec_bad', $result['verification']['failed']);
        $this->assertTrue($result['needs_credentials']);
    }

    public function testRestoreSkipsTablesAbsentFromTheAppliance(): void
    {
        $result = $this->engine->restore($this->payload([
            'totally_unknown_table' => [['id' => 'x']],
        ], null));

        $this->assertTrue($result['success']);
        $this->assertNotContains('totally_unknown_table', $result['restored_tables']);
        $this->assertNotEmpty($result['warnings']);
    }

    // ------------------------------------------------------------------
    // Key store
    // ------------------------------------------------------------------

    public function testKeyStoreRoundTripsAndReadsPayloadHexForm(): void
    {
        $this->assertNull($this->keyStore->read());

        $this->assertTrue($this->keyStore->write(self::KEY_A));
        $this->assertTrue($this->keyStore->matches(self::KEY_A));
        $this->assertTrue($this->keyStore->matches(bin2hex(self::KEY_A)), 'hex form must normalise to the same key');

        // Refuses to clobber without an explicit replace.
        $this->assertFalse($this->keyStore->write(self::KEY_B));
        $this->assertTrue(hash_equals(self::KEY_A, (string) $this->keyStore->read()));

        $this->assertTrue($this->keyStore->write(self::KEY_B, true));
        $this->assertTrue($this->keyStore->matches(self::KEY_B));
    }
}
