<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Validates the CVEs & Security Advisories feature:
 * - Menu endpoint rendering (/security/cve and /cve)
 * - Authenticated root vulnerability package scanning
 * - NIST NVD document links
 * - Feed refresh & CSV export
 */
final class CveAdvisoriesTest extends BaseTestCase
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
    }

    private function adminSession(): array
    {
        $perms = [
            'dashboard.view', 'ids.view', 'ids.edit', 'security.cve.view', 'security.cve.scan',
            'system.info.view', 'system.maintenance.manage'
        ];

        return [
            'logged_in'        => true,
            'user_id'          => 'usr_superadmin',
            'username'         => 'administrator',
            'is_superadmin'    => 1,
            'permissions'      => $perms,
            'user_permissions' => $perms,
        ];
    }

    public function testCveIndexRendersCleanly(): void
    {
        $res = $this->withSession($this->adminSession())->get('security/cve');
        $status = $res->response()->getStatusCode();
        $body = $res->response()->getBody();

        $this->assertSame(200, $status);
        $this->assertStringContainsString('CVEs &amp; Security Advisories', $body);
        $this->assertStringContainsString('NIST NVD', $body);
        $this->assertStringContainsString('https://nvd.nist.gov/vuln/detail/', $body);
        $this->assertStringNotContainsString('Whoops!', $body);
        $this->assertStringNotContainsString('ErrorException', $body);
    }

    public function testCveAliasRouteWorks(): void
    {
        $res = $this->withSession($this->adminSession())->get('cve');
        $this->assertSame(200, $res->response()->getStatusCode());
        $this->assertStringContainsString('CVEs &amp; Security Advisories', $res->response()->getBody());
    }

    public function testRunAuthenticatedScan(): void
    {
        $res = $this->withSession($this->adminSession())->post('security/cve/scan', [
            csrf_token() => csrf_hash(),
        ]);
        $this->assertSame(302, $res->response()->getStatusCode());

        // Verify scan record created
        $scan = $this->db->table('cve_scans')->orderBy('created_at', 'DESC')->get()->getRowArray();
        $this->assertNotNull($scan);
        $this->assertSame('authenticated_root', $scan['scan_type']);
        $this->assertGreaterThan(0, $scan['total_packages']);

        // Verify advisories populated
        $advCount = $this->db->table('cve_advisories')->countAllResults();
        $this->assertGreaterThan(0, $advCount);
    }

    public function testRefreshFeed(): void
    {
        $res = $this->withSession($this->adminSession())->post('security/cve/refresh-feed', [
            csrf_token() => csrf_hash(),
        ]);
        $this->assertSame(302, $res->response()->getStatusCode());
    }

    public function testExportCsv(): void
    {
        $res = $this->withSession($this->adminSession())->get('security/cve/export-csv');
        $this->assertSame(200, $res->response()->getStatusCode());
        $this->assertStringContainsString('text/csv', $res->response()->getHeaderLine('Content-Type'));
        $body = $res->response()->getBody();
        $this->assertStringContainsString('CVE ID', $body);
        $this->assertStringContainsString('NIST NVD Advisory URL', $body);
    }

    public function testAdvisoryDetail(): void
    {
        // Scan against a fixed package list, so the result does not depend on
        // whether the build host happens to have openssh-server installed.
        $engine = new class ($this->db) extends \App\Libraries\CveAuditEngine {
            public function discoverInstalledPackages(): array
            {
                return ['openssh-server' => ['package' => 'openssh-server', 'version' => '1:9.2p1-2', 'arch' => 'amd64']];
            }
        };
        $engine->runAuthenticatedScan();

        $res = $this->withSession($this->adminSession())->get('security/cve/detail/CVE-2024-6387');
        $this->assertSame(200, $res->response()->getStatusCode());
        $json = json_decode($res->response()->getBody(), true);
        $this->assertIsArray($json);
        $this->assertSame('CVE-2024-6387', $json['id']);
        $this->assertSame('CRITICAL', $json['severity']);
        $this->assertStringContainsString('https://nvd.nist.gov/vuln/detail/CVE-2024-6387', $json['nvd_url']);
    }
}
