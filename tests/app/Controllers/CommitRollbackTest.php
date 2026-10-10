<?php

declare(strict_types=1);

namespace Tests\App\Controllers;

use App\Models\ConfigTransactionModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The commit transaction lifecycle: open a transaction, then close it by
 * confirming or by rolling back.
 *
 * `config_transactions` has no `created_at`/`updated_at` of its own — it records
 * its timing in `applied_at` (when the candidate configuration reached the
 * appliance) and `confirmed_at` (when the transaction was resolved). An insert
 * without an id used to fail outright on this schema (`id` is a VARCHAR primary
 * key with no auto-increment), so a transaction could never even be opened; the
 * first test below is what stops that returning.
 *
 * @internal
 */
final class CommitRollbackTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['dashboard.view'];

    /** @var list<array<string, mixed>> */
    private array $txRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The test connections share one in-memory database across the whole
        // process, so the transaction log is handed back exactly as it was found.
        $this->txRows = $this->db->table('config_transactions')->get()->getResultArray();
        $this->db->table('config_transactions')->emptyTable();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->db->table('config_transactions')->emptyTable();

        if ($this->txRows !== []) {
            $this->db->table('config_transactions')->insertBatch($this->txRows);
        }

        parent::tearDown();
    }

    /**
     * Opening a transaction is what a rollback later closes, and it could never
     * happen: the model asked the database for an id the column cannot generate.
     */
    public function testTheWatchdogOpensATransactionThatCanBeClosed(): void
    {
        $ipc = new FakeIpcClient([
            'system.commit_watchdog' => ['result' => ['success' => true], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $result = $this->post('system/commit/watchdog', $this->csrf([]));
        $result->assertStatus(200);

        $body = json_decode((string) $result->getJSON(), true);
        $id   = $body['transaction_id'] ?? null;

        $this->assertIsString($id);
        $this->assertNotSame('', $id, 'the watchdog must report the transaction it opened');

        $row = $this->row((string) $id);

        $this->assertIsArray($row, 'the watchdog must record the transaction it opened');
        $this->assertSame('pending_confirm', $row['status']);
        $this->assertNotNull($row['applied_at'], 'opening a transaction must record when it was applied');
        $this->assertNull($row['confirmed_at'], 'an open transaction is not resolved yet');
        $this->assertSame('system.commit_watchdog', $ipc->calls[0]['method']);
    }

    /**
     * The point of the change: a rolled-back transaction says *when* it was
     * rolled back, instead of being indistinguishable from one still pending.
     */
    public function testRollbackRecordsWhenItHappened(): void
    {
        $id     = (new ConfigTransactionModel())->startPending('Ministrator', 'deadbeef', 'writable/backups/tx.db');
        $before = $this->row($id);

        $ipc = new FakeIpcClient([
            'system.rollback' => ['result' => ['success' => true, 'restored' => true], 'error' => null],
        ]);
        Services::injectMock('ipc', $ipc);

        $this->post('system/commit/rollback', $this->csrf([]))->assertStatus(200);

        $row = $this->row($id);

        $this->assertSame('rolled_back', $row['status']);
        $this->assertNotNull($row['confirmed_at'], 'a rollback must record when it happened');
        $this->assertSame(
            $before['applied_at'],
            $row['applied_at'],
            'the apply time is a separate fact and must not be rewritten by the rollback'
        );
        $this->assertSame('system.rollback', $ipc->calls[0]['method']);
    }

    public function testAConfirmedTransactionRecordsWhenItWasConfirmed(): void
    {
        $id = (new ConfigTransactionModel())->startPending('Ministrator', 'deadbeef', 'writable/backups/tx.db');

        Services::injectMock('ipc', new FakeIpcClient([
            'system.confirm' => ['result' => ['success' => true], 'error' => null],
        ]));

        $this->post('system/commit/permanent', $this->csrf([]))->assertStatus(200);

        $row = $this->row($id);

        $this->assertSame('confirmed', $row['status']);
        $this->assertNotNull($row['confirmed_at']);
    }

    public function testRollingBackWithNothingPendingStillSucceeds(): void
    {
        Services::injectMock('ipc', new FakeIpcClient([
            'system.rollback' => ['result' => ['success' => true], 'error' => null],
        ]));

        $this->post('system/commit/rollback', $this->csrf([]))->assertStatus(200);

        $this->assertSame(0, $this->db->table('config_transactions')->countAllResults());
    }

    /**
     * The countdown is driven by `applied_at`. A transaction whose apply time
     * cannot be read must not be treated as expired — auto-rolling-back a
     * configuration because a timestamp was missing would be an outage caused by
     * a null.
     */
    public function testAStatusReadWithNoApplyTimeDoesNotAutoRollBack(): void
    {
        $this->insertPending('tx_no_time', null);

        Services::injectMock('ipc', new FakeIpcClient());

        $body = $this->statusBody();

        $this->assertTrue($body['active'] ?? false, 'the transaction must still be reported as open');
        $this->assertGreaterThan(0, $body['remaining_sec'] ?? 0);

        $row = $this->row('tx_no_time');
        $this->assertSame('pending_confirm', $row['status'], 'an unreadable apply time is not an expired window');
        $this->assertNull($row['confirmed_at']);
    }

    public function testAnExpiredWindowRollsBackAndRecordsWhen(): void
    {
        $this->insertPending('tx_expired', date('Y-m-d H:i:s', time() - 120));

        Services::injectMock('ipc', new FakeIpcClient([
            'system.rollback' => ['result' => ['success' => true], 'error' => null],
        ]));

        $body = $this->statusBody();

        $this->assertFalse($body['active'] ?? true);
        $this->assertTrue($body['expired'] ?? false);

        $row = $this->row('tx_expired');

        $this->assertSame('rolled_back', $row['status']);
        $this->assertNotNull($row['confirmed_at'], 'an expired window must record when it was rolled back');
    }

    // ------------------------------------------------------------------

    /**
     * The countdown endpoint, read as a signed-in operator.
     *
     * `assertStatus(200)` rather than `assertOK()`: a framework `isOK()` accepts
     * the 3xx of a login redirect, so `assertOK()` would happily pass against the
     * auth page and quietly test nothing.
     *
     * @return array<string, mixed>
     */
    private function statusBody(): array
    {
        $result = $this->withSession($this->session())->get('system/commit/status');
        $result->assertStatus(200);

        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body, 'the status endpoint must answer with JSON');

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        $row = $this->db->table('config_transactions')->where('id', $id)->get()->getRowArray();

        $this->assertIsArray($row, 'transaction ' . $id . ' is not in the log');

        return $row;
    }

    /**
     * A transaction inserted directly, so a null `applied_at` can be reproduced.
     */
    private function insertPending(string $id, ?string $appliedAt): void
    {
        $this->db->table('config_transactions')->insert([
            'id'             => $id,
            'admin_user'     => 'Ministrator',
            'status'         => 'pending_confirm',
            'candidate_hash' => 'deadbeef',
            'backup_path'    => 'writable/backups/tx.db',
            'applied_at'     => $appliedAt,
            'confirmed_at'   => null,
        ]);
    }

    /**
     * @return array<int|string, string|null>
     */
    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    /**
     * @param array<int|string, string|null> $params
     *
     * @return array<int|string, string|null>
     */
    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }
}
