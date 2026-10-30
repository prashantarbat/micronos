<?php

declare(strict_types=1);

use App\Libraries\PackageManager;
use App\Plugins\PluginRegistry;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\CatalogueFixture;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Plugins page (Plugin Spec §9): the catalogue renders, a successful install
 * persists the plugin and its capability grants, a protected-core plugin is
 * refused without touching the database, and refresh reaches the daemon.
 *
 * @internal
 */
final class PluginsControllerTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['plugins.view', 'plugins.install', 'plugins.configure', 'plugins.repo.refresh'];

    private string $dir;

    /** @var list<string> */
    private array $tempCaches = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPluginTables();

        $this->dir = sys_get_temp_dir() . '/micron_pluginctl_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        // Deterministic catalogue: off, and pointed at a fresh cache file, so a
        // dev cache under writable/ can never affect these assertions.
        Services::injectMock('remoteCatalogue', CatalogueFixture::unconfigured($this->tempCache()));
    }

    protected function tearDown(): void
    {
        $this->clearPluginTables();
        foreach ($this->tempCaches as $cache) {
            @unlink($cache);
            @unlink($cache . '.part');
        }
        $this->tempCaches = [];
        foreach (glob($this->dir . '/*/plugin.json') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testIndexRendersTheCatalogue(): void
    {
        $result = $this->withSession($this->session())->get('plugins');

        $result->assertOK();
        $result->assertSee('Plugins &amp; Packages');
        $result->assertSee('LLDP Neighbour Discovery');
        $result->assertSee('lldpd');
    }

    public function testInstallPersistsThePluginAndItsGrants(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.install' => ['result' => ['success' => true, 'status' => 'applied', 'steps' => []], 'error' => null],
        ]));

        $this->post('plugins/lldpd/install', $this->csrf([
            'grants' => ['system.service:lldpd'],
        ]))->assertRedirect();

        $row = $this->db->table('plugins')->where('id', 'lldpd')->get()->getRowArray();
        $this->assertNotNull($row, 'The plugin row was not persisted.');
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame('B', $row['class']);

        $grants = $this->db->table('plugin_grants')->where('plugin_id', 'lldpd')->get()->getResultArray();
        $this->assertCount(9, $grants, 'Every declared capability is recorded, granted or not.');

        $granted = array_values(array_filter($grants, static fn ($g) => (int) $g['granted'] === 1));
        $this->assertCount(1, $granted);
        $this->assertSame('system.service:lldpd', $granted[0]['capability']);
    }

    public function testInstallOfABlockedPluginWritesNothing(): void
    {
        $this->writePlugin('evil', [
            'schema'       => 1,
            'id'           => 'evil',
            'name'         => 'Evil',
            'version'      => '1.0.0',
            'class'        => 'B',
            'requires'     => ['packages' => ['nftables']],
            'capabilities' => [],
            'lifecycle'    => [['step' => 'install', 'action' => 'apt.install', 'packages' => ['nftables']]],
        ]);

        Services::injectMock('packageManager', new PackageManager(new FakeIpcClient(), new PluginRegistry($this->dir)));

        $result = $this->post('plugins/evil/install', $this->csrf([]));
        $result->assertRedirect();
        $result->assertSessionHas('error');

        $this->assertSame(0, $this->db->table('plugins')->where('id', 'evil')->countAllResults());
    }

    public function testRefreshDispatchesToTheDaemon(): void
    {
        $ipc = new FakeIpcClient([
            'plugin.repo.refresh' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->post('plugins/refresh', $this->csrf([]))->assertRedirect();

        $this->assertSame('plugin.repo.refresh', $ipc->calls[0]['method']);
    }

    public function testIndexRendersFullDaemonControlsForInstalledPlugins(): void
    {
        $this->installRow('lldpd');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'status' => 'ok', 'state' => 'running'], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins');

        $result->assertOK();
        $result->assertSee('Plugin daemons');
        $result->assertSee('plugins/daemons');
        $result->assertSee('data-service="lldpd"');
        // Full start / restart / stop, not just Restart.
        $result->assertSee('data-action="start"');
        $result->assertSee('data-action="restart"');
        $result->assertSee('data-action="stop"');
    }

    public function testDaemonControlDispatchesActionForAnInstalledPlugin(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient([
            'plugin.service' => ['result' => [
                'success' => true, 'status' => 'applied', 'id' => 'lldpd', 'unit' => 'lldpd', 'action' => 'start', 'state' => 'running',
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/daemons/lldpd/control', $this->csrf(['action' => 'start']));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true, 'service' => 'lldpd', 'status' => 'running']);
        $this->assertSame('plugin.service', $ipc->calls[0]['method']);
        $this->assertSame('lldpd', $ipc->calls[0]['params']['id']);
        $this->assertSame('start', $ipc->calls[0]['params']['action']);
    }

    public function testDaemonControlRefusesBadInput(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        // Invalid action → 422, no daemon call.
        $this->post('plugins/daemons/lldpd/control', $this->csrf(['action' => 'explode']))->assertStatus(422);
        // Unknown plugin → 404.
        $this->post('plugins/daemons/ghost/control', $this->csrf(['action' => 'start']))->assertStatus(404);
        // Known plugin that has no daemon unit → 422.
        $this->installRow('lightsquid');
        $this->post('plugins/daemons/lightsquid/control', $this->csrf(['action' => 'start']))->assertStatus(422);
        // Not installed → 404.
        $this->post('plugins/daemons/darkstat/control', $this->csrf(['action' => 'start']))->assertStatus(404);

        $this->assertSame([], array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.service'));
    }

    public function testDaemonStatusReportsStateOnlyForInstalledPluginDaemons(): void
    {
        $this->installRow('lldpd');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'status' => 'ok', 'state' => 'running'], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins/daemons/status?ids=lldpd,darkstat,ghost');

        $result->assertOK();
        $json = json_decode((string) $result->getJSON(), true);
        $this->assertTrue($json['ok']);
        // darkstat is available but not installed; ghost is unknown.
        $this->assertSame(['lldpd' => 'running', 'darkstat' => 'unknown', 'ghost' => 'unknown'], $json['services']);
    }

    public function testDaemonLogsReturnsJournalForAnInstalledPlugin(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient([
            'plugin.logs' => ['result' => ['success' => true, 'status' => 'ok', 'logs' => 'lldpd ready'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/daemons/lldpd/logs');

        $result->assertOK();
        $result->assertJSONFragment(['logs' => 'lldpd ready']);
        $this->assertSame('plugin.logs', $ipc->calls[0]['method']);
        $this->assertSame('lldpd', $ipc->calls[0]['params']['id']);

        // A plugin that isn't installed has no daemon journal.
        $this->withSession($this->session())->get('plugins/daemons/darkstat/logs')->assertStatus(404);
    }

    public function testPluginPageRendersForAnInstalledPlugin(): void
    {
        // A plugin with no page of its own falls back to the generic metadata
        // page — every plugin the image currently ships has a page, so this
        // uses one from the test registry.
        $this->writePlugin('demo', [
            'schema'       => 1,
            'id'           => 'demo',
            'name'         => 'Demo Plugin',
            'version'      => '1.0.0',
            'class'        => 'B',
            'vendor'       => 'Micron Network Systems',
            'description'  => 'A plugin with no page of its own.',
            'requires'     => ['packages' => ['demo'], 'repos' => [], 'kernel_modules' => [], 'binaries' => ['/usr/bin/demo']],
            'provides'     => ['services' => ['demo'], 'menu' => [], 'status_source' => 'rpc:plugin.status'],
            'capabilities' => ['system.service:demo'],
            'lifecycle'    => [
                ['step' => 'install', 'action' => 'apt.install', 'packages' => ['demo']],
                ['step' => 'enable', 'action' => 'system.enable', 'unit' => 'demo'],
                ['step' => 'start', 'action' => 'system.start', 'unit' => 'demo'],
                ['step' => 'uninstall', 'action' => 'apt.remove', 'packages' => ['demo']],
            ],
        ]);
        $this->installRow('demo');
        Services::injectMock('packageManager', new PackageManager(new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'status' => 'ok', 'state' => 'running'], 'error' => null],
        ]), new PluginRegistry($this->dir)));

        $result = $this->withSession($this->session())->get('plugins/demo');

        $result->assertOK();
        $result->assertSee('Demo Plugin');
        $result->assertSee('Declared capabilities');
        $result->assertSee('demo');
        // The plugin's own daemon gets the live control widget.
        $result->assertSee('data-service="demo"');
        $result->assertSee('data-action="start"');
    }

    public function testPluginPageIsNotFoundForAnUninstalledPlugin(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->withSession($this->session())->get('plugins/darkstat');
    }

    public function testIperf3PluginPageRendersTheRunner(): void
    {
        $this->installRow('iperf3');
        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'status' => 'ok', 'state' => 'running'], 'error' => null],
        ]));

        $result = $this->withSession($this->session())->get('plugins/iperf3');

        $result->assertOK();
        // The plugin's declared menu entry lands on its own runner, not the
        // generic metadata page.
        $result->assertSee('Client throughput test');
        $result->assertSee('On-demand server');
        $result->assertSee('plugins/iperf3/run');
    }

    public function testIperf3RunDispatchesToTheDaemon(): void
    {
        $this->installRow('iperf3');

        $ipc = new FakeIpcClient([
            'plugin.iperf3.run' => ['result' => [
                'success' => true, 'status' => 'ok', 'mode' => 'client', 'target' => '203.0.113.10',
                'port' => 5201, 'protocol' => 'tcp', 'direction' => 'upload', 'mbps' => 941.2,
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/iperf3/run', $this->csrf([
            'mode' => 'client', 'target' => '203.0.113.10', 'port' => 5201, 'duration' => 10,
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.iperf3.run', $ipc->calls[0]['method']);
        $this->assertSame('203.0.113.10', $ipc->calls[0]['params']['target']);
        $this->assertSame('client', $ipc->calls[0]['params']['mode']);
    }

    public function testIperf3RunRefusesWhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $this->post('plugins/iperf3/run', $this->csrf(['mode' => 'client', 'target' => '10.0.0.1']))->assertStatus(404);
        $this->assertSame([], array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.iperf3.run'));
    }

    public function testUpdatesScreenListsInstalledPlugins(): void
    {
        $this->installRow('lldpd');

        $result = $this->withSession($this->session())->get('plugins/updates');

        $result->assertOK();
        $result->assertSee('Plugin Updates &amp; Upgrades');
        $result->assertSee('LLDP Neighbour Discovery');
        $result->assertSee('UP TO DATE');
        $result->assertSee('Upgrade All');
    }

    public function testUpgradeAllDispatchesForEveryInstalledPlugin(): void
    {
        $this->installRow('lldpd');
        $this->installRow('iperf3');

        $ipc = new FakeIpcClient([
            'plugin.upgrade' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->post('plugins/upgrade-all', $this->csrf([]))->assertRedirect();

        $upgraded = array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.upgrade');
        $this->assertCount(2, $upgraded);
    }

    public function testNavRendersInstalledPluginMenuEntriesAndUpdates(): void
    {
        $this->installRow('lldpd');

        $result = $this->withSession($this->session())->get('plugins');

        // The plugin's declared `provides.menu` label appears in the navigation,
        // alongside the new Updates entry.
        $result->assertSee('LLDP Neighbours');
        $result->assertSee('Updates &amp; Upgrades');
        $result->assertSee('plugins/updates');
    }

    public function testUpdatesScreenShowsTheSignedCatalogueVersion(): void
    {
        $this->installRow('iperf3');

        $key    = CatalogueFixture::keypair();
        $body   = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $remote = CatalogueFixture::remoteCatalogue($key, $body, $this->tempCache());
        $this->assertTrue($remote->sync()['ok']);
        Services::injectMock('remoteCatalogue', $remote);

        $result = $this->withSession($this->session())->get('plugins/updates');

        $result->assertOK();
        // The catalogue's 1.1.0 outbids the shipped 1.0.0.
        $result->assertSee('1.1.0');
        $result->assertSee('FROM CATALOGUE');
        $result->assertSee('CATALOGUE VERIFIED');
        $result->assertSee('UPDATE AVAILABLE');
    }

    public function testUpgradeRecordsTheCatalogueVersion(): void
    {
        $this->installRow('iperf3');

        $key    = CatalogueFixture::keypair();
        $body   = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $remote = CatalogueFixture::remoteCatalogue($key, $body, $this->tempCache());
        $remote->sync();
        Services::injectMock('remoteCatalogue', $remote);

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.upgrade' => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]));

        $this->post('plugins/iperf3/upgrade', $this->csrf([]))->assertRedirect();

        // The installed row moves to the catalogue's version, not the shipped 1.0.0.
        $row = $this->db->table('plugins')->where('id', 'iperf3')->get()->getRowArray();
        $this->assertSame('1.1.0', $row['version']);
    }

    public function testSyncCatalogueEndpointFlashesSuccessForAVerifiedFeed(): void
    {
        $key    = CatalogueFixture::keypair();
        $body   = CatalogueFixture::envelope(CatalogueFixture::catalogue(), $key);
        $remote = CatalogueFixture::remoteCatalogue($key, $body, $this->tempCache());
        Services::injectMock('remoteCatalogue', $remote);

        $result = $this->post('plugins/catalogue/sync', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    public function testSyncCatalogueEndpointRefusesAnUnsignedFeed(): void
    {
        $key    = CatalogueFixture::keypair();
        $body   = (string) json_encode(['schema' => 1, 'plugins' => []]);
        $remote = CatalogueFixture::remoteCatalogue($key, $body, $this->tempCache());
        Services::injectMock('remoteCatalogue', $remote);

        $result = $this->post('plugins/catalogue/sync', $this->csrf([]));

        $result->assertRedirect();
        $result->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // Class C (the NetBird overlay)
    // ------------------------------------------------------------------

    public function testAClassCPluginIsBlockedUntilItsRepositoryIsApplied(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        // Fresh appliance: the seeded preset row is disabled, so the manifest's
        // `requires.repos` cannot resolve and the plugin is not installable.
        $this->seedPresetRow(enabled: false);

        $page = $this->withSession($this->session())->get('plugins');
        $page->assertOK();
        // assertSee() compares against a DOM re-serialization, so entities come
        // back decoded: the escaped quote in the view is a plain one here.
        $page->assertSee('repository "netbird" is required by the manifest but is not registered and enabled');
        // A blocked Class C plugin names a next step, not a dead end.
        $page->assertSee('Register the NetBird (pkgs.netbird.io) preset on the Repositories page');
        $page->assertSee('Open Repositories');

        $manager = new PackageManager();

        $blocked = $manager->plan('netbird');
        $this->assertFalse($blocked['ok']);
        $this->assertSame(['netbird'], $blocked['repos']);

        // Enabling the repository (the operator's consent to talk to the vendor)
        // is what makes the third-party packages resolvable.
        $this->db->table('plugin_repos')->where('id', 'netbird')->update(['enabled' => 1]);

        $plan = $manager->plan('netbird');
        $this->assertTrue($plan['ok'], implode(' ', $plan['blocked']));
        $this->assertNull($plan['preset_hint']);
    }

    /**
     * The shipped `zerotier` preset is bound to the `zerotier` manifest, and the
     * binding is two-sided: the manifest's `requires.repos` names the repository,
     * and the preset's `plugins` names the manifest. Without the manifest the
     * preset would be trust data for nothing; without the repository the plugin
     * is blocked. Enabling it is what makes the vendor package resolvable.
     */
    public function testTheZerotierPresetUnblocksTheZerotierPlugin(): void
    {
        $this->seedPresetRow(enabled: false, id: 'zerotier');

        $manager = new PackageManager();

        $blocked = $manager->plan('zerotier');
        $this->assertFalse($blocked['ok']);
        $this->assertSame(['zerotier'], $blocked['repos']);

        // The manifest on one side names the repository...
        $this->assertSame(['zerotier'], (new PluginRegistry())->get('zerotier')->repos());
        // ...and the preset on the other side names the plugin, so the shipped
        // trust data can never be orphaned from what it is trust *for*.
        $this->assertSame(['zerotier'], array_column((new \App\Libraries\RepoPresets())->for('zerotier'), 'id'));

        // The operator's consent to talk to the vendor is what unblocks it.
        $this->db->table('plugin_repos')->where('id', 'zerotier')->update(['enabled' => 1]);

        $plan = $manager->plan('zerotier');
        $this->assertTrue($plan['ok'], implode(' ', $plan['blocked']));
    }

    public function testInstallingAClassCPluginRequiresTheClassCConsent(): void
    {
        $this->seedPresetRow(enabled: true);

        $ipc = new FakeIpcClient([
            'plugin.install' => ['result' => ['success' => true, 'status' => 'applied', 'id' => 'netbird'], 'error' => null],
        ]);
        // The real shipped catalogue (this is about the manifest's class), with
        // the daemon faked so an install can succeed without a data plane.
        Services::injectMock('packageManager', new PackageManager($ipc));
        Services::injectMock('ipc', $ipc);

        // Approving capabilities is not enough for Class C: the third-party
        // repository, the external identity and the routing change need their
        // own acknowledgement, enforced here rather than only in the dialog.
        $refused = $this->post('plugins/netbird/install', $this->csrf([]));


        $refused->assertRedirect();
        $refused->assertSessionHas('error');
        $this->assertSame([], array_filter($ipc->calls, static fn ($c) => $c['method'] === 'plugin.install'));
        $this->assertSame(0, $this->db->table('plugins')->where('id', 'netbird')->countAllResults());

        $allowed = $this->post('plugins/netbird/install', $this->csrf(['class_c_ack' => '1']));

        $allowed->assertRedirect();
        $allowed->assertSessionHas('success');
        $this->assertSame(1, $this->db->table('plugins')->where('id', 'netbird')->countAllResults());
    }

    public function testTheConsentDialogShowsTheClassCNoticeForClassCPluginsOnly(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $page = $this->withSession($this->session())->get('plugins');

        $page->assertOK();
        $page->assertSee('plugClassC');
        $page->assertSee('Third-party repository, external identity, live routing');
        $page->assertSee('name="class_c_ack"');
        $page->assertSee('plugPlanRepos');
        $page->assertSee('I understand this installs packages from a third-party repository');
        // The dialog decides per plugin, from the plan's class.
        $page->assertSee("String(plan.class || '').toUpperCase() === 'C'");
    }

    public function testTheNetbirdPageRendersTheOverlaySurface(): void
    {
        $this->installRow('netbird');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'status' => 'ok', 'state' => 'running'], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/netbird');

        $page->assertOK();
        // The view must render *inside* the shared layout. The raw view is a
        // valid 200 response on its own, so a forgotten extend() is invisible to
        // the content assertions below — it only shows up as an unstyled page
        // with no navigation. The <title> is the layout's, not the view's.
        $page->assertSee('NetBird Mesh VPN — Plugins — MicronOS', 'title');
        $page->assertSee('NetBird Mesh VPN');
        $page->assertSee('CLASS C');
        $page->assertSee('This is a Class C extension');
        $page->assertSee('plugins/netbird/status');
        $page->assertSee('plugins/netbird/control');
        // Enrolment takes the setup key through the daemon, not through storage.
        $page->assertSee('nbSetupKey');
        $page->assertSee("never written to the appliance's configuration database");
        // The agent's own unit is controllable from the page.
        $page->assertSee('data-service="netbird"');
    }

    public function testTheNetbirdPageIs404ForAPluginThatIsNotInstalled(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->withSession($this->session())->get('plugins/netbird');
    }

    public function testNetbirdStatusReportsTheOverlayThroughTheDaemon(): void
    {
        $this->installRow('netbird');

        $ipc = new FakeIpcClient([
            'plugin.netbird.status' => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'netbird', 'state' => 'running',
                'data' => ['fqdn' => 'appliance.netbird.cloud', 'management_connected' => true, 'peers_total' => 3, 'peers_connected' => 2],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/netbird/status');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.netbird.status', $ipc->calls[0]['method']);
    }

    public function testNetbirdStatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/netbird/status');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testEnrollingPassesTheSetupKeyToTheDaemonAndNeverEchoesItBack(): void
    {
        $this->installRow('netbird');

        $secret = 'A1B2C3D4E5F6G7H8';
        $ipc = new FakeIpcClient([
            'plugin.netbird.enroll' => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'netbird', 'state' => 'running',
                'message' => 'setup key submitted; the peer is enrolling',
                'data' => ['fqdn' => 'appliance.netbird.cloud'],
                // A daemon that echoed the key back would be a leak; the response
                // below must not surface it either.
                'steps' => ['netbird up --setup-key-file <staged>'],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/netbird/control', $this->csrf([
            'action'    => 'enroll',
            'setup_key' => $secret,
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);

        // The key reached the daemon...
        $this->assertSame('plugin.netbird.enroll', $ipc->calls[0]['method']);
        $this->assertSame($secret, $ipc->calls[0]['params']['setup_key']);

        // ...and never came back to the browser.
        $this->assertStringNotContainsString($secret, (string) $result->getBody());
    }

    public function testNetbirdControlRefusesAnUnknownAction(): void
    {
        $this->installRow('netbird');

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/netbird/control', $this->csrf(['action' => 'destroy']));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => false]);
        // Nothing reaches the daemon for an action the overlay does not have.
        $this->assertSame([], $ipc->calls);
    }

    public function testTheZerotierPageRendersTheOverlaySurface(): void
    {
        $this->installRow('zerotier');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'status' => 'ok', 'state' => 'running'], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/zerotier');

        $page->assertOK();
        // The view must render *inside* the shared layout. The raw view is a
        // valid 200 response on its own, so a forgotten extend() is invisible to
        // the content assertions below — it only shows up as an unstyled page
        // with no navigation. The <title> is the layout's, not the view's.
        $page->assertSee('ZeroTier One — Plugins — MicronOS', 'title');
        $page->assertSee('ZeroTier Overlay');
        $page->assertSee('CLASS C');
        $page->assertSee('This is a Class C extension');
        $page->assertSee('plugins/zerotier/status');
        $page->assertSee('plugins/zerotier/control');
        // Joining takes a network id and nothing else.
        $page->assertSee('ztNwid');
        $page->assertSee("[0-9a-fA-F]{16}");
        // The agent's own unit is controllable from the page.
        $page->assertSee('data-service="zerotier-one"');
    }

    public function testTheZerotierPageIs404ForAPluginThatIsNotInstalled(): void
    {
        Services::injectMock('ipc', new FakeIpcClient());

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->withSession($this->session())->get('plugins/zerotier');
    }

    public function testZerotierStatusReportsTheOverlayThroughTheDaemon(): void
    {
        $this->installRow('zerotier');

        $ipc = new FakeIpcClient([
            'plugin.zerotier.status' => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'zerotier', 'state' => 'running',
                'data' => [
                    'address' => 'a0c8f1e2b3', 'version' => '1.14.0', 'online' => true,
                    'networks_joined' => 1,
                    'networks' => [['nwid' => '8056c2e21c000001', 'name' => 'micron', 'status' => 'OK']],
                ],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/zerotier/status');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        // The membership list travels through to the browser: the page decides
        // what to render from these bytes.
        $this->assertStringContainsString('8056c2e21c000001', (string) $result->getBody());
        $this->assertStringContainsString('"networks_joined": 1', (string) $result->getBody());
        $this->assertSame('plugin.zerotier.status', $ipc->calls[0]['method']);
    }

    public function testZerotierStatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/zerotier/status');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testJoiningAndLeavingANetworkGoThroughTheDaemon(): void
    {
        $this->installRow('zerotier');

        $nwid = '8056c2e21c000001';
        $ipc = new FakeIpcClient([
            'plugin.zerotier.join'  => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'zerotier', 'state' => 'running',
                'message' => 'join submitted; the agent is applying it',
                'data' => ['address' => 'a0c8f1e2b3', 'networks_joined' => 1,
                           'networks' => [['nwid' => $nwid, 'name' => 'micron', 'status' => 'OK']]],
            ], 'error' => null],
            'plugin.zerotier.leave' => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'zerotier', 'state' => 'running',
                'message' => 'leave submitted; the agent is applying it',
                'data' => ['address' => 'a0c8f1e2b3', 'networks_joined' => 0, 'networks' => []],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $joined = $this->post('plugins/zerotier/control', $this->csrf(['action' => 'join', 'nwid' => $nwid]));
        $joined->assertOK();
        $joined->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.zerotier.join', $ipc->calls[0]['method']);
        $this->assertSame($nwid, $ipc->calls[0]['params']['nwid']);

        $left = $this->post('plugins/zerotier/control', $this->csrf(['action' => 'leave', 'nwid' => $nwid]));
        $left->assertOK();
        $left->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.zerotier.leave', $ipc->calls[1]['method']);
        $this->assertSame($nwid, $ipc->calls[1]['params']['nwid']);
    }

    public function testZerotierControlRefusesAnUnknownAction(): void
    {
        $this->installRow('zerotier');

        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/zerotier/control', $this->csrf(['action' => 'leave-all']));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => false]);
        // Nothing reaches the daemon for an action the overlay does not have.
        $this->assertSame([], $ipc->calls);
    }

    public function testTheNat64PageRendersTheTranslatorSurface(): void
    {
        $this->installRow('nat64');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'state' => 'running'], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/nat64');

        $page->assertOK();
        // Renders inside the shared layout, not as a bare view.
        $page->assertSee('NAT64 / DNS64 — Plugins — MicronOS', 'title');
        $page->assertSee('CLASS B');
        $page->assertSee('RFC 6052');
        $page->assertSee('64:ff9b::/96');
        $page->assertSee('plugins/nat64/status');
        $page->assertSee('plugins/nat64/apply');
    }

    public function testNat64StatusReportsTheTranslatorThroughTheDaemon(): void
    {
        $this->installRow('nat64');

        $ipc = new FakeIpcClient([
            'plugin.nat64.status' => ['result' => [
                'success' => true, 'state' => 'running', 'id' => 'nat64',
                'data' => ['prefix' => '64:ff9b::/96', 'tayga_state' => 'running', 'enabled' => true],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/nat64/status');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.nat64.status', $ipc->calls[0]['method']);
    }

    public function testNat64StatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/nat64/status');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testNat64ApplyDispatchesToTheDaemonAndReloadsTheFirewall(): void
    {
        $this->installRow('nat64');

        $ipc = new FakeIpcClient([
            'plugin.nat64.apply'  => ['result' => ['success' => true, 'message' => 'translator configured'], 'error' => null],
            'firewall.apply'      => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/nat64/apply', $this->csrf([
            'enabled' => '1',
            'prefix'  => '2001:db8:64::/96',
            'pool'    => '192.168.255.0/24',
            'dns64'   => '1',
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);

        $this->assertSame('plugin.nat64.apply', $ipc->calls[0]['method']);
        $this->assertSame('2001:db8:64::/96', $ipc->calls[0]['params']['prefix']);

        // The translator's interface only becomes reachable once the compiler
        // has emitted its accept, so the new ruleset is pushed right after.
        $this->assertSame('firewall.apply', $ipc->calls[1]['method']);
        $this->assertFalse($ipc->calls[1]['params']['safe_mode']);
        $this->assertStringContainsString('iifname "nat64"', (string) $ipc->calls[1]['params']['ruleset']);
    }

    public function testTunnelApplyForwardsTheUpdateKeyOnceAndNeverEchoesItBack(): void
    {
        $this->installRow('ipv6-tunnel');

        $secret = 'A1B2C3D4E5F6G7H8';
        $ipc = new FakeIpcClient([
            'plugin.tunnel.apply' => ['result' => ['success' => true, 'message' => 'tunnel configured'], 'error' => null],
            'firewall.apply'      => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/ipv6-tunnel/apply', $this->csrf([
            'enabled'     => '1',
            'broker'      => 'tunnelbroker.net',
            'endpoint_v4' => '216.66.80.30',
            'local_v4'    => 'auto',
            'client_v6'   => '2001:470:1f08:a2a::2/64',
            'server_v6'   => '2001:470:1f09:a2a::1',
            'update_user' => 'micron',
            'update_key'  => $secret,
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);

        $this->assertSame('plugin.tunnel.apply', $ipc->calls[0]['method']);
        $this->assertSame($secret, $ipc->calls[0]['params']['update_key']);
        // ...and never came back to the browser.
        $this->assertStringNotContainsString($secret, (string) $result->getBody());

        $this->assertSame('firewall.apply', $ipc->calls[1]['method']);
        $this->assertStringContainsString('oifname "sit1"', (string) $ipc->calls[1]['params']['ruleset']);
    }

    public function testTunnelApplyLeavesTheStoredKeyAloneWhenNoKeyIsTyped(): void
    {
        $this->installRow('ipv6-tunnel');

        $ipc = new FakeIpcClient([
            'plugin.tunnel.apply' => ['result' => ['success' => true, 'message' => 'tunnel configured'], 'error' => null],
            'firewall.apply'      => ['result' => ['success' => true, 'status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/ipv6-tunnel/apply', $this->csrf([
            'enabled'     => '1',
            'endpoint_v4' => '216.66.80.30',
            'client_v6'   => '2001:470:1f08:a2a::2/64',
            'server_v6'   => '2001:470:1f09:a2a::1',
        ]));

        $result->assertOK();
        // An absent field means "keep the stored key": the daemon must not see
        // an empty one, which would clear it.
        $this->assertSame('plugin.tunnel.apply', $ipc->calls[0]['method']);
        $this->assertArrayNotHasKey('update_key', $ipc->calls[0]['params']);
    }

    public function testTunnelStatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/ipv6-tunnel/status');

        $result->assertStatus(404);
        $this->assertSame([], $ipc->calls);
    }

    public function testTunnelUpdateContactsTheBrokerThroughTheDaemon(): void
    {
        $this->installRow('ipv6-tunnel');

        $ipc = new FakeIpcClient([
            'plugin.tunnel.update' => ['result' => ['success' => true, 'message' => 'broker updated the endpoint'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/ipv6-tunnel/update', $this->csrf([]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.tunnel.update', $ipc->calls[0]['method']);
        // The broker call changes no interface, so the firewall is left alone.
        $this->assertCount(1, $ipc->calls);
    }

    public function testNat64ApplyIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/nat64/apply', $this->csrf([
            'enabled' => '1',
            'prefix'  => '64:ff9b::/96',
            'pool'    => '192.168.255.0/24',
        ]));

        $result->assertStatus(404);
        $this->assertSame([], $ipc->calls);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The row the image seeds for the NetBird preset repository, in whichever
     * enablement state a test needs.
     */
    public function testTheDarkstatPageRendersTheSettingsForm(): void
    {
        $this->installRow('darkstat');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'state' => 'running'], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/darkstat');

        $page->assertOK();
        // Renders inside the shared layout, not as a bare view.
        $page->assertSee('darkstat Traffic Statistics — Plugins — MicronOS', 'title');
        $page->assertSee('CLASS B');
        // The settings the request is about: a port to bind the web UI to, plus
        // the rest of the analyser's configuration.
        $page->assertSee('Web UI port');
        $page->assertSee('Capture interface(s)');
        $page->assertSee('Local networks');
        $page->assertSee('Capture filter (BPF)');
        $page->assertSee('Database directory (chroot)');
        $page->assertSee('Hosts retained when full');
        $page->assertSee('plugins/darkstat/status');
        $page->assertSee('plugins/darkstat/apply');
    }

    public function testDarkstatStatusReportsThroughTheDaemon(): void
    {
        $this->installRow('darkstat');

        $ipc = new FakeIpcClient([
            'plugin.darkstat.status' => ['result' => [
                'success' => true, 'state' => 'running', 'id' => 'darkstat',
                'data' => ['port' => 666, 'interfaces' => ['eth0'], 'dropin_present' => true],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/darkstat/status');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.darkstat.status', $ipc->calls[0]['method']);
    }

    public function testDarkstatStatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/darkstat/status');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testDarkstatApplyForwardsTheSettingsToTheDaemon(): void
    {
        $this->installRow('darkstat');

        $ipc = new FakeIpcClient([
            'plugin.darkstat.apply' => ['result' => ['success' => true, 'message' => 'darkstat is capturing'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/darkstat/apply', $this->csrf([
            'enabled'       => '1',
            'interface'     => 'eth0, eth1',
            'port'          => '8080',
            'local_nets'    => '192.168.0.0/16',
            'filter'        => 'not arp',
            'resolve_dns'   => '0',
            'local_only'    => '1',
            'chroot_dir'    => '/var/lib/darkstat',
            'database_file' => 'darkstat.db',
            'daylog'        => 'darkstat-day.log',
            'hosts_max'     => '2000',
            'hosts_keep'    => '20',
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);

        $this->assertSame('plugin.darkstat.apply', $ipc->calls[0]['method']);
        $params = $ipc->calls[0]['params'];
        $this->assertSame('eth0, eth1', $params['interface']);
        $this->assertSame(8080, $params['port']);
        $this->assertSame('192.168.0.0/16', $params['local_nets']);
        $this->assertSame('not arp', $params['filter']);
        $this->assertFalse($params['resolve_dns']);
        $this->assertTrue($params['local_only']);
        $this->assertSame(2000, $params['hosts_max']);
        $this->assertSame(20, $params['hosts_keep']);
    }

    public function testDarkstatApplyOpensItsWebPortOnTheLanAndClosesItWhenDisabled(): void
    {
        $this->installRow('darkstat');

        $ipc = new FakeIpcClient([
            'plugin.darkstat.apply' => ['result' => ['success' => true, 'message' => 'darkstat is capturing'], 'error' => null],
            'firewall.apply'        => ['result' => ['status' => 'applied'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->post('plugins/darkstat/apply', $this->csrf(['enabled' => '1', 'interface' => 'enp7s0', 'port' => '666']))
            ->assertOK();

        $row = $this->db->table('plugin_settings')->where('plugin_id', 'darkstat')->where('setting_key', 'web_port')->get()->getRowArray();
        $this->assertSame('666', (string) ($row['value'] ?? ''), 'the web port is recorded for the firewall compiler');

        $fw = array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'firewall.apply'));
        $this->assertCount(1, $fw, 'the ruleset is reloaded so the port is reachable');
        $this->assertMatchesRegularExpression('/tcp dport 666 accept comment "darkstat web UI \(plugin\)/', $fw[0]['params']['ruleset']);
        $this->assertDoesNotMatchRegularExpression('/iifname "eth0" tcp dport 666/', $fw[0]['params']['ruleset'], 'never opened on the WAN');

        // Applying the same port again does not reload the firewall.
        $this->post('plugins/darkstat/apply', $this->csrf(['enabled' => '1', 'interface' => 'enp7s0', 'port' => '666']))->assertOK();
        $this->assertCount(1, array_filter($ipc->calls, static fn ($c) => $c['method'] === 'firewall.apply'));

        // Disabling closes it again.
        $this->post('plugins/darkstat/apply', $this->csrf(['enabled' => '0', 'interface' => 'enp7s0', 'port' => '666']))->assertOK();
        $this->assertNull($this->db->table('plugin_settings')->where('plugin_id', 'darkstat')->where('setting_key', 'web_port')->get()->getRowArray());
        $fw = array_values(array_filter($ipc->calls, static fn ($c) => $c['method'] === 'firewall.apply'));
        $this->assertCount(2, $fw);
        $this->assertStringNotContainsString('dport 666', $fw[1]['params']['ruleset']);
    }

    public function testTheLightsquidPageRendersTheSettingsForm(): void
    {
        $this->installRow('lightsquid');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'state' => 'ok'], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/lightsquid');

        $page->assertOK();
        // Renders inside the shared layout, not as a bare view.
        $page->assertSee('Lightsquid Report — Plugins — MicronOS', 'title');
        $page->assertSee('CLASS B');
        // The settings pfSense's Lightsquid package exposes: the report look,
        // the IP resolve method, the skip list and the refresh schedule.
        $page->assertSee('Report language');
        $page->assertSee('Report template');
        $page->assertSee('Bar colour');
        $page->assertSee('IP resolve method');
        $page->assertSee('Skip URL(s)');
        $page->assertSee('Refresh schedule');
        $page->assertSee('Squid log directory');
        $page->assertSee('plugins/lightsquid/status');
        $page->assertSee('plugins/lightsquid/apply');
        $page->assertSee('plugins/lightsquid/refresh');
    }

    public function testLightsquidStatusReportsThroughTheDaemon(): void
    {
        $this->installRow('lightsquid');

        $ipc = new FakeIpcClient([
            'plugin.lightsquid.status' => ['result' => [
                'success' => true, 'state' => 'configured', 'id' => 'lightsquid',
                'data' => ['config_present' => true, 'cron_present' => true, 'lang' => 'eng', 'schedule' => '60m'],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lightsquid/status');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.lightsquid.status', $ipc->calls[0]['method']);
    }

    public function testLightsquidStatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lightsquid/status');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testLightsquidApplyForwardsTheSettingsToTheDaemon(): void
    {
        $this->installRow('lightsquid');

        $ipc = new FakeIpcClient([
            'plugin.lightsquid.apply' => ['result' => ['success' => true, 'message' => 'lightsquid reports will be regenerated every 30m'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/lightsquid/apply', $this->csrf([
            'enabled'    => '1',
            'lang'       => 'fr',
            'template'   => 'novopf',
            'barcolor'   => 'blue',
            'ip2name'    => 'simple',
            'skipurl'    => 'example.com|example.net',
            'log_dir'    => '/var/log/squid',
            'report_dir' => '/var/lightsquid/report',
            'schedule'   => '30m',
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);

        $this->assertSame('plugin.lightsquid.apply', $ipc->calls[0]['method']);
        $params = $ipc->calls[0]['params'];
        $this->assertTrue($params['enabled']);
        $this->assertSame('fr', $params['lang']);
        $this->assertSame('novopf', $params['template']);
        $this->assertSame('blue', $params['barcolor']);
        $this->assertSame('simple', $params['ip2name']);
        $this->assertSame('example.com|example.net', $params['skipurl']);
        $this->assertSame('/var/log/squid', $params['log_dir']);
        $this->assertSame('/var/lightsquid/report', $params['report_dir']);
        $this->assertSame('30m', $params['schedule']);
    }

    public function testLightsquidRefreshRunsTheParserThroughTheDaemon(): void
    {
        $this->installRow('lightsquid');

        $ipc = new FakeIpcClient([
            'plugin.lightsquid.refresh' => ['result' => ['success' => true, 'message' => 'parsed 1 Squid log file(s)'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/lightsquid/refresh', $this->csrf(['mode' => 'full']));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.lightsquid.refresh', $ipc->calls[0]['method']);
        $this->assertSame('full', $ipc->calls[0]['params']['mode']);
    }

    public function testTheLldpdPageRendersTheSettingsForm(): void
    {
        $this->installRow('lldpd');

        Services::injectMock('ipc', new FakeIpcClient([
            'plugin.status' => ['result' => ['success' => true, 'state' => 'running'], 'error' => null],
        ]));

        $page = $this->withSession($this->session())->get('plugins/lldpd');

        $page->assertOK();
        // Renders inside the shared layout, not as a bare view.
        $page->assertSee('LLDP Neighbour Discovery — Plugins — MicronOS', 'title');
        $page->assertSee('CLASS B');
        // The settings a link-discovery daemon exposes: where it listens, what
        // it advertises, and which extra protocols it speaks.
        $page->assertSee('Interfaces');
        $page->assertSee('Management addresses');
        $page->assertSee('System name');
        $page->assertSee('System description');
        $page->assertSee('CDP platform');
        $page->assertSee('Transmit interval (seconds)');
        $page->assertSee('Transmit hold');
        $page->assertSee('Receive-only (do not advertise)');
        $page->assertSee('Also speak CDP (Cisco)');
        // And the neighbour table, which is what the plugin's menu entry names:
        // one row per neighbour with the fields the daemon parses out, each
        // column a sort control, and a way back to the raw lldpcli listing.
        $page->assertSee('Neighbours');
        $page->assertSee('Local port');
        $page->assertSee('Remote chassis');
        $page->assertSee('Remote port');
        $page->assertSee('Management address');
        $page->assertSee('TTL');
        $page->assertSee('ldNbSort');
        $page->assertSee('Show raw output');
        $page->assertSee('plugins/lldpd/status');
        $page->assertSee('plugins/lldpd/apply');
        $page->assertSee('plugins/lldpd/neighbours');
    }

    public function testLldpdStatusReportsThroughTheDaemon(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient([
            'plugin.lldpd.status' => ['result' => [
                'success' => true, 'state' => 'running', 'id' => 'lldpd',
                'data' => ['service_state' => 'running', 'dropin_present' => true, 'interfaces' => 'eth0, eth1'],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lldpd/status');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.lldpd.status', $ipc->calls[0]['method']);
    }

    public function testLldpdStatusIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lldpd/status');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testLldpdNeighboursReturnsTheDaemonListing(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient([
            'plugin.lldpd.neighbours' => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'lldpd',
                'data' => ['neighbours' => "LLDP neighbors:\n  eth0 -> switch1 Gi0/1\n", 'truncated' => false],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lldpd/neighbours');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.lldpd.neighbours', $ipc->calls[0]['method']);
        // No format asked for: the daemon's default (the raw listing) is used.
        $this->assertSame([], $ipc->calls[0]['params']);
    }

    public function testLldpdNeighboursForwardsTheRequestedFormat(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient([
            'plugin.lldpd.neighbours' => ['result' => [
                'success' => true, 'status' => 'ok', 'id' => 'lldpd',
                'data' => ['ports' => [['interface' => 'eth1', 'chassis' => 'core-sw1', 'port' => 'Gi0/12']], 'truncated' => false],
            ], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lldpd/neighbours?format=json');

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);
        $this->assertSame('plugin.lldpd.neighbours', $ipc->calls[0]['method']);
        $this->assertSame('json', $ipc->calls[0]['params']['format']);
    }

    public function testLldpdNeighboursIs404WhenThePluginIsNotInstalled(): void
    {
        $ipc = new FakeIpcClient();
        Services::injectMock('ipc', $ipc);

        $result = $this->withSession($this->session())->get('plugins/lldpd/neighbours');

        $result->assertStatus(404);
        $result->assertJSONFragment(['ok' => false]);
        $this->assertSame([], $ipc->calls);
    }

    public function testLldpdApplyForwardsTheSettingsToTheDaemon(): void
    {
        $this->installRow('lldpd');

        $ipc = new FakeIpcClient([
            'plugin.lldpd.apply' => ['result' => ['success' => true, 'message' => 'lldpd is advertising on eth0'], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('plugins/lldpd/apply', $this->csrf([
            'enabled'                 => '1',
            'interfaces'              => 'eth*,!eth1',
            'management_addresses'    => 'eth0',
            'system_name'             => 'edge.example.com',
            'system_description'      => 'MicronOS edge firewall',
            'platform'                => 'MicronOS',
            'tx_interval'             => '45',
            'tx_hold'                 => '2',
            'receive_only'            => '0',
            'cdp'                     => '1',
            'fdp'                     => '1',
            'sonmp'                   => '0',
            'edp'                     => '0',
            'snmp_subagent'           => '1',
            'suppress_kernel_version' => '1',
        ]));

        $result->assertOK();
        $result->assertJSONFragment(['ok' => true]);

        $this->assertSame('plugin.lldpd.apply', $ipc->calls[0]['method']);
        $params = $ipc->calls[0]['params'];
        $this->assertTrue($params['enabled']);
        $this->assertSame('eth*,!eth1', $params['interfaces']);
        $this->assertSame('eth0', $params['management_addresses']);
        $this->assertSame('edge.example.com', $params['system_name']);
        $this->assertSame('MicronOS edge firewall', $params['system_description']);
        $this->assertSame(45, $params['tx_interval']);
        $this->assertSame(2, $params['tx_hold']);
        $this->assertFalse($params['receive_only']);
        $this->assertTrue($params['cdp']);
        $this->assertFalse($params['edp']);
        $this->assertTrue($params['snmp_subagent']);
        $this->assertTrue($params['suppress_kernel_version']);
    }

    private function seedPresetRow(bool $enabled, string $id = 'netbird'): void
    {
        if (! $this->db->tableExists('plugin_repos')) {
            return;
        }

        $this->db->table('plugin_repos')->where('id', $id)->delete();
        $preset = (new \App\Libraries\RepoPresets())->get($id);
        $input  = \App\Libraries\RepoPresets::input($preset);
        $repo   = \App\Libraries\RepoRegistry::normalize($input)['repo'];

        $this->db->table('plugin_repos')->insert([
            'id'              => $id,
            'name'            => (string) $repo['name'],
            'url'             => (string) $repo['url'],
            'suite'           => (string) $repo['suite'],
            'components'      => implode(',', (array) $repo['components']),
            'architectures'   => implode(',', (array) $repo['architectures']),
            'key_fingerprint' => (string) $repo['fingerprint'],
            'key_url'         => '',
            'key_armor'       => (string) $repo['key_armor'],
            'enabled'         => $enabled ? 1 : 0,
            'added_by'        => 'preset',
        ]);
    }

    private function tempCache(): string
    {
        $path = sys_get_temp_dir() . '/micron_cat_ctl_' . bin2hex(random_bytes(6)) . '.json';
        $this->tempCaches[] = $path;

        return $path;
    }

    private function clearPluginTables(): void
    {
        foreach (['plugin_grants', 'plugins', 'plugin_settings', 'plugin_install_log', 'plugin_repos'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->emptyTable();
            }
        }
    }

    private function installRow(string $id): void
    {
        $this->db->table('plugins')->insert([
            'id'            => $id,
            'name'          => $id,
            'version'       => '1.0.0',
            'class'         => 'B',
            'state'         => 'enabled',
            'installed_at'  => date('Y-m-d H:i:s'),
            'enabled'       => 1,
            'data_policy'   => 'retain',
            'manifest_json' => '{}',
        ]);
    }

    public function testLightsquidReportRendersConsoleWhenNoReportsExist(): void
    {
        $this->installRow('lightsquid');

        $result = $this->withSession($this->session())->get('lightsquid');

        $result->assertOK();
        $result->assertSee('LightSquid Console');
        $result->assertSee('Refresh Today');
    }

    public function testDirectPluginUrlsRedirect(): void
    {
        $result = $this->withSession($this->session())->get('darkstat');
        $result->assertRedirectTo('plugins/darkstat');
    }

    private function writePlugin(string $id, array $manifest): void
    {
        mkdir($this->dir . '/' . $id, 0700, true);
        file_put_contents($this->dir . '/' . $id . '/plugin.json', json_encode($manifest));
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
