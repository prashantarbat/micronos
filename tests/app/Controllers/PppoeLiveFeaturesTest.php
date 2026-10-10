<?php

namespace Tests\App\Controllers;

use App\Libraries\PppoeBackoff;
use App\Models\PppoeSessionHistoryModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature tests for live PPPoE features:
 *  - POST pppoe/redial (live 'Redial now' action, backoff reset, health update)
 *  - GET pppoe/users/{id}/history (subscriber session history & RADIUS limit changes)
 *
 * @internal
 */
final class PppoeLiveFeaturesTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'pppoe.server.view',
        'pppoe.server.manage',
        'pppoe.server.apply',
        'pppoe.client.manage',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->db->tableExists(PppoeBackoff::TABLE)) {
            $this->db->table(PppoeBackoff::TABLE)->emptyTable();
        }
        if ($this->db->tableExists('pppoe_session_history')) {
            $this->db->table('pppoe_session_history')->where('subscriber_id', 'test_sub_hist')->delete();
        }
        if ($this->db->tableExists('pppoe_server_users')) {
            $this->db->table('pppoe_server_users')->where('id', 'test_sub_hist')->delete();
        }

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'test_admin',
            'username'         => 'admin',
            'role'             => 'admin',
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    public function testRedialEndpointDispatchesIpcAndResetsBackoff(): void
    {
        // 1. Put eth0 into backoff
        $backoff = new PppoeBackoff($this->db);
        $backoff->recordFailure('eth0', 'Line dead', time());
        $this->assertTrue($backoff->isBackedOff('eth0'));

        // 2. Perform Redial now
        $result = $this->post('pppoe/redial', $this->csrf([
            'interface' => 'eth0',
            'force'     => '1',
        ]));

        $result->assertRedirectTo(base_url('pppoe'));

        // 3. Backoff must be reset
        $this->assertFalse($backoff->isBackedOff('eth0'));
    }

    public function testRedialEndpointViaAjaxReturnsJsonWithHealthAndPartial(): void
    {
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']);

        $result = $this->post('pppoe/redial', $this->csrf([
            'interface' => 'eth0',
        ]));

        $result->assertOK();

        $json = json_decode($result->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('message', $json);
        $this->assertArrayHasKey('linkHealth', $json);
        $this->assertArrayHasKey('html', $json);
        $this->assertStringContainsString('Session / Device', $json['html']);
    }

    public function testSubscriberHistoryRendersViewWithDeltasAndMetrics(): void
    {
        $now = date('Y-m-d H:i:s');

        // Seed subscriber
        $this->db->table('pppoe_server_users')->insert([
            'id'          => 'test_sub_hist',
            'server_id'   => 'srv_main',
            'username'    => 'fiber_customer',
            'password'    => 'secret123',
            'rate_limit'  => '2048/1024',
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // Seed 2 sessions showing rate limit upgrade
        $historyModel = new PppoeSessionHistoryModel();
        $historyModel->insert([
            'id'                => 'hist_sess_01',
            'subscriber_id'     => 'test_sub_hist',
            'username'          => 'fiber_customer',
            'server_id'         => 'srv_main',
            'server_name'       => 'Fiber AC',
            'interface'         => 'ppp0',
            'assigned_ip'       => '10.0.0.50',
            'session_id'        => 'ppp0',
            'login_at'          => '2026-10-01 10:00:00',
            'logout_at'         => '2026-10-01 18:00:00',
            'uptime_seconds'    => 28800,
            'terminate_cause'   => 'User-Request',
            'rx_bytes'          => 524288000,
            'tx_bytes'          => 262144000,
            'rate_limit'        => '1024/512',
            'session_timeout'   => 3600,
            'framed_ip'         => '10.0.0.50',
            'radius_attributes' => json_encode(['Mikrotik-Rate-Limit' => '1024k/512k']),
            'created_at'        => '2026-10-01 10:00:00',
        ]);

        $historyModel->insert([
            'id'                => 'hist_sess_02',
            'subscriber_id'     => 'test_sub_hist',
            'username'          => 'fiber_customer',
            'server_id'         => 'srv_main',
            'server_name'       => 'Fiber AC',
            'interface'         => 'ppp0',
            'assigned_ip'       => '10.0.0.50',
            'session_id'        => 'ppp0',
            'login_at'          => '2026-10-02 10:00:00',
            'logout_at'         => null,
            'uptime_seconds'    => 3600,
            'terminate_cause'   => 'Active',
            'rx_bytes'          => 104857600,
            'tx_bytes'          => 52428800,
            'rate_limit'        => '2048/1024',
            'session_timeout'   => 7200,
            'framed_ip'         => '10.0.0.50',
            'radius_attributes' => json_encode(['Mikrotik-Rate-Limit' => '2048k/1024k']),
            'created_at'        => '2026-10-02 10:00:00',
        ]);

        $this->withSession($this->session());
        $result = $this->get('pppoe/users/test_sub_hist/history');

        $result->assertOK();
        $body = $result->getBody();

        // Check key elements
        $this->assertStringContainsString('fiber_customer', $body);
        $this->assertStringContainsString('Session History', $body);
        $this->assertStringContainsString('Limit Policy Changes', $body);
        $this->assertStringContainsString('Rate Limit Progression Detected', $body);
        $this->assertStringContainsString('1024/512', $body);
        $this->assertStringContainsString('2048/1024', $body);
    }
}
