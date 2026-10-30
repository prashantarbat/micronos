<?php

namespace Tests\Unit;

use App\Libraries\WireguardCompiler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WireguardClientProfileTest extends CIUnitTestCase
{
    public function testProfileWithoutPeerAddressDoesNotInventInterfaceAddress(): void
    {
        $compiler = new WireguardCompiler();
        $profile  = $compiler->generateClientProfile(
            [
                'name'                 => 'WG_ILL',
                'peer_address'         => null,
                'private_key'          => 'client-private-key',
                'persistent_keepalive' => 25,
            ],
            [
                'name'        => 'Test Tunnel',
                'interface_name' => 'wg0',
                'public_key'  => 'server-public-key',
                'dns_servers' => '1.1.1.1',
                'listen_port' => 51820,
                'mtu'         => 1420,
            ],
            'vpn.example.test',
            ['10.9.0.0/24']
        );

        $this->assertStringNotContainsString('Address = ', $profile);
        $this->assertStringNotContainsString('10.9.0.2/32', $profile);
        $this->assertStringContainsString('PrivateKey = client-private-key', $profile);
        $this->assertStringContainsString('AllowedIPs = 10.9.0.0/24', $profile);
    }
}