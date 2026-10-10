<?php

namespace Tests\App\Models;

use App\Models\PppoeSessionHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Tests subscriber session history tracking, RADIUS attribute decoding,
 * and limit/policy delta calculations.
 *
 * @internal
 */
final class PppoeSessionHistoryModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private PppoeSessionHistoryModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new PppoeSessionHistoryModel();

        if ($this->db->tableExists('pppoe_session_history')) {
            $this->db->table('pppoe_session_history')->where('subscriber_id', 'test_sub_01')->delete();
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->tableExists('pppoe_session_history')) {
            $this->db->table('pppoe_session_history')->where('subscriber_id', 'test_sub_01')->delete();
        }
        parent::tearDown();
    }

    public function testSubscriberHistoryWithDeltasIdentifiesRateLimitAndTimeoutChanges(): void
    {
        // Session 1: initial 1024/512 kbps, 3600s timeout
        $this->model->insert([
            'id'                => 'sess_01',
            'subscriber_id'     => 'test_sub_01',
            'username'          => 'john_doe',
            'server_id'         => 'srv_main',
            'server_name'       => 'Main PPPoE Concentrator',
            'interface'         => 'eth1',
            'assigned_ip'       => '10.10.0.10',
            'mac_address'       => '00:11:22:33:44:55',
            'session_id'        => 'ppp-001',
            'login_at'          => '2026-10-01 08:00:00',
            'logout_at'         => '2026-10-01 10:00:00',
            'uptime_seconds'    => 7200,
            'terminate_cause'   => 'User-Request',
            'rx_bytes'          => 104857600, // 100 MB
            'tx_bytes'          => 52428800,  // 50 MB
            'rate_limit'        => '1024/512',
            'session_timeout'   => 3600,
            'idle_timeout'      => 600,
            'interim_interval'  => 300,
            'framed_ip'         => '10.10.0.10',
            'radius_attributes' => json_encode([
                'Mikrotik-Rate-Limit' => '1024k/512k',
                'Session-Timeout'     => 3600,
            ]),
            'created_at'        => '2026-10-01 08:00:00',
        ]);

        // Session 2: upgraded to 2048/1024 kbps, 7200s timeout
        $this->model->insert([
            'id'                => 'sess_02',
            'subscriber_id'     => 'test_sub_01',
            'username'          => 'john_doe',
            'server_id'         => 'srv_main',
            'server_name'       => 'Main PPPoE Concentrator',
            'interface'         => 'eth1',
            'assigned_ip'       => '10.10.0.10',
            'mac_address'       => '00:11:22:33:44:55',
            'session_id'        => 'ppp-002',
            'login_at'          => '2026-10-02 08:00:00',
            'logout_at'         => '2026-10-02 12:00:00',
            'uptime_seconds'    => 14400,
            'terminate_cause'   => 'Lost-Carrier',
            'rx_bytes'          => 209715200, // 200 MB
            'tx_bytes'          => 104857600, // 100 MB
            'rate_limit'        => '2048/1024',
            'session_timeout'   => 7200,
            'idle_timeout'      => 600,
            'interim_interval'  => 300,
            'framed_ip'         => '10.10.0.10',
            'radius_attributes' => json_encode([
                'Mikrotik-Rate-Limit' => '2048k/1024k',
                'Session-Timeout'     => 7200,
            ]),
            'created_at'        => '2026-10-02 08:00:00',
        ]);

        // Session 3: active login with same 2048/1024 kbps limit
        $this->model->insert([
            'id'                => 'sess_03',
            'subscriber_id'     => 'test_sub_01',
            'username'          => 'john_doe',
            'server_id'         => 'srv_main',
            'server_name'       => 'Main PPPoE Concentrator',
            'interface'         => 'eth1',
            'assigned_ip'       => '10.10.0.15',
            'mac_address'       => '00:11:22:33:44:55',
            'session_id'        => 'ppp-003',
            'login_at'          => '2026-10-03 08:00:00',
            'logout_at'         => null,
            'uptime_seconds'    => 3600,
            'terminate_cause'   => 'active',
            'rx_bytes'          => 52428800,
            'tx_bytes'          => 26214400,
            'rate_limit'        => '2048/1024',
            'session_timeout'   => 7200,
            'idle_timeout'      => 600,
            'interim_interval'  => 300,
            'framed_ip'         => '10.10.0.15',
            'radius_attributes' => json_encode([
                'Mikrotik-Rate-Limit' => '2048k/1024k',
            ]),
            'created_at'        => '2026-10-03 08:00:00',
        ]);

        $history = $this->model->getSubscriberHistoryWithDeltas('test_sub_01');

        $this->assertCount(3, $history);

        // Result is newest first: sess_03, sess_02, sess_01
        $newest = $history[0];
        $this->assertSame('sess_03', $newest['id']);
        $this->assertTrue($newest['is_active']);
        $this->assertFalse($newest['has_limit_change'], 'No limit change between sess_02 and sess_03');

        $middle = $history[1];
        $this->assertSame('sess_02', $middle['id']);
        $this->assertFalse($middle['is_active']);
        $this->assertTrue($middle['has_limit_change'], 'Limit changed between sess_01 and sess_02');

        // Check changes on middle session
        $this->assertNotEmpty($middle['changes']);
        $changeTypes = array_column($middle['changes'], 'type');
        $this->assertContains('rate_limit', $changeTypes);
        $this->assertContains('session_timeout', $changeTypes);

        $oldest = $history[2];
        $this->assertSame('sess_01', $oldest['id']);
        $this->assertFalse($oldest['has_limit_change'], 'Oldest session has no prior session to compare with');

        // Test metrics
        $metrics = $this->model->getSubscriberMetrics($history);
        $this->assertSame(3, $metrics['total_sessions']);
        $this->assertSame(1, $metrics['active_sessions']);
        $this->assertSame(1, $metrics['total_limit_changes']);
        $this->assertSame('2048/1024', $metrics['current_rate_limit']);
        $this->assertSame('1024/512', $metrics['first_rate_limit']);
        $this->assertSame(25200, $metrics['total_uptime_seconds']); // 7200 + 14400 + 3600
    }
}
