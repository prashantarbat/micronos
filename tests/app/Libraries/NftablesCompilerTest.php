<?php

use App\Libraries\NftablesCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Covers the DNAT (Destination NAT) and SNAT-pool halves of the nftables
 * compiler — the two object types introduced by the vendor-neutral NAT rename.
 *
 * The full appliance migration set is executed against the in-memory `tests`
 * database so the compiler sees real schema, and rows are inserted directly.
 *
 * @internal
 */
final class NftablesCompilerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    protected $migrate     = true;
    protected $migrateOnce = true;

    // The in-memory test database is shared for the whole run; a full
    // regression would exercise down() methods that do not need to be part of
    // this suite. Migrate once (latest) and clear the tables we touch.
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();

        $interfacesTable = $this->db->getPrefix() . 'interfaces';
        $interfaceColumns = $this->db->query("PRAGMA table_info('{$interfacesTable}')")->getResultArray();
        if ($interfaceColumns === []) {
            $this->db->query("CREATE TABLE {$interfacesTable} (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(64) NOT NULL,
                physical_device VARCHAR(32) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                interface_type VARCHAR(16) NOT NULL DEFAULT 'physical',
                ip_assignment_type VARCHAR(16) NOT NULL DEFAULT 'none',
                ipv4_address VARCHAR(45),
                ipv4_netmask INTEGER,
                ipv4_gateway VARCHAR(45),
                ipv6_assignment_type VARCHAR(16) DEFAULT 'disabled',
                ipv6_address VARCHAR(45),
                ipv6_prefix INTEGER,
                admin_access TEXT
            )");
        } elseif (! in_array('admin_access', array_column($interfaceColumns, 'name'), true)) {
            $this->db->query("ALTER TABLE {$interfacesTable} ADD COLUMN admin_access TEXT NULL");
        }

        $this->db->table('nat_dnat')->emptyTable();
        $this->db->table('nat_snat_pools')->emptyTable();
        $this->db->table('firewall_rules')->emptyTable();
        $this->db->table('firewall_addresses')->where('is_system', 0)->delete();
        $this->clearPluginRows();

        // The interfaces table is normally seeded by an early migration; make
        // sure the logical → kernel device mapping the compiler relies on is
        // present regardless of seed order.
        $this->db->table('interfaces')->where('id', 'wan')->delete();
        $this->db->table('interfaces')->where('id', 'lan')->delete();
        $this->db->table('interfaces')->insertBatch([
            ['id' => 'wan', 'name' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1', 'enabled' => 1, 'ip_assignment_type' => 'static'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearPluginRows();

        parent::tearDown();
    }

    /**
     * The plugin rows the gateway compiler reads live in the same database as
     * the firewall state; drop the two this file seeds so a run cannot leak
     * accepts into an unrelated test.
     */
    private function clearPluginRows(): void
    {
        if ($this->db->tableExists('plugins')) {
            $this->db->table('plugins')->whereIn('id', ['nat64', 'ipv6-tunnel'])->delete();
        }
    }

    /**
     * While the NAT64/DNS64 plugin is installed and enabled, its tayga tun
     * device is accepted in both directions in the forward chain — otherwise
     * the default-deny policy would drop every translated packet.
     */
    public function testEnabledPluginVirtualInterfacesAreAcceptedInForwardChain(): void
    {
        $this->seedPlugin('nat64', 1);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('iifname "nat64" accept', $ruleset);
        $this->assertStringContainsString('oifname "nat64" accept', $ruleset);
        // The 6in4 tunnel is a separate plugin and must not appear here.
        $this->assertStringNotContainsString('"sit1"', $ruleset);
    }

    /**
     * The IPv6 tunnel plugin's 6in4 device is accepted only while its own row
     * is enabled, and its label is distinguishable from the NAT64 device.
     */
    public function testEnabledTunnelPluginAcceptsSit1(): void
    {
        $this->seedPlugin('ipv6-tunnel', 1);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('iifname "sit1" accept', $ruleset);
        $this->assertStringContainsString('oifname "sit1" accept', $ruleset);
        $this->assertStringNotContainsString('"nat64"', $ruleset);
    }

    /**
     * A plugin row that is installed but disabled, or absent entirely, leaves
     * no standing accept behind — the hole closes with the plugin.
     */
    public function testDisabledOrAbsentPluginsLeaveNoVirtualInterfaceAccept(): void
    {
        $ruleset = (new NftablesCompiler($this->db))->compile();
        $this->assertStringNotContainsString('"nat64"', $ruleset);
        $this->assertStringNotContainsString('"sit1"', $ruleset);

        $this->seedPlugin('nat64', 0);
        $this->seedPlugin('ipv6-tunnel', 0);

        $ruleset = (new NftablesCompiler($this->db))->compile();
        $this->assertStringNotContainsString('"nat64"', $ruleset);
        $this->assertStringNotContainsString('"sit1"', $ruleset);
    }

    /**
     * A port-forwarding DNAT rule is emitted in the prerouting chain with the
     * resolved kernel device, external match, port clause and mapped target.
     */
    public function testCompilesDnatPortForward(): void
    {
        $this->insertDnat([
            'id'            => 'dnat_test_web',
            'name'          => 'Web Forward',
            'interface_id'  => 'wan',
            'external_ip'   => '203.0.113.10',
            'external_port' => 8443,
            'protocol'      => 'tcp',
            'mapped_ip'     => '192.168.1.50',
            'mapped_port'   => 443,
            'nat_reflection' => 0,
            'bidirectional' => 0,
            'enabled'       => 1,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('iifname "eth0"', $ruleset);
        $this->assertStringContainsString('ip daddr 203.0.113.10', $ruleset);
        $this->assertStringContainsString('tcp dport 8443', $ruleset);
        $this->assertStringContainsString('dnat to 192.168.1.50:443', $ruleset);
        $this->assertStringContainsString('comment "DNAT: Web Forward"', $ruleset);
    }

    /**
     * A DNAT rule with no ports matches the whole destination IP and maps to
     * the bare mapped address.
     */
    public function testCompilesPortlessDnatMapping(): void
    {
        $this->insertDnat([
            'id'             => 'dnat_test_host',
            'name'           => 'Host Mapping',
            'interface_id'   => 'wan',
            'external_ip'    => '203.0.113.11',
            'external_port'  => null,
            'protocol'       => 'any',
            'mapped_ip'      => '192.168.1.60',
            'mapped_port'    => null,
            'nat_reflection' => 0,
            'bidirectional'  => 0,
            'enabled'        => 1,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('ip daddr 203.0.113.11 dnat to 192.168.1.60', $ruleset);
        // No port clause should be generated for an "any"/portless mapping.
        $this->assertStringNotContainsString('dport 8443', $ruleset);
    }

    /**
     * Disabled DNAT rules must never reach the compiled ruleset.
     */
    public function testDisabledDnatIsNotEmitted(): void
    {
        $this->insertDnat([
            'id'            => 'dnat_test_off',
            'name'          => 'Disabled Forward',
            'interface_id'  => 'wan',
            'external_ip'   => '203.0.113.12',
            'external_port' => 8080,
            'protocol'      => 'tcp',
            'mapped_ip'     => '192.168.1.70',
            'mapped_port'   => 80,
            'enabled'       => 0,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringNotContainsString('203.0.113.12', $ruleset);
        $this->assertStringNotContainsString('Disabled Forward', $ruleset);
    }

    /**
     * A bi-directional DNAT rule also emits the 1:1 return SNAT in postrouting.
     */
    public function testBiDirectionalDnatEmitsReturnSnat(): void
    {
        $this->insertDnat([
            'id'            => 'dnat_test_1to1',
            'name'          => 'One To One',
            'interface_id'  => 'wan',
            'external_ip'   => '203.0.113.13',
            'external_port' => null,
            'protocol'      => 'any',
            'mapped_ip'     => '192.168.1.80',
            'mapped_port'   => null,
            'bidirectional' => 1,
            'enabled'       => 1,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString(
            'ip saddr 192.168.1.80 snat to 203.0.113.13 comment "DNAT 1:1 return: One To One"',
            $ruleset,
        );
    }

    /**
     * An SNAT pool referenced by a NAT-enabled policy compiles to a range
     * snat statement on the policy's destination interface.
     */
    public function testCompilesSnatPoolRangeFromPolicy(): void
    {
        $this->insertSnatPool([
            'id'           => 'snat_test_range',
            'name'         => 'Outbound Pool',
            'interface_id' => 'wan',
            'start_ip'     => '203.0.113.20',
            'end_ip'       => '203.0.113.30',
            'enabled'      => 1,
        ]);

        $this->insertPolicy([
            'id'             => 'pol_snat_range',
            'name'           => 'LAN to WAN',
            'src_interface'  => 'lan',
            'dst_interface'  => 'wan',
            'nat_enabled'    => 1,
            'snat_pool_name' => 'Outbound Pool',
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString(
            'oifname "eth0" snat to 203.0.113.20-203.0.113.30 '
            . 'comment "Policy NAT (SNAT pool Outbound Pool): LAN to WAN"',
            $ruleset,
        );
    }

    /**
     * A single-address SNAT pool compiles to a bare address (no range dash).
     */
    public function testCompilesSingleAddressSnatPool(): void
    {
        $this->insertSnatPool([
            'id'           => 'snat_test_single',
            'name'         => 'Single Egress',
            'interface_id' => 'wan',
            'start_ip'     => '203.0.113.40',
            'end_ip'       => null,
            'enabled'      => 1,
        ]);

        $this->insertPolicy([
            'id'             => 'pol_snat_single',
            'name'           => 'Guest Egress',
            'src_interface'  => 'lan',
            'dst_interface'  => 'wan',
            'nat_enabled'    => 1,
            'snat_pool_name' => 'Single Egress',
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString(
            'oifname "eth0" snat to 203.0.113.40 comment "Policy NAT (SNAT pool Single Egress): Guest Egress"',
            $ruleset,
        );
    }

    /**
     * A NAT-enabled policy with no pool falls back to interface masquerade.
     */
    public function testNatEnabledPolicyWithoutPoolMasquerades(): void
    {
        $this->insertPolicy([
            'id'            => 'pol_masq',
            'name'          => 'Masquerade Policy',
            'src_interface' => 'lan',
            'dst_interface' => 'wan',
            'nat_enabled'   => 1,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString(
            'oifname "eth0" masquerade comment "Policy NAT: Masquerade Policy"',
            $ruleset,
        );
    }

    // ------------------------------------------------------------------
    // FQDN & Internet Service sets
    // ------------------------------------------------------------------

    /**
     * A wildcard FQDN address object compiles to a named set reference rather
     * than a single (stale) resolved address.
     */
    public function testWildcardFqdnEmitsNamedSet(): void
    {
        $this->insertAddress([
            'id'    => 'addr_wild',
            'name'  => 'Partner_Wildcard',
            'type'  => 'fqdn',
            'value' => '*.example.com',
        ]);
        $this->insertPolicy([
            'id'           => 'pol_wild',
            'name'         => 'Allow Partner',
            'src_interface' => 'lan',
            'dst_interface' => 'wan',
            'destinations' => json_encode(['Partner_Wildcard']),
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('set fqdn_wild_example_com {', $ruleset);
        $this->assertStringContainsString('ip daddr @fqdn_wild_example_com', $ruleset);
    }

    /**
     * An internet-service address object compiles to its catalogue set.
     */
    public function testInternetServiceEmitsNamedSet(): void
    {
        $this->insertAddress([
            'id'    => 'addr_isdb',
            'name'  => 'M365_Access',
            'type'  => 'internet-service',
            'value' => 'microsoft_365, zoom',
        ]);
        $this->insertPolicy([
            'id'           => 'pol_isdb',
            'name'         => 'Allow SaaS',
            'src_interface' => 'lan',
            'dst_interface' => 'wan',
            'destinations' => json_encode(['M365_Access']),
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('set isdb_microsoft_365 {', $ruleset);
        $this->assertStringContainsString('set isdb_zoom {', $ruleset);
        $this->assertStringContainsString('ip daddr { @isdb_microsoft_365, @isdb_zoom }', $ruleset);
    }

    /**
     * Verifies that the compiler enforces enterprise Stealth Rule on WAN devices
     * to protect management ports, and terminates the forward chain with an
     * explicit Deny All by Default Cleanup Rule.
     */
    public function testCompilesStealthRuleAndDefaultDenyCleanupRule(): void
    {
        $ruleset = (new NftablesCompiler($this->db))->compile();

        // 1. Stealth Rule in input chain protecting management ports from WAN (eth0)
        $this->assertStringContainsString('iifname "eth0" tcp dport { 80, 443, 8443, 22, 23 } counter drop comment "Stealth Rule: Drop WAN Management Probing"', $ruleset);

        // 2. Base kernel policy drop in input & forward
        $this->assertStringContainsString('hook input priority filter; policy drop;', $ruleset);
        $this->assertStringContainsString('hook forward priority filter; policy drop;', $ruleset);

        // 3. Explicit bottom-of-rulebase terminator Cleanup Rule in forward chain
        $this->assertStringContainsString('counter drop comment "Default Deny All / Cleanup Rule"', $ruleset);
    }

    public function testAllowsWebGuiOnEnabledOptionalInterfacesWithoutOpeningSsh(): void
    {
        $this->db->table('interfaces')->insertBatch([
            ['id' => 'opt1', 'name' => 'OPT1', 'physical_device' => 'eth2', 'admin_access' => '["http","https","ping"]', 'enabled' => 1, 'ip_assignment_type' => 'static'],
            ['id' => 'opt2', 'name' => 'OPT2', 'physical_device' => 'eth3', 'admin_access' => '[]', 'enabled' => 0, 'ip_assignment_type' => 'static'],
            ['id' => 'opt3', 'name' => 'OPT3', 'physical_device' => 'eth0', 'admin_access' => '[]', 'enabled' => 1, 'ip_assignment_type' => 'static'],
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('iifname "eth2" tcp dport { 80, 443, 8443 } accept comment "Administrative access: http,https,ping"', $ruleset);
        $this->assertStringNotContainsString('iifname "eth2" tcp dport { 80, 443, 8443, 22 } accept', $ruleset);
        $this->assertStringNotContainsString('iifname "eth3" tcp dport', $ruleset);
        $this->assertStringNotContainsString('iifname "eth0" tcp dport { 80, 443, 8443 } accept', $ruleset);
        $this->assertStringContainsString('iifname "eth2" ip protocol icmp icmp type echo-request limit rate 50/second accept', $ruleset);
    }

    public function testAdministrativeAccessOnlyOpensSelectedServicesPerInterface(): void
    {
        $this->db->table('interfaces')->insert([
            'id' => 'opt_access_test', 'name' => 'OPT Access Test', 'physical_device' => 'eth7',
            'admin_access' => '["https","snmp"]', 'enabled' => 1, 'ip_assignment_type' => 'static',
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        $this->assertStringContainsString('iifname "eth7" tcp dport { 443, 8443 } accept comment "Administrative access: https,snmp"', $ruleset);
        $this->assertStringContainsString('iifname "eth7" udp dport 161 accept comment "Administrative access: SNMP"', $ruleset);
        $this->assertStringNotContainsString('iifname "eth7" ip protocol icmp icmp type echo-request', $ruleset);
        $this->assertStringNotContainsString('iifname "eth7" tcp dport { 80, 443, 8443, 22 } accept', $ruleset);
    }

    public function testVpnTunnelPoliciesAndRoutingProtocols(): void
    {
        if ($this->db->tableExists('wireguard_tunnels')) {
            $this->db->table('wireguard_tunnels')->emptyTable();
            $this->db->table('wireguard_tunnels')->insert([
                'id'             => 1,
                'name'           => 'WG538',
                'interface_name' => 'wg538',
                'private_key'    => 'aPrivateKey==',
                'public_key'     => 'aPublicKey==',
                'listen_port'    => 51820,
                'enabled'        => 1,
                'ipv4_address'   => '10.168.38.1',
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        $this->insertPolicy([
            'id'            => 'pol_wg_any',
            'name'          => 'Wiregaurd -> ANY',
            'src_interface' => 'wg538',
            'dst_interface' => 'any',
            'sources'       => json_encode(['all']),
            'destinations'  => json_encode(['all']),
            'services'      => json_encode(['ALL']),
            'action'        => 'accept',
            'nat_enabled'   => 1,
            'enabled'       => 1,
        ]);

        $this->insertPolicy([
            'id'            => 'pol_any_wg',
            'name'          => 'ANY -> Wiregaurd',
            'src_interface' => 'any',
            'dst_interface' => 'wg538',
            'sources'       => json_encode(['all']),
            'destinations'  => json_encode(['all']),
            'services'      => json_encode(['ALL']),
            'action'        => 'accept',
            'nat_enabled'   => 0,
            'enabled'       => 1,
        ]);

        $ruleset = (new NftablesCompiler($this->db))->compile();

        // 1. Dynamic Routing Protocols accepted in input chain
        $this->assertStringContainsString('ip protocol ospf accept comment "Routing: OSPFv2 (Protocol 89)"', $ruleset);
        $this->assertStringContainsString('tcp dport 179 accept comment "Routing: BGP (TCP 179)"', $ruleset);

        // 2. Wireguard policies in forward chain
        $this->assertStringContainsString('iifname "wg538"', $ruleset);
        $this->assertStringContainsString('oifname "wg538"', $ruleset);
        $this->assertStringContainsString('comment "Wiregaurd -> ANY"', $ruleset);
        $this->assertStringContainsString('comment "ANY -> Wiregaurd"', $ruleset);

        // 3. Policy NAT in postrouting chain for WG538 -> ANY
        $this->assertStringContainsString('iifname "wg538" masquerade comment "Policy NAT: Wiregaurd -> ANY"', $ruleset);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertAddress(array $overrides = []): void
    {
        $this->db->table('firewall_addresses')->insert(array_merge([
            'id'         => 'addr_' . bin2hex(random_bytes(4)),
            'name'       => 'Address',
            'type'       => 'ipmask',
            'value'      => '10.0.0.0/8',
            'interface'  => 'any',
            'is_system'  => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    private function insertDnat(array $overrides = []): void
    {
        $this->db->table('nat_dnat')->insert(array_merge([
            'name'           => 'DNAT',
            'interface_id'   => 'wan',
            'external_ip'    => '203.0.113.1',
            'external_port'  => null,
            'protocol'       => 'tcp',
            'mapped_ip'      => '192.168.1.2',
            'mapped_port'    => null,
            'nat_reflection' => 0,
            'bidirectional'  => 0,
            'comment'        => null,
            'enabled'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    private function insertSnatPool(array $overrides = []): void
    {
        $this->db->table('nat_snat_pools')->insert(array_merge([
            'name'         => 'Pool',
            'interface_id' => 'wan',
            'start_ip'     => '203.0.113.1',
            'end_ip'       => null,
            'comment'      => null,
            'enabled'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    private function seedPlugin(string $id, int $enabled): void
    {
        $this->db->table('plugins')->insert([
            'id'         => $id,
            'name'       => $id,
            'version'    => '1.0.0',
            'class'      => 'B',
            'state'      => $enabled ? 'enabled' : 'disabled',
            'enabled'    => $enabled,
            'data_policy' => 'retain',
            'installed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertPolicy(array $overrides = []): void
    {
        static $sequence = 0;
        $sequence++;

        $this->db->table('firewall_rules')->insert(array_merge([
            'id'             => 'pol_' . bin2hex(random_bytes(4)),
            'sequence'       => $sequence,
            'interface_id'   => 'lan',
            'action'         => 'accept',
            'direction'      => 'out',
            'protocol'       => 'any',
            'source_type'    => 'any',
            'source_value'   => 'any',
            'dest_type'      => 'any',
            'dest_value'     => 'any',
            'log_enabled'    => 0,
            'enabled'        => 1,
            'name'           => 'Policy',
            'src_interface'  => 'lan',
            'dst_interface'  => 'wan',
            'sources'        => json_encode(['LAN_Subnet']),
            'destinations'   => json_encode(['all']),
            'services'       => json_encode(['ALL']),
            'nat_enabled'    => 0,
            'schedule'       => 'always',
            'log_traffic'    => 'all',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    public function testCompilesCustomSshAndWebguiPorts(): void
    {
        if ($this->db->tableExists('system_settings')) {
            $this->db->table('system_settings')->where('id', 1)->delete();
            $this->db->table('system_settings')->insert([
                'id'              => 1,
                'hostname'        => 'custom-fw',
                'domain'          => 'test.local',
                'ssh_port'        => 2222,
                'webgui_port'     => 8443,
                'webgui_protocol' => 'https',
                'ipv6_enabled'    => 0,
            ]);
        }

        $compiler = new NftablesCompiler();
        $ruleset  = $compiler->compile();

        $this->assertStringContainsString('iifname "eth1" tcp dport { 80, 443, 2222, 8443 } accept', $ruleset);
        $this->assertStringContainsString('iifname "eth0" tcp dport { 80, 443, 8443, 22, 23, 2222 } counter drop', $ruleset);
    }
}
