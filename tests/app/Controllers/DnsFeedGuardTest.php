<?php

declare(strict_types=1);

use App\Connectors\ManualFeedIngestor;
use App\Libraries\ConnectorEgressGuard;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Operator-supplied `dns_feeds` URLs (threat model F3): rejected at entry when
 * they are internal or not https, and fetched only through the egress guard on
 * sync, with the live blocklist never blanked by a failed run.
 *
 * @internal
 */
final class DnsFeedGuardTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['dns.view', 'dns.edit', 'dns.flush'];

    private string $stageDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The tests suite shares one in-memory database, so clear the whole
        // table rather than a test-id prefix: `dns_feeds` rows use generated
        // `feed_*` ids that a prefix delete would leave behind, and a stray
        // enabled row would make `syncFeeds()` attempt a real network fetch.
        $this->db->table('dns_feeds')->emptyTable();

        $this->stageDir = sys_get_temp_dir() . '/micron_dnsfeed_' . bin2hex(random_bytes(4));
        mkdir($this->stageDir, 0700, true);

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('dns_feeds')->emptyTable();
        foreach (glob($this->stageDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->stageDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Save-time validation
    // ------------------------------------------------------------------

    public function testStoreFeedRejectsInternalAndNonHttpsUrls(): void
    {
        foreach ([
            'http://127.0.0.1/list',
            'https://localhost/list',
            'http://feeds.example/list',            // not https
            'https://169.254.169.254/latest/meta-data/',
            'https://metadata.google.internal/v1/',
        ] as $url) {
            $this->post('dns/feeds/store', $this->csrf([
                'name'     => 'Bad Feed',
                'url'      => $url,
                'category' => 'Custom Feed',
            ]))->assertRedirect();

            $this->assertSame(0, $this->db->table('dns_feeds')->countAllResults(), "url must be refused: {$url}");
        }
    }

    public function testStoreFeedAcceptsPublicHttpsUrlWithZeroEntries(): void
    {
        $this->post('dns/feeds/store', $this->csrf([
            'name'     => 'Good Feed',
            'url'      => 'https://feeds.example/hosts',
            'category' => 'Custom Feed',
        ]))->assertRedirect();

        $row = $this->db->table('dns_feeds')->where('name', 'Good Feed')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['entries_count'], 'entries are unknown until the first sync');
    }

    // ------------------------------------------------------------------
    // Sync-time fetch
    // ------------------------------------------------------------------

    public function testSyncFetchesThroughTheGuardAndAppliesDomains(): void
    {
        $this->seedFeed('feedtest_good', 'https://feeds.example/hosts');

        $ingestor = new ManualFeedIngestor(
            $this->guard(['93.184.216.34'], "0.0.0.0 evil.example.com\n127.0.0.1 tracker.example.net\n"),
            null,
            $this->stageDir,
        );
        Services::injectMock('manualFeedIngestor', $ingestor);

        $ipc = new FakeIpcClient([
            'dnsbl.apply' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->post('dns/feeds/sync', $this->csrf([]))->assertRedirect();

        // The daemon was asked to compile the combined set.
        $this->assertSame('dnsbl.apply', $ipc->calls[0]['method']);
        $this->assertContains('evil.example.com', $ipc->calls[0]['params']['domains']);

        $row = $this->db->table('dns_feeds')->where('id', 'feedtest_good')->get()->getRowArray();
        $this->assertSame(2, (int) $row['entries_count']);
    }

    public function testSyncLeavesBlocklistUntouchedWhenEveryFeedFails(): void
    {
        $this->seedFeed('feedtest_internal', 'https://a.example/hosts');

        // The guard resolves every host to an internal address, so the fetch is
        // refused before any body is retrieved.
        $ingestor = new ManualFeedIngestor(
            $this->guard(['10.0.0.5'], "evil.example.com\n"),
            null,
            $this->stageDir,
        );
        Services::injectMock('manualFeedIngestor', $ingestor);

        $ipc = new FakeIpcClient([
            'dnsbl.apply' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('dns/feeds/sync', $this->csrf([]));
        $result->assertRedirect();

        // No apply call at all: an all-failed sync must not blank the blocklist.
        $this->assertSame([], $ipc->calls);
        $result->assertSessionHas(
            'error',
            'DNSBL sync failed: no feed produced a valid domain set (1 of 1 feed(s) failed). The live blocklist was left unchanged.',
        );
    }

    public function testSyncWithNoEnabledFeedsIsRefused(): void
    {
        $result = $this->post('dns/feeds/sync', $this->csrf([]));
        $result->assertRedirect();

        $result->assertSessionHas('error', 'No enabled DNSBL feeds to synchronize.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function guard(array $ips, string $body): ConnectorEgressGuard
    {
        $transport = static function (array $request, callable $sink) use ($body): array {
            $sink($body);

            return ['status' => 200, 'headers' => [], 'bytes_in' => strlen($body), 'error' => null];
        };

        return new ConnectorEgressGuard(static fn (string $host): array => $ips, $transport);
    }

    private function seedFeed(string $id, string $url): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('dns_feeds')->insert([
            'id'            => $id,
            'name'          => 'Test Feed',
            'url'           => $url,
            'category'      => 'Custom Feed',
            'entries_count' => 0,
            'enabled'       => 1,
            'last_updated'  => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }
}
