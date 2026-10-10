<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The console transactional rollback (`config:rollback`), driven by
 * `bin/microctl config rollback`.
 *
 * It is the console equivalent of the layout's Rollback action: discard the
 * unconfirmed configuration transaction, then have the daemon reload the
 * snapshot it captured before the last apply. The daemon's answer is reported
 * rather than assumed — a rollback that did not happen must never look like one
 * that did.
 *
 * @internal
 */
final class ConfigRollbackCommandTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** @var array<int, array<string, mixed>> */
    private array $txRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The test connections share one in-memory database for the whole
        // process: any transaction this test stages has to be given back.
        $this->txRows = $this->db->table('config_transactions')->get()->getResultArray();
    }

    protected function tearDown(): void
    {
        $this->db->table('config_transactions')->emptyTable();
        if ($this->txRows !== []) {
            $this->db->table('config_transactions')->insertBatch($this->txRows);
        }

        parent::tearDown();
    }

    public function testTheCommandIsRegistered(): void
    {
        $this->assertArrayHasKey('config:rollback', service('commands')->getCommands());
    }

    public function testNoPendingTransactionStillAsksTheDaemonToRollBack(): void
    {
        $ipc = $this->daemon(['restored' => true]);
        Services::injectMock('ipc', $ipc);

        $exit = $this->runRollback();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertSame(['system.rollback'], array_column($ipc->calls, 'method'));
        $this->assertStringContainsString('reloaded the last verified configuration snapshot', $this->getStreamFilterBuffer());
    }

    public function testAPendingTransactionIsDiscardedBeforeTheDaemonIsAsked(): void
    {
        $id = $this->seedPending();

        Services::injectMock('ipc', $this->daemon(['restored' => true]));

        $exit = $this->runRollback();

        $this->assertSame(EXIT_SUCCESS, $exit, $this->getStreamFilterBuffer());

        $row = $this->db->table('config_transactions')->where('id', $id)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('rolled_back', $row['status']);
        $this->assertStringContainsString('Discarded the unconfirmed configuration transaction', $this->getStreamFilterBuffer());
    }

    public function testADaemonThatRefusesTheRollbackFailsTheCommand(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'system.rollback' => [
                'result' => null,
                'error'  => ['code' => -32601, 'message' => 'Method not found: system.rollback'],
            ],
        ]));

        $exit = $this->runRollback();

        $this->assertSame(EXIT_ERROR, $exit);
        $this->assertStringContainsString('Method not found: system.rollback', $this->getStreamFilterBuffer());
    }

    public function testADaemonWithNothingToRestoreIsNotAFailure(): void
    {
        Services::injectMock('ipc', $this->daemon(['restored' => false]));

        $exit = $this->runRollback();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertStringContainsString('no verified snapshot to roll back to', $this->getStreamFilterBuffer());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function runRollback(): int
    {
        $this->resetStreamFilterBuffer();

        return (int) service('commands')->run('config:rollback', []);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function daemon(array $result): FakeIpcClient
    {
        return new FakeIpcClient([
            'system.rollback' => ['result' => $result + ['success' => true, 'status' => 'ok'], 'error' => null],
        ]);
    }

    private function seedPending(): string
    {
        $id = 'tx_test_' . bin2hex(random_bytes(6));

        $this->db->table('config_transactions')->insert([
            'id'             => $id,
            'admin_user'     => 'Ministrator',
            'status'         => 'pending_confirm',
            'candidate_hash' => 'deadbeef',
            'backup_path'    => 'writable/backups/tx_snapshot_test.db',
            'applied_at'     => date('Y-m-d H:i:s'),
            'confirmed_at'   => null,
        ]);

        return $id;
    }
}
