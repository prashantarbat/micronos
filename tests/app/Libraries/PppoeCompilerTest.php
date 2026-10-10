<?php

use App\Libraries\PppoeCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * PPPoE configuration compilation.
 *
 * The compiler renders both directions: a client peer file plus credentials for
 * an interface that dials an access concentrator, and the options, subscriber
 * list and argv for the appliance's own concentrator.
 *
 * @internal
 */
final class PppoeCompilerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('pppoe_server_users')->emptyTable();
        $this->db->table('pppoe_servers')->emptyTable();
        $this->db->table('interfaces')->where('id', 'pppoe_wan')->delete();
    }

    private function compiler(): PppoeCompiler
    {
        return new PppoeCompiler($this->db);
    }

    private function insertClientInterface(array $overrides = []): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('interfaces')->insert(array_merge([
            'id'                 => 'pppoe_wan',
            'name'               => 'WAN_PPPOE',
            'physical_device'    => 'eth0',
            'enabled'            => 1,
            'ip_assignment_type' => 'pppoe',
            'pppoe_username'     => 'user@isp.example',
            'pppoe_password'     => $this->compiler()->encrypt('s3cret'),
            'pppoe_service'      => null,
            'pppoe_ac_name'      => null,
            'pppoe_mtu'          => 1492,
            'pppoe_mru'          => 1492,
            'pppoe_idle_timeout' => 0,
            'pppoe_default_route'=> 1,
            'mtu'                => 1500,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], $overrides));
    }

    private function insertServer(array $overrides = []): array
    {
        $now = date('Y-m-d H:i:s');
        $row = array_merge([
            'id'            => 'pppsrv_test',
            'name'          => 'PPPOE_LAN',
            'interface'     => 'eth1',
            'service_name'  => 'MicronOS',
            'ac_name'       => null,
            'local_ip'      => '10.0.0.1',
            'remote_start'  => '10.0.0.10',
            'remote_end'    => '10.0.0.250',
            'dns1'          => null,
            'dns2'          => null,
            'auth_required' => 1,
            'idle_timeout'  => 0,
            'max_sessions'  => 64,
            'description'   => null,
            'enabled'       => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ], $overrides);

        $this->db->table('pppoe_servers')->insert($row);

        return $row;
    }

    private function insertSubscriber(string $serverId, array $overrides = []): array
    {
        $now = date('Y-m-d H:i:s');
        $row = array_merge([
            'id'         => 'pppuser_' . bin2hex(random_bytes(4)),
            'server_id'  => $serverId,
            'username'   => 'sub1',
            'password'   => $this->compiler()->encrypt('subpass'),
            'static_ip'  => null,
            'dns1'       => null,
            'dns2'       => null,
            'rate_limit' => null,
            'description'=> null,
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $this->db->table('pppoe_server_users')->insert($row);

        return $row;
    }

    // ---------------------------------------------------------------------
    // Client
    // ---------------------------------------------------------------------

    public function testDeviceAndPeerNamesAreDerivedFromThePhysicalDevice(): void
    {
        $compiler = $this->compiler();

        $this->assertSame('pppoe-eth0', $compiler->deviceName('eth0'));
        $this->assertSame('micron-eth0', $compiler->peerFileName('eth0'));

        // A VLAN child keeps a distinct identity so two links cannot collide.
        $this->assertSame('pppoe-eth07', $compiler->deviceName('eth0.7'));
        $this->assertSame('micron-eth07', $compiler->peerFileName('eth0.7'));
    }

    public function testClientPeerFileUsesRpPppoePluginOnTheDevice(): void
    {
        $this->insertClientInterface();

        $peer = $this->compiler()->compileClientPeer($this->compiler()->fetchClientInterfaces()[0]);

        $this->assertStringContainsString('plugin rp-pppoe.so', $peer);
        $this->assertStringContainsString('nic-eth0', $peer);
        $this->assertStringContainsString('user "user@isp.example"', $peer);
        $this->assertStringContainsString('noauth', $peer);
    }

    public function testClientPeerDefaultsToPppoeMtu(): void
    {
        $this->insertClientInterface(['pppoe_mtu' => null, 'pppoe_mru' => null]);

        $peer = $this->compiler()->compileClientPeer($this->compiler()->fetchClientInterfaces()[0]);

        // 1500 Ethernet less 8 bytes of PPPoE/PPP overhead.
        $this->assertStringContainsString('mtu 1492', $peer);
        $this->assertStringContainsString('mru 1492', $peer);
    }

    public function testClientPeerKeepsTheLinkUpByDefault(): void
    {
        $this->insertClientInterface(['pppoe_idle_timeout' => 0]);

        $peer = $this->compiler()->compileClientPeer($this->compiler()->fetchClientInterfaces()[0]);

        $this->assertStringContainsString('persist', $peer);
        $this->assertStringContainsString('maxfail 0', $peer);
        $this->assertStringNotContainsString('idle ', $peer);
    }

    public function testClientPeerHonoursAnIdleTimeout(): void
    {
        $this->insertClientInterface(['pppoe_idle_timeout' => 600]);

        $peer = $this->compiler()->compileClientPeer($this->compiler()->fetchClientInterfaces()[0]);

        $this->assertStringContainsString('idle 600', $peer);
        $this->assertStringNotContainsString('persist', $peer);
    }

    public function testDefaultRouteSwitchControlsTheRouteDirective(): void
    {
        $compiler = $this->compiler();

        $this->insertClientInterface(['pppoe_default_route' => 1]);
        $peer = $compiler->compileClientPeer($compiler->fetchClientInterfaces()[0]);
        $this->assertStringContainsString('defaultroute', $peer);
        $this->assertStringNotContainsString('nodefaultroute', $peer);

        $this->db->table('interfaces')->where('id', 'pppoe_wan')->update(['pppoe_default_route' => 0]);
        $peer = $compiler->compileClientPeer($compiler->fetchClientInterfaces()[0]);
        $this->assertStringContainsString('nodefaultroute', $peer);
    }

    public function testServiceAndAccessConcentratorAreOptional(): void
    {
        $compiler = $this->compiler();

        $this->insertClientInterface();
        $peer = $compiler->compileClientPeer($compiler->fetchClientInterfaces()[0]);
        $this->assertStringNotContainsString('rp_pppoe_service', $peer);
        $this->assertStringNotContainsString('rp_pppoe_ac', $peer);

        $this->db->table('interfaces')->where('id', 'pppoe_wan')->update([
            'pppoe_service' => 'ISP-100M',
            'pppoe_ac_name' => 'AC-BRAS-01',
        ]);
        $peer = $compiler->compileClientPeer($compiler->fetchClientInterfaces()[0]);
        $this->assertStringContainsString('rp_pppoe_service "ISP-100M"', $peer);
        $this->assertStringContainsString('rp_pppoe_ac "AC-BRAS-01"', $peer);
    }

    public function testClientPasswordIsDecryptedIntoChapSecrets(): void
    {
        $this->insertClientInterface();

        $secrets = $this->compiler()->compileClientSecrets();

        $this->assertStringContainsString('user@isp.example', $secrets);
        $this->assertStringContainsString("s3cret", $secrets);
        // The stored value is ciphertext, never the plaintext.
        $this->assertStringNotContainsString($this->compiler()->encrypt('s3cret'), $secrets);
    }

    public function testOnlyPppoeInterfacesProduceAClientPeer(): void
    {
        $this->insertClientInterface();
        $this->db->table('interfaces')->where('id', 'wan')->update(['ip_assignment_type' => 'static']);

        $clients = $this->compiler()->fetchClientInterfaces();

        foreach ($clients as $client) {
            $this->assertSame('pppoe', $client['ip_assignment_type']);
        }
    }

    public function testANewlineInACredentialCannotInjectAPeerOption(): void
    {
        $this->insertClientInterface(['pppoe_username' => "user\nnoauth\npersist"]);

        $peer = $this->compiler()->compileClientPeer($this->compiler()->fetchClientInterfaces()[0]);

        // The control characters are stripped, so the injected text stays on the
        // same line as the username instead of becoming its own directive.
        $this->assertStringNotContainsString("user\nnoauth", $peer);
        $this->assertStringContainsString('user "usernoauthpersist"', $peer);
    }

    // ---------------------------------------------------------------------
    // Server
    // ---------------------------------------------------------------------

    public function testServerValidationRejectsMalformedInput(): void
    {
        $compiler = $this->compiler();

        $base = [
            'name' => 'S', 'interface' => 'eth1', 'local_ip' => '10.0.0.1',
            'remote_start' => '10.0.0.10', 'remote_end' => '10.0.0.20',
        ];

        $this->assertNull($compiler->validateServer($base));

        $this->assertNotNull($compiler->validateServer(['name' => ''] + $base));
        $this->assertNotNull($compiler->validateServer(['local_ip' => 'not-an-ip'] + $base));
        $this->assertNotNull($compiler->validateServer(['remote_start' => 'nope'] + $base));
        $this->assertNotNull($compiler->validateServer(['dns1' => '999.1.1.1'] + $base));

        // An inverted pool would hand out nothing.
        $this->assertNotNull($compiler->validateServer(['remote_start' => '10.0.0.50'] + $base));
    }

    public function testServerOptionsCarryThePoolAndResolvers(): void
    {
        $server = $this->insertServer(['dns1' => '1.1.1.1', 'dns2' => '8.8.8.8']);

        $options = $this->compiler()->compileServerOptions($server);

        $this->assertStringContainsString('require-chap', $options);
        $this->assertStringContainsString('proxyarp', $options);
        $this->assertStringContainsString('ms-dns 1.1.1.1', $options);
        $this->assertStringContainsString('ms-dns 8.8.8.8', $options);
        $this->assertStringContainsString('ipparam "10.0.0.10-10.0.0.250"', $options);
    }

    public function testServerOptionsFallBackToTheLocalAddressForDns(): void
    {
        $server = $this->insertServer();

        $options = $this->compiler()->compileServerOptions($server);

        $this->assertStringContainsString('ms-dns 10.0.0.1', $options);
    }

    public function testServerArgvIsAnArgumentListWithNoShell(): void
    {
        $server = $this->insertServer(['ac_name' => 'MICRON-AC', 'max_sessions' => 128]);

        $argv = $this->compiler()->serverArgv($server);

        $this->assertSame('pppoe-server', $argv[0]);
        $this->assertContains('-I', $argv);
        $this->assertContains('eth1', $argv);
        $this->assertContains('-L', $argv);
        $this->assertContains('10.0.0.1', $argv);
        $this->assertContains('-R', $argv);
        $this->assertContains('10.0.0.10', $argv);
        $this->assertContains('128', $argv);
        $this->assertContains('MICRON-AC', $argv);
        // The options file pppd should read.
        $this->assertContains(PppoeCompiler::SERVER_OPTIONS, $argv);

        foreach ($argv as $arg) {
            $this->assertIsString($arg);
            $this->assertStringNotContainsString(' ', $arg);
        }
    }

    public function testServerSecretsListEnabledSubscribersOnly(): void
    {
        $server = $this->insertServer();
        $this->insertSubscriber($server['id'], ['username' => 'alice', 'password' => $this->compiler()->encrypt('alicepw')]);
        $this->insertSubscriber($server['id'], ['username' => 'bob', 'enabled' => 0]);

        $secrets = $this->compiler()->compileServerSecrets($server['id']);

        $this->assertStringContainsString("alice\t*", $secrets);
        $this->assertStringContainsString('alicepw', $secrets);
        $this->assertStringNotContainsString('bob', $secrets);
    }

    public function testSubscriberWithAFixedAddressGetsItInTheSecretsFile(): void
    {
        $server = $this->insertServer();
        $this->insertSubscriber($server['id'], ['username' => 'carol', 'static_ip' => '10.0.0.99']);

        $secrets = $this->compiler()->compileServerSecrets($server['id']);

        $this->assertStringContainsString("carol\t*\tsubpass\t10.0.0.99", $secrets);
    }

    public function testSubscriberWithoutAFixedAddressFallsBackToThePool(): void
    {
        $server = $this->insertServer();
        $this->insertSubscriber($server['id'], ['username' => 'dave']);

        $secrets = $this->compiler()->compileServerSecrets($server['id']);

        $this->assertStringContainsString("dave\t*\tsubpass\t*", $secrets);
    }

    public function testSubscriberValidationRequiresCredentials(): void
    {
        $compiler = $this->compiler();

        $this->assertNull($compiler->validateUser(['username' => 'a', 'password' => 'b']));
        $this->assertNotNull($compiler->validateUser(['username' => '', 'password' => 'b']));
        $this->assertNotNull($compiler->validateUser(['username' => 'a', 'password' => '']));
        $this->assertNotNull($compiler->validateUser(['username' => 'a', 'password' => 'b', 'static_ip' => 'nope']));
    }

    public function testStoredSecretSurvivesTheDatabaseAsBase64(): void
    {
        // CI4's Encrypter returns raw bytes, which a TEXT column does not
        // round-trip faithfully. The stored form must be text.
        $compiler = new PppoeCompiler($this->db);

        $cipher = $compiler->encrypt('subpass');
        $this->assertTrue(ctype_print($cipher), 'Stored ciphertext must be printable text.');
        $this->assertSame('subpass', $compiler->decrypt($cipher));

        // And it survives an actual column round-trip.
        $this->insertClientInterface(['pppoe_password' => $cipher]);
        $row = $compiler->fetchClientInterfaces()[0];
        $this->assertSame('subpass', $compiler->clientPassword($row));
    }

    public function testAPlaintextSecretIsStillUsable(): void
    {
        // A hand-edited row (or one carried over from an older appliance) holds
        // the password as-is; it must not lock the link out.
        $compiler = new PppoeCompiler($this->db);

        $this->assertSame('legacy-pass', $compiler->decrypt('legacy-pass'));
        $this->assertSame('', $compiler->decrypt(''));
    }

    public function testMtuIsClampedToWhatPppdAccepts(): void
    {
        $compiler = $this->compiler();

        $this->assertSame(1492, $compiler->clampMtu(0));
        $this->assertSame(576, $compiler->clampMtu(100));
        $this->assertSame(9000, $compiler->clampMtu(99999));
        $this->assertSame(1400, $compiler->clampMtu(1400));
    }

    public function testCheckClientLinkHealthReportsUpWhenSessionIsUp(): void
    {
        $compiler = $this->compiler();
        $this->insertClientInterface(['physical_device' => 'eth0', 'name' => 'ISP Fiber']);

        $sessions = [
            [
                'role'           => 'client',
                'device'         => 'eth0',
                'state'          => 'UP',
                'local_ip'       => '203.0.113.15',
                'peer_ip'        => '203.0.113.1',
                'uptime_seconds' => 3600,
            ],
        ];

        $health = $compiler->checkClientLinkHealth(null, $sessions);

        $this->assertSame(1, $health['total']);
        $this->assertSame(1, $health['up']);
        $this->assertSame(0, $health['down']);
        $this->assertSame(0, $health['backed_off']);
        $this->assertSame('healthy', $health['overall_state']);
        $this->assertArrayHasKey('eth0', $health['links']);
        $this->assertSame('UP', $health['links']['eth0']['state']);
        $this->assertTrue($health['links']['eth0']['is_healthy']);
        $this->assertFalse($health['links']['eth0']['is_backed_off']);
    }

    public function testCheckClientLinkHealthReportsBackoffWhenInBackoff(): void
    {
        $compiler = $this->compiler();
        $this->insertClientInterface(['physical_device' => 'eth0', 'name' => 'ISP Fiber']);

        $backoff = new \App\Libraries\PppoeBackoff($this->db);
        $backoff->recordFailure('eth0', 'Carrier lost', time());

        // Empty sessions (daemon has no session up)
        $health = $compiler->checkClientLinkHealth(null, [], $backoff);

        $this->assertSame(1, $health['total']);
        $this->assertSame(0, $health['up']);
        $this->assertSame(0, $health['down']);
        $this->assertSame(1, $health['backed_off']);
        $this->assertSame('backed_off', $health['overall_state']);
        $this->assertSame('BACKOFF', $health['links']['eth0']['state']);
        $this->assertFalse($health['links']['eth0']['is_healthy']);
        $this->assertTrue($health['links']['eth0']['is_backed_off']);
        $this->assertSame(1, $health['links']['eth0']['failure_count']);
        $this->assertSame('5m', $health['links']['eth0']['remaining_human']);
    }

    public function testCheckClientLinkHealthReportsDownWhenDisconnected(): void
    {
        $compiler = $this->compiler();
        $this->insertClientInterface(['physical_device' => 'eth0', 'name' => 'ISP Fiber']);

        $backoff = new \App\Libraries\PppoeBackoff($this->db);
        $backoff->reset('eth0');

        $health = $compiler->checkClientLinkHealth(null, [], $backoff);

        $this->assertSame(1, $health['total']);
        $this->assertSame(0, $health['up']);
        $this->assertSame(1, $health['down']);
        $this->assertSame(0, $health['backed_off']);
        $this->assertSame('down', $health['overall_state']);
        $this->assertSame('DOWN', $health['links']['eth0']['state']);
        $this->assertFalse($health['links']['eth0']['is_healthy']);
        $this->assertFalse($health['links']['eth0']['is_backed_off']);
    }
}

