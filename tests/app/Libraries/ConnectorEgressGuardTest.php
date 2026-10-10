<?php

declare(strict_types=1);

use App\Libraries\ConnectorEgressGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The connector fetch egress guard (threat model §5.3, findings F3/F5).
 *
 * The DNS resolver and the HTTP transport are injected, so pinning, per-hop
 * redirect validation and the stream caps are exercised deterministically with
 * no network access.
 *
 * @internal
 */
final class ConnectorEgressGuardTest extends CIUnitTestCase
{
    private const PUBLIC_V4 = '93.184.216.34';
    private const PUBLIC_V6 = '2606:4700:4700::1111';

    /** @var list<array{url: string, headers: list<string>}> */
    private array $seenRequests = [];

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seenRequests = [];
        $this->tmpDir = sys_get_temp_dir() . '/micron_egress_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @return callable(string): list<string> */
    private function resolver(array $map): callable
    {
        return static fn (string $host): array => $map[$host] ?? [];
    }

    /**
     * A fake one-hop transport. Each hop is ['status','headers','chunks','bytes_in'].
     *
     * @param list<array<string, mixed>> $hops
     *
     * @return callable(array, callable): array
     */
    private function transport(array $hops): callable
    {
        return function (array $request, callable $sink) use ($hops): array {
            static $i = -1;
            $i = $i + 1;
            $hop = $hops[$i] ?? $hops[count($hops) - 1];

            $this->seenRequests[] = ['url' => $request['url'], 'headers' => $request['headers'], 'ip' => $request['ip']];

            $bytes = 0;
            foreach ($hop['chunks'] ?? [] as $chunk) {
                $accepted = $sink($chunk);
                if ($accepted === 0) {
                    break; // emulate the transport aborting a capped stream
                }
                $bytes += strlen($chunk);
            }

            return [
                'status'   => $hop['status'] ?? 200,
                'headers'  => $hop['headers'] ?? [],
                'bytes_in' => $hop['bytes_in'] ?? $bytes,
                'error'    => $hop['error'] ?? null,
            ];
        };
    }

    // ------------------------------------------------------------------
    // Scheme allowlist (T2.4)
    // ------------------------------------------------------------------

    public function testOnlyHttpsIsAllowedByDefault(): void
    {
        $guard = new ConnectorEgressGuard($this->resolver(['example.com' => [self::PUBLIC_V4]]));

        $this->assertTrue($guard->check('https://example.com/feed')['ok']);

        foreach ([
            'http://example.com/feed',
            'file:///etc/passwd',
            'gopher://example.com/',
            'dict://example.com/',
            'data://text/plain;base64,AAAA',
            'ftp://example.com/feed',
        ] as $url) {
            $result = $guard->check($url);
            $this->assertFalse($result['ok'], "scheme must be refused: {$url}");
        }
    }

    public function testSyntaxCheckRefusesInternalTargetsWithoutDns(): void
    {
        $guard = new ConnectorEgressGuard($this->resolver([]));

        // No resolver is consulted: a hostname passes syntactically even though
        // it would fail resolution, which is exactly what save-time needs.
        $this->assertTrue($guard->checkSyntax('https://feeds.example/list')['ok']);

        foreach ([
            'http://feeds.example/list',
            'https://127.0.0.1/list',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/list',
            'https://localhost/list',
            'https://metadata.google.internal/computeMetadata/v1/',
            'https://router.local/list',
            'https://printer.home.arpa/list',
        ] as $url) {
            $this->assertFalse($guard->checkSyntax($url)['ok'], "syntax check must refuse: {$url}");
        }
    }

    public function testDeniedSchemeNeverReachesTheTransport(): void
    {
        $guard = new ConnectorEgressGuard($this->resolver([]), $this->transport([['chunks' => ['x']]]));

        $result = $guard->fetch('http://169.254.169.254/latest/meta-data/');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->seenRequests, 'a refused scheme must not perform a request');
    }

    // ------------------------------------------------------------------
    // Denylist (T2.1)
    // ------------------------------------------------------------------

    public function testDenylistCoversInternalAndReservedSpace(): void
    {
        foreach ([
            '127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254',
            '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '192.0.2.1',
            '::1', 'fe80::1', 'fc00::1', 'ff02::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1',
            '2001:db8::1', '2002::1', '2001::1', 'not-an-ip',
        ] as $ip) {
            $this->assertTrue(ConnectorEgressGuard::isDenied($ip), "should be denied: {$ip}");
        }

        foreach (['8.8.8.8', '1.1.1.1', self::PUBLIC_V4, self::PUBLIC_V6, '2606:4700::6810:84e5'] as $ip) {
            $this->assertFalse(ConnectorEgressGuard::isDenied($ip), "should be allowed: {$ip}");
        }
    }

    public function testLiteralInternalHostIsRefusedWithoutResolution(): void
    {
        $guard = new ConnectorEgressGuard($this->resolver([]));

        $this->assertFalse($guard->check('https://127.0.0.1/feed')['ok']);
        $this->assertFalse($guard->check('https://[::1]/feed')['ok']);
        $this->assertFalse($guard->check('https://169.254.169.254/latest/meta-data/')['ok']);
    }

    public function testHostResolvingToAnyInternalAddressIsRefusedEntirely(): void
    {
        // Round-robin DNS: one public, one internal. Trusting the public one
        // would let the connection land on the internal address.
        $guard = new ConnectorEgressGuard($this->resolver([
            'mixed.example' => [self::PUBLIC_V4, '10.0.0.5'],
        ]));

        $result = $guard->check('https://mixed.example/feed');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['pinned']);
    }

    // ------------------------------------------------------------------
    // Resolve-and-pin (T2.2)
    // ------------------------------------------------------------------

    public function testHostIsResolvedOnceAndTheConnectionIsPinned(): void
    {
        $calls = 0;
        $resolver = function (string $host) use (&$calls): array {
            $calls++;

            return [self::PUBLIC_V4];
        };

        $guard = new ConnectorEgressGuard($resolver, $this->transport([['chunks' => ["data\n"]]]));
        $result = $guard->fetch('https://feed.example/list');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $calls, 'the host must be resolved exactly once per hop');
        $this->assertSame(self::PUBLIC_V4, $this->seenRequests[0]['ip'], 'the transport must be pinned to the validated IP');
    }

    // ------------------------------------------------------------------
    // Redirect validation (T2.3)
    // ------------------------------------------------------------------

    public function testAllowedRedirectChainIsFollowed(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4], 'b.example' => [self::PUBLIC_V4]]),
            $this->transport([
                ['status' => 302, 'headers' => ['location' => 'https://b.example/feed'], 'chunks' => []],
                ['status' => 200, 'chunks' => ["one\n", "two\n"]],
            ]),
        );

        $file = $this->tmpDir . '/feed.txt';
        $result = $guard->fetch('https://a.example/feed', ['destination' => $file]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['hops']);
        $this->assertSame("one\ntwo\n", file_get_contents($file));
        $this->assertSame(hash('sha256', "one\ntwo\n"), $result['sha256']);
    }

    public function testRelativeRedirectIsResolved(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([
                ['status' => 302, 'headers' => ['location' => '/next/feed'], 'chunks' => []],
                ['status' => 200, 'chunks' => ["ok\n"]],
            ]),
        );

        $result = $guard->fetch('https://a.example/first/feed');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://a.example/next/feed', $this->seenRequests[1]['url']);
    }

    public function testRedirectToAnInternalHostIsRefused(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4], 'evil.example' => ['10.0.0.5']]),
            $this->transport([
                ['status' => 302, 'headers' => ['location' => 'https://evil.example/'], 'chunks' => []],
                ['status' => 200, 'chunks' => ["should-not-happen\n"]],
            ]),
        );

        $result = $guard->fetch('https://a.example/feed');

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $this->seenRequests, 'the internal redirect target must never be contacted');
    }

    public function testRedirectLoopIsCapped(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([['status' => 302, 'headers' => ['location' => 'https://a.example/loop'], 'chunks' => []]]),
            ['max_redirects' => 2],
        );

        $result = $guard->fetch('https://a.example/feed');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('redirect', (string) $result['error']);
        $this->assertCount(3, $this->seenRequests); // initial + 2 allowed hops
    }

    // ------------------------------------------------------------------
    // Cross-origin redirect credentials (T2.6)
    // ------------------------------------------------------------------

    public function testAuthorizationIsStrippedOnCrossOriginRedirect(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4], 'b.example' => [self::PUBLIC_V4]]),
            $this->transport([
                ['status' => 302, 'headers' => ['location' => 'https://b.example/feed'], 'chunks' => []],
                ['status' => 200, 'chunks' => ["ok\n"]],
            ]),
        );

        $result = $guard->fetch('https://a.example/feed', ['headers' => ['Authorization: Bearer sekrit']]);

        $this->assertTrue($result['ok']);
        $this->assertContains('Authorization: Bearer sekrit', $this->seenRequests[0]['headers']);
        $this->assertNotContains('Authorization: Bearer sekrit', $this->seenRequests[1]['headers']);
    }

    public function testCrossOriginRedirectIsRefusedWhenAuthIsSensitive(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4], 'b.example' => [self::PUBLIC_V4]]),
            $this->transport([
                ['status' => 302, 'headers' => ['location' => 'https://b.example/feed'], 'chunks' => []],
                ['status' => 200, 'chunks' => ["ok\n"]],
            ]),
        );

        $result = $guard->fetch('https://a.example/feed', [
            'headers'        => ['Authorization: Basic dXNlcjpwYXNz'],
            'sensitive_auth' => true,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $this->seenRequests, 'credentials must not be replayed cross-origin');
    }

    // ------------------------------------------------------------------
    // Stream / ratio caps (T2.7)
    // ------------------------------------------------------------------

    public function testDecompressedCapAbortsAndLeavesNoArtifact(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([['status' => 200, 'chunks' => [str_repeat('a', 800), str_repeat('b', 800)], 'bytes_in' => 1600]]),
            ['max_decompressed_bytes' => 1000],
        );

        $file = $this->tmpDir . '/cap.feed';
        $result = $guard->fetch('https://a.example/feed', ['destination' => $file]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('decompressed size cap', (string) $result['error']);
        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($file . '.part');
    }

    public function testCompressedCapIsEnforced(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([['status' => 200, 'chunks' => ['small'], 'bytes_in' => 5_000_000]]),
            ['max_bytes' => 1_000_000],
        );

        $result = $guard->fetch('https://a.example/feed');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('compressed size cap', (string) $result['error']);
    }

    public function testDecompressionRatioCapIsEnforced(): void
    {
        // 1 KiB compressed expanding to ~100 KiB exceeds a 10:1 ratio cap.
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([['status' => 200, 'chunks' => [str_repeat('a', 100_000)], 'bytes_in' => 1_000]]),
            ['max_ratio' => 10],
        );

        $result = $guard->fetch('https://a.example/feed');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ratio cap', (string) $result['error']);
    }

    public function testStreamingCountsAndDigestWithoutReturningBody(): void
    {
        $secret = 'MARKER-should-never-be-echoed';
        $guard  = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([['status' => 200, 'chunks' => [$secret . "\n"], 'bytes_in' => strlen($secret) + 1]]),
        );

        $file = $this->tmpDir . '/body.feed';
        $result = $guard->fetch('https://a.example/feed', ['destination' => $file]);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThan(0, $result['bytes_out']);
        $this->assertNotNull($result['sha256']);

        // T2.9: the body is never returned to the caller.
        $this->assertArrayNotHasKey('body', $result);
        $this->assertStringNotContainsString($secret, json_encode($result));
        $this->assertStringContainsString($secret, (string) file_get_contents($file));
    }

    public function testFailedFetchLeavesNoArtifactAtTheDestination(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4], 'b.example' => [self::PUBLIC_V4]]),
            $this->transport([
                ['status' => 302, 'headers' => ['location' => 'https://b.example/feed'], 'chunks' => []],
                ['status' => 500, 'chunks' => ['upstream error body']],
            ]),
        );

        $file = $this->tmpDir . '/fail.feed';
        $result = $guard->fetch('https://a.example/feed', ['destination' => $file]);

        $this->assertFalse($result['ok']);
        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($file . '.part');
    }

    public function testUpstreamErrorReportsOnlyTheStatusCode(): void
    {
        $guard = new ConnectorEgressGuard(
            $this->resolver(['a.example' => [self::PUBLIC_V4]]),
            $this->transport([['status' => 404, 'chunks' => ['INTERNAL-SECRET-DETAIL']]]),
        );

        $result = $guard->fetch('https://a.example/feed');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('404', (string) $result['error']);
        $this->assertStringNotContainsString('INTERNAL-SECRET-DETAIL', json_encode($result));
    }
}
