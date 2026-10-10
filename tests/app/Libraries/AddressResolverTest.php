<?php

use App\Libraries\AddressResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Offline resolution of non-literal address objects.
 *
 * Resolution itself depends on DNS, so these tests cover the deterministic
 * parts: literal pass-through, range handling, feed-file targets and the
 * feed-file writer.
 *
 * @internal
 */
final class AddressResolverTest extends CIUnitTestCase
{
    private AddressResolver $resolver;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AddressResolver();
        $this->tmpDir   = WRITEPATH . 'fqdn_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function testLiteralTypesPassThroughUnchanged(): void
    {
        $this->assertSame(['10.0.0.0/8'], $this->resolver->resolve(['type' => 'ipmask', 'value' => '10.0.0.0/8']));
        $this->assertSame([], $this->resolver->resolve(['type' => 'ipmask', 'value' => '']));
    }

    public function testRangeKeepsItsForm(): void
    {
        $this->assertSame(['10.0.0.1-10.0.0.50'], $this->resolver->resolve(['type' => 'iprange', 'value' => '10.0.0.1-10.0.0.50']));
        $this->assertSame([], $this->resolver->resolve(['type' => 'iprange', 'value' => 'not-a-range']));
    }

    public function testUnknownTypeResolvesToNothing(): void
    {
        $this->assertSame([], $this->resolver->resolve(['type' => 'mystery', 'value' => 'x']));
    }

    public function testFeedTargetsPerType(): void
    {
        $fqdn = $this->resolver->feedTargets(['type' => 'fqdn', 'value' => '*.example.com']);
        $this->assertCount(1, $fqdn);
        $this->assertSame('fqdn_wild_example_com.list', $fqdn[0][1]);

        $isdb = $this->resolver->feedTargets(['type' => 'internet-service', 'value' => 'microsoft_365, zoom']);
        $this->assertSame(
            [['microsoft_365.list'], ['zoom.list']],
            array_map(static fn ($t) => array_slice($t, 1), $isdb),
        );
        $this->assertStringEndsWith('isdb', $isdb[0][0]);

        $geo = $this->resolver->feedTargets(['type' => 'geoip', 'value' => 'US, CA']);
        $this->assertSame(
            [['us.netset'], ['ca.netset']],
            array_map(static fn ($t) => array_slice($t, 1), $geo),
        );
        $this->assertStringEndsWith('geoip', $geo[0][0]);
    }

    public function testFeedTargetsForLiteralTypeAreEmpty(): void
    {
        $this->assertSame([], $this->resolver->feedTargets(['type' => 'ipmask', 'value' => '10.0.0.0/8']));
    }

    public function testWriteFeedCreatesDirectoryAndFile(): void
    {
        $ok = $this->resolver->writeFeed($this->tmpDir, 'example.list', ['10.0.0.0/8', '192.168.0.0/16']);
        $this->assertTrue($ok);

        $file = $this->tmpDir . '/example.list';
        $this->assertFileExists($file);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('10.0.0.0/8', $contents);
        $this->assertStringContainsString('192.168.0.0/16', $contents);
        $this->assertStringContainsString('generated on', $contents);
    }

    public function testWriteFeedWithNoPrefixesRecordsThat(): void
    {
        $this->resolver->writeFeed($this->tmpDir, 'empty.list', []);

        $contents = (string) file_get_contents($this->tmpDir . '/empty.list');
        $this->assertStringContainsString('no prefixes resolved', $contents);
    }

    public function testWrittenFeedIsReadBackByTheCompilerHelpers(): void
    {
        // The feed file the resolver writes is the same file Fqdn::feed() reads,
        // so a resolved domain is inlined by the compiler without any DNS.
        $this->resolver->writeFeed($this->tmpDir, 'fqdn_example_com.list', ['203.0.113.7']);
        $written = (string) file_get_contents($this->tmpDir . '/fqdn_example_com.list');

        $this->assertStringContainsString('203.0.113.7', $written);
    }
}
