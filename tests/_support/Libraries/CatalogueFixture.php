<?php

namespace Tests\Support\Libraries;

use App\Libraries\CatalogueVerifier;
use App\Libraries\ConnectorEgressGuard;
use App\Libraries\RemoteCatalogue;
use Config\PluginCatalogue;

/**
 * CatalogueFixture — builds signed plugin-catalogue documents for tests.
 *
 * Runs a real RSA keypair through OpenSSL and signs the exact payload bytes, so
 * the verifier is exercised against genuine signatures rather than a stub.
 */
final class CatalogueFixture
{
    /**
     * A fixed test RSA keypair. Embedded rather than generated so the suite does
     * not depend on the host's OpenSSL config file (gradual key generation needs
     * one; signing and verification do not).
     */
    private const TEST_PRIVATE = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCkwHhOl74+x9l0
CuOJm3QCQ10VxNj2PbKECbPf0+Ctw126GdYfzlFiqQ5bBQH9SLmRgOhRO51O+rUG
jhWWJXdDFbB0ccUAlkZvp0X2mb9hlSdQ1LXt7DXR3OdweqcLcDHi3SKNHaxC4RGD
tC7EyTPe10qRKFFbomqV4eRDtlIW1MP9T0FvpEkEheDg9FR3HLrEVFDFk12RvKZ8
PUoticyOFOtgf7Wypk6h3jjttQLS6aVktBGI8sd9Z48+cjOHi/SJp3F6YRiqw43I
Qsb73N3ltFJsDl+PaxPysXR2Agx5xJdYBu++PhQx3HozBIB/SRRV4FNknvLlLD+3
fTzqm8BnAgMBAAECggEAMWOLjU/7Fbn2i82J2aIy/Eyydois2+CyB9gaxrIvLBgh
nF0Lbjx89F0msw2Ew6fODXr96gA7j8Yjh4QHn5u6rW8ulzHV82+ZTPZxyFPd5gR/
JUGnClyh47hb30G9mvW21sIhDfnDvsbW58sXrCF+/cI/ldVm0lgVqLnvoobhA/un
9ihYeeIuM7m4ELO+mohH6SxgcmLxlD9jNcP0PLfenbzP6SvT+PpammXdm3v1UgVg
0QwuTpqSFHlzfV801oD1A6Dv2T9Z2GxRdp2NIGBAyIgbWBvybmYVcQ+usOIXyG1L
g6nCutCJIRupmDHyboXSGoD6xGfBija9lv7B2O58qQKBgQDfYfolbRBEgFrt9lVT
BnKrRZKfBKZ1bcqhZcjSzLzqQhyGNC5zOPIrIkX/KaKzsMfdIafWLVo3S2NxmwGm
s0Be6AX9BFgDIGvh7LApfEq9hIulryKhAXCA7a7PKetxkeBOzuoMAdGPE0eVxPTD
yuXcgrTvALRkpazY6KCSKPH5HwKBgQC8zuBZfZhmtsP3cGSMM+l3fZswLuwWGjL7
qIXImAPPE14YSA5LhwkzEPy8IS3CkAbqd6oJjwqMzpF+v8csQke/ML/u4OGljekf
pwVyvYS6r/ELIHFN9IbzsIbVvI5ETLMglCVcVkueg39gaknlcb2iPTwynbLnZt12
pLD90NUnuQKBgCK7izdyg8sC4iCJ4q5sHDxBLxEGmhM8JPt/ZgBQ0TbjXKgJSQel
XRIT3CpWgPPLkufxKbWFi7d5Q1cQsOsIiAO5s+SpXj3uNpgEQ0fQCZb6GvSCDLJY
FPQXAM05SWB5PBVE2uNPDRRf/QtuelhPHfpxYEyhIWtcz0lSslTHhQglAoGBAI3S
p9sQ6IVfC9y15BEcfWZwRz3uQRBpJZq7f6Kp7VKCrc93cKVzDYn6XJ3EHmdjYI6G
zIbZl4clM00OKUIpl1MZ/1IkQdrluhM4lnMVwLmqp1lASVvRLf2mv+GU/Rh4tKTo
Ixg1CiBLjkaSEGf1BAj2Dl3G7ze6oOgZ1YkScMqhAoGAO1IJdZdqy9xwsAGJReA9
br5yOdo50vXxtyRFpSGb9/sdBySKDP1cHPuWhHwRWvtGP8/mDXZSkoxC9dGzGOqd
7rbYBD7zqvymk2TcafwW8ee8c0wcfUA6/pvyWG1Dk0E8Xil8t8Y0QUiGzpowCUKo
Kphbpc6PJyrbJAO2Zoqa8no=
-----END PRIVATE KEY-----
PEM;

    private const TEST_PUBLIC = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEApMB4Tpe+PsfZdArjiZt0
AkNdFcTY9j2yhAmz39PgrcNduhnWH85RYqkOWwUB/Ui5kYDoUTudTvq1Bo4VliV3
QxWwdHHFAJZGb6dF9pm/YZUnUNS17ew10dzncHqnC3Ax4t0ijR2sQuERg7QuxMkz
3tdKkShRW6JqleHkQ7ZSFtTD/U9Bb6RJBIXg4PRUdxy6xFRQxZNdkbymfD1KLYnM
jhTrYH+1sqZOod447bUC0umlZLQRiPLHfWePPnIzh4v0iadxemEYqsONyELG+9zd
5bRSbA5fj2sT8rF0dgIMecSXWAbvvj4UMdx6MwSAf0kUVeBTZJ7y5Sw/t3086pvA
ZwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * @return array{key_id: string, private: string, public: string}
     */
    public static function keypair(string $keyId = 'micron-plugins-test'): array
    {
        return [
            'key_id'  => $keyId,
            'private' => self::TEST_PRIVATE . "\n",
            'public'  => self::TEST_PUBLIC . "\n",
        ];
    }

    /**
     * A valid inner catalogue payload, with overrides merged in.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function catalogue(array $overrides = []): array
    {
        $plugins = $overrides['plugins'] ?? [[
            'id'           => 'iperf3',
            'name'         => 'iperf3 Throughput Test',
            'version'      => '1.1.0',
            'class'        => 'B',
            'packages'     => ['iperf3'],
            'units'        => ['iperf3'],
            'capabilities' => ['system.service:iperf3', 'root.rpc:plugin.status'],
        ]];
        unset($overrides['plugins']);

        return $overrides + [
            'schema'            => 1,
            'catalogue_version' => 7,
            'issued_at'         => gmdate('c', time() - 60),
            'expires_at'        => gmdate('c', time() + 86400),
            'plugins'           => $plugins,
        ];
    }

    /**
     * @param array<string, mixed> $catalogue
     */
    public static function payload(array $catalogue): string
    {
        return (string) json_encode($catalogue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sign a payload into an envelope document.
     */
    public static function sign(string $payload, string $privatePem, string $keyId): string
    {
        $signature = '';
        openssl_sign($payload, $signature, openssl_pkey_get_private($privatePem), OPENSSL_ALGO_SHA256);

        return (string) json_encode([
            'schema'    => 1,
            'signature' => [
                'algorithm' => 'RSA-SHA256',
                'key_id'    => $keyId,
                'value'     => base64_encode($signature),
            ],
            'payload' => base64_encode($payload),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * A ready-made valid envelope for the given catalogue.
     *
     * @param array<string, mixed> $catalogue
     * @param array{key_id: string, private: string, public: string} $key
     */
    public static function envelope(array $catalogue, array $key): string
    {
        return self::sign(self::payload($catalogue), $key['private'], $key['key_id']);
    }

    /**
     * A deterministic egress guard whose transport returns the given body — no
     * network, no DNS. The host resolves to a public address so the SSRF policy
     * passes.
     */
    public static function guard(string $body, int $status = 200): ConnectorEgressGuard
    {
        return new ConnectorEgressGuard(
            static fn (string $host): array => ['93.184.216.34'],
            static function (array $request, callable $sink) use ($body, $status): array {
                $accepted = $sink($body);

                return [
                    'status'   => $status,
                    'headers'  => [],
                    'bytes_in' => strlen($body),
                    'error'    => $accepted === 0 ? 'cap exceeded' : null,
                ];
            },
        );
    }

    /**
     * A catalogue that is switched off and trusts nothing, so a test that hits
     * the Updates screen is deterministic regardless of any dev cache file.
     */
    public static function unconfigured(string $cacheFile): RemoteCatalogue
    {
        $config = new PluginCatalogue();
        $config->enabled     = false;
        $config->url         = '';
        $config->keyringPath = '';
        $config->trustedKeys = [];
        $config->cachePath   = $cacheFile;

        return new RemoteCatalogue(self::guard(''), new CatalogueVerifier($config), $config, $cacheFile);
    }

    /**
     * A RemoteCatalogue wired to the stubbed guard and a private cache file.
     *
     * @param array{key_id: string, private: string, public: string} $key
     */
    public static function remoteCatalogue(array $key, string $body, string $cacheFile, string $url = 'https://cdn.example.com/plugins/catalogue.json'): RemoteCatalogue
    {
        $config = new PluginCatalogue();
        $config->enabled     = true;
        $config->url         = $url;
        $config->keyringPath = '';
        $config->trustedKeys = [$key['key_id'] => $key['public']];
        $config->cachePath   = $cacheFile;

        return new RemoteCatalogue(self::guard($body), new CatalogueVerifier($config), $config, $cacheFile);
    }
}
