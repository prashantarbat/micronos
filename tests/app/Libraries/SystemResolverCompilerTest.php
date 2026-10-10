<?php

use App\Libraries\SystemResolverCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Covers the appliance stub resolver compiler: the DNS Server Override flag,
 * the DNS Resolution Behavior closed set, and the `resolv.conf` it produces.
 *
 * Most assertions pass an explicit settings array, so the behavior matrix is
 * tested independently of whatever the shared `dns_settings` row happens to
 * contain. The few database-reading tests snapshot and restore that row, since
 * the test suite shares one in-memory database.
 *
 * @internal
 */
final class SystemResolverCompilerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** @var array<string, mixed>|null Row as found, restored so later DNS tests are unaffected. */
    private ?array $original = null;

    private const UPSTREAMS = [
        ['name' => 'Cloudflare Primary (DoT)', 'ip' => '1.1.1.1', 'port' => 853, 'sni' => 'cloudflare-dns.com'],
        ['name' => 'Cloudflare Secondary (DoT)', 'ip' => '1.0.0.1', 'port' => 853, 'sni' => 'cloudflare-dns.com'],
        ['name' => 'Quad9 Secured (DoT)', 'ip' => '9.9.9.9', 'port' => 853, 'sni' => 'dns.quad9.net'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = $this->db->table('dns_settings')->where('id', 'global')->get()->getRowArray();
        $this->db->table('dns_settings')->where('id', 'global')->delete();

        // The shared database may hold rows another DNS test left behind; a
        // dynamic server would silently change every nameserver assertion.
        $this->db->table('dynamic_dns_servers')->emptyTable();

        // Seed a stable starting row for the database-reading tests.
        $this->db->table('dns_settings')->insert([
            'id'                      => 'global',
            'enable_dnssec'           => 1,
            'enable_dnsbl'            => 1,
            'upstream_mode'           => 'dot',
            'upstream_servers'        => json_encode(self::UPSTREAMS),
            'cache_min_ttl'           => 60,
            'cache_max_ttl'           => 86400,
            'blocked_query_action'    => '0.0.0.0',
            'dns_server_override'     => 1,
            'dns_resolution_behavior' => 'local-fallback',
            'updated_at'              => '2026-10-06 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->delete();
        if (is_array($this->original)) {
            $this->db->table('dns_settings')->insert($this->original);
        }

        $this->db->table('dynamic_dns_servers')->emptyTable();

        parent::tearDown();
    }

    /** Seeds one dynamic_dns_servers row. */
    private function seedDynamic(string $source, string $interface, string $address): void
    {
        $this->db->table('dynamic_dns_servers')->insert([
            'id'             => 'dyn_' . $source . '_' . str_replace(':', '', $address),
            'source'         => $source,
            'interface_id'   => null,
            'interface_name' => $interface,
            'address'        => $address,
            'assigned_at'    => '2026-10-06 00:00:00',
            'updated_at'     => '2026-10-06 00:00:00',
        ]);
    }

    private function compiler(): SystemResolverCompiler
    {
        return new SystemResolverCompiler($this->db);
    }

    /** An explicit settings array (never touches the database). */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'upstream_servers'        => json_encode(self::UPSTREAMS),
            'dns_server_override'     => 1,
            'dns_resolution_behavior' => 'local-fallback',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Behavior model
    // ------------------------------------------------------------------

    /** The documented default, and unknown/empty values coerce to it. */
    public function testNormalizeFallsBackToTheDefaultBehavior(): void
    {
        $this->assertSame('local-fallback', SystemResolverCompiler::DEFAULT_BEHAVIOR);
        $this->assertSame('local-fallback', SystemResolverCompiler::normalize(null));
        $this->assertSame('local-fallback', SystemResolverCompiler::normalize(''));
        $this->assertSame('local-fallback', SystemResolverCompiler::normalize('nonsense'));
        $this->assertSame('local-only', SystemResolverCompiler::normalize(' LOCAL-ONLY '));
        $this->assertSame('remote-only', SystemResolverCompiler::normalize('remote-only'));
    }

    /** A submitted value is validated against the closed set, not coerced. */
    public function testIsValidAcceptsOnlyTheClosedSet(): void
    {
        foreach (SystemResolverCompiler::BEHAVIORS as $behavior) {
            $this->assertTrue(SystemResolverCompiler::isValid($behavior), $behavior . ' should be valid');
        }

        $this->assertFalse(SystemResolverCompiler::isValid('local'));
        $this->assertFalse(SystemResolverCompiler::isValid('LOCAL-ONLY'));
        $this->assertFalse(SystemResolverCompiler::isValid(''));
        $this->assertCount(3, SystemResolverCompiler::BEHAVIORS);
    }

    /** Local vs remote usage, the property the compiler branches on. */
    public function testBehaviorMatrix(): void
    {
        $this->assertTrue(SystemResolverCompiler::usesLocal('local-fallback'));
        $this->assertTrue(SystemResolverCompiler::usesRemote('local-fallback'));

        $this->assertTrue(SystemResolverCompiler::usesLocal('local-only'));
        $this->assertFalse(SystemResolverCompiler::usesRemote('local-only'));

        $this->assertFalse(SystemResolverCompiler::usesLocal('remote-only'));
        $this->assertTrue(SystemResolverCompiler::usesRemote('remote-only'));
    }

    /** Every behavior carries a pfSense-style label and non-empty help text. */
    public function testEveryBehaviorHasALabelAndHelp(): void
    {
        foreach (SystemResolverCompiler::BEHAVIORS as $behavior) {
            $label = SystemResolverCompiler::label($behavior);
            $help  = SystemResolverCompiler::help($behavior);

            $this->assertNotSame('', $label);
            $this->assertNotSame('', $help);
            $this->assertGreaterThan(80, strlen($help), $behavior . ' help should explain the option');
        }

        $this->assertSame(
            'Use local DNS (127.0.0.1), fall back to remote DNS Servers (Default)',
            SystemResolverCompiler::label('local-fallback'),
        );
        $this->assertStringContainsString('127.0.0.1', SystemResolverCompiler::label('local-only'));
        $this->assertStringContainsString('remote DNS Servers', SystemResolverCompiler::label('remote-only'));

        // Unknown values fall back to the default entry rather than an empty string.
        $this->assertSame(SystemResolverCompiler::label('local-fallback'), SystemResolverCompiler::label('bogus'));
    }

    // ------------------------------------------------------------------
    // nameserver selection
    // ------------------------------------------------------------------

    /** Default: the local resolver first, then the configured remote servers. */
    public function testLocalFallbackListsLocalFirstThenRemote(): void
    {
        $servers = $this->compiler()->nameservers($this->settings());

        $this->assertSame(['127.0.0.1', '1.1.1.1', '1.0.0.1', '9.9.9.9'], $servers);
    }

    /** local-only: the remote servers are dropped entirely. */
    public function testLocalOnlyNamesOnlyTheLocalResolver(): void
    {
        $settings = $this->settings(['dns_resolution_behavior' => 'local-only']);
        $compiler = $this->compiler();

        $this->assertSame(['127.0.0.1'], $compiler->nameservers($settings));

        $conf = $compiler->compile($settings);
        $this->assertStringContainsString("\nnameserver 127.0.0.1\n", $conf);
        foreach (['1.1.1.1', '1.0.0.1', '9.9.9.9'] as $ip) {
            $this->assertStringNotContainsString("nameserver {$ip}", $conf);
        }
    }

    /** remote-only: the local resolver is dropped, remote servers are used. */
    public function testRemoteOnlyNamesOnlyRemoteServers(): void
    {
        $settings = $this->settings(['dns_resolution_behavior' => 'remote-only']);
        $compiler = $this->compiler();

        $this->assertSame(['1.1.1.1', '1.0.0.1', '9.9.9.9'], $compiler->nameservers($settings));

        $conf = $compiler->compile($settings);
        $this->assertStringContainsString("\nnameserver 1.1.1.1\n", $conf);
        $this->assertStringNotContainsString('nameserver 127.0.0.1', $conf);
    }

    /**
     * remote-only with nothing configured is unresolvable, and the compiler
     * still refuses to emit a file without a server.
     */
    public function testRemoteOnlyWithNoRemoteServersIsUnresolvableButStillCompiles(): void
    {
        $settings = $this->settings(['dns_resolution_behavior' => 'remote-only', 'upstream_servers' => '[]']);
        $compiler = $this->compiler();

        $this->assertTrue($compiler->isUnresolvable($settings));
        $this->assertSame([], $compiler->nameservers($settings));

        $conf = $compiler->compile($settings);
        $this->assertStringContainsString("# !!! No DNS servers were configured for this behavior.", $conf);
        $this->assertStringContainsString("\nnameserver 127.0.0.1\n", $conf, 'the fallback must keep the firewall resolvable');
    }

    /** A workable configuration is never reported as unresolvable. */
    public function testResolvableCombinations(): void
    {
        $compiler = $this->compiler();

        $this->assertFalse($compiler->isUnresolvable($this->settings()));
        $this->assertFalse($compiler->isUnresolvable($this->settings(['dns_resolution_behavior' => 'local-only'])));
        $this->assertFalse($compiler->isUnresolvable($this->settings(['dns_resolution_behavior' => 'remote-only'])));
        $this->assertTrue($compiler->isUnresolvable($this->settings([
            'dns_resolution_behavior' => 'remote-only',
            'upstream_servers'        => json_encode([['name' => 'bad', 'ip' => 'not-an-ip', 'port' => 53]]),
        ])));
    }

    /** Upstream entries are validated, deduplicated and capped. */
    public function testRemoteServersAreValidatedDedupedAndCapped(): void
    {
        $entries = [
            ['ip' => '8.8.8.8'],
            ['ip' => '8.8.8.8'],            // duplicate
            ['ip' => 'not-an-ip'],          // invalid
            ['ip' => ''],
            ['ip' => '2606:4700:4700::1111'],
            ['ip' => ' 9.9.9.9 '],          // whitespace is trimmed
        ];
        // Past the cap: the extras must be dropped, not truncated arbitrarily.
        for ($i = 1; $i <= 10; $i++) {
            $entries[] = ['ip' => "10.20.30.{$i}"];
        }

        $servers = $this->compiler()->remoteServers($this->settings(['upstream_servers' => json_encode($entries)]));

        $this->assertSame(['8.8.8.8', '2606:4700:4700::1111', '9.9.9.9', '10.20.30.1', '10.20.30.2', '10.20.30.3', '10.20.30.4', '10.20.30.5'], $servers);
        $this->assertLessThanOrEqual(8, count($servers));
    }

    /** Unparseable upstream storage degrades to "no remote servers". */
    public function testUnparseableUpstreamServersYieldNoRemotes(): void
    {
        $compiler = $this->compiler();

        $this->assertSame([], $compiler->remoteServers($this->settings(['upstream_servers' => 'not json'])));
        $this->assertSame([], $compiler->remoteServers($this->settings(['upstream_servers' => null])));
        $this->assertSame(['1.1.1.1'], $compiler->remoteServers($this->settings(['upstream_servers' => ['1.1.1.1']])));
    }

    // ------------------------------------------------------------------
    // DNS Server Override
    // ------------------------------------------------------------------

    /** The override flag becomes a machine-readable directive. */
    public function testOverrideDirectiveReflectsTheSetting(): void
    {
        $compiler = $this->compiler();

        $on = $compiler->compile($this->settings(['dns_server_override' => 1]));
        $this->assertStringContainsString('# dynamic-wan-override: enabled', $on);
        $this->assertStringContainsString('# DNS Server Override: enabled', $on);

        $off = $compiler->compile($this->settings(['dns_server_override' => 0]));
        $this->assertStringContainsString('# dynamic-wan-override: disabled', $off);
        $this->assertStringContainsString('# DNS Server Override: disabled', $off);
        $this->assertStringContainsString('are ignored', $off);
    }

    /** A row that predates the column behaves as pfSense's checked default. */
    public function testOverrideDefaultsToEnabledWhenTheColumnIsAbsent(): void
    {
        $compiler = $this->compiler();
        $settings = $this->settings();
        unset($settings['dns_server_override']);

        $this->assertTrue($compiler->overrideEnabled($settings));
        $this->assertStringContainsString('# dynamic-wan-override: enabled', $compiler->compile($settings));
    }

    /**
     * The override is meaningless when remote servers are ignored, and the
     * compiled file says so rather than implying it has an effect.
     */
    public function testOverrideIsFlaggedAsIneffectiveUnderLocalOnly(): void
    {
        $conf = $this->compiler()->compile($this->settings([
            'dns_server_override'     => 1,
            'dns_resolution_behavior' => 'local-only',
        ]));

        $this->assertStringContainsString('has no effect while remote DNS Servers are ignored', $conf);
    }

    /** With the override off, the artifact states that dynamic servers are ignored. */
    public function testDisabledOverrideExplainsThatDynamicServersAreIgnored(): void
    {
        $conf = $this->compiler()->compile($this->settings([
            'dns_server_override'     => 0,
            'dns_resolution_behavior' => 'local-fallback',
        ]));

        $this->assertStringContainsString('Dynamic WAN/OpenVPN-assigned servers are ignored', $conf);
    }

    // ------------------------------------------------------------------
    // Artifact shape
    // ------------------------------------------------------------------

    /** The file is self-describing and never empty of nameservers. */
    public function testCompiledArtifactIsSelfDescribing(): void
    {
        $conf = $this->compiler()->compile($this->settings());

        $this->assertStringContainsString('Micron Firewall OS (MicronOS) — Appliance Stub Resolver', $conf);
        $this->assertStringContainsString('# DNS Resolution Behavior: ' . SystemResolverCompiler::label('local-fallback'), $conf);
        $this->assertStringContainsString('/etc/unbound/unbound.conf', $conf, 'it must point at what serves clients');
        $this->assertSame(4, substr_count($conf, "\nnameserver "), 'one nameserver line per server');
        $this->assertStringEndsWith("\n", $conf);
        $this->assertStringNotContainsString("\nnameserver 1.1.1.1\nnameserver 127.0.0.1", $conf);
    }

    // ------------------------------------------------------------------
    // Database-backed reads
    // ------------------------------------------------------------------

    /** Without an explicit array the compiler reads the persisted row. */
    public function testReadsThePersistedSettingsRow(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->update([
            'dns_resolution_behavior' => 'remote-only',
            'dns_server_override'     => 0,
        ]);

        $compiler = $this->compiler();

        $this->assertSame('remote-only', $compiler->behavior());
        $this->assertFalse($compiler->overrideEnabled());
        $this->assertSame(['1.1.1.1', '1.0.0.1', '9.9.9.9'], $compiler->nameservers());
    }

    /** A stored value outside the closed set degrades to the default. */
    public function testUnknownStoredBehaviorDegradesToTheDefault(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->update(['dns_resolution_behavior' => 'split-horizon']);

        $this->assertSame('local-fallback', $this->compiler()->behavior());
    }

    /** A missing settings row / unmigrated table must not crash the compiler. */
    public function testCompilesWithNoSettingsRow(): void
    {
        $this->db->table('dns_settings')->where('id', 'global')->delete();

        $compiler = $this->compiler();
        $conf     = $compiler->compile();

        $this->assertSame('local-fallback', $compiler->behavior());
        $this->assertTrue($compiler->overrideEnabled(), 'no row means the documented default');
        $this->assertSame(['127.0.0.1'], $compiler->nameservers(), 'no row means no remote servers');
        $this->assertStringContainsString("\nnameserver 127.0.0.1\n", $conf);
    }

    // ------------------------------------------------------------------
    // Dynamically assigned servers (DHCP/PPP on WAN, remote OpenVPN)
    // ------------------------------------------------------------------

    /** With the override on, dynamic servers are merged ahead of the static list. */
    public function testDynamicServersAreMergedAheadOfTheStaticListWhenOverrideIsOn(): void
    {
        $this->seedDynamic('dhcp', 'WAN', '10.0.0.138');
        $this->seedDynamic('openvpn', 'ovpn1', '10.8.0.1');

        $compiler = $this->compiler();

        $this->assertSame(['10.0.0.138', '10.8.0.1'], $compiler->dynamicServers());
        $this->assertSame(
            ['127.0.0.1', '10.0.0.138', '10.8.0.1', '1.1.1.1', '1.0.0.1', '9.9.9.9'],
            $compiler->nameservers(),
        );

        $conf = $compiler->compile();
        $this->assertStringContainsString('# Dynamic WAN/OpenVPN-assigned servers merged ahead of the configured list: 10.0.0.138, 10.8.0.1', $conf);
        $this->assertStringContainsString("\nnameserver 10.0.0.138\n", $conf);
    }

    /** WAN-first ordering: DHCP before PPP before OpenVPN regardless of insert order. */
    public function testDynamicServersAreOrderedWanFirst(): void
    {
        $this->seedDynamic('openvpn', 'ovpn1', '10.8.0.1');
        $this->seedDynamic('ppp', 'WAN_PPP', '100.64.0.53');
        $this->seedDynamic('dhcp', 'WAN', '10.0.0.138');

        $this->assertSame(
            ['10.0.0.138', '100.64.0.53', '10.8.0.1'],
            $this->compiler()->dynamicServers(),
        );
    }

    /** With the override off, dynamic servers are dropped from the compiled file. */
    public function testDynamicServersAreDroppedWhenOverrideIsOff(): void
    {
        $this->seedDynamic('dhcp', 'WAN', '10.0.0.138');

        $compiler = $this->compiler();
        $settings = $this->settings(['dns_server_override' => 0]);

        // They remain on record (the page can still show them) but are not used.
        $this->assertSame(['10.0.0.138'], $compiler->storedDynamicServers());
        $this->assertSame([], $compiler->dynamicServers($settings));
        $this->assertSame(['127.0.0.1', '1.1.1.1', '1.0.0.1', '9.9.9.9'], $compiler->nameservers($settings));

        $conf = $compiler->compile($settings);
        $this->assertStringNotContainsString('nameserver 10.0.0.138', $conf);
        $this->assertStringContainsString('Dynamic WAN/OpenVPN-assigned servers are ignored', $conf);
    }

    /** local-only ignores remote servers wholesale, so the dynamic ones go too. */
    public function testDynamicServersAreIgnoredUnderLocalOnly(): void
    {
        $this->seedDynamic('dhcp', 'WAN', '10.0.0.138');

        $compiler = $this->compiler();
        $settings = $this->settings(['dns_server_override' => 1, 'dns_resolution_behavior' => 'local-only']);

        $this->assertSame([], $compiler->dynamicServers($settings));
        $this->assertSame(['127.0.0.1'], $compiler->nameservers($settings));

        $conf = $compiler->compile($settings);
        $this->assertStringNotContainsString('nameserver 10.0.0.138', $conf);
        $this->assertStringContainsString('dynamically assigned server(s) are on record but not used', $conf);
    }

    /** remote-only uses the dynamic servers in place of the local resolver. */
    public function testRemoteOnlyMergesDynamicServers(): void
    {
        $this->seedDynamic('ppp', 'WAN_PPP', '100.64.0.53');

        $settings = $this->settings(['dns_resolution_behavior' => 'remote-only']);

        $this->assertSame(
            ['100.64.0.53', '1.1.1.1', '1.0.0.1', '9.9.9.9'],
            $this->compiler()->nameservers($settings),
        );
    }

    /** A duplicate dynamic address is merged only once. */
    public function testDuplicateDynamicAddressIsMergedOnce(): void
    {
        $this->seedDynamic('dhcp', 'WAN', '1.1.1.1');
        $this->seedDynamic('ppp', 'WAN_PPP', '1.1.1.1');

        // Stored once per source, but the compiler deduplicates across the merge.
        $this->assertSame(['1.1.1.1'], $this->compiler()->dynamicServers());
        $this->assertSameCount(1, $this->compiler()->nameservers(), '1.1.1.1');
    }

    /** The merged list never exceeds the daemon's ceiling of eight servers. */
    public function testMergedServerListIsCappedAtEight(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->seedDynamic('dhcp', 'WAN', "10.0.0.{$i}");
        }

        $servers = $this->compiler()->nameservers();

        $this->assertCount(8, $servers);
        $this->assertSame('127.0.0.1', $servers[0]);
        $this->assertSame('10.0.0.1', $servers[1]);
    }

    /** A dynamic server keeps a remote-only policy resolvable with no statics. */
    public function testDynamicServerMakesRemoteOnlyResolvable(): void
    {
        $this->seedDynamic('dhcp', 'WAN', '10.0.0.138');

        $compiler = $this->compiler();
        $settings = $this->settings(['dns_resolution_behavior' => 'remote-only', 'upstream_servers' => '[]']);

        $this->assertFalse($compiler->isUnresolvable($settings));
        $this->assertSame(['10.0.0.138'], $compiler->nameservers($settings));
    }

    /** With no dynamic rows the artifact says so instead of implying a merge. */
    public function testCompiledArtifactNotesAbsentDynamicServers(): void
    {
        $conf = $this->compiler()->compile($this->settings(['dns_server_override' => 1]));

        $this->assertStringContainsString('# No dynamically assigned WAN/OpenVPN DNS servers are currently present.', $conf);
    }

    /** Counts a specific value in an array (helper for readability). */
    private function assertSameCount(int $expected, array $haystack, string $needle): void
    {
        $this->assertSame($expected, count(array_keys($haystack, $needle, true)));
    }
}
