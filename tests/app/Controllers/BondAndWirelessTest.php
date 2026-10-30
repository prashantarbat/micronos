<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * Feature tests for LACP Link Aggregation (bond0) & Wireless WLAN (wlan0)
 * in Micron OS Interfaces Controller.
 *
 * @internal
 */
final class BondAndWirelessTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'interfaces.view',
        'interfaces.edit',
        'interfaces.apply',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        // This machine has one Wi-Fi radio, named wlan0 (development client).
        putenv('MICRON_MOCK_WIRELESS=wlan0');
        \Config\Services::injectMock('ipc', new \App\Libraries\IpcClient());

        $this->db->table('interface_bonds')->where('id', 'bond0')->delete();
        $this->db->table('wireless_interfaces')->where('id', 'wlan0')->delete();
        $this->db->table('interfaces')->whereIn('id', ['bond0', 'wlan0'])->delete();

        // Ensure base interfaces exist
        $now = date('Y-m-d H:i:s');
        if ($this->db->table('interfaces')->where('id', 'wan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id'                 => 'wan',
                'name'               => 'WAN',
                'physical_device'    => 'eth0',
                'interface_type'     => 'physical',
                'enabled'            => 1,
                'ip_assignment_type' => 'dhcp',
                'mtu'                => 1500,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
        if ($this->db->table('interfaces')->where('id', 'lan')->countAllResults() === 0) {
            $this->db->table('interfaces')->insert([
                'id'                 => 'lan',
                'name'               => 'LAN',
                'physical_device'    => 'eth1',
                'interface_type'     => 'physical',
                'enabled'            => 1,
                'ip_assignment_type' => 'static',
                'ipv4_address'       => '192.168.1.1',
                'ipv4_netmask'       => 24,
                'mtu'                => 1500,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('interface_bonds')->where('id', 'bond0')->delete();
        $this->db->table('wireless_interfaces')->where('id', 'wlan0')->delete();
        $this->db->table('interfaces')->whereIn('id', ['bond0', 'wlan0'])->delete();
        putenv('MICRON_MOCK_WIRELESS');
        parent::tearDown();
    }

    private function sessionData(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'ministrator',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params): array
    {
        $this->withSession($this->sessionData());

        return $params + [config(SecurityConfig::class)->tokenName => service('security')->getHash()];
    }

    public function testIndexViewContainsBondAndWirelessSections(): void
    {
        $result = $this->withSession($this->sessionData())->get('interfaces');

        $result->assertOK();
        $result->assertSee('Link Aggregation Groups');
        $result->assertSee('Wireless LAN');
        $result->assertSee('bond0');
        $result->assertSee('wlan0');
    }

    public function testStoreBondCreatesRecordAndInterface(): void
    {
        $payload = $this->csrf([
            'id'               => 'bond0',
            'name'             => 'Core Switch LACP Trunk',
            'mode'             => '802.3ad',
            'slaves'           => ['eth1', 'eth2'],
            'lacp_rate'        => 'fast',
            'xmit_hash_policy' => 'layer2+3',
            'miimon'           => 100,
            'mtu'              => 1500,
        ]);

        $result = $this->withSession($this->sessionData())->post('interfaces/bonds/store', $payload);

        $result->assertRedirectTo(base_url('interfaces'));
        $result->assertSessionHas('success');

        // Verify interface_bonds table
        $bondRow = $this->db->table('interface_bonds')->where('id', 'bond0')->get()->getRowArray();
        $this->assertNotNull($bondRow);
        $this->assertSame('Core Switch LACP Trunk', $bondRow['name']);
        $this->assertSame('802.3ad', $bondRow['mode']);

        $slaves = json_decode((string) $bondRow['slaves'], true);
        $this->assertContains('eth1', $slaves);
        $this->assertContains('eth2', $slaves);

        // Verify interfaces table registration
        $ifaceRow = $this->db->table('interfaces')->where('id', 'bond0')->get()->getRowArray();
        $this->assertNotNull($ifaceRow);
        $this->assertSame('bond', $ifaceRow['interface_type']);
        $this->assertSame('bond0', $ifaceRow['physical_device']);
    }

    public function testDeleteBondRemovesRecordAndReleasesInterface(): void
    {
        // First insert
        $now = date('Y-m-d H:i:s');
        $this->db->table('interface_bonds')->insert([
            'id'               => 'bond0',
            'name'             => 'Core Trunk',
            'mode'             => '802.3ad',
            'slaves'           => json_encode(['eth1', 'eth2']),
            'lacp_rate'        => 'fast',
            'xmit_hash_policy' => 'layer2+3',
            'miimon'           => 100,
            'mtu'              => 1500,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $this->db->table('interfaces')->insert([
            'id'                 => 'bond0',
            'name'               => 'Core Trunk',
            'physical_device'    => 'bond0',
            'interface_type'     => 'bond',
            'enabled'            => 1,
            'ip_assignment_type' => 'none',
            'mtu'                => 1500,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $payload = $this->csrf([]);
        $result = $this->withSession($this->sessionData())->post('interfaces/bonds/bond0/delete', $payload);

        $result->assertRedirectTo(base_url('interfaces'));
        $result->assertSessionHas('success');

        $this->assertSame(0, $this->db->table('interface_bonds')->where('id', 'bond0')->countAllResults());
        $this->assertSame(0, $this->db->table('interfaces')->where('id', 'bond0')->countAllResults());
    }

    public function testSaveWirelessCreatesConfigAndRegistersInterface(): void
    {
        $payload = $this->csrf([
            'id'               => 'wlan0',
            'interface'        => 'wlan0',
            'name'             => 'Micron Corporate Wi-Fi',
            'mode'             => 'ap',
            'ssid'             => 'MicronOS-Enterprise',
            'passphrase'       => 'SecureWPA3Key!2026',
            'security'         => 'wpa3',
            'hw_mode'          => 'ax',
            'channel'          => 36,
            'country_code'     => 'US',
            'hidden_ssid'      => 0,
            'client_isolation' => 1,
            'tx_power'         => 20,
            'enabled'          => 1,
        ]);

        $result = $this->withSession($this->sessionData())->post('interfaces/wireless/save', $payload);

        $result->assertRedirectTo(base_url('interfaces'));
        $result->assertSessionHas('success');

        // Check wireless_interfaces table
        $wRow = $this->db->table('wireless_interfaces')->where('id', 'wlan0')->get()->getRowArray();
        $this->assertNotNull($wRow);
        $this->assertSame('MicronOS-Enterprise', $wRow['ssid']);
        $this->assertSame('wpa3', $wRow['security']);
        $this->assertSame('ax', $wRow['hw_mode']);
        $this->assertSame(36, (int) $wRow['channel']);
        $this->assertSame(1, (int) $wRow['client_isolation']);

        // Check interfaces table
        $ifaceRow = $this->db->table('interfaces')->where('physical_device', 'wlan0')->get()->getRowArray();
        $this->assertNotNull($ifaceRow);
        $this->assertSame('wireless', $ifaceRow['interface_type']);
        $this->assertSame('192.168.50.1', $ifaceRow['ipv4_address']);
    }

    public function testWirelessStatusEndpointReturnsJson(): void
    {
        $result = $this->withSession($this->sessionData())->get('interfaces/wireless/status/wlan0');

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success'] ?? false);
    }

    public function testWirelessOptionsAreHiddenWithoutAWirelessAdapter(): void
    {
        putenv('MICRON_MOCK_WIRELESS');

        $result = $this->withSession($this->sessionData())->get('interfaces');
        $result->assertOK();
        $body = $result->getBody();

        $this->assertStringNotContainsString('id="wirelessSection"', $body);
        $this->assertStringNotContainsString('id="configureWirelessModal"', $body);
        $this->assertStringNotContainsString('Setup Wi-Fi', $body);
        $this->assertStringNotContainsString('value="wlan0"', $body, 'no invented wlan0 in the port picker');
    }

    public function testWirelessOptionsAppearWhenAnAdapterIsInstalled(): void
    {
        $body = $this->withSession($this->sessionData())->get('interfaces')->getBody();

        $this->assertStringContainsString('id="wirelessSection"', $body);
        $this->assertStringContainsString('id="configureWirelessModal"', $body);
        $this->assertMatchesRegularExpression('/<select id="wlan_interface"[^>]*>\s*<option value="wlan0"/', $body);
    }

    public function testSavingWifiIsRefusedWithoutAWirelessAdapter(): void
    {
        putenv('MICRON_MOCK_WIRELESS');

        $result = $this->post('interfaces/wireless/save', $this->csrf([
            'id' => 'wlan0', 'interface' => 'wlan0', 'mode' => 'ap', 'ssid' => 'Test', 'security' => 'wpa2', 'passphrase' => 'longenough1',
        ]));

        $result->assertRedirect();
        $this->assertNull($this->db->table('wireless_interfaces')->where('id', 'wlan0')->get()->getRowArray());
    }

    public function testSavingWifiIsRefusedForANonWirelessPort(): void
    {
        $result = $this->post('interfaces/wireless/save', $this->csrf([
            'id' => 'enp3s0', 'interface' => 'enp3s0', 'mode' => 'ap', 'ssid' => 'Test', 'security' => 'wpa2', 'passphrase' => 'longenough1',
        ]));

        $result->assertRedirect();
        $this->assertNull($this->db->table('wireless_interfaces')->where('id', 'enp3s0')->get()->getRowArray());
    }

    public function testWirelessStatusIs404ForAnAbsentRadio(): void
    {
        putenv('MICRON_MOCK_WIRELESS');
        $this->withSession($this->sessionData())->get('interfaces/wireless/status/wlan0')->assertStatus(404);
    }

    public function testTheStoredPassphraseIsNeverRenderedIntoThePage(): void
    {
        $this->db->table('wireless_interfaces')->insert([
            'id' => 'wlan0', 'interface' => 'wlan0', 'name' => 'AP', 'mode' => 'ap', 'ssid' => "Bob's AP",
            'passphrase' => 'SuperSecretPassphrase9', 'security' => 'wpa2', 'hw_mode' => 'g', 'channel' => 6,
            'country_code' => 'US', 'hidden_ssid' => 0, 'client_isolation' => 0, 'tx_power' => 20, 'enabled' => 1,
        ]);

        $body = $this->withSession($this->sessionData())->get('interfaces')->getBody();
        $this->assertStringNotContainsString('SuperSecretPassphrase9', $body);
        $this->assertStringContainsString('has_passphrase', $body);
    }
}
