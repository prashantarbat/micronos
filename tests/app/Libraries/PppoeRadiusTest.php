<?php

use App\Libraries\PppoeCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * PPPoE authentication against RADIUS.
 *
 * A RADIUS-backed server must load the plugin, point it at a configuration that
 * can decode reply attributes, and keep the shared secret out of any command
 * line. The local subscriber list is deliberately still compiled, so switching
 * backends needs no data re-entry.
 *
 * @internal
 */
final class PppoeRadiusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('pppoe_server_users')->emptyTable();
        $this->db->table('pppoe_servers')->emptyTable();
    }

    private function compiler(): PppoeCompiler
    {
        return new PppoeCompiler($this->db);
    }

    private function insertServer(array $overrides = []): array
    {
        $now = date('Y-m-d H:i:s');
        $row = array_merge([
            'id'             => 'pppsrv_radius',
            'name'           => 'RADIUS_LAN',
            'interface'      => 'eth1',
            'service_name'   => 'MicronOS',
            'ac_name'        => null,
            'local_ip'       => '10.0.0.1',
            'remote_start'   => '10.0.0.10',
            'remote_end'     => '10.0.0.250',
            'dns1'           => null,
            'dns2'           => null,
            'auth_required'  => 1,
            'auth_backend'   => 'radius',
            'radius_server'  => '10.20.30.40',
            'radius_port'    => 1812,
            'radius_secret'  => $this->compiler()->encrypt('sharedsecret'),
            'radius_timeout' => 5,
            'radius_retries' => 3,
            'radius_nas_id'  => 'MICRON-NAS-01',
            'radius_server2' => null,
            'idle_timeout'   => 0,
            'max_sessions'   => 64,
            'description'    => null,
            'enabled'        => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $overrides);

        $this->db->table('pppoe_servers')->insert($row);

        return $row;
    }

    // ---------------------------------------------------------------------
    // Backend selection
    // ---------------------------------------------------------------------

    public function testBackendDefaultsToLocal(): void
    {
        $compiler = $this->compiler();

        $this->assertTrue($compiler->usesRadius(['auth_backend' => 'radius']));
        $this->assertFalse($compiler->usesRadius(['auth_backend' => 'local']));
        $this->assertFalse($compiler->usesRadius([]));
    }

    public function testLocalServerGetsNoRadiusOptions(): void
    {
        $server = $this->insertServer(['auth_backend' => 'local']);

        $this->assertSame([], $this->compiler()->radiusServerOptions($server));
        $this->assertStringNotContainsString('radius.so', $this->compiler()->compileServerOptions($server));
    }

    public function testRadiusServerLoadsThePluginAndPointsAtItsConfig(): void
    {
        $server = $this->insertServer();

        $options = $this->compiler()->compileServerOptions($server);

        $this->assertStringContainsString('plugin radius.so', $options);
        $this->assertStringContainsString('radius-config-file /etc/radiusclient/radiusclient.conf', $options);

        // radattr records the reply attributes for the session monitor, and it
        // depends on symbols in radius.so, so it must be loaded after it.
        $this->assertStringContainsString('plugin radattr.so', $options);
        $this->assertLessThan(
            strpos($options, 'plugin radattr.so'),
            strpos($options, 'plugin radius.so'),
            'radius.so must load before radattr.so',
        );

        // pppd rejects an unknown option outright, which would stop the server
        // from starting at all; the NAS identifier belongs in radiusclient.conf.
        $this->assertStringNotContainsString('radius-config-file-nas', $options);
    }

    public function testRadiusServerStillCompilesItsLocalList(): void
    {
        // Switching back to local must not require re-entering subscribers.
        $server = $this->insertServer();
        $now = date('Y-m-d H:i:s');
        $this->db->table('pppoe_server_users')->insert([
            'id' => 'u1', 'server_id' => $server['id'], 'username' => 'localuser',
            'password' => $this->compiler()->encrypt('localpass'), 'enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $secrets = $this->compiler()->compileServerSecrets($server['id']);

        $this->assertStringContainsString('localuser', $secrets);
        $this->assertStringContainsString('localpass', $secrets);
    }

    // ---------------------------------------------------------------------
    // Servers file
    // ---------------------------------------------------------------------

    public function testRadiusServersFileCarriesHostPortSecretAndTimers(): void
    {
        $server = $this->insertServer();

        $file = $this->compiler()->compileRadiusServers($server);

        $this->assertStringContainsString("10.20.30.40:1812\tsharedsecret\t5\t3", $file);
        // The stored value is ciphertext, never the plaintext secret.
        $this->assertStringNotContainsString($server['radius_secret'], $file);
    }

    public function testSecondaryServerIsAddedWhenConfigured(): void
    {
        $server = $this->insertServer(['radius_server2' => '10.20.30.41']);

        $file = $this->compiler()->compileRadiusServers($server);

        $this->assertStringContainsString('10.20.30.40:1812', $file);
        $this->assertStringContainsString('10.20.30.41:1812', $file);
    }

    public function testSecretIsDecryptedIntoTheServersFile(): void
    {
        $server = $this->insertServer();

        $this->assertSame('sharedsecret', $this->compiler()->radiusSecret($server));
    }

    // ---------------------------------------------------------------------
    // Client configuration
    // ---------------------------------------------------------------------

    public function testRadiusClientConfDeclaresTheDictionaries(): void
    {
        $server = $this->insertServer();

        $conf = $this->compiler()->compileRadiusClientConf($server);

        // Without the dictionaries the plugin cannot decode reply attributes.
        $this->assertStringContainsString('dictionary /usr/share/freeradius/dictionary', $conf);
        $this->assertStringContainsString('dictionary /usr/share/freeradius/dictionary.rfc2865', $conf);
        $this->assertStringContainsString('dictionary /usr/share/freeradius/dictionary.microsoft', $conf);
        $this->assertStringContainsString('servers /etc/radiusclient/servers', $conf);
        $this->assertStringContainsString('nas_identifier "MICRON-NAS-01"', $conf);
    }

    public function testNasIdentifierFallsBackToTheServerName(): void
    {
        $server = $this->insertServer(['radius_nas_id' => null]);

        $conf = $this->compiler()->compileRadiusClientConf($server);

        $this->assertStringContainsString('nas_identifier "RADIUS_LAN"', $conf);
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    public function testValidationSkipsLocalServers(): void
    {
        $this->assertNull($this->compiler()->validateRadius(['auth_backend' => 'local']));
    }

    public function testValidationRejectsUnusableRadiusSettings(): void
    {
        $compiler = $this->compiler();

        $base = [
            'auth_backend'   => 'radius',
            'radius_server'  => '10.20.30.40',
            'radius_port'    => 1812,
            'radius_timeout' => 5,
            'radius_retries' => 3,
            'radius_secret'  => 'sharedsecret',
        ];

        $this->assertNull($compiler->validateRadius($base));

        $this->assertNotNull($compiler->validateRadius(['radius_server' => ''] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_server' => 'not a host!'] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_port' => 0] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_port' => 70000] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_timeout' => 0] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_retries' => 99] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_server2' => 'bad host'] + $base));
    }

    public function testValidationRequiresASecretUnlessOneIsStored(): void
    {
        $compiler = $this->compiler();

        $base = [
            'auth_backend'   => 'radius',
            'radius_server'  => '10.20.30.40',
            'radius_port'    => 1812,
            'radius_timeout' => 5,
            'radius_retries' => 3,
        ];

        // A new server must supply one...
        $this->assertNotNull($compiler->validateRadius($base + ['radius_secret' => '']));

        // ...but an existing server that already holds one may keep it.
        $this->assertNull($compiler->validateRadius($base + ['radius_secret' => '', 'radius_secret_set' => true]));
    }

    public function testHostnameValidationAcceptsNamesAndAddresses(): void
    {
        $compiler = $this->compiler();

        $this->assertTrue($compiler->isHostname('radius.example.com'));
        $this->assertTrue($compiler->isHostname('radius'));
        $this->assertFalse($compiler->isHostname(''));
        $this->assertFalse($compiler->isHostname('has space'));
        $this->assertFalse($compiler->isHostname('bad_host'));
    }

    // ---------------------------------------------------------------------
    // Test request
    // ---------------------------------------------------------------------

    public function testRadclientArgvReadsTheSecretFromAFile(): void
    {
        $server = $this->insertServer();

        $argv = $this->compiler()->radclientArgv($server, '/run/micron/radtest.secret');

        $this->assertSame('radclient', $argv[0]);
        $this->assertContains('10.20.30.40:1812', $argv);
        $this->assertContains('auth', $argv);

        // radclient takes the secret as its third positional argument, which
        // would expose it through /proc. -S must be used instead, and the
        // secret must never appear in argv in any form.
        $this->assertContains('-S', $argv);
        $this->assertContains('/run/micron/radtest.secret', $argv);
        $this->assertSame('-D', $argv[array_search('-D', $argv, true)]);

        foreach ($argv as $arg) {
            $this->assertStringNotContainsString('sharedsecret', $arg);
        }

        // The secret must not be passed positionally after `auth` either.
        $authIndex = array_search('auth', $argv, true);
        $this->assertSame($authIndex, count($argv) - 1, 'Nothing may follow the auth command.');
    }

    public function testRadclientRequestCarriesTheCredentials(): void
    {
        $server = $this->insertServer();

        $request = $this->compiler()->radclientRequest($server, 'testuser', 'testpass');

        $this->assertStringContainsString('User-Name = "testuser"', $request);
        $this->assertStringContainsString('User-Password = "testpass"', $request);
        $this->assertStringContainsString('NAS-Identifier = "MICRON-NAS-01"', $request);
        $this->assertStringContainsString('Framed-Protocol = PPP', $request);
    }

    public function testRadclientRequestQuotesAnInjectedValue(): void
    {
        $server = $this->compiler();

        $request = $server->radclientRequest(
            ['radius_nas_id' => 'NAS'],
            'user"name',
            'pass\\word',
        );

        // A quote in a credential must be escaped, not allowed to close the
        // attribute value and inject another line.
        $this->assertStringContainsString('User-Name = "user\\"name"', $request);
        $this->assertStringContainsString('User-Password = "pass\\\\word"', $request);
    }

    public function testRadiusDirsCoverTheKnownPackagings(): void
    {
        $this->assertContains('/etc/radiusclient', PppoeCompiler::RADIUS_DIRS);
        $this->assertContains('/etc/freeradius-client', PppoeCompiler::RADIUS_DIRS);
    }

    // ---------------------------------------------------------------------
    // Client configuration endpoints
    // ---------------------------------------------------------------------

    /**
     * The client matches `authserver` against the `servers` file by name, so a
     * placeholder would point every request at a host the secret is not
     * registered for.
     */
    public function testAuthServerNamesTheConfiguredHostNotAPlaceholder(): void
    {
        $conf = $this->compiler()->compileRadiusClientConf($this->insertServer());

        $this->assertStringContainsString('authserver 10.20.30.40:1812', $conf);
        $this->assertStringNotContainsString('authserver localhost', $conf);
    }

    public function testBothAuthServersAreListed(): void
    {
        $server = $this->insertServer(['radius_server2' => '10.20.30.41']);

        $conf = $this->compiler()->compileRadiusClientConf($server);

        $this->assertStringContainsString('authserver 10.20.30.40:1812,10.20.30.41:1812', $conf);
    }

    // ---------------------------------------------------------------------
    // Accounting
    // ---------------------------------------------------------------------

    public function testAccountingIsOffByDefault(): void
    {
        $server = $this->insertServer();
        $compiler = $this->compiler();

        $this->assertFalse($compiler->usesAccounting($server));
        $this->assertSame([], $compiler->radiusAcctServers($server));

        // Without the directive pppd sends no accounting records at all.
        $conf = $compiler->compileRadiusClientConf($server);
        $this->assertStringNotContainsString('\nacctserver', $conf);
        $this->assertStringContainsString('accounting disabled', $conf);
    }

    public function testAccountingEnabledEmitsTheAccountingServerOnTheAccountingPort(): void
    {
        $server = $this->insertServer(['radius_accounting' => 1]);
        $compiler = $this->compiler();

        $this->assertTrue($compiler->usesAccounting($server));
        $this->assertSame(['10.20.30.40:1813'], $compiler->radiusAcctServers($server));
        $this->assertStringContainsString('acctserver 10.20.30.40:1813', $compiler->compileRadiusClientConf($server));
    }

    public function testTheAccountingPortIsIndependentOfTheAuthPort(): void
    {
        $server = $this->insertServer(['radius_accounting' => 1, 'radius_acct_port' => 11813]);
        $conf = $this->compiler()->compileRadiusClientConf($server);

        $this->assertStringContainsString('authserver 10.20.30.40:1812', $conf);
        $this->assertStringContainsString('acctserver 10.20.30.40:11813', $conf);
    }

    public function testAnAccountingHostOverridesTheAuthHost(): void
    {
        $server = $this->insertServer([
            'radius_accounting'  => 1,
            'radius_acct_server' => 'acct.example.net',
        ]);

        $conf = $this->compiler()->compileRadiusClientConf($server);

        $this->assertStringContainsString('authserver 10.20.30.40:1812', $conf);
        $this->assertStringContainsString('acctserver acct.example.net:1813', $conf);
    }

    public function testTheSecondaryHostIsAlsoAnAccountingServerByDefault(): void
    {
        $server = $this->insertServer(['radius_accounting' => 1, 'radius_server2' => '10.20.30.41']);

        $this->assertSame(['10.20.30.40:1813', '10.20.30.41:1813'], $this->compiler()->radiusAcctServers($server));
    }

    public function testAccountingCannotBeOnForALocalBackend(): void
    {
        $server = $this->insertServer(['auth_backend' => 'local', 'radius_accounting' => 1]);

        $this->assertFalse($this->compiler()->usesAccounting($server), 'accounting requires the RADIUS backend');
        $this->assertSame([], $this->compiler()->radiusAcctServers($server));
    }

    public function testValidationRejectsAnUnusableAccountingConfiguration(): void
    {
        $compiler = $this->compiler();
        $base = [
            'auth_backend'    => 'radius',
            'radius_server'   => '10.20.30.40',
            'radius_port'     => 1812,
            'radius_timeout'  => 5,
            'radius_retries'  => 3,
            'radius_secret'   => 'sharedsecret',
            'radius_secret_set' => true,
            'radius_accounting' => 1,
        ];

        $this->assertNull($compiler->validateRadius($base));
        $this->assertNull($compiler->validateRadius(['radius_acct_port' => 1813] + $base));

        $this->assertNotNull($compiler->validateRadius(['radius_acct_port' => 0] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_acct_port' => 70000] + $base));
        $this->assertNotNull($compiler->validateRadius(['radius_acct_server' => 'not a host'] + $base));
    }

    public function testAnInvalidAccountingPortIsIgnoredWhileAccountingIsOff(): void
    {
        // The field is only meaningful when accounting is on, so a stale value
        // left over in a disabled form must not block the save.
        $error = $this->compiler()->validateRadius([
            'auth_backend'      => 'radius',
            'radius_server'     => '10.20.30.40',
            'radius_port'       => 1812,
            'radius_timeout'    => 5,
            'radius_retries'    => 3,
            'radius_secret'     => 'sharedsecret',
            'radius_secret_set' => true,
            'radius_accounting' => 0,
            'radius_acct_port'  => 0,
        ]);

        $this->assertNull($error);
    }
}
