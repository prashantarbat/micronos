<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

final class RoutingComprehensiveAuditTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private FakeIpcClient $ipc;

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
        $this->ipc = new FakeIpcClient([
            'frr.status' => [
                'result' => [
                    'running'   => true,
                    'enabled'   => true,
                    'version'   => 'FRRouting 9.1',
                    'uptime'    => '1d',
                    'bgp_as'    => '65000',
                    'router_id' => '10.0.0.1',
                ],
                'error' => null,
            ],
            'diag.routing_table' => [
                'result' => [
                    'routes' => [
                        [
                            'destination' => '0.0.0.0/0',
                            'gateway'     => '192.168.1.1',
                            'interface'   => 'eth0',
                            'metric'      => '100',
                            'protocol'    => 'kernel',
                            'flags'       => 'UG',
                        ],
                    ],
                ],
                'error' => null,
            ],
            'sdwan.status' => [
                'result' => [
                    'active' => true,
                ],
                'error' => null,
            ],
        ]);
        Services::injectMock('ipc', $this->ipc);
    }

    private function session(): array
    {
        $perms = [
            'dashboard.view', 'routing.table.view', 'routing.static.manage',
            'routing.pbr.manage', 'routing.vrf.manage', 'routing.routes.apply',
            'routing.bfd.manage', 'routing.multiwan.manage',
        ];

        return [
            'logged_in'        => true,
            'user_id'          => 'usr_superadmin',
            'username'         => 'ministrator',
            'is_superadmin'    => 1,
            'permissions'      => $perms,
            'user_permissions' => $perms,
        ];
    }

    /**
     * Test all routing URLs and tabs with mock / populated data.
     */
    public function testAllRoutingTabsAndSubTabsRenderCleanly(): void
    {
        $endpoints = [
            'routing',
            'routing/static',
            'routing/ospf',
            'routing/bgp',
            'routing/rip',
            'routing/isis',
            'routing/bfd',
            'routing/vrf',
            'routing/multiwan',
            'routing/full-table',
            'routing/policy',
            'routing/sdwan',

            // Tabs
            'routing?tab=bgp',
            'routing?tab=ospf',
            'routing?tab=ospf&sub=areas',
            'routing?tab=ospf&sub=interfaces',
            'routing?tab=ospf&sub=networks',
            'routing?tab=ospf&sub=neighbors',
            'routing?tab=ospf&sub=policy',
            'routing?tab=ospf&sub=status',
            'routing?tab=rip',
            'routing?tab=isis',
            'routing?tab=bfd',
            'routing?tab=policy',
            'routing?tab=policy&sub=al',
            'routing?tab=policy&sub=pl',
            'routing?tab=policy&sub=rm',
            'routing?tab=vrf',
            'routing?tab=vrf&sub=pbr',
            'routing?tab=multiwan',
            'routing?tab=multiwan&sub=gateways',
            'routing?tab=multiwan&sub=groups',
            'routing?tab=frr',
            'routing?tab=fib',

            // SD-WAN tabs
            'routing/sdwan?tab=matrix',
            'routing/sdwan?tab=profiles',
            'routing/sdwan?tab=rules',
            'routing/sdwan?tab=members',
            'routing/sdwan?tab=settings',
        ];

        foreach ($endpoints as $uri) {
            $res = $this->withSession($this->session())->get($uri);
            $status = $res->response()->getStatusCode();
            $body = $res->response()->getBody();

            $this->assertSame(200, $status, "Expected 200 OK for [{$uri}], got {$status}");
            $this->assertStringNotContainsString('Whoops!', $body, "Whoops error found on [{$uri}]");
            $this->assertStringNotContainsString('ErrorException', $body, "ErrorException found on [{$uri}]");
            $this->assertStringNotContainsString('Fatal error', $body, "Fatal error found on [{$uri}]");
            $this->assertStringNotContainsString('TypeError', $body, "TypeError found on [{$uri}]");
        }
    }

    /**
     * Test JSON endpoints for live status, gateway checks, and config downloads.
     */
    public function testRoutingJsonAndDownloadEndpoints(): void
    {
        // OSPF JSON Status
        $ospfRes = $this->withSession($this->session())->get('routing/ospf/status');
        $this->assertSame(200, $ospfRes->response()->getStatusCode());
        $this->assertTrue(str_contains($ospfRes->response()->getHeaderLine('Content-Type'), 'application/json'));

        // Static Gateway check
        $gwRes = $this->withSession($this->session())->get('routing/static/check-gateways');
        $this->assertSame(200, $gwRes->response()->getStatusCode());
        $this->assertTrue(str_contains($gwRes->response()->getHeaderLine('Content-Type'), 'application/json'));

        // FRR config download
        $frrRes = $this->withSession($this->session())->get('routing/frr/download');
        $this->assertSame(200, $frrRes->response()->getStatusCode());
        $this->assertTrue(str_contains($frrRes->response()->getHeaderLine('Content-Disposition'), 'frr.conf'));

        // SD-WAN metrics
        $sdwanRes = $this->withSession($this->session())->get('routing/sdwan/metrics');
        $this->assertSame(200, $sdwanRes->response()->getStatusCode());
        $this->assertTrue(str_contains($sdwanRes->response()->getHeaderLine('Content-Type'), 'application/json'));
    }

    /**
     * Zero-config / fresh install simulation:
     * When FRR daemon is stopped, returns empty router_id, empty routes, and all routing tables are 0 rows.
     */
    public function testFreshInstallZeroConfigStateRendersSafely(): void
    {
        $emptyIpc = new FakeIpcClient([
            'frr.status' => [
                'result' => [
                    'running'   => false,
                    'enabled'   => false,
                    'version'   => '',
                    'uptime'    => '',
                    'bgp_as'    => '',
                    'router_id' => '',
                ],
                'error' => null,
            ],
            'diag.routing_table' => [
                'result' => [
                    'routes' => [],
                ],
                'error' => null,
            ],
            'vpn.wireguard.status' => [
                'result' => ['status' => 'ok', 'interfaces' => [], 'peers' => []],
                'error'  => null,
            ],
        ]);
        Services::injectMock('ipc', $emptyIpc);

        // Every protocol tab in zero-config state
        $tabs = ['bgp', 'ospf', 'rip', 'isis', 'bfd', 'vrf', 'multiwan', 'frr', 'fib', 'policy'];
        foreach ($tabs as $tab) {
            $res = $this->withSession($this->session())->get('routing?tab=' . $tab);
            $this->assertSame(200, $res->response()->getStatusCode(), "Zero-config failure on tab [{$tab}]");
            $this->assertStringNotContainsString('Whoops!', $res->response()->getBody());
        }

        // Dedicated pages in zero-config state
        $resStatic = $this->withSession($this->session())->get('routing/static');
        $this->assertSame(200, $resStatic->response()->getStatusCode());
        $this->assertStringNotContainsString('Whoops!', $resStatic->response()->getBody());

        $resSdwan = $this->withSession($this->session())->get('routing/sdwan');
        $this->assertSame(200, $resSdwan->response()->getStatusCode());
        $this->assertStringNotContainsString('Whoops!', $resSdwan->response()->getBody());
    }
}
