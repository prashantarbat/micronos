<?php

declare(strict_types=1);

use App\Libraries\CatalogueVerifier;
use App\Libraries\ConnectorEgressGuard;
use App\Libraries\RemoteCatalogue;
use Config\PluginCatalogue;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\CatalogueFixture;

/**
 * RemoteCatalogue: fetch → verify → cache, with the SSRF guard stubbed by an
 * injected transport. Nothing is cached unless the signature verifies.
 *
 * @internal
 */
final class RemoteCatalogueTest extends BaseTestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
            @unlink($file . '.part');
        }
        $this->cleanup = [];

        parent::tearDown();
    }

    public function testSyncVerifiesAndCachesTheCatalogue(): void
    {
        $key        = CatalogueFixture::keypair();
        $body       = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $catalogue  = $this->catalogue($key, $body);

        $result = $catalogue->sync();

        $this->assertTrue($result['ok'], (string) $result['message']);
        $this->assertSame('micron-plugins-test', $result['meta']['key_id']);
        $this->assertFileExists($this->cacheFile);

        $this->assertSame(['iperf3' => '1.1.0'], $catalogue->versions());

        $status = $catalogue->status();
        $this->assertTrue($status['available']);
        $this->assertSame(7, $status['version']);
        $this->assertSame(1, $status['count']);
        $this->assertFalse($status['stale']);
    }

    public function testSyncRefusesATamperedDocumentAndCachesNothing(): void
    {
        $key      = CatalogueFixture::keypair();
        $payload  = CatalogueFixture::payload(CatalogueFixture::catalogue());
        $envelope = json_decode(CatalogueFixture::sign($payload, $key['private'], $key['key_id']), true);
        $envelope['payload'] = base64_encode($payload . ' ');
        $body     = (string) json_encode($envelope);

        $catalogue = $this->catalogue($key, $body);
        $result    = $catalogue->sync();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not verify', (string) $result['message']);
        $this->assertFileDoesNotExist($this->cacheFile);
        $this->assertNull($catalogue->catalogue());
    }

    public function testSyncRefusesWhenNotConfigured(): void
    {
        $key   = CatalogueFixture::keypair();
        $body  = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);

        $config = $this->config($key, $body);
        $config->url = '';

        $catalogue = new RemoteCatalogue($this->guard($body), new CatalogueVerifier($config), $config, $this->cacheFile);
        $result    = $catalogue->sync();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', (string) $result['message']);
    }

    public function testSyncRefusesAPlaintextHttpUrl(): void
    {
        $key   = CatalogueFixture::keypair();
        $body  = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);

        $config = $this->config($key, $body);
        $config->url = 'http://cdn.example.com/catalogue.json';

        $catalogue = new RemoteCatalogue($this->guard($body), new CatalogueVerifier($config), $config, $this->cacheFile);
        $result    = $catalogue->sync();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must use https', (string) $result['message']);
    }

    public function testACachedCatalogueIsInvalidatedWhenItsKeyIsNoLongerTrusted(): void
    {
        $key   = CatalogueFixture::keypair();
        $body  = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $config = $this->config($key, $body);

        $catalogue = new RemoteCatalogue($this->guard($body), new CatalogueVerifier($config), $config, $this->cacheFile);
        $catalogue->sync();
        $this->assertNotNull($catalogue->catalogue());

        // Key rotated out of the keyring: the same cache no longer verifies.
        $config->trustedKeys = [];
        $verifier = new CatalogueVerifier($config);
        $rotated  = new RemoteCatalogue($this->guard($body), $verifier, $config, $this->cacheFile);

        $this->assertNull($rotated->catalogue());
        $this->assertFalse($rotated->status()['available']);
    }

    public function testStaleCatalogueIsFlagged(): void
    {
        $key   = CatalogueFixture::keypair();
        $body  = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $config = $this->config($key, $body);
        $config->maxAgeHours = 1;

        $catalogue = new RemoteCatalogue($this->guard($body), new CatalogueVerifier($config), $config, $this->cacheFile);
        $catalogue->sync();

        // Backdate the cached fetch time by two hours.
        $record = json_decode((string) file_get_contents($this->cacheFile), true);
        $record['fetched_at'] = gmdate('c', time() - 7200);
        file_put_contents($this->cacheFile, (string) json_encode($record));

        $status = $catalogue->status();

        $this->assertTrue($status['available']);
        $this->assertTrue($status['stale']);
    }

    public function testImportVerifiesALocalEnvelope(): void
    {
        $key   = CatalogueFixture::keypair();
        $body  = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $file  = $this->tempFile($body);
        $catalogue = $this->catalogue($key, $body);

        $result = $catalogue->import($file);

        $this->assertTrue($result['ok'], (string) $result['message']);
        $this->assertSame(['iperf3' => '1.1.0'], $catalogue->versions());
        $this->assertSame('file://' . basename($file), $catalogue->status()['url']);
    }

    public function testImportRefusesATamperedFile(): void
    {
        $key      = CatalogueFixture::keypair();
        $payload  = CatalogueFixture::payload(CatalogueFixture::catalogue());
        $envelope = json_decode(CatalogueFixture::sign($payload, $key['private'], $key['key_id']), true);
        $envelope['payload'] = base64_encode($payload . ' ');
        $file     = $this->tempFile((string) json_encode($envelope));
        $catalogue = $this->catalogue($key, $payload);

        $result = $catalogue->import($file);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not verify', (string) $result['message']);
        $this->assertNull($catalogue->catalogue());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private string $cacheFile = '';

    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/micron_cat_file_' . bin2hex(random_bytes(6)) . '.signed';
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;

        return $path;
    }

    private function catalogue(array $key, string $body): RemoteCatalogue
    {
        $config = $this->config($key, $body);

        return new RemoteCatalogue($this->guard($body), new CatalogueVerifier($config), $config, $this->cacheFile);
    }

    /** @param array{key_id: string, private: string, public: string} $key */
    private function config(array $key, string $body): PluginCatalogue
    {
        $this->cacheFile = sys_get_temp_dir() . '/micron_cat_test_' . bin2hex(random_bytes(6)) . '.json';
        $this->cleanup[] = $this->cacheFile;

        $config = new PluginCatalogue();
        $config->enabled     = true;
        $config->url         = 'https://cdn.example.com/plugins/catalogue.json';
        $config->keyringPath = '';
        $config->trustedKeys = [$key['key_id'] => $key['public']];
        $config->cachePath   = $this->cacheFile;

        return $config;
    }

    private function guard(string $body): ConnectorEgressGuard
    {
        return new ConnectorEgressGuard(
            static fn (string $host): array => ['93.184.216.34'],
            static function (array $request, callable $sink) use ($body): array {
                $accepted = $sink($body);

                return [
                    'status'   => 200,
                    'headers'  => [],
                    'bytes_in' => strlen($body),
                    'error'    => $accepted === 0 ? 'cap exceeded' : null,
                ];
            },
        );
    }
}
