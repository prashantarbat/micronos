<?php

namespace App\Libraries\Network;

use App\Libraries\IpcClient;
use App\Libraries\KeaDhcpCompiler;
use App\Libraries\NftablesCompiler;
use CodeIgniter\Database\BaseConnection;

/**
 * The single path by which a network change reaches the operating system.
 *
 * Used by the Interfaces page, System > Networking (IPv6), the console CLI
 * (`php spark network:*`, `microctl interface set-lan`, `microctl ipv6`) and
 * boot-time reconciliation, so they can never disagree:
 *
 *   1. open a database transaction and apply the requested change to it
 *      (interface row, IPv6 switch …) plus every dependent setting
 *      (AddressChangeSync);
 *   2. build the plan, the nftables ruleset and the Kea configuration from
 *      that uncommitted state and validate the plan;
 *   3. send all three to micrond's `network.apply`, which snapshots the
 *      running state, applies, verifies and — on any failure — restores it;
 *   4. commit the database only when the daemon reports success; otherwise
 *      roll it back, so the database never claims something the OS refused.
 *
 * Changes that can cut off the management session are applied with a
 * confirmation window: the daemon rolls back unless `confirm()` arrives in
 * time, and reconcile() restores the database rows if that happens.
 */
final class NetworkTransaction
{
    public const CONFIRM_SECONDS = 120;

    public function __construct(private IpcClient $ipc, private ?BaseConnection $db = null)
    {
        $this->db ??= \Config\Database::connect();
    }

    /**
     * @param callable(BaseConnection): (array<string, mixed>|null) $mutate
     *        applies the change inside the transaction; may return
     *        ['before' => row, 'after' => row] for dependency sync
     * @param array{confirm?: bool, update_references?: bool, actor?: string, reason?: string} $opts
     *
     * @return array{ok: bool, status: string, message: string, tx?: string,
     *               updated: list<array<string,string>>, review: list<array<string,string>>,
     *               warnings: list<string>, errors: list<string>, steps: list<string>}
     */
    public function apply(?callable $mutate = null, array $opts = []): array
    {
        $out = ['ok' => false, 'status' => 'error', 'message' => '', 'updated' => [], 'review' => [],
            'warnings' => [], 'errors' => [], 'steps' => []];

        if ($this->hasPending()) {
            $out['status']  = 'busy';
            $out['message'] = 'Another network change is waiting for confirmation. Confirm it or let it roll back first.';

            return $out;
        }

        $snapshot = $this->captureRows();
        $this->db->transBegin();
        try {
            $diff = $mutate !== null ? $mutate($this->db) : null;
            if (is_array($diff) && isset($diff['before'], $diff['after'])) {
                $sync = (new AddressChangeSync($this->db))->sync($diff['before'], $diff['after'], (bool) ($opts['update_references'] ?? true));
                $out['updated'] = $sync['updated'];
                $out['review']  = $sync['review'];
            }

            $plan   = (new NetworkPlan($this->db))->build();
            $errors = NetworkPlan::validate($plan);
            if ($errors !== []) {
                $this->db->transRollback();
                $out['status'] = 'invalid';
                $out['errors'] = $errors;
                $out['message'] = 'The network configuration was not changed: ' . implode('; ', $errors);

                return $out;
            }

            $ruleset = (new NftablesCompiler($this->db))->compile();
            $kea     = new KeaDhcpCompiler($this->db);
            $dhcp4   = json_decode($kea->compile(), true);
            $out['warnings'] = array_merge($out['warnings'], $kea->warnings);

            $resp   = $this->ipc->call('network.apply', array_filter([
                'plan'            => $plan,
                'ruleset'         => $ruleset,
                'dhcp4'           => is_array($dhcp4) ? $dhcp4 : null,
                'confirm_timeout' => ! empty($opts['confirm']) ? self::CONFIRM_SECONDS : null,
            ], static fn ($v) => $v !== null));
            $result = $resp['result'] ?? null;

            if (! empty($resp['error']) || ! is_array($result)) {
                $this->db->transRollback();
                $out['message'] = 'The appliance daemon did not apply the change: '
                    . (string) ($resp['error']['message'] ?? 'no answer') . '. Nothing was changed.';

                return $out;
            }

            $out['steps']    = array_values(array_map('strval', (array) ($result['steps'] ?? [])));
            $out['warnings'] = array_merge($out['warnings'], array_values(array_map('strval', (array) ($result['warnings'] ?? []))));
            $status          = (string) ($result['status'] ?? 'error');

            if (! in_array($status, ['applied', 'pending_confirm'], true) || ($result['success'] ?? false) !== true) {
                $this->db->transRollback();
                $out['status'] = $status;
                $out['errors'] = array_values(array_map('strval', (array) ($result['errors'] ?? [])));
                $out['message'] = (string) ($result['message'] ?? 'The network change was refused.');

                return $out;
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $out['message'] = 'The network change failed before it reached the appliance: ' . $e->getMessage();

            return $out;
        }

        $out['ok']     = true;
        $out['status'] = $status;
        if ($status === 'pending_confirm') {
            $out['tx'] = (string) ($result['tx'] ?? '');
            $this->recordPending($out['tx'], $snapshot, (string) ($opts['actor'] ?? 'system'), (string) ($opts['reason'] ?? ''));
            $out['message'] = 'The change is live. Confirm it within ' . self::CONFIRM_SECONDS
                . ' seconds from the new address, or it is rolled back automatically.';
        } else {
            $out['message'] = 'Network configuration applied and verified.';
        }
        $this->audit((string) ($opts['actor'] ?? 'system'), $status, (string) ($opts['reason'] ?? 'network apply'), $out);

        return $out;
    }

    /** Confirm a pending change (from the new address). */
    public function confirm(string $tx): array
    {
        $resp   = $this->ipc->call('network.confirm', ['tx' => $tx]);
        $result = (array) ($resp['result'] ?? []);
        if (($result['status'] ?? '') === 'applied') {
            $this->markTx($tx, 'committed');
        } elseif (($result['status'] ?? '') === 'rolled_back') {
            $this->reconcile();
        }

        return ['ok' => ($result['status'] ?? '') === 'applied', 'status' => (string) ($result['status'] ?? 'error'),
            'message' => (string) ($result['message'] ?? ($resp['error']['message'] ?? 'no answer from the appliance daemon'))];
    }

    /** Roll a pending change back now. */
    public function rollback(): array
    {
        $resp = $this->ipc->call('network.rollback');
        $this->reconcile();

        return ['ok' => (($resp['result']['status'] ?? '') === 'rolled_back'),
            'message' => (string) ($resp['result']['message'] ?? 'no answer from the appliance daemon')];
    }

    /**
     * Bring the database back in line when the daemon rolled a pending change
     * back (timeout, reboot, operator). Idempotent; safe to call on every load.
     *
     * @return string|null what was done
     */
    public function reconcile(): ?string
    {
        if (! $this->db->tableExists('network_transactions')) {
            return null;
        }
        $rows = $this->db->table('network_transactions')->where('status', 'pending')->get()->getResultArray();
        if ($rows === []) {
            return null;
        }
        $status = $this->ipc->call('network.status')['result'] ?? null;
        if (! is_array($status)) {
            return null;
        }
        $pendingTx = (string) ($status['pending']['tx'] ?? '');
        $done      = null;
        foreach ($rows as $row) {
            if ($row['tx'] === $pendingTx) {
                continue;
            }
            $last = (array) ($status['last'] ?? []);
            if (($last['tx'] ?? '') === $row['tx'] && ($last['status'] ?? '') === 'applied') {
                $this->markTx($row['tx'], 'committed');
                continue;
            }
            $this->restoreRows(json_decode((string) $row['before_json'], true) ?: []);
            $this->markTx($row['tx'], 'rolled_back');
            $done = 'The unconfirmed network change ' . $row['tx'] . ' was rolled back; the configuration database was restored to match.';
        }

        return $done;
    }

    public function hasPending(): bool
    {
        try {
            return $this->db->tableExists('network_transactions')
                && $this->db->table('network_transactions')->where('status', 'pending')->countAllResults() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Tables whose rows a network change may touch (for confirm-window rollback). */
    private const TRACKED = ['interfaces', 'system_settings', 'dhcp_server_settings', 'dns_hosts', 'wan_gateways',
        'static_routes', 'firewall_rules', 'firewall_addresses', 'nat_dnat', 'nat_snat_pools'];

    /** @return array<string, list<array<string, mixed>>> */
    private function captureRows(): array
    {
        $snap = [];
        foreach (self::TRACKED as $t) {
            if ($this->db->tableExists($t)) {
                $snap[$t] = $this->db->table($t)->get()->getResultArray();
            }
        }

        return $snap;
    }

    private function restoreRows(array $snap): void
    {
        // Row-by-row (update, re-insert, delete what was added) so foreign-key
        // cascades from `interfaces` never fire.
        $this->db->transStart();
        foreach (self::TRACKED as $table) {
            if (! array_key_exists($table, $snap) || ! $this->db->tableExists($table)) {
                continue;
            }
            $before = [];
            foreach ((array) $snap[$table] as $row) {
                if (isset($row['id'])) {
                    $before[(string) $row['id']] = $row;
                }
            }
            foreach ($this->db->table($table)->select('id')->get()->getResultArray() as $r) {
                if (! isset($before[(string) $r['id']])) {
                    $this->db->table($table)->where('id', $r['id'])->delete();
                }
            }
            foreach ($before as $id => $row) {
                if ($this->db->table($table)->where('id', $id)->countAllResults() > 0) {
                    $this->db->table($table)->where('id', $id)->update($row);
                } else {
                    $this->db->table($table)->insert($row);
                }
            }
        }
        $this->db->transComplete();
    }

    private function recordPending(string $tx, array $snapshot, string $actor, string $reason): void
    {
        if (! $this->db->tableExists('network_transactions') || $tx === '') {
            return;
        }
        $this->db->table('network_transactions')->insert([
            'tx'          => $tx,
            'status'      => 'pending',
            'actor'       => $actor,
            'reason'      => mb_substr($reason, 0, 255),
            'before_json' => json_encode($snapshot),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function markTx(string $tx, string $status): void
    {
        if ($this->db->tableExists('network_transactions')) {
            $this->db->table('network_transactions')->where('tx', $tx)
                ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function audit(string $actor, string $status, string $reason, array $out): void
    {
        try {
            if (! $this->db->tableExists('audit_logs')) {
                return;
            }
            $this->db->table('audit_logs')->insert([
                'admin_user'      => $actor,
                'client_ip'       => 'local',
                'user_id'         => $actor,
                'username'        => $actor,
                'action_type'     => 'network',
                'action'          => 'network.apply',
                'target_resource' => 'Network',
                'target'          => 'interfaces',
                'new_state'       => mb_substr($reason . ' → ' . $status . ($out['updated'] !== [] ? ' (' . count($out['updated']) . ' dependent settings updated)' : ''), 0, 500),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }
}
