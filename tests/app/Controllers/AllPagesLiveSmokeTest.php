<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

final class AllPagesLiveSmokeTest extends BaseTestCase
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
        $this->ipc = new FakeIpcClient();
        Services::injectMock('ipc', $this->ipc);

        // Ensure default system setting exists
        if ($this->db->table('system_settings')->where('id', 1)->countAllResults() === 0) {
            $this->db->table('system_settings')->insert([
                'id' => 1,
                'hostname' => 'micronos-live',
                'theme' => 'dark'
            ]);
        }
    }

    private function session(): array
    {
        $db = \Config\Database::connect();
        $perms = [];
        try {
            $rows = $db->table('permissions')->get()->getResultArray();
            $perms = array_column($rows, 'name');
        } catch (\Throwable $e) {}

        if (empty($perms)) {
            $perms = [
                'dashboard.view', 'interfaces.view', 'interfaces.edit',
                'firewall.rules.view', 'firewall.rules.manage', 'firewall.addresses.manage',
                'firewall.services.manage', 'firewall.schedules.manage', 'firewall.dos.manage',
                'nat.view', 'nat.manage', 'routing.table.view', 'routing.static.manage',
                'routing.pbr.manage', 'routing.vrf.manage', 'routing.routes.apply',
                'vpn.wireguard.view', 'vpn.wireguard.manage', 'vpn.openvpn.view',
                'vpn.openvpn.manage', 'vpn.ipsec.view', 'vpn.ipsec.manage',
                'qos.view', 'qos.apply', 'dhcp.view', 'dhcp.manage',
                'dns.view', 'dns.edit', 'ha.view', 'ha.manage',
                'diag.ping', 'diag.traceroute', 'diag.packet_capture', 'diag.logs',
                'diag.backup', 'system.view', 'system.info.view', 'system.settings.edit',
                'system.users.manage', 'system.revisions.view', 'system.revisions.manage',
                'plugins.view', 'plugins.manage', 'cron.view', 'cron.manage',
                'docs.view', 'certificates.view', 'certificates.manage', 'roles.manage',
            ];
        }

        return [
            'logged_in'        => true,
            'user_id'          => 'usr_superadmin',
            'username'         => 'ministrator',
            'is_superadmin'    => 1,
            'permissions'      => $perms,
            'user_permissions' => $perms,
        ];
    }

    public function testAllMajorPagesRenderWithout500Or404(): void
    {
        $routesToTest = [
            // Core & Network
            'dashboard',
            'terminal',
            'interfaces',
            'interfaces/new',
            'dhcp',
            'dhcp/relay',
            'dns',
            'pppoe',
            'ha',
            'multicast',
            'captive',

            // Routing
            'routing',
            'routing/static',
            'routing/ospf',
            'routing/bgp',
            'routing/isis',
            'routing/rip',
            'routing/sdwan',

            // Policy & Firewall
            'firewall/rules',
            'firewall/dos',
            'firewall/addresses',
            'firewall/address-groups',
            'firewall/schedules',
            'firewall/internet-services',
            'firewall/services',
            'firewall/service-groups',
            'nat',
            'nat?tab=dnat',
            'nat?tab=snat',
            'nat?tab=outbound',
            'nat?tab=nptv6',
            'qos',

            // VPN
            'vpn/wireguard',
            'vpn/openvpn',
            'vpn/ipsec',
            'vpn/portal',
            'vpn/clientless-gateway',
            'vpn/clientless-gateway/portal',

            // Security
            'webfilter',
            'webfilter?tab=general',
            'webfilter?tab=acls',
            'webfilter?tab=filtering',
            'webfilter?tab=ssl',
            'webfilter?tab=cache',
            'webfilter?tab=wpad',
            'webfilter?tab=antivirus',
            'webfilter?tab=logs',
            'ids',
            'appid',
            'certificates',
            'siem',
            'ssl-inspection',
            'security/threat-intel',
            'security/cve',
            'cve',

            // Admin & Operations
            'users',
            'roles',
            'system/sso',
            'system/revisions',
            'backup',
            'diag',
            'diag/logs',
            'services',
            'snmp',
            'cron',
            'system/updates',
            'system',
            'docs',
            'plugins',
            'plugins/updates',
            'plugins/repositories',
        ];

        $failed = [];
        foreach ($routesToTest as $uri) {
            try {
                $response = $this->withSession($this->session())->get($uri);
                $status = $response->response()->getStatusCode();
                $body = $response->response()->getBody();

                if ($status >= 400) {
                    $failed[] = "[$uri] HTTP $status - " . substr(strip_tags($body), 0, 150);
                } elseif (str_contains($body, 'Whoops!') || str_contains($body, 'An error occurred') || str_contains($body, 'Unable to prepare statement') || str_contains($body, 'no such table')) {
                    $failed[] = "[$uri] Error text detected in response body: " . substr(strip_tags($body), 0, 150);
                }
            } catch (\Throwable $e) {
                $failed[] = "[$uri] Exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
            }
        }

        $this->assertEmpty($failed, "The following pages failed smoke test:\n" . implode("\n", $failed));
    }
}
