<?php

namespace Tests\App\Libraries;

use App\Libraries\HaConfigSync;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * HA config sync: what is copied, how the payload is sealed, and every reason
 * the receiving node refuses it.
 */
final class HaConfigSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const KEY  = 'shared-sync-key-0123456789';
    private const PEER = '10.254.254.1';

    private string $stateFile;

    private array $savedDnsHosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedDnsHosts = $this->db->table('dns_hosts')->get()->getResultArray();
        $this->stateFile = WRITEPATH . 'ha_sync_state_test_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        $this->db->table('dns_hosts')->emptyTable();
        if ($this->savedDnsHosts !== []) {
            $this->db->table('dns_hosts')->insertBatch($this->savedDnsHosts);
        }
        parent::tearDown();
    }

    private function settings(array $over = []): array
    {
        return array_merge([
            'enabled' => 1, 'config_sync_enabled' => 1,
            'config_sync_peer_ip' => self::PEER, 'config_sync_api_key' => self::KEY,
        ], $over);
    }

    private function sync(): HaConfigSync
    {
        return new HaConfigSync($this->db, $this->stateFile);
    }

    private function sealed(array $payload = ['version' => 1, 'tables' => []], ?int $ts = null, string $key = self::KEY): array
    {
        return $this->sync()->seal($payload, $key, $ts ?? time());
    }

    private function openWith(array $sealed, array $settings = [], string $ip = self::PEER): array
    {
        return $this->sync()->open(
            $this->settings($settings), $ip, $sealed['body'],
            $sealed['headers']['X-Micron-Sync-Time'], $sealed['headers']['X-Micron-Sync-Signature'],
        );
    }

    public function testNodeIdentityIsNeverCopied(): void
    {
        foreach (['interfaces', 'ha_settings', 'system_settings', 'pki_certificates', 'dhcp_server_settings', 'static_routes'] as $own) {
            $this->assertNotContains($own, HaConfigSync::SYNC_TABLES);
        }
        $this->assertContains('firewall_rules', HaConfigSync::SYNC_TABLES);
        $this->assertContains('users', HaConfigSync::SYNC_TABLES);
    }

    public function testPushNeedsHaSyncAndAStrongKey(): void
    {
        $sync = $this->sync();
        $this->assertNull($sync->pushProblem($this->settings()));
        $this->assertNotNull($sync->pushProblem($this->settings(['enabled' => 0])));
        $this->assertNotNull($sync->pushProblem($this->settings(['config_sync_enabled' => 0])));
        $this->assertNotNull($sync->pushProblem($this->settings(['config_sync_peer_ip' => 'peer.local'])));
        $this->assertNotNull($sync->pushProblem($this->settings(['config_sync_api_key' => 'short'])));
    }

    public function testThePayloadIsNotReadableOnTheWire(): void
    {
        $sealed = $this->sealed(['version' => 1, 'tables' => ['users' => [['username' => 'secret-admin-name']]]]);

        $this->assertStringNotContainsString('secret-admin-name', $sealed['body']);
        $this->assertStringNotContainsString('secret-admin-name', (string) base64_decode($sealed['body']));
    }

    public function testAGenuineSyncOpens(): void
    {
        $opened = $this->openWith($this->sealed());

        $this->assertTrue($opened['ok'], $opened['message']);
        $this->assertSame(1, $opened['payload']['version']);
    }

    public function testRefusals(): void
    {
        $cases = [
            'sync off on receiver' => [$this->sealed(), ['config_sync_enabled' => 0], self::PEER],
            'HA off on receiver'   => [$this->sealed(), ['enabled' => 0], self::PEER],
            'unknown sender'       => [$this->sealed(), [], '10.0.0.99'],
            'stale timestamp'      => [$this->sealed(ts: time() - 3600), [], self::PEER],
            'wrong key'            => [$this->sealed(key: 'another-key-0123456789abc'), [], self::PEER],
        ];
        foreach ($cases as $label => [$sealed, $settings, $ip]) {
            $opened = $this->openWith($sealed, $settings, $ip);
            $this->assertFalse($opened['ok'], $label);
            $this->assertGreaterThanOrEqual(400, $opened['status'], $label);
        }
    }

    public function testATamperedBodyIsRefused(): void
    {
        $sealed = $this->sealed();
        $sealed['body'] = base64_encode(strrev((string) base64_decode($sealed['body'])));

        $this->assertFalse($this->openWith($sealed)['ok']);
    }

    public function testAReplayIsRefused(): void
    {
        $sealed = $this->sealed();

        $this->assertTrue($this->openWith($sealed)['ok']);
        $replay = $this->openWith($sealed);
        $this->assertFalse($replay['ok']);
        $this->assertSame(409, $replay['status']);
    }

    public function testImportReplacesSharedTablesAndIgnoresUnknownOnes(): void
    {
        $columns = $this->db->getFieldNames('dns_hosts');
        $row     = array_intersect_key([
            'id' => 'dnsh_synced_test', 'hostname' => 'synced-host', 'domain' => 'example.lan', 'ip_address' => '192.0.2.10',
            'description' => 'from peer', 'enabled' => 1,
        ], array_flip($columns));
        $row['not_a_column'] = 'ignored';

        $written = $this->sync()->import(['version' => 1, 'tables' => [
            'dns_hosts'  => [$row],
            'interfaces' => [['id' => 'evil']],
        ]]);

        $this->assertSame(['dns_hosts' => 1], $written);
        $this->assertSame(1, $this->db->table('dns_hosts')->countAllResults());
        $this->assertSame(0, $this->db->table('interfaces')->where('id', 'evil')->countAllResults());
    }

    public function testPushSendsTheSealedExportToThePeer(): void
    {
        $captured = [];
        $result   = $this->sync()->push($this->settings(), function (string $url, string $body, array $headers) use (&$captured) {
            $captured = compact('url', 'body', 'headers');

            return ['status' => 200, 'body' => json_encode(['ok' => true, 'message' => 'Imported 3 rows.'])];
        });

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('https://' . self::PEER . '/ha/sync/receive', $captured['url']);
        $this->assertArrayHasKey('X-Micron-Sync-Signature', $captured['headers']);
    }

    public function testAPeerRefusalIsReportedNotHidden(): void
    {
        $result = $this->sync()->push($this->settings(), static fn () => ['status' => 403, 'body' => json_encode(['ok' => false, 'message' => 'the sender is not the configured peer'])]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not the configured peer', $result['message']);
    }
}
