<?php

namespace Tests\App\Controllers;

use App\Libraries\NftablesCompiler;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\BaseTestCase;

/**
 * The peer endpoint is reachable without a session but refuses anything not
 * sealed by the peer, and the firewall opens HA traffic only to the peer.
 */
final class HaSyncEndpointTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private ?array $savedHa = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedHa = $this->db->table('ha_settings')->get(1)->getRowArray();
    }

    protected function tearDown(): void
    {
        $this->db->table('ha_settings')->emptyTable();
        if ($this->savedHa !== null) {
            $this->db->table('ha_settings')->insert($this->savedHa);
        }
        parent::tearDown();
    }

    private function setHa(array $values): void
    {
        $this->db->table('ha_settings')->emptyTable();
        $this->db->table('ha_settings')->insert(array_merge([
            'id' => 1, 'enabled' => 1, 'node_name' => 'node-a', 'mode' => 'master',
            'vrrp_router_id' => 51, 'vrrp_interface' => 'eth1', 'vrrp_priority' => 110,
            'conntrackd_enabled' => 1, 'conntrackd_interface' => 'eth2',
            'peer_sync_ip' => '10.254.254.2', 'peer_sync_port' => 3780,
            'config_sync_enabled' => 1, 'config_sync_peer_ip' => '10.254.254.2',
            'config_sync_api_key' => 'shared-sync-key-0123456789',
        ], $values));
    }

    public function testAnUnsignedSyncIsRefusedWithoutALoginRedirect(): void
    {
        $this->setHa([]);

        $result = $this->withBody('not-a-sealed-payload')->post('ha/sync/receive');

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertFalse(json_decode((string) $result->response()->getBody(), true)['ok']);
    }

    public function testTheFirewallOpensHaTrafficOnlyToThePeer(): void
    {
        $this->setHa([]);
        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip protocol vrrp accept', $ruleset);
        $this->assertStringContainsString('ip saddr 10.254.254.2 udp dport 3780 accept', $ruleset);
        $this->assertStringContainsString('ip saddr 10.254.254.2 tcp dport 443 accept', $ruleset);
    }

    public function testNothingIsOpenedWhileHaIsOff(): void
    {
        $this->setHa(['enabled' => 0]);
        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringNotContainsString('HA config sync from peer', $ruleset);
        $this->assertStringNotContainsString('ip protocol vrrp accept', $ruleset);
    }
}
