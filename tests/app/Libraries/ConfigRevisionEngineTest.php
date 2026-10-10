<?php

namespace Tests\App\Libraries;

use App\Libraries\AppVersion;
use App\Libraries\ConfigRevisionEngine;
use App\Models\ConfigRevisionModel;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

/**
 * Unit and integration tests for ConfigRevisionEngine.
 */
class ConfigRevisionEngineTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    protected ConfigRevisionEngine $engine;
    protected string $testRepoPath;

    protected function setUp(): void
    {
        parent::setUp();
        AppVersion::clearCache();

        // Ensure system_settings row 1 exists
        if ($this->db->table('system_settings')->where('id', 1)->countAllResults() === 0) {
            $this->db->table('system_settings')->insert([
                'id'       => 1,
                'hostname' => 'micronos-fw01',
                'domain'   => 'firewall.local',
                'theme'    => 'dark',
            ]);
        }

        // Use a clean test repository in writable
        $this->testRepoPath = WRITEPATH . 'test_config_repo_' . uniqid();
        $this->engine = new ConfigRevisionEngine($this->db, $this->testRepoPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up temporary repo
        $this->removeDirectory($this->testRepoPath);
        AppVersion::setVersion(0, 1, 23);
    }

    public function testEnsureGitRepoCreatesGitDirectory(): void
    {
        $this->assertTrue(is_dir($this->testRepoPath));
        $this->assertTrue(is_dir($this->testRepoPath . DIRECTORY_SEPARATOR . '.git'));
    }

    public function testExportStateContainsApplianceMetadata(): void
    {
        $state = $this->engine->exportState('system');

        $this->assertSame('Micron OS', $state['project']);
        $this->assertSame('0.1.23', $state['version']);
        $this->assertSame('Development', $state['channel']);
        $this->assertIsArray($state['tables']);
        $this->assertArrayHasKey('system_settings', $state['tables']);
    }

    public function testCommitCreatesGitCommitAndRecordsInDatabase(): void
    {
        $startVersion = AppVersion::getVersion();
        $this->assertSame('0.1.23', $startVersion);

        // Make an initial commit
        $result = $this->engine->commit('ministrator', 'Initial system baseline', 'system', true);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['commit_hash']);
        $this->assertSame('0.1.24', $result['version']); // Patch bumped!
        $this->assertSame('Development', $result['channel']);
        $this->assertSame('ministrator', $result['author']);

        // Verify database entry
        $model = new ConfigRevisionModel();
        $rev = $model->getByHash($result['commit_hash']);
        $this->assertNotNull($rev);
        $this->assertSame('Initial system baseline', $rev['message']);
        $this->assertSame('system', $rev['subsystem']);
    }

    public function testGetDiffComputesUnifiedLineChanges(): void
    {
        // 1. Initial commit
        $rev1 = $this->engine->commit('ministrator', 'Baseline', 'system', true);

        // 2. Modify a setting in DB to create real diff
        $this->db->table('system_settings')->where('id', 1)->update([
            'hostname' => 'micronos-edge-gateway-99',
        ]);

        // 3. Second commit
        $rev2 = $this->engine->commit('ministrator', 'Updated hostname to edge gateway', 'system', true);

        // 4. Inspect Diff
        $diff = $this->engine->getDiff($rev2['commit_hash']);

        $this->assertSame($rev2['commit_hash'], $diff['commit_hash']);
        $this->assertGreaterThan(0, $diff['additions']);
        $this->assertIsArray($diff['lines']);

        // Check line elements
        $foundAdd = false;
        foreach ($diff['lines'] as $line) {
            if ($line['type'] === 'add' && str_contains($line['content'], 'micronos-edge-gateway-99')) {
                $foundAdd = true;
                break;
            }
        }
        $this->assertTrue($foundAdd, 'Unified diff must include the added hostname.');
    }

    public function testRollbackRestoresPreviousState(): void
    {
        // Baseline hostname
        $this->db->table('system_settings')->where('id', 1)->update([
            'hostname' => 'baseline-box',
        ]);
        $rev1 = $this->engine->commit('ministrator', 'Baseline hostname setup', 'system', true);

        // Change hostname
        $this->db->table('system_settings')->where('id', 1)->update([
            'hostname' => 'changed-box',
        ]);
        $rev2 = $this->engine->commit('ministrator', 'Change to changed-box', 'system', true);

        // Verify current value is changed-box
        $curr = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
        $this->assertSame('changed-box', $curr['hostname']);

        // Rollback to rev1
        $rollbackResult = $this->engine->rollback($rev1['commit_hash'], 'ministrator');
        $this->assertTrue($rollbackResult['success']);

        // Verify DB value is restored to baseline-box
        $restored = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
        $this->assertSame('baseline-box', $restored['hostname']);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        // On Windows, use shell rmdir /s /q
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('rmdir /s /q ' . escapeshellarg($path));
        } else {
            @exec('rm -rf ' . escapeshellarg($path));
        }
    }
}
