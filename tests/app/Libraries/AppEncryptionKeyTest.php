<?php

namespace Tests\App\Libraries;

use App\Libraries\ConnectorKeyStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The appliance ships no .env, so its encryption key must come from the
 * per-appliance master key that micrond generates on first start.
 */
class AppEncryptionKeyTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/micron-appkey-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testNoMasterKeyMeansNoDerivedKey(): void
    {
        $this->assertNull(ConnectorKeyStore::appEncryptionKey($this->dir . '/missing.key'));
    }

    public function testTheKeyIsDerivedFromTheHexMasterKeyMicrondWrites(): void
    {
        $path = $this->dir . '/secrets.key';
        file_put_contents($path, str_repeat('ab', 32) . "\n");

        $key = ConnectorKeyStore::appEncryptionKey($path);

        $this->assertSame(32, strlen((string) $key));
        $this->assertNotSame(hex2bin(str_repeat('ab', 32)), $key, 'the master key must not be used directly');
        $this->assertSame($key, ConnectorKeyStore::appEncryptionKey($path), 'derivation is stable across requests');
    }

    public function testDifferentAppliancesGetDifferentKeys(): void
    {
        $a = $this->dir . '/a.key';
        $b = $this->dir . '/b.key';
        file_put_contents($a, bin2hex(random_bytes(32)));
        file_put_contents($b, bin2hex(random_bytes(32)));

        $this->assertNotSame(ConnectorKeyStore::appEncryptionKey($a), ConnectorKeyStore::appEncryptionKey($b));
    }

    public function testADerivedKeyEncryptsAndDecrypts(): void
    {
        $path = $this->dir . '/secrets.key';
        file_put_contents($path, bin2hex(random_bytes(32)));

        $config      = new \Config\Encryption();
        $config->key = (string) ConnectorKeyStore::appEncryptionKey($path);
        $encrypter   = \Config\Services::encrypter($config, false);

        $this->assertSame('pppoe-secret', $encrypter->decrypt($encrypter->encrypt('pppoe-secret')));
    }

    public function testAKeyFromTheEnvironmentWins(): void
    {
        // phpunit.dist.xml sets encryption.key, as a developer's .env would.
        $this->assertSame(hex2bin('5f1c0e8a3b7d2946c1a0e5f7b38d4c2a9e6f1b0d7c3a58e2f4b6d9c1e0a7f3b5'), (new \Config\Encryption())->key);
    }
}
