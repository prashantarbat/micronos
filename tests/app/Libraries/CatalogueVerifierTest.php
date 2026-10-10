<?php

declare(strict_types=1);

use App\Libraries\CatalogueVerifier;
use Config\PluginCatalogue;
use Tests\Support\Libraries\CatalogueFixture;
use Tests\Support\BaseTestCase;

/**
 * CatalogueVerifier: a signed catalogue is trusted only when the signature
 * verifies against a shipped key and the document is current.
 *
 * @internal
 */
final class CatalogueVerifierTest extends BaseTestCase
{
    public function testVerifiesAGenuineEnvelope(): void
    {
        $key    = CatalogueFixture::keypair('micron-plugins-test');
        $raw    = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('micron-plugins-test', $result['key_id']);
        $this->assertSame('iperf3', $result['catalogue']['plugins'][0]['id']);
        $this->assertSame('1.1.0', $result['catalogue']['plugins'][0]['version']);
    }

    public function testRejectsATamperedPayload(): void
    {
        $key     = CatalogueFixture::keypair();
        $payload = CatalogueFixture::payload(CatalogueFixture::catalogue());
        $raw     = CatalogueFixture::sign($payload, $key['private'], $key['key_id']);

        // Re-encode the payload after the signature was taken.
        $envelope = json_decode($raw, true);
        $envelope['payload'] = base64_encode($payload . ' ');

        $result = $this->verifier($key['public'])->verify((string) json_encode($envelope));

        $this->assertFalse($result['ok']);
        $this->assertSame('catalogue signature does not verify', $result['error']);
    }

    public function testRejectsAnUntrustedKey(): void
    {
        $signer = CatalogueFixture::keypair('attacker');
        $raw    = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $signer);

        $result = $this->verifier(CatalogueFixture::keypair('trusted')['public'], 'trusted')->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('untrusted key', (string) $result['error']);
    }

    public function testRejectsAnExpiredCatalogue(): void
    {
        $key = CatalogueFixture::keypair();
        $raw = CatalogueFixture::envelope(CatalogueFixture::catalogue([
            'issued_at'  => gmdate('c', time() - 7200),
            'expires_at' => gmdate('c', time() - 3600),
        ]), $key);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertSame('catalogue has expired', $result['error']);
    }

    public function testRejectsAFutureIssuedCatalogue(): void
    {
        $key = CatalogueFixture::keypair();
        $raw = CatalogueFixture::envelope(CatalogueFixture::catalogue([
            'issued_at'  => gmdate('c', time() + 86400),
            'expires_at' => gmdate('c', time() + 172800),
        ]), $key);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertSame('catalogue is issued in the future', $result['error']);
    }

    public function testRejectsAnUnsignedDocument(): void
    {
        $key = CatalogueFixture::keypair();
        // A payload with no signature block at all.
        $raw = (string) json_encode(['schema' => 1, 'payload' => base64_encode('{}')]);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertSame('catalogue is unsigned', $result['error']);
    }

    public function testRejectsADocumentWithNoPayload(): void
    {
        $key = CatalogueFixture::keypair();
        $raw = (string) json_encode(['schema' => 1, 'plugins' => []]);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertSame('catalogue carries no signed payload', $result['error']);
    }

    public function testRejectsAMalformedPluginEntry(): void
    {
        $key = CatalogueFixture::keypair();
        $raw = CatalogueFixture::envelope(CatalogueFixture::catalogue([
            'plugins' => [['id' => 'iperf3', 'version' => '1.1.0'], ['id' => 'Bad Id', 'version' => '1.0.0']],
        ]), $key);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid id', (string) $result['error']);
    }

    public function testRejectsADuplicatePlugin(): void
    {
        $key = CatalogueFixture::keypair();
        $raw = CatalogueFixture::envelope(CatalogueFixture::catalogue([
            'plugins' => [
                ['id' => 'iperf3', 'version' => '1.1.0'],
                ['id' => 'iperf3', 'version' => '1.2.0'],
            ],
        ]), $key);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('more than once', (string) $result['error']);
    }

    public function testRejectsAMissingVersion(): void
    {
        $key = CatalogueFixture::keypair();
        $raw = CatalogueFixture::envelope(CatalogueFixture::catalogue([
            'plugins' => [['id' => 'iperf3']],
        ]), $key);

        $result = $this->verifier($key['public'])->verify($raw);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('has no version', (string) $result['error']);
    }

    public function testReadsTrustedKeysFromTheKeyringDirectory(): void
    {
        $key = CatalogueFixture::keypair('keyring-key');
        $dir = sys_get_temp_dir() . '/micron_keyring_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/keyring-key.pem', $key['public']);

        $config = new PluginCatalogue();
        $config->keyringPath = $dir;
        $config->trustedKeys = [];

        try {
            $raw    = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
            $result = (new CatalogueVerifier($config))->verify($raw);
            $this->assertTrue($result['ok'], (string) $result['error']);
        } finally {
            @unlink($dir . '/keyring-key.pem');
            @rmdir($dir);
        }
    }

    private function verifier(string $publicPem, string $keyId = 'micron-plugins-test'): CatalogueVerifier
    {
        $config = new PluginCatalogue();
        $config->keyringPath = '';
        $config->trustedKeys = [$keyId => $publicPem];

        return new CatalogueVerifier($config);
    }
}
