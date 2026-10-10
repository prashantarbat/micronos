<?php

use App\Libraries\InternetService;
use App\Libraries\ServiceDatabase;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The Internet Service Database refresh engine.
 *
 * The HTTP fetch is injected, so these tests exercise the download→parse→store
 * path deterministically without touching the network. Feed files are written
 * to a temporary directory and removed afterwards.
 *
 * @internal
 */
final class ServiceDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private string $serviceDir = '';

    private string $geoDir = '';

    /** @var array<string,string> url => body */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceDir = InternetService::dataDir();
        $this->geoDir     = WRITEPATH . 'geoip';

        // Keep the seeded rows but make sure the ones we assert on are enabled.
        $this->db->table('internet_services')->where('id', 'aws')->update([
            'enabled' => 1, 'prefixes' => null, 'last_synced_at' => null,
        ]);
        $this->db->table('internet_services')->where('id', 'github')->update([
            'enabled' => 1, 'prefixes' => null, 'last_synced_at' => null,
        ]);
        $this->db->table('internet_services')->where('id', 'zoom')->update([
            'enabled' => 1, 'prefixes' => null, 'last_synced_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['aws', 'github', 'zoom'] as $key) {
            @unlink($this->serviceDir . '/' . $key . '.list');
        }
        foreach (['us', 'ca'] as $cc) {
            @unlink($this->geoDir . '/' . $cc . '.netset');
        }

        parent::tearDown();
    }

    private function engine(): ServiceDatabase
    {
        return new ServiceDatabase($this->db, function (string $url) {
            return $this->responses[$url] ?? null;
        });
    }

    public function testSyncStoresPrefixesAndStampsTheRow(): void
    {
        $this->responses[App\Libraries\ServiceSources::url('aws')] = (string) json_encode([
            'prefixes' => [['ip_prefix' => '52.94.0.0/22']],
        ]);

        $summary = $this->engine()->syncServices(['aws']);

        $this->assertSame(1, $summary['synced']);
        $this->assertSame(0, $summary['failed']);

        $row = $this->db->table('internet_services')->where('id', 'aws')->get()->getRowArray();
        $this->assertNotNull($row['last_synced_at']);
        $this->assertSame(['52.94.0.0/22'], json_decode((string) $row['prefixes'], true));

        $feed = $this->serviceDir . '/aws.list';
        $this->assertFileExists($feed);
        $this->assertStringContainsString('52.94.0.0/22', (string) file_get_contents($feed));
    }

    public function testFailedDownloadIsReportedAndLeavesTheRowAlone(): void
    {
        // No response registered → the fetcher returns null.
        $summary = $this->engine()->syncServices(['github']);

        $this->assertSame(0, $summary['synced']);
        $this->assertSame(1, $summary['failed']);

        $row = $this->db->table('internet_services')->where('id', 'github')->get()->getRowArray();
        $this->assertNull($row['last_synced_at']);
    }

    public function testEmptyFeedDoesNotWipeAPreviousList(): void
    {
        $this->db->table('internet_services')->where('id', 'zoom')->update([
            'prefixes' => json_encode(['203.0.113.0/24']),
        ]);
        $this->responses[App\Libraries\ServiceSources::url('zoom')] = "# no entries\n";

        $summary = $this->engine()->syncServices(['zoom']);

        $this->assertSame(1, $summary['failed']);
        $row = $this->db->table('internet_services')->where('id', 'zoom')->get()->getRowArray();
        $this->assertSame(['203.0.113.0/24'], json_decode((string) $row['prefixes'], true));
    }

    public function testDryRunWritesNothing(): void
    {
        $this->responses[App\Libraries\ServiceSources::url('aws')] = (string) json_encode([
            'prefixes' => [['ip_prefix' => '52.94.0.0/22']],
        ]);

        $summary = $this->engine()->syncServices(['aws'], true);

        $this->assertSame(1, $summary['synced']);
        $this->assertFileDoesNotExist($this->serviceDir . '/aws.list');

        $row = $this->db->table('internet_services')->where('id', 'aws')->get()->getRowArray();
        $this->assertNull($row['last_synced_at']);
    }

    public function testServicesWithoutAFeedAreSkipped(): void
    {
        $summary = $this->engine()->syncServices(['netflix']);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('no-source', $summary['services'][0]['status']);
    }

    public function testLinkPatternResolvesTheDatedDocument(): void
    {
        // The landing page is fetched first; the newest dated link is then
        // downloaded and parsed. The fetcher sees both URLs in order.
        $landing = App\Libraries\ServiceSources::url('microsoft_azure');
        $doc     = 'https://download.microsoft.com/download/7/1/d/x/ServiceTags_Public_20260101.json';

        $this->responses[$landing] = '<a href="' . $doc . '">link</a>';
        $this->responses[$doc]     = (string) json_encode([
            'values' => [['properties' => ['addressPrefixes' => ['4.145.74.52/30']]]],
        ]);

        $summary = $this->engine()->syncServices(['microsoft_azure'], true);

        $this->assertSame(1, $summary['synced']);
        $this->assertSame(1, $summary['services'][0]['count']);
    }

    public function testCountrySyncWritesANetset(): void
    {
        $this->responses[sprintf(ServiceDatabase::COUNTRY_URL_TEMPLATE, 'us')] = "203.0.113.0/24\n198.51.100.0/24\n";

        $summary = $this->engine()->syncCountries(['US']);

        $this->assertSame(1, $summary['synced']);
        $this->assertFileExists($this->geoDir . '/us.netset');
        $this->assertStringContainsString('203.0.113.0/24', (string) file_get_contents($this->geoDir . '/us.netset'));
    }

    public function testCountriesInUseAreCollectedFromGeoipObjects(): void
    {
        $this->db->table('firewall_addresses')->where('name', '__geo_use__')->delete();
        $now = date('Y-m-d H:i:s');
        $this->db->table('firewall_addresses')->insert([
            'id' => 'addr_test_geo_use', 'name' => '__geo_use__', 'type' => 'geoip', 'value' => 'US, CA',
            'interface' => 'any', 'color' => '#000', 'comment' => '', 'is_system' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $codes = $this->engine()->countriesInUse();
        $this->assertContains('US', $codes);
        $this->assertContains('CA', $codes);

        $this->db->table('firewall_addresses')->where('name', '__geo_use__')->delete();
    }
}
