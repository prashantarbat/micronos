<?php

use App\Libraries\Acme;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The ACME client, against a stubbed CA that speaks the protocol.
 *
 * The stub is a real (if tiny) ACME server: it hands out nonces, checks that
 * every signed request carries one, and answers the directory, account, order,
 * authorization, challenge, finalize and certificate endpoints. That matters
 * because the interesting failure modes of this client are protocol failures —
 * a DER signature where JOSE wants `r‖s`, a `kid` where the CA wants the JWK, a
 * nonce reused after it was spent — and none of them is visible from a mock that
 * only asserts the call was made.
 *
 * The strongest check here is {@see self::testTheJwsSignatureIsAValidEs256Signature}:
 * it takes the signature the client produced, converts it back to DER and has
 * OpenSSL verify it against the account key's own public half. A DER-shaped or
 * zero-padded signature fails that, which is exactly the bug a stub written to
 * match the implementation would hide.
 *
 * @internal
 */
final class AcmeTest extends CIUnitTestCase
{
    private const DIRECTORY = Acme::LETSENCRYPT;

    // ------------------------------------------------------------------
    // Configuration & keys
    // ------------------------------------------------------------------

    public function testOnlyTheLetsEncryptDirectoriesAreAccepted(): void
    {
        $this->assertTrue(Acme::isAllowedDirectory(Acme::LETSENCRYPT));
        $this->assertTrue(Acme::isAllowedDirectory(Acme::LETSENCRYPT_STAGING));
        $this->assertTrue(Acme::isAllowedDirectory(Acme::LETSENCRYPT . '/'));

        // A suffix test would accept every one of these, which is why the check
        // is an exact match on the published endpoints.
        foreach ([
            'https://acme-v02.api.letsencrypt.org.evil.example/directory',
            'http://acme-v02.api.letsencrypt.org/directory',
            'https://acme-v02.api.letsencrypt.org/directory/../evil',
            'https://acme-staging-v02.api.letsencrypt.org/directory-extra',
            'https://evil.example/directory',
            '',
        ] as $rejected) {
            $this->assertFalse(Acme::isAllowedDirectory($rejected), "'{$rejected}' must be refused");
        }
    }

    /**
     * The allowlist must be *load-bearing*, not decorative: an unlisted
     * directory has to fall back before anything is sent anywhere.
     */
    public function testAnUnlistedDirectoryIsNeverContacted(): void
    {
        $seen = [];

        $client = new Acme(
            function (array $request) use (&$seen): array {
                $seen[] = $request['url'];

                return [
                    'status'  => 200,
                    'headers' => ['replay-nonce' => 'n1'],
                    'body'    => (string) json_encode([
                        'newNonce'   => Acme::LETSENCRYPT !== '' ? str_replace('/directory', '/new-nonce', Acme::LETSENCRYPT) : '',
                        'newAccount' => str_replace('/directory', '/new-account', Acme::LETSENCRYPT),
                        'newOrder'   => str_replace('/directory', '/new-order', Acme::LETSENCRYPT),
                    ]),
                    'error'   => null,
                ];
            },
            'https://acme-v02.api.letsencrypt.org.evil.example/directory',
        );

        $this->assertTrue($client->directory()['ok']);
        $this->assertSame(
            [Acme::LETSENCRYPT],
            $seen,
            'a directory that is not in the allowlist must never be requested'
        );
    }

    public function testTheAccountKeyIsP256AndItsJwkIsWellFormed(): void
    {
        $pem = Acme::generateKey();
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $pem);

        $jwk = Acme::keyToJwk($pem);
        $this->assertSame('EC', $jwk['kty']);
        $this->assertSame('P-256', $jwk['crv']);

        // A P-256 coordinate is 32 bytes, so 43 base64url characters.
        foreach (['x', 'y'] as $member) {
            $this->assertSame(43, strlen($jwk[$member]), "the {$member} coordinate must be 32 bytes, base64url");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk[$member]);
        }

        // Two keys are two accounts: the thumbprint must not collide.
        $this->assertNotSame(Acme::jwkThumbprint($jwk), Acme::jwkThumbprint(Acme::keyToJwk(Acme::generateKey())));
    }

    /**
     * The RFC 7638 thumbprint is a hash of one exact serialization: the required
     * members, lexicographic, no whitespace. The test builds that string itself,
     * so a reordered or padded version in the library is caught.
     */
    public function testTheThumbprintIsTheCanonicalJwkHash(): void
    {
        $jwk = Acme::keyToJwk(Acme::generateKey());

        $canonical = '{"crv":"' . $jwk['crv'] . '","kty":"' . $jwk['kty'] . '","x":"' . $jwk['x'] . '","y":"' . $jwk['y'] . '"}';
        $expected  = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        $this->assertSame($expected, Acme::jwkThumbprint($jwk));
    }

    public function testTheDnsTxtValueIsTheHashOfTheKeyAuthorization(): void
    {
        $client = new Acme($this->stub()['transport'], self::DIRECTORY, Acme::generateKey());
        $client->newAccount('ops@example.com');

        $expected = rtrim(strtr(base64_encode(hash('sha256', $client->keyAuthorization('tok-123'), true)), '+/', '-_'), '=');

        $this->assertSame($expected, $client->dnsTxtValue('tok-123'));
        $this->assertSame('tok-123.' . $client->thumbprint(), $client->keyAuthorization('tok-123'));
    }

    public function testAnUnusableSignatureIsRejectedRatherThanZeroPadded(): void
    {
        $this->assertNull(Acme::derToRaw(''));
        $this->assertNull(Acme::derToRaw('not a der signature'));
        $this->assertNull(Acme::derToRaw("\x30\x06\x02\x01\x01")); // one integer, not two

        // A well-formed two-integer sequence round-trips to 64 bytes.
        $der = "\x30\x08\x02\x02\x00\xff\x02\x02\x01\x02";
        $raw = Acme::derToRaw($der);
        $this->assertNotNull($raw);
        $this->assertSame(64, strlen($raw));
        $this->assertSame(str_repeat("\x00", 31) . "\xff", substr($raw, 0, 32));
    }

    // ------------------------------------------------------------------
    // The signed request
    // ------------------------------------------------------------------

    /**
     * The one check a stub matching the implementation cannot fake: the CA-side
     * verification of the signature the client produced.
     */
    public function testTheJwsSignatureIsAValidEs256Signature(): void
    {
        $stub   = $this->stub();
        $key    = Acme::generateKey();
        $client = new Acme($stub['transport'], self::DIRECTORY, $key);

        $this->assertTrue($client->newAccount('ops@example.com')['ok']);
        // A second request, so the `kid` branch is exercised too — the account
        // request is the only one that legitimately carries a JWK.
        $this->assertTrue($client->newOrder(['vpn.example.com'])['ok']);

        $posted = $this->signedRequests($stub['requests']);
        $this->assertNotEmpty($posted, 'the account request must be a signed POST');
        $this->assertGreaterThan(1, count($posted));

        foreach ($posted as $request) {
            $protected = json_decode(self::unb64($request['protected']), true);
            $payload   = json_decode(self::unb64($request['payload']), true);

            $this->assertIsArray($protected);
            $this->assertSame('ES256', $protected['alg']);
            $this->assertNotSame('', (string) ($protected['nonce'] ?? ''), 'every signed request needs a nonce');
            $this->assertSame($request['url'], $protected['url']);

            // `newAccount` authenticates with the embedded JWK; everything after
            // it must use the account URL the CA handed back.
            if (str_contains($request['url'], '/new-account')) {
                $this->assertArrayHasKey('jwk', $protected);
                $this->assertArrayNotHasKey('kid', $protected);
            } else {
                $this->assertArrayHasKey('kid', $protected, 'later requests must be signed with the account kid');
                $this->assertArrayNotHasKey('jwk', $protected);
            }

            $this->assertIsArray($payload);

            // Verify the ES256 signature the way the CA does: raw r‖s back to DER,
            // then OpenSSL against the account key's public half.
            $signature = self::unb64($request['signature']);
            $this->assertSame(64, strlen($signature), 'ES256 signatures are the fixed-width r‖s pair');

            $der = self::rawToDer($signature);
            $this->assertNotNull($der);
            $this->assertSame(
                1,
                openssl_verify(
                    $request['protected'] . '.' . $request['payload'],
                    $der,
                    openssl_pkey_get_details(openssl_pkey_get_private($key))['key'],
                    OPENSSL_ALGO_SHA256,
                ),
                'the JWS signature must verify against the account key'
            );
        }
    }

    public function testAStaleNonceIsRetriedOnceAndThenSucceeds(): void
    {
        $stub = $this->stub(['badNonceOnce' => true]);
        $client = new Acme($stub['transport'], self::DIRECTORY, Acme::generateKey());

        $this->assertTrue($client->newAccount('ops@example.com')['ok']);

        $posts = array_values(array_filter($stub['requests'], static fn (array $r): bool => str_contains($r['url'], '/new-account')));
        $this->assertCount(2, $posts, 'a badNonce must be retried exactly once, with a fresh nonce');

        // The retry must have spent a *different* nonce, or it would fail again.
        $nonces = [];
        foreach ($posts as $post) {
            $protected = json_decode(self::unb64($post['protected']), true);
            $nonces[]  = $protected['nonce'];
        }
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    // ------------------------------------------------------------------
    // The issuance flow
    // ------------------------------------------------------------------

    public function testIssueCompletesTheWholeFlowAgainstAStubbedCa(): void
    {
        $stub   = $this->stub();
        $client = new Acme($stub['transport'], self::DIRECTORY, Acme::generateKey());

        $orderKey = Acme::generateKey();
        $csr      = Acme::csrFor(['vpn.example.com', 'www.vpn.example.com'], $orderKey);
        $this->assertNotNull($csr);

        $published = [];
        $result    = $client->issue(
            ['vpn.example.com', 'www.vpn.example.com'],
            'ops@example.com',
            $csr,
            ['http-01' => static function (string $token, string $value) use (&$published): bool {
                $published = ['token' => $token, 'value' => $value];

                return true;
            }],
            3,
            0,
        );

        $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
        $this->assertStringContainsString('BEGIN CERTIFICATE', $result['chain']);

        // The operator-facing step is the file the appliance would serve.
        $this->assertSame('tok-http-01', $published['token']);
        $this->assertSame('tok-http-01.' . $client->thumbprint(), $published['value']);

        // The order really was a signed conversation, not a single request.
        $urls = array_column($stub['requests'], 'url');
        foreach (['/new-account', '/new-order', '/authz/1', '/challenge/1', '/finalize/1', '/cert/1'] as $endpoint) {
            $this->assertNotEmpty(
                array_filter($urls, static fn (string $url): bool => str_ends_with($url, $endpoint)),
                "the flow must reach {$endpoint}"
            );
        }
    }

    public function testAnAuthorizationTheCaRejectsCarriesTheCasOwnDetail(): void
    {
        $stub   = $this->stub(['authorizationStatus' => 'invalid']);
        $client = new Acme($stub['transport'], self::DIRECTORY, Acme::generateKey());
        $client->newAccount('ops@example.com');

        $result = $client->pollAuthorization($stub['base'] . '/authz/1', 2, 0);

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_AUTHORIZATION_FAILED, $result['reason']);
        $this->assertStringContainsString('DNS problem', (string) $result['message']);
    }

    public function testARateLimitedOrderIsClassifiedAsSuchRatherThanAsAnOrderFailure(): void
    {
        $stub   = $this->stub(['orderStatus' => 429]);
        $client = new Acme($stub['transport'], self::DIRECTORY, Acme::generateKey());
        $client->newAccount('ops@example.com');

        $result = $client->newOrder(['vpn.example.com']);

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_RATE_LIMITED, $result['reason']);
        $this->assertStringContainsString('too many certificates', (string) $result['message']);
    }

    public function testAnUnreachableDirectoryIsReportedAsADirectoryFailure(): void
    {
        $client = new Acme(
            static fn (array $request): array => ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'could not connect'],
            self::DIRECTORY,
        );

        $result = $client->directory();

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_DIRECTORY_UNREACHABLE, $result['reason']);
    }

    public function testAnOrderWithoutAuthorizationsIsRefused(): void
    {
        $stub   = $this->stub(['orderBody' => ['status' => 'pending', 'finalize' => 'https://x/finalize/1']]);
        $client = new Acme($stub['transport'], self::DIRECTORY, Acme::generateKey());
        $client->newAccount('ops@example.com');

        $result = $client->newOrder(['vpn.example.com']);

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_ORDER_FAILED, $result['reason']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A tiny ACME server. `$overrides` steers the parts a test needs to fail.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{transport: callable, requests: array<int, array<string, string>>, base: string}
     */
    private function stub(array $overrides = []): array
    {
        $base     = self::DIRECTORY === Acme::LETSENCRYPT ? 'https://acme-v02.api.letsencrypt.org' : 'https://acme-staging-v02.api.letsencrypt.org';
        $requests = [];
        $nonces   = 0;
        $authz    = 0;

        // `$state` is by reference: the `badNonce` knob has to flip *after* the
        // first attempt, or the retry would be rejected forever.
        $state = $overrides;

        $transport = function (array $request) use ($base, &$requests, &$nonces, &$authz, &$state): array {
            $requests[] = ['method' => $request['method'], 'url' => $request['url'], 'body' => $request['body']];

            // Record the JWS members as soon as the body is read, so a request the
            // CA *rejects* is still available to the assertions — the failed
            // attempt is the one worth inspecting in the badNonce test.
            $requests[array_key_last($requests)] += [
                'protected' => $this->payloadOf($request['body'], 'protected'),
                'payload'   => $this->payloadOf($request['body'], 'payload'),
                'signature' => $this->payloadOf($request['body'], 'signature'),
            ];

            $nonces++;
            $nonce = 'nonce-' . $nonces;

            $json = static fn (array $body, int $status = 200, array $headers = []): array => [
                'status'  => $status,
                'headers' => $headers + ['replay-nonce' => 'nonce-' . mt_rand()],
                'body'    => (string) json_encode($body),
                'error'   => null,
            ];

            $url  = (string) $request['url'];
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($path === '/directory') {
                return $json([
                    'newNonce'   => $base . '/new-nonce',
                    'newAccount' => $base . '/new-account',
                    'newOrder'   => $base . '/new-order',
                ], 200, ['replay-nonce' => $nonce]);
            }

            if ($path === '/new-nonce') {
                return ['status' => 200, 'headers' => ['replay-nonce' => $nonce], 'body' => '', 'error' => null];
            }

            // Every signed request must carry a nonce in its protected header.
            $protected = json_decode(self::unb64($this->payloadOf($request['body'], 'protected')), true);
            if (! is_array($protected) || ($protected['nonce'] ?? '') === '') {
                return $json(['type' => 'urn:ietf:params:acme:error:malformed', 'detail' => 'no nonce'], 400, ['replay-nonce' => $nonce]);
            }

            if (($state['badNonceOnce'] ?? false) === true && $path === '/new-account' && ! isset($state['__retried'])) {
                $state['__retried'] = true;

                return $json(['type' => 'urn:ietf:params:acme:error:badNonce', 'detail' => 'stale nonce'], 400, ['replay-nonce' => $nonce]);
            }

            // The signed request names its own URL, so the assertions can pair a
            // request with the endpoint it was aimed at.
            $requests[array_key_last($requests)]['url'] = (string) ($protected['url'] ?? $url);

            if ($path === '/new-account') {
                return $json(['status' => 'valid'], 201, ['location' => $base . '/acct/1', 'replay-nonce' => $nonce]);
            }

            if ($path === '/new-order') {
                if (($state['orderStatus'] ?? 0) >= 400) {
                    return $json(['type' => 'urn:ietf:params:acme:error:rateLimited', 'detail' => 'too many certificates already issued'], (int) $state['orderStatus'], ['replay-nonce' => $nonce]);
                }

                return $json(
                    $state['orderBody'] ?? ['status' => 'pending', 'authorizations' => [$base . '/authz/1'], 'finalize' => $base . '/finalize/1'],
                    201,
                    ['location' => $base . '/order/1', 'replay-nonce' => $nonce],
                );
            }

            if ($path === '/authz/1') {
                $authz++;
                $status = $state['authorizationStatus'] ?? ($authz > 1 ? 'valid' : 'pending');

                $challenge = ['type' => 'http-01', 'url' => $base . '/challenge/1', 'token' => 'tok-http-01', 'status' => $status === 'valid' ? 'valid' : 'pending'];
                if ($status === 'invalid') {
                    $challenge['error'] = ['type' => 'urn:ietf:params:acme:error:dns', 'detail' => 'DNS problem: no A record for vpn.example.com'];
                }

                return $json([
                    'status'     => $status,
                    'identifier' => ['type' => 'dns', 'value' => 'vpn.example.com'],
                    'challenges' => [$challenge],
                ], 200, ['replay-nonce' => $nonce]);
            }

            if ($path === '/challenge/1') {
                return $json(['type' => 'http-01', 'status' => 'processing'], 200, ['replay-nonce' => $nonce]);
            }

            if ($path === '/finalize/1') {
                return $json(['status' => 'valid', 'certificate' => $base . '/cert/1'], 200, ['location' => $base . '/order/1', 'replay-nonce' => $nonce]);
            }

            if ($path === '/cert/1') {
                return [
                    'status'  => 200,
                    'headers' => ['content-type' => 'application/pem-certificate-chain', 'replay-nonce' => $nonce],
                    'body'    => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
                    'error'   => null,
                ];
            }

            return $json(['type' => 'about:blank', 'detail' => 'unexpected endpoint ' . $path], 404, ['replay-nonce' => $nonce]);
        };

        return ['transport' => $transport, 'requests' => &$requests, 'base' => $base];
    }

    /** The value of one member of a flattened JWS body. */
    private function payloadOf(string $body, string $member): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? (string) ($decoded[$member] ?? '') : '';
    }

    /**
     * @param array<int, array<string, string>> $requests
     *
     * @return list<array<string, string>>
     */
    private function signedRequests(array $requests): array
    {
        return array_values(array_filter(
            $requests,
            static fn (array $r): bool => ($r['method'] ?? '') === 'POST' && ($r['protected'] ?? '') !== '',
        ));
    }

    private static function unb64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    /** Raw `r‖s` back to DER, so OpenSSL can verify what JOSE carries. */
    private static function rawToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }

        $ints = '';
        foreach ([substr($raw, 0, 32), substr($raw, 32)] as $part) {
            $part = ltrim($part, "\x00");
            if ($part === '' || (ord($part[0]) & 0x80) !== 0) {
                $part = "\x00" . $part;
            }
            $ints .= "\x02" . chr(strlen($part)) . $part;
        }

        return "\x30" . chr(strlen($ints)) . $ints;
    }
}
