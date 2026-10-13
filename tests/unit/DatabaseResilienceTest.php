<?php

namespace Tests\Unit;

use App\Libraries\DatabaseResilienceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DatabaseResilienceTest extends CIUnitTestCase
{
    private string $tempDir;
    private string $testDbPath;
    private string $testSnapshotPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'micron_resilience_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);

        $this->testDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_config.db';
        $this->testSnapshotPath = $this->testDbPath . '.snapshot';

        // Initialize SQLite database with sample schema & data
        $sqlite = new \SQLite3($this->testDbPath);
        $sqlite->exec('CREATE TABLE system_settings (key TEXT PRIMARY KEY, val TEXT);');
        $sqlite->exec("INSERT INTO system_settings VALUES ('hostname', 'micron-appliance');");
        $sqlite->exec("INSERT INTO system_settings VALUES ('uptime', '3600');");
        $sqlite->close();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up files in temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @chmod($f, 0666);
                        @unlink($f);
                    }
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testPathsResolution(): void
    {
        $service = new DatabaseResilienceService($this->testDbPath);

        $this->assertSame($this->testDbPath, $service->getDbPath());
        $this->assertSame($this->testSnapshotPath, $service->getSnapshotPath());
    }

    public function testEnforceWalModeAndBarriers(): void
    {
        $service = new DatabaseResilienceService($this->testDbPath);
        $result = $service->enforceWal();

        $this->assertSame('applied', $result['status']);
        $this->assertSame('wal', $result['journal_mode']);
        $this->assertSame(2, $result['synchronous']);
        $this->assertSame(1000, $result['wal_autocheckpoint']);
        $this->assertSame(5000, $result['busy_timeout']);
    }

    public function testVerifyIntegrityOnHealthyDatabase(): void
    {
        $service = new DatabaseResilienceService($this->testDbPath);
        $check = $service->verifyIntegrity();

        $this->assertTrue($check['healthy']);
        $this->assertSame('ok', $check['status']);
        $this->assertSame('ok', strtolower($check['result']));
        $this->assertEmpty($check['errors']);
    }

    public function testCreateSnapshotViaVacuumInto(): void
    {
        $service = new DatabaseResilienceService($this->testDbPath);
        $snap = $service->createSnapshot();

        $this->assertTrue($snap['success']);
        $this->assertFileExists($this->testSnapshotPath);
        $this->assertGreaterThan(0, $snap['size']);
        $this->assertNotEmpty($snap['sha256']);

        // Verify snapshot contains identical data
        $snapSqlite = new \SQLite3($this->testSnapshotPath, SQLITE3_OPEN_READONLY);
        $val = $snapSqlite->querySingle("SELECT val FROM system_settings WHERE key = 'hostname';");
        $snapSqlite->close();

        $this->assertSame('micron-appliance', $val);
    }

    public function testRestorationFromSnapshotAfterCorruption(): void
    {
        $service = new DatabaseResilienceService($this->testDbPath);

        // 1. Take snapshot of healthy database
        $snap = $service->createSnapshot();
        $this->assertTrue($snap['success']);

        // 2. Simulate violent corrupting power loss by clobbering database header & body
        file_put_contents($this->testDbPath, "CORRUPTED_TRUNCATED_GARBAGE_BYTES_NO_SQLITE_HEADER\x00\xFF\xFE\xFD");

        // Verify corruption is detected
        $checkCorrupted = $service->verifyIntegrity();
        $this->assertFalse($checkCorrupted['healthy']);

        // 3. Perform restoration
        $restore = $service->restoreFromSnapshot();
        $this->assertTrue($restore['success']);

        // 4. Verify database is once again completely healthy
        $checkRestored = $service->verifyIntegrity();
        $this->assertTrue($checkRestored['healthy']);
        $this->assertSame('ok', strtolower($checkRestored['result']));

        // Verify data was preserved
        $sqlite = new \SQLite3($this->testDbPath, SQLITE3_OPEN_READONLY);
        $val = $sqlite->querySingle("SELECT val FROM system_settings WHERE key = 'hostname';");
        $sqlite->close();

        $this->assertSame('micron-appliance', $val);
    }

    public function testAutoHealRoutineRecoversCorruptedDatabase(): void
    {
        $service = new DatabaseResilienceService($this->testDbPath);

        // First pass on healthy database takes snapshot
        $healHealthy = $service->autoHeal();
        $this->assertTrue($healHealthy['healthy']);
        $this->assertFalse($healHealthy['healed']);
        $this->assertFileExists($this->testSnapshotPath);

        // Corrupt database
        file_put_contents($this->testDbPath, "TRUNCATED_POWER_FAIL_DATA");

        // Second pass auto-heals from snapshot
        $healCorrupted = $service->autoHeal();
        $this->assertTrue($healCorrupted['healthy']);
        $this->assertTrue($healCorrupted['healed']);
        $this->assertSame('restored_from_snapshot', $healCorrupted['action']);

        // Verify restored integrity
        $check = $service->verifyIntegrity();
        $this->assertTrue($check['healthy']);
    }
}
