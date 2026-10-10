<?php

namespace Tests\Support\Libraries;

use App\Libraries\ConnectorEgressGuard;

/**
 * Fixtures for the curated-repository tests.
 *
 * Verifying a repository means fetching *signed release metadata*, so these
 * tests need three things a plain string fixture cannot express: key material
 * the armour check accepts, a plausible `InRelease` document, and an egress
 * guard that answers **per path** — because the interesting behaviour is the
 * fallback from a clearsigned `InRelease` to a detached `Release` +
 * `Release.gpg`, which needs the first candidate to 404 while the others
 * succeed.
 *
 * The guard is real: only the resolver and transport are injected, so the URL
 * validation, scheme allowlist, pinning and byte caps all still run.
 */
final class RepoFixture
{
    public const FINGERPRINT = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';

    /**
     * An ASCII-armored public key block that passes the syntax check.
     *
     * Real key material is irrelevant here — the *fingerprint comparison* is
     * the daemon's job and is exercised in the micrond harness against stubbed
     * `gpg`. What matters on the PHP side is that the armour is recognised (and
     * that something which is not armour is refused).
     */
    public static function armor(string $label = 'micron test key'): string
    {
        return "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n"
            . base64_encode($label) . "\n"
            . "-----END PGP PUBLIC KEY BLOCK-----\n";
    }

    /**
     * A clearsigned `InRelease` document, as a vendor would publish it.
     *
     * @param array<string, string> $fields overrides applied over the defaults
     */
    public static function inRelease(array $fields = []): string
    {
        $defaults = [
            'Origin'        => 'Vendor Repository',
            'Label'         => 'Vendor',
            'Suite'         => 'stable',
            'Codename'      => 'vendorbook',
            'Date'          => 'Mon, 05 Oct 2026 00:00:00 UTC',
            'Architectures' => 'amd64 arm64',
            'Components'    => 'main',
            'Valid-Until'   => gmdate('D, d M Y H:i:s', time() + 604800) . ' GMT',
        ];

        $body = "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n";
        foreach ($fields + $defaults as $name => $value) {
            $body .= "{$name}: {$value}\n";
        }
        $body .= "-----BEGIN PGP SIGNATURE-----\n\nc2lnbmF0dXJl\n-----END PGP SIGNATURE-----\n";

        return $body;
    }

    public static function detachedSignature(): string
    {
        return "-----BEGIN PGP SIGNATURE-----\n\nZGV0YWNoZWQ=\n-----END PGP SIGNATURE-----\n";
    }

    /**
     * An egress guard that answers from a path => [status, body] map.
     *
     * @param array<string, array{0: int, 1: string}> $responses keyed by URL path
     */
    public static function routedGuard(array $responses, int $fallbackStatus = 404, string $fallbackBody = ''): ConnectorEgressGuard
    {
        return new ConnectorEgressGuard(
            static fn (string $host): array => ['93.184.216.34'],
            static function (array $request, callable $sink) use ($responses, $fallbackStatus, $fallbackBody): array {
                $path = (string) parse_url((string) ($request['url'] ?? ''), PHP_URL_PATH);
                [$status, $body] = $responses[$path] ?? [$fallbackStatus, $fallbackBody];

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
}
