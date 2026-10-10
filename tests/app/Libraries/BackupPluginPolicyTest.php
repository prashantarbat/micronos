<?php

declare(strict_types=1);

use App\Libraries\BackupEngine;
use App\Libraries\ConnectorKeyStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Plugin state in the configuration backup (Plugin Spec §10 and §13, backup
 * policy §7.6).
 *
 * Three tables, two policies. `plugins` and `plugin_grants` hold no secret — a
 * manifest is the plugin's public shape and a grant is a capability name the
 * operator already approved — so they travel in a plaintext archive as well as
 * an encrypted one, like the curated repository registry beside them. A rebuilt
 * appliance has to come back knowing which plugins it installed and which
 * capabilities it consented to, or the operator re-approves every grant by hand.
 *
 * `plugin_settings` is mixed: an API key or token (`is_secret`) can sit beside
 * ordinary configuration. The non-secret rows are configuration and travel in
 * both forms; the secret rows get the §7.6 encrypted-only treatment — never in a
 * plaintext archive, only inside the password envelope, exactly as
 * `connector_secrets` is. What makes the split safe on the way back is that a
 * restore must never truncate the table, because the target's own secret rows
 * are precisely what a plaintext archive cannot carry.
 *
 * @internal
 */
final class BackupPluginPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PASSWORD = 'correct horse battery staple';

    /** A value distinctive enough that a raw-bytes search proves it is really absent. */
    private const SECRET_VALUE = 'zt-api-token-DO-NOT-LEAK-7f3a';

    private const KEY_A = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10"
        . "\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20";

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPluginTables();
    }

    protected function tearDown(): void
    {
        $this->clearPluginTables();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function clearPluginTables(): void
    {
        foreach (['plugin_settings', 'plugin_grants', 'plugins'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->emptyTable();
            }
        }
    }

    private function installPlugin(string $id = 'zerotier', bool $enabled = true): void
    {
        $this->db->table('plugins')->insert([
            'id'            => $id,
            'name'          => 'ZeroTier One',
            'version'       => '0.1.0',
            'class'         => 'C',
            'state'         => 'installed',
            'installed_at'  => '2026-10-09 00:00:00',
            'enabled'       => $enabled ? 1 : 0,
            'data_policy'   => 'retain',
            'manifest_json' => '{"id":"' . $id . '"}',
        ]);
    }

    private function grant(string $capability, string $pluginId = 'zerotier', bool $granted = true): void
    {
        $this->db->table('plugin_grants')->insert([
            'plugin_id'  => $pluginId,
            'capability' => $capability,
            'granted'    => $granted ? 1 : 0,
            'granted_by' => 'operator',
            'granted_at' => '2026-10-09 00:00:00',
        ]);
    }

    private function setting(string $key, string $value, bool $secret, string $pluginId = 'zerotier'): void
    {
        $this->db->table('plugin_settings')->insert([
            'plugin_id'   => $pluginId,
            'setting_key' => $key,
            'value'       => $value,
            'is_secret'   => $secret ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $tables @return array<string, mixed> */
    private function payload(array $tables): array
    {
        return ['format' => 2, 'tables' => $tables];
    }

    /** The tables of a decoded archive, decrypting it when it is an envelope. */
    private function decodedTables(array $backup, ?string $password = null): array
    {
        $raw = $password !== null
            ? (new BackupEngine($this->db, new ConnectorKeyStore()))->decryptPayload($backup['content'], $password)
            : $backup['content'];

        $this->assertIsString($raw);

        return json_decode($raw, true)['tables'];
    }

    // ------------------------------------------------------------------
    // createBackup — the plaintext archive
    // ------------------------------------------------------------------

    public function testPlaintextArchiveCarriesTheInstalledPluginsAndTheirGrants(): void
    {
        $this->installPlugin();
        $this->grant('system.service:zerotier-one');
        $this->grant('net.raw');

        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(null);

        $this->assertFalse($backup['is_encrypted']);

        $tables = $this->decodedTables($backup);

        $this->assertArrayHasKey('plugins', $tables);
        $this->assertArrayHasKey('plugin_grants', $tables);
        $this->assertCount(1, $tables['plugins']);
        $this->assertSame('zerotier', $tables['plugins'][0]['id']);
        $this->assertSame(1, (int) $tables['plugins'][0]['enabled']);
        $this->assertCount(2, $tables['plugin_grants']);
    }

    public function testPlaintextArchiveCarriesNonSecretSettingsButNeverSecretOnes(): void
    {
        $this->installPlugin();
        $this->setting('bind_interface', 'eth1', false);
        $this->setting('api_token', self::SECRET_VALUE, true);

        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(null);

        // The secret must be nowhere in the bytes on disk, not merely absent from
        // the parsed table.
        $this->assertStringNotContainsString(self::SECRET_VALUE, $backup['content']);

        $tables = $this->decodedTables($backup);

        $this->assertArrayHasKey('plugin_settings', $tables);
        $this->assertCount(1, $tables['plugin_settings']);
        $this->assertSame('bind_interface', $tables['plugin_settings'][0]['setting_key']);
        $this->assertSame(0, (int) $tables['plugin_settings'][0]['is_secret']);

        // A plaintext archive that omitted the table entirely would be a
        // different bug; assert it is present and deliberately holds only the
        // non-secret half.
        $this->assertArrayNotHasKey('secrets', json_decode($backup['content'], true));
    }

    // ------------------------------------------------------------------
    // createBackup — the encrypted envelope
    // ------------------------------------------------------------------

    public function testEncryptedArchiveCarriesSecretSettingsInsideTheEnvelope(): void
    {
        $this->installPlugin();
        $this->setting('bind_interface', 'eth1', false);
        $this->setting('api_token', self::SECRET_VALUE, true);

        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(self::PASSWORD);

        $this->assertTrue($backup['is_encrypted']);
        // Credential-grade content means the register can report it as such.
        $this->assertTrue($backup['credentials_included']);

        // The secret is inside the ciphertext, never beside it.
        $this->assertStringNotContainsString(self::SECRET_VALUE, $backup['content']);

        $tables = $this->decodedTables($backup, self::PASSWORD);

        $this->assertArrayHasKey('plugin_settings', $tables);
        $this->assertCount(2, $tables['plugin_settings'], 'both the secret and the non-secret setting must ride along');

        $secret = array_values(array_filter(
            $tables['plugin_settings'],
            static fn (array $row): bool => (int) $row['is_secret'] === 1,
        ));
        $this->assertCount(1, $secret);
        $this->assertSame(self::SECRET_VALUE, $secret[0]['value']);
    }

    public function testAnEncryptedArchiveOfAPluginWithNoSecretsIsNotCredentialBearing(): void
    {
        $this->installPlugin();
        $this->setting('bind_interface', 'eth1', false);

        $backup = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(self::PASSWORD);

        $decoded = json_decode(
            (new BackupEngine($this->db, new ConnectorKeyStore()))->decryptPayload($backup['content'], self::PASSWORD),
            true,
        );

        $this->assertCount(1, $decoded['tables']['plugin_settings']);
        $this->assertFalse($backup['credentials_included'], 'an archive with no secret rows carries no credential');
    }

    // ------------------------------------------------------------------
    // restore
    // ------------------------------------------------------------------

    public function testAnEncryptedArchiveRestoresPluginsGrantsAndSecretSettings(): void
    {
        $this->installPlugin();
        $this->grant('net.raw');
        $this->setting('bind_interface', 'eth1', false);
        $this->setting('api_token', self::SECRET_VALUE, true);

        $engine = new BackupEngine($this->db, new ConnectorKeyStore());
        $backup = $engine->createBackup(self::PASSWORD);
        $decoded = json_decode($engine->decryptPayload($backup['content'], self::PASSWORD), true);

        // A rebuilt appliance: nothing installed.
        $this->clearPluginTables();

        $result = $engine->restore($decoded);

        $this->assertTrue($result['success'], implode(' ', $result['warnings']));
        $this->assertContains('plugins', $result['restored_tables']);
        $this->assertContains('plugin_grants', $result['restored_tables']);
        $this->assertContains('plugin_settings', $result['restored_tables']);

        $this->assertSame('zerotier', $this->db->table('plugins')->get()->getRowArray()['id']);
        $this->assertSame(1, $this->db->table('plugin_grants')->countAllResults());

        $settings = $this->db->table('plugin_settings')->orderBy('setting_key', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $settings);

        $secret = array_values(array_filter($settings, static fn (array $r): bool => (int) $r['is_secret'] === 1));
        $this->assertSame(self::SECRET_VALUE, $secret[0]['value']);
    }

    /**
     * The half of the policy that is easy to get wrong: a plaintext archive
     * carries the non-secret rows, and restoring it must add those without
     * truncating away the appliance's own secret rows — a plaintext archive is
     * meant to hold no secret, not to destroy the ones already on the target.
     */
    public function testAPlaintextRestoreAddsNonSecretSettingsWithoutDestroyingSecretOnes(): void
    {
        $this->installPlugin();
        $this->setting('bind_interface', 'eth1', false);

        $archive = (new BackupEngine($this->db, new ConnectorKeyStore()))->createBackup(null);
        $decoded = json_decode($archive['content'], true);

        // The rebuilt appliance has already had a secret entered for this plugin:
        // a token the plaintext archive could not be carrying.
        $this->clearPluginTables();
        $this->setting('api_token', 'target-side-token', true);

        $result = (new BackupEngine($this->db, new ConnectorKeyStore()))->restore($decoded);

        $this->assertTrue($result['success'], implode(' ', $result['warnings']));
        $this->assertContains('plugin_settings', $result['restored_tables']);

        $rows = $this->db->table('plugin_settings')->get()->getResultArray();
        $this->assertCount(2, $rows, 'the restored non-secret row and the target\'s own secret row');

        $secret = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['is_secret'] === 1));
        $this->assertCount(1, $secret);
        $this->assertSame('target-side-token', $secret[0]['value'], 'the secret must survive the plaintext restore untouched');
    }

    /**
     * The encrypted path, by contrast, carries the whole table, so it is
     * reproduced exactly: rows the archive does not name are gone.
     */
    public function testAnEncryptedRestoreReproducesTheSettingsTableExactly(): void
    {
        $this->installPlugin();
        $this->setting('bind_interface', 'eth1', false);
        $this->setting('api_token', self::SECRET_VALUE, true);

        $engine = new BackupEngine($this->db, new ConnectorKeyStore());
        $backup = $engine->createBackup(self::PASSWORD);
        $decoded = json_decode($engine->decryptPayload($backup['content'], self::PASSWORD), true);

        // A target with a stray setting the archive never knew about.
        $this->clearPluginTables();
        $this->setting('stale_key', 'stale', false);

        $result = $engine->restore($decoded);

        $this->assertTrue($result['success']);
        $keys = array_column($this->db->table('plugin_settings')->get()->getResultArray(), 'setting_key');
        sort($keys);
        $this->assertSame(['api_token', 'bind_interface'], $keys);
    }

    public function testARestoreWithoutPluginTablesLeavesThemAlone(): void
    {
        $this->installPlugin();
        $this->grant('net.raw');

        // An archive from before plugins existed.
        $result = (new BackupEngine($this->db, new ConnectorKeyStore()))->restore($this->payload([
            'interfaces' => [],
        ]));

        $this->assertTrue($result['success']);
        $this->assertNotContains('plugins', $result['restored_tables']);
        $this->assertSame(1, $this->db->table('plugins')->countAllResults());
        $this->assertSame(1, $this->db->table('plugin_grants')->countAllResults());
    }

    /**
     * The `secrets` object stays outside `tables`; the setting rows must not be
     * smuggled into a table restore path that could write key material into the
     * database by accident.
     */
    public function testEncryptedKeyMaterialStaysOutsideTheTables(): void
    {
        $this->installPlugin();
        $this->setting('api_token', self::SECRET_VALUE, true);

        $keyDir = sys_get_temp_dir() . '/micron_keys_' . bin2hex(random_bytes(4));
        mkdir($keyDir, 0700, true);
        $keyPath = $keyDir . '/secrets.key';
        $keyStore = new ConnectorKeyStore($keyPath);
        $keyStore->write(self::KEY_A);

        try {
            $backup = (new BackupEngine($this->db, $keyStore))->createBackup(self::PASSWORD);
            $decoded = json_decode(
                (new BackupEngine($this->db, $keyStore))->decryptPayload($backup['content'], self::PASSWORD),
                true,
            );

            $this->assertArrayHasKey('secrets', $decoded);
            $this->assertArrayNotHasKey('secrets', $decoded['tables']);
            $this->assertSame(bin2hex(self::KEY_A), $decoded['secrets']['master_key']);
        } finally {
            @unlink($keyPath);
            @rmdir($keyDir);
        }
    }
}
