<?php

namespace Tests\app\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Verifies Routing Controller, all standalone protocol pages,
 * legacy protocol redirects, FIB table inspection, and IPC interactions.
 */
class RoutingPagesTest extends \Tests\Support\BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'routing.table.view',
        'routing.static.manage',
        'routing.pbr.manage',
        'routing.vrf.manage',
        'routing.routes.apply',
    ];

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'usr_admin',
            'username'         => 'ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    public function testRoutingIndexRendersSuccessfully(): void
    {
        $response = $this->withSession($this->session())->get('routing');
        $response->assertOK();
        $response->assertSee('Dynamic Routing &amp; Multi-WAN');
        $response->assertSee('Static Routes');
        $response->assertSee('Kernel Forwarding Information Base');
    }

    public function testRoutingStaticRendersSuccessfully(): void
    {
        $response = $this->withSession($this->session())->get('routing/static');
        $response->assertOK();
        $response->assertSee('Configured Static Routes');
    }

    public function testRoutingFullTableRendersCombinedView(): void
    {
        $response = $this->withSession($this->session())->get('routing/full-table');
        $response->assertOK();
        $response->assertSee('Full Routing Table');
        $response->assertSee('Kernel FIB (Forwarding Information Base)');
    }

    public function testRoutingTabsRenderSuccessfully(): void
    {
        $tabs = ['bgp', 'ospf', 'rip', 'isis', 'bfd', 'vrf', 'multiwan', 'frr', 'fib', 'policy'];

        foreach ($tabs as $tab) {
            $response = $this->withSession($this->session())->get('routing?tab=' . $tab);
            $response->assertOK();
            $response->assertSee('Dynamic Routing &amp; Multi-WAN');
        }
    }

    public function testLegacyStandaloneAliasesRedirectToCanonicalTabs(): void
    {
        $aliases = [
            'bgp'              => 'routing?tab=bgp',
            'rip'              => 'routing?tab=rip',
            'isis'             => 'routing?tab=isis',
            'bfd'              => 'routing?tab=bfd',
            'vrf'              => 'routing?tab=vrf',
            'multiwan'         => 'routing?tab=multiwan',
        ];

        foreach ($aliases as $alias => $target) {
            $response = $this->withSession($this->session())->get($alias);
            $response->assertRedirectTo(base_url($target));
        }

        // Each protocol now has its own page under routing/, like OSPF.
        foreach (['bgp', 'rip', 'isis', 'bfd', 'vrf', 'multiwan'] as $page) {
            $this->withSession($this->session())->get('routing/' . $page)->assertOK();
        }

        // 'ospf' alias redirects to the dedicated 'routing/ospf' page
        $this->withSession($this->session())->get('ospf')->assertRedirectTo(base_url('routing/ospf'));
        $this->withSession($this->session())->get('routing/ospf')->assertOK();
    }

    public function testRoutingFIBWithLiveIpcData(): void
    {
        $fakeIpc = new FakeIpcClient([
            'diag.routing_table' => [
                'result' => [
                    'routes' => [
                        [
                            'destination' => '172.20.0.0/16',
                            'gateway'     => '192.168.1.254',
                            'interface'   => 'eth0',
                            'flags'       => 'UG',
                            'metric'      => 10,
                            'protocol'    => 'ospf',
                        ],
                    ],
                ],
                'error' => null,
            ],
            'frr.status' => [
                'result' => [
                    'running'   => true,
                    'enabled'   => true,
                    'version'   => 'FRRouting 9.1-test',
                    'uptime'    => '10d',
                    'bgp_as'    => '65001',
                    'router_id' => '1.1.1.1',
                ],
                'error' => null,
            ],
        ]);
        Services::injectMock('ipc', $fakeIpc);

        $response = $this->withSession($this->session())->get('routing');
        $response->assertOK();
        $response->assertSee('172.20.0.0/16');
        $response->assertSee('192.168.1.254');
        $response->assertSee('FRRouting 9.1-test');
    }

    public function testRoutingIndexWithEmptyApplianceDefaults(): void
    {
        // Simulate a fresh physical hardware install where frr.status returns empty router-id,
        // mock is false, and WAN gateways have unknown/null telemetry.
        $fakeIpc = new FakeIpcClient([
            'diag.routing_table' => [
                'result' => [
                    'routes' => [],
                ],
                'error' => null,
            ],
            'frr.status' => [
                'result' => [
                    'running'   => true,
                    'enabled'   => false,
                    'version'   => '',
                    'uptime'    => '',
                    'bgp_as'    => '',
                    'router_id' => '',
                ],
                'error' => null,
            ],
        ]);
        Services::injectMock('ipc', $fakeIpc);

        $response = $this->withSession($this->session())->get('routing');
        $response->assertOK();
        $response->assertSee('Dynamic Routing &amp; Multi-WAN');
        $response->assertDontSee('Whoops!');
    }
}
