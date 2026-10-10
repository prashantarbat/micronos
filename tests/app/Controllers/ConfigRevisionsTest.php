<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use App\Libraries\ConfigRevisionEngine;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * Feature tests for ConfigRevisions Controller (System -> Configuration Revisions & Diff).
 */
final class ConfigRevisionsTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'system.settings.edit',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        AppVersion::clearCache();

        // Seed CSRF token
        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        // Ensure system_settings has row 1
        if ($this->db->table('system_settings')->where('id', 1)->countAllResults() === 0) {
            $this->db->table('system_settings')->insert([
                'id'       => 1,
                'hostname' => 'micronos-fw01',
                'domain'   => 'firewall.local',
                'theme'    => 'dark',
            ]);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        AppVersion::setVersion(0, 1, 23);
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function csrf(array $params = []): array
    {
        return $params + [config(SecurityConfig::class)->tokenName => service('security')->getHash()];
    }

    public function testIndexRendersTimelineAndAppVersion(): void
    {
        $result = $this->withSession($this->session())->get('system/revisions');

        $result->assertStatus(200);
        $result->assertSee('Configuration Revisions &amp; Diff');
        $result->assertSee('Micron OS');
        $result->assertSee('Git Revision Engine');
        $result->assertSee('v' . AppVersion::getVersion());
        $result->assertSee(AppVersion::getChannel());
    }

    public function testManualCommitCreatesRevisionAndBumpsPatch(): void
    {
        $engine = \Config\Services::configRevisionEngine();

        $result = $this->withSession($this->session())->post('system/revisions/commit', $this->csrf([
            'message' => 'Test manual checkpoint snapshot',
        ]));

        $result->assertStatus(302);
        $result->assertSessionHas('success');

        // Verify history has at least 1 revision
        $history = $engine->getHistory(10);
        $this->assertNotEmpty($history);
        $this->assertSame('Test manual checkpoint snapshot', $history[0]['message']);
    }

    public function testDiffEndpointReturnsJson(): void
    {
        $engine = \Config\Services::configRevisionEngine();
        $rev = $engine->commit('ministrator', 'Baseline for diff testing', 'system', true);
        $hash = $rev['commit_hash'];

        $result = $this->withSession($this->session())->get("system/revisions/diff/{$hash}?format=json");

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);

        $this->assertTrue($body['success']);
        $this->assertSame($hash, $body['diff']['commit_hash']);
        $this->assertArrayHasKey('lines', $body['diff']);
    }

    public function testExportEndpointReturnsJsonAttachment(): void
    {
        $engine = \Config\Services::configRevisionEngine();
        $this->db->table('system_settings')->where('id', 1)->update(['hostname' => 'export-test-box']);
        $rev = $engine->commit('ministrator', 'Snapshot for export test', 'system', true);
        $hash = $rev['commit_hash'];

        $result = $this->withSession($this->session())->get("system/revisions/export/{$hash}");

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $body = json_decode($result->getJSON(), true);

        $this->assertSame('Micron OS', $body['project']);
        $this->assertArrayHasKey('tables', $body);
    }

    public function testRollbackEndpointRestoresConfiguration(): void
    {
        $engine = \Config\Services::configRevisionEngine();

        // 1. Checkpoint 1
        $this->db->table('system_settings')->where('id', 1)->update(['hostname' => 'box-point-1']);
        $rev1 = $engine->commit('ministrator', 'Point 1', 'system', true);

        // 2. Checkpoint 2
        $this->db->table('system_settings')->where('id', 1)->update(['hostname' => 'box-point-2']);
        $rev2 = $engine->commit('ministrator', 'Point 2', 'system', true);

        // 3. Rollback to Point 1
        $result = $this->withSession($this->session())->post("system/revisions/rollback/{$rev1['commit_hash']}", $this->csrf([]));

        $result->assertStatus(302);
        $result->assertSessionHas('success');

        // Verify DB was restored
        $current = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
        $this->assertSame('box-point-1', $current['hostname']);
    }
}
