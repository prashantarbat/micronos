<?php

declare(strict_types=1);

use App\Connectors\ManualFeedIngestor;
use App\Libraries\ConnectorEgressGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Operator-supplied `dns_feeds` URLs are fetched only through the connector
 * egress guard (threat model F3), parsed into a canonical domain set, and a
 * failed feed contributes nothing.
 *
 * @internal
 */
final class ManualFeedIngestorTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/micron_manual_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string,string> $bodies url => body */
    private function ingestor(array $ips, array $bodies): ManualFeedIngestor
    {
        $transport = static function (array $request, callable $sink) use ($bodies): array {
            $body = $bodies[$request['url']] ?? '';

            if ($body === '') {
                return ['status' => 404, 'headers' => [], 'bytes_in' => 0, 'error' => null];
            }

            $sink($body);

            return ['status' => 200, 'headers' => [], 'bytes_in' => strlen($body), 'error' => null];
        };

        return new ManualFeedIngestor(new ConnectorEgressGuard(static fn (string $host): array => $ips, $transport), null, $this->dir);
    }

    private function feed(string $id, string $url, string $name = ''): array
    {
        return ['id' => $id, 'name' => $name !== '' ? $name : $id, 'url' => $url, 'enabled' => 1];
    }

    public function testIngestsHostsAndPlainDomainFeedsIntoOneCanonicalSet(): void
    {
        $ingestor = $this->ingestor(['93.184.216.34'], [
            'https://a.example/hosts' => "0.0.0.0 evil.example.com\n# comment\n127.0.0.1 tracker.example.net\n",
            'https://b.example/list'  => "plain.example.org\nevil.example.com\n", // dedupes against feed A
        ]);

        $result = $ingestor->ingest([
            $this->feed('feed_a', 'https://a.example/hosts', 'Hosts Feed'),
            $this->feed('feed_b', 'https://b.example/list', 'Plain Feed'),
        ]);

        $this->assertSame(2, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(['evil.example.com', 'plain.example.org', 'tracker.example.net'], $result['entries']);
        // `accepted` sums each feed's own accepted count (2 + 2); `entries` is the
        // deduplicated union (3), because feed B repeats a domain from feed A.
        $this->assertSame(4, $result['accepted']);
        $this->assertCount(3, $result['staged']); // two per-feed sets + one combined

        $this->assertFileExists($this->dir . '/manual-feeds.set');
        $this->assertFileDoesNotExist($this->dir . '/feed_a.feed'); // raw never retained
    }

    public function testRefusedFeedIsReportedAndContributesNothing(): void
    {
        // Resolver returns an internal address: the guard must refuse.
        $ingestor = $this->ingestor(['10.0.0.5'], ['https://a.example/hosts' => "evil.example.com\n"]);

        $result = $ingestor->ingest([$this->feed('feed_a', 'https://a.example/hosts')]);

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([], $result['entries']);
        $this->assertStringContainsString('egress refused', (string) $result['feeds'][0]['error']);
        $this->assertFileDoesNotExist($this->dir . '/manual-feeds.set');
    }

    public function testPartialFailureKeepsTheWorkingFeed(): void
    {
        $ingestor = $this->ingestor(['93.184.216.34'], [
            'https://good.example/list' => "good.example.com\n",
            // bad.example has no body registered → 404
        ]);

        $result = $ingestor->ingest([
            $this->feed('good', 'https://good.example/list'),
            $this->feed('bad', 'https://bad.example/list'),
        ]);

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(['good.example.com'], $result['entries']);
    }

    public function testFeedWithNoValidEntriesFailsRatherThanBlankingTheSet(): void
    {
        $ingestor = $this->ingestor(['93.184.216.34'], ['https://a.example/list' => "# comments only\n\n"]);

        $result = $ingestor->ingest([$this->feed('feed_a', 'https://a.example/list')]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame([], $result['entries']);
        $this->assertStringContainsString('no valid entries', (string) $result['feeds'][0]['error']);
        $this->assertFileDoesNotExist($this->dir . '/manual-feeds.set');
    }
}
