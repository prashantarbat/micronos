<?php

namespace Tests\App\Controllers;

use App\Libraries\MulticastCompiler;
use App\Models\MulticastSettingsModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Multicast & IGMP module: compiler output, settings persistence and the
 * daemon-apply failure path.
 *
 * @internal
 */
final class MulticastModuleTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['multicast.view', 'multicast.edit', 'multicast.apply'];

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function sessionData(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params = []): array
    {
        $this->withSession($this->sessionData());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    private function injectIpc(array $responses): FakeIpcClient
    {
        $ipc = new FakeIpcClient($responses);
        Services::injectMock('ipc', $ipc);

        return $ipc;
    }

    // ------------------------------------------------------------------
    // Compiler — IGMP proxy
    // ------------------------------------------------------------------

    public function testCompilesIgmpProxyConfig(): void
    {
        $out = (new MulticastCompiler())->compileIgmpProxy([
            'enabled'            => 1,
            'igmp_proxy_enabled' => 1,
            'igmp_upstream'      => 'wan0',
            'igmp_downstreams'   => "lan0\nopt1",
            'igmp_altnets'       => '10.0.0.0/8',
            'igmp_quickleave'    => 1,
        ]);

        $this->assertStringContainsString('quickleave', $out);
        $this->assertStringContainsString('phyint wan0 upstream', $out);
        $this->assertStringContainsString('phyint lan0 downstream', $out);
        $this->assertStringContainsString('phyint opt1 downstream', $out);
        $this->assertStringContainsString('altnet 10.0.0.0/8;', $out);
    }

    public function testIgmpProxyRejectsInvalidInterfaces(): void
    {
        $warnings = [];
        $out = (new MulticastCompiler())->compileIgmpProxy([
            'enabled'            => 1,
            'igmp_proxy_enabled' => 1,
            'igmp_upstream'      => 'wan0; rm -rf /',
            'igmp_downstreams'   => 'lan0',
        ], $warnings);

        $this->assertSame('', $out, 'A malformed upstream interface must abort the config');
        $this->assertNotEmpty($warnings);
    }

    public function testIgmpProxyDownstreamCannotEqualUpstream(): void
    {
        $warnings = [];
        $out = (new MulticastCompiler())->compileIgmpProxy([
            'enabled'            => 1,
            'igmp_proxy_enabled' => 1,
            'igmp_upstream'      => 'wan0',
            'igmp_downstreams'   => 'wan0',
        ], $warnings);

        $this->assertSame('', $out);
        $this->assertNotEmpty($warnings);
    }

    public function testIgmpProxySkipsInvalidAltnets(): void
    {
        $warnings = [];
        $out = (new MulticastCompiler())->compileIgmpProxy([
            'enabled'            => 1,
            'igmp_proxy_enabled' => 1,
            'igmp_upstream'      => 'wan0',
            'igmp_downstreams'   => 'lan0',
            'igmp_altnets'       => "10.0.0.0/8\nnot-a-cidr\n192.168.0.0/16",
        ], $warnings);

        $this->assertStringContainsString('altnet 10.0.0.0/8;', $out);
        $this->assertStringContainsString('altnet 192.168.0.0/16;', $out);
        $this->assertStringNotContainsString('not-a-cidr', $out);
        $this->assertNotEmpty($warnings);
    }

    // ------------------------------------------------------------------
    // Compiler — PIM / IGMP
    // ------------------------------------------------------------------

    public function testCompilesPimCommands(): void
    {
        $out = (new MulticastCompiler())->compilePimCommands([
            'enabled'              => 1,
            'pim_enabled'          => 1,
            'pim_mode'             => 'sparse',
            'pim_rp_address'       => '10.0.0.1',
            'pim_interfaces'       => "lan0\nopt1",
            'igmp_querier_enabled' => 1,
            'igmp_version'         => 3,
        ]);

        $this->assertStringContainsString('router pim', $out);
        $this->assertStringContainsString('ip pim rp 10.0.0.1', $out);
        $this->assertStringContainsString('interface lan0', $out);
        $this->assertStringContainsString('ip igmp', $out);
        $this->assertStringContainsString('ip igmp version 3', $out);
        $this->assertStringContainsString('write memory', $out);
    }

    public function testSsmModeUsesSsmRangeNotRp(): void
    {
        $out = (new MulticastCompiler())->compilePimCommands([
            'enabled'        => 1,
            'pim_enabled'    => 1,
            'pim_mode'       => 'ssm',
            'pim_rp_address' => '10.0.0.1',
            'pim_ssm_range'  => '232.0.0.0/8',
            'pim_interfaces' => 'lan0',
        ]);

        $this->assertStringContainsString('ip pim ssm range 232.0.0.0/8', $out);
        $this->assertStringNotContainsString('ip pim rp ', $out);
    }

    public function testPimRejectsInvalidRpAddress(): void
    {
        $warnings = [];
        $out = (new MulticastCompiler())->compilePimCommands([
            'enabled'        => 1,
            'pim_enabled'    => 1,
            'pim_mode'       => 'sparse',
            'pim_rp_address' => 'not an ip',
            'pim_interfaces' => 'lan0',
        ], $warnings);

        $this->assertStringNotContainsString('ip pim rp', $out);
        $this->assertNotEmpty($warnings);
    }

    // ------------------------------------------------------------------
    // Compiler — snooping + mDNS
    // ------------------------------------------------------------------

    public function testSnoopingSysfsPlan(): void
    {
        $warnings = [];
        $plan = (new MulticastCompiler())->snoopingSysfs([
            'enabled'                 => 1,
            'snooping_enabled'        => 1,
            'snooping_bridge'         => 'br-lan',
            'snooping_querier'        => 1,
            'snooping_query_interval' => 125,
        ], $warnings);

        $this->assertSame('br-lan', $plan['bridge']);
        $this->assertSame('1', $plan['sysfs']['multicast_snooping']);
        $this->assertSame('1', $plan['sysfs']['multicast_querier']);
        // 125s expressed in centiseconds, as the kernel expects.
        $this->assertSame('12500', $plan['sysfs']['multicast_query_interval']);
        $this->assertEmpty($warnings);
    }

    public function testSnoopingRejectsInvalidBridge(): void
    {
        $warnings = [];
        $plan = (new MulticastCompiler())->snoopingSysfs([
            'enabled'          => 1,
            'snooping_enabled' => 1,
            'snooping_bridge'  => 'br lan; evil',
        ], $warnings);

        $this->assertSame([], $plan['sysfs']);
        $this->assertNull($plan['bridge']);
        $this->assertNotEmpty($warnings);
    }

    public function testMdnsRequiresTwoInterfaces(): void
    {
        $warnings = [];
        $mdns = (new MulticastCompiler())->compileMdns([
            'enabled'         => 1,
            'mdns_enabled'    => 1,
            'mdns_interfaces' => 'br-lan',
        ], $warnings);

        $this->assertSame('', $mdns['command']);
        $this->assertNotEmpty($warnings);
    }

    public function testMdnsCommandForTwoInterfaces(): void
    {
        $mdns = (new MulticastCompiler())->compileMdns([
            'enabled'         => 1,
            'mdns_enabled'    => 1,
            'mdns_interfaces' => "br-lan\nbr-iot",
            'mdns_mode'       => 'reflect',
        ]);

        $this->assertSame('mdns-repeater -f br-lan br-iot', $mdns['command']);
        $this->assertSame(['br-lan', 'br-iot'], $mdns['interfaces']);
    }

    // ------------------------------------------------------------------
    // Controller
    // ------------------------------------------------------------------

    public function testIndexRendersMulticastPage(): void
    {
        $result = $this->withSession($this->sessionData())->get('/multicast');
        $result->assertStatus(200);
        $result->assertSee('Multicast &amp; IGMP');
        $result->assertSee('IGMP Proxy');
        $result->assertSee('Multicast Routing');
        $result->assertSee('Bridge Snooping');
    }

    public function testIndexShowsFriendlyCapabilityLabelsButKeepsRealDaemonIds(): void
    {
        $result = $this->withSession($this->sessionData())->get('/multicast');
        $result->assertStatus(200);

        // Friendly names are what the operator reads…
        $result->assertSee('data-name="IGMP"');
        $result->assertSee('data-name="PIM"');
        $result->assertSee('data-name="mDNS"');

        // …while the control endpoints still target the real daemons.
        $result->assertSee('data-service="igmpproxy"');
        $result->assertSee('data-service="frr"');
        $result->assertSee('data-service="mdns-repeater"');
    }

    public function testSavePersistsAndAppliesConfig(): void
    {
        $ipc = $this->injectIpc([
            'multicast.apply' => ['result' => ['success' => true, 'status' => 'applied', 'steps' => [], 'warnings' => []], 'error' => null],
        ]);

        $result = $this->withSession($this->sessionData())->post('/multicast/save', $this->csrf([
            'enabled'            => '1',
            'igmp_proxy_enabled' => '1',
            'igmp_upstream'      => 'wan0',
            'igmp_downstreams'   => 'lan0',
            'pim_enabled'        => '1',
            'pim_mode'           => 'sparse',
            'pim_rp_address'     => '10.0.0.1',
            'pim_interfaces'     => 'lan0',
        ]));

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('multicast'));
        $result->assertSessionHas('success', 'Multicast & IGMP configuration saved and applied.');

        $settings = (new MulticastSettingsModel())->getSettings();
        $this->assertSame(1, (int) $settings['enabled']);
        $this->assertSame('wan0', $settings['igmp_upstream']);
        $this->assertSame('10.0.0.1', $settings['pim_rp_address']);

        $this->assertSame('multicast.apply', $ipc->calls[0]['method']);
        // The compiled IGMP proxy config travels with the apply call.
        $this->assertStringContainsString('phyint wan0 upstream', (string) $ipc->calls[0]['params']['igmp_proxy']['config']);
    }

    public function testSaveSurfacesApplyFailure(): void
    {
        $this->injectIpc([
            'multicast.apply' => [
                'result' => ['success' => false, 'status' => 'error', 'message' => 'vtysh: unknown command'],
                'error'  => null,
            ],
        ]);

        $result = $this->withSession($this->sessionData())->post('/multicast/save', $this->csrf([
            'enabled'      => '1',
            'pim_enabled'  => '1',
        ]));

        $result->assertStatus(302);
        $result->assertSessionHas('error', 'Multicast apply failed: vtysh: unknown command');
    }

    public function testApplyReportsSuccess(): void
    {
        $ipc = $this->injectIpc([
            'multicast.apply' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);

        $result = $this->withSession($this->sessionData())->post('/multicast/apply', $this->csrf());

        $result->assertStatus(302);
        $result->assertSessionHas('success', 'Multicast & IGMP configuration applied.');
        $this->assertSame('multicast.apply', $ipc->calls[0]['method']);
    }

    public function testApplyMirrorsDaemonRunStateIntoSystemServices(): void
    {
        $db = \Config\Database::connect();
        $db->table('system_services')->where('id', 'igmpproxy')->update(['status' => 'stopped', 'pid' => null]);
        $db->table('system_services')->where('id', 'mdns-repeater')->update(['status' => 'stopped', 'pid' => null]);

        $this->injectIpc([
            'multicast.apply' => [
                'result' => [
                    'success'  => true,
                    'status'   => 'applied',
                    'steps'    => [],
                    'warnings' => [],
                    // What micrond now reports after touching the daemons.
                    'services' => ['igmpproxy' => 'running', 'mdns-repeater' => 'stopped'],
                ],
                'error' => null,
            ],
        ]);

        $this->withSession($this->sessionData())->post('/multicast/apply', $this->csrf())->assertStatus(302);

        $igmp = $db->table('system_services')->where('id', 'igmpproxy')->get()->getRowArray();
        $mdns = $db->table('system_services')->where('id', 'mdns-repeater')->get()->getRowArray();

        $this->assertSame('running', $igmp['status']);
        $this->assertNull($igmp['pid'], 'micrond reported no pid, so none is invented');
        $this->assertSame('stopped', $mdns['status']);
        $this->assertNull($mdns['pid']);
    }

    public function testApplyFallsBackToRequestedStateWhenDaemonReportsNothing(): void
    {
        $db = \Config\Database::connect();
        $db->table('system_services')->where('id', 'igmpproxy')->update(['status' => 'running', 'pid' => 999]);

        // Disable the master switch: nothing should be reported running.
        (new MulticastSettingsModel())->saveSettings(['enabled' => 0, 'igmp_proxy_enabled' => 1]);

        $this->injectIpc([
            'multicast.apply' => ['result' => ['success' => true, 'status' => 'disabled'], 'error' => null],
        ]);

        $this->withSession($this->sessionData())->post('/multicast/apply', $this->csrf())->assertStatus(302);

        $igmp = $db->table('system_services')->where('id', 'igmpproxy')->get()->getRowArray();
        $this->assertSame('stopped', $igmp['status']);
        $this->assertNull($igmp['pid']);
    }

    public function testSaveClampsInvalidEnums(): void
    {
        $this->injectIpc([
            'multicast.apply' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);

        $this->withSession($this->sessionData())->post('/multicast/save', $this->csrf([
            'pim_mode'       => 'sideways',
            'mdns_mode'      => 'explode',
            'igmp_version'   => 9,
        ]))->assertStatus(302);

        $settings = (new MulticastSettingsModel())->getSettings();
        $this->assertSame('sparse', $settings['pim_mode']);
        $this->assertSame('reflect', $settings['mdns_mode']);
        $this->assertSame(3, (int) $settings['igmp_version']);
    }
}
