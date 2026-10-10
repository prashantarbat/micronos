<?php

declare(strict_types=1);

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The dashboard's record of the last configuration rollback.
 *
 * A rollback — especially the automatic one the commit watchdog performs when
 * nobody confirms within its window — is the kind of event an operator has to
 * find out about afterwards. The transaction log is the only place it is
 * recorded, so the dashboard has to read it from there, and has to distinguish
 * "nothing has been rolled back" from "a transaction is still open".
 *
 * @internal
 */
final class DashboardRollbackTest extends BaseTestCase
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

        $this->txRows = $this->db->table('config_transactions')->get()->getResultArray();
        $this->db->table('config_transactions')->emptyTable();

        // The dashboard polls the daemon for every widget; the mock keeps the
        // page rendering deterministic without a control socket.
        Services::injectMock('ipc', new FakeIpcClient([
            'system.info'         => ['result' => ['hostname' => 'micronos-fw01', 'kernel' => '6.6.30-micronos'], 'error' => null],
            'interface.list'      => ['result' => [], 'error' => null],
            'wireguard.status'    => ['result' => [], 'error' => null],
            'openvpn.status'      => ['result' => [], 'error' => null],
            'squid.status'        => ['result' => [], 'error' => null],
            'firewall.list_rules' => ['result' => [], 'error' => null],
        ]));

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

    public function testTheDashboardShowsWhenTheLastRollbackHappened(): void
    {
        $this->seedTransaction('tx_rolled', 'rolled_back', '2026-10-06 09:15:00', 'Ministrator');

        $page = $this->dashboard();

        $page->assertSee('Last Rollback');
        $page->assertSee('2026-10-06 09:15:00');
        $page->assertSee('Ministrator');
    }

    public function testTheDashboardSaysSoWhenNothingHasBeenRolledBack(): void
    {
        $this->dashboard()->assertSee('No configuration rollback has been recorded');
    }

    /**
     * An open transaction is not a rollback. Showing one as "last rollback"
     * would report a discarded configuration that is in fact still live and
     * waiting to be confirmed.
     */
    public function testAnOpenTransactionIsNotReportedAsARollback(): void
    {
        $this->seedTransaction('tx_open', 'pending_confirm', null, 'OpenTransactionAdmin');

        $page = $this->dashboard();

        $page->assertSee('No configuration rollback has been recorded');

        // The second argument of assertSee/assertDontSee is an element selector,
        // not a message, so the reason has to live in a comment.
        $page->assertDontSee('OpenTransactionAdmin');
    }

    /**
     * "Last" has to mean the most recent rollback, not the last row written — the
     * rollback stamp is what makes that answerable.
     */
    public function testTheMostRecentRollbackIsTheOneShown(): void
    {
        $this->seedTransaction('tx_older', 'rolled_back', '2026-10-05 08:00:00', 'OlderAdmin');
        $this->seedTransaction('tx_newer', 'rolled_back', '2026-10-06 09:15:00', 'NewerAdmin');

        $page = $this->dashboard();

        $page->assertSee('2026-10-06 09:15:00');
        $page->assertSee('NewerAdmin');
        $page->assertDontSee('2026-10-05 08:00:00');
        $page->assertDontSee('OlderAdmin');
    }

    /**
     * A transaction rolled back before the resolution stamp existed still has its
     * apply time, and the dashboard says which of the two it is showing rather
     * than passing one off as the other.
     */
    public function testARollbackWithNoResolutionStampFallsBackToItsApplyTime(): void
    {
        $this->seedTransaction('tx_legacy', 'rolled_back', null, 'LegacyAdmin', '2026-10-04 07:30:00');

        $page = $this->dashboard();

        $page->assertSee('2026-10-04 07:30:00');
        $page->assertSee('was not timestamped');
    }

    // ------------------------------------------------------------------

    private function dashboard(): \CodeIgniter\Test\TestResponse
    {
        // `assertStatus(200)` rather than `assertOK()`: the framework's `isOK()`
        // accepts a 3xx, so `assertOK()` would pass against the login redirect
        // this test would get without a session — and assert nothing at all.
        $page = $this->withSession($this->session())->get('dashboard');
        $page->assertStatus(200);

        return $page;
    }

    private function seedTransaction(string $id, string $status, ?string $confirmedAt, string $admin, ?string $appliedAt = null): void
    {
        $this->db->table('config_transactions')->insert([
            'id'             => $id,
            'admin_user'     => $admin,
            'status'         => $status,
            'candidate_hash' => 'deadbeef',
            'backup_path'    => 'writable/backups/tx.db',
            'applied_at'     => $appliedAt ?? $confirmedAt ?? date('Y-m-d H:i:s'),
            'confirmed_at'   => $confirmedAt,
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
}
