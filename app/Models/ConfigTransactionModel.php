<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * `config_transactions` — the two-phase commit record behind the Commit
 * Watchdog.
 *
 * The table carries **no** `created_at`/`updated_at` and no `rolled_back_at`:
 * its own timing is `applied_at` (when the candidate configuration reached the
 * appliance) and `confirmed_at` (when the transaction was *resolved* — confirmed
 * by the operator, or discarded by a rollback). Read `status` to know which of
 * those happened; `confirmed_at` alone only says that the transaction is closed
 * and when.
 *
 * Two things about this schema are easy to get wrong, and both were:
 *
 *  - The model's CI4 timestamps must stay off. With them on, every insert and
 *    update referenced `created_at`/`updated_at`, so the watchdog and the
 *    rollback both died with "no such column: updated_at".
 *  - `id` is a `VARCHAR(36) PRIMARY KEY`, not an auto-increment column, so the
 *    insert has to supply one. With `$useAutoIncrement = true` and no id, the
 *    insert raised "NOT NULL constraint failed: ...id", which meant a transaction
 *    could never be created and there was nothing for a rollback to close.
 */
class ConfigTransactionModel extends Model
{
    protected $table            = 'config_transactions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        // The primary key is generated here rather than by the database (see the
        // class docblock), so it has to survive field protection.
        'id',
        'admin_user',
        'status', // pending_confirm, confirmed, rolled_back, failed
        'candidate_hash',
        'backup_path',
        'applied_at',
        'confirmed_at',
    ];

    /** See the class docblock: the table has its own timing columns. */
    protected $useTimestamps = false;

    /**
     * Open a transaction awaiting confirmation and return its id.
     *
     * The id is generated here because the column cannot generate one — see the
     * class docblock. `applied_at` is stamped now: the candidate configuration
     * reached the appliance at this moment, whatever is decided about it next.
     */
    public function startPending(string $adminUser, string $candidateHash, string $backupPath): string
    {
        $id = 'tx_' . bin2hex(random_bytes(8));

        $this->insert([
            'id'             => $id,
            'admin_user'     => $adminUser,
            'status'         => 'pending_confirm',
            'candidate_hash' => $candidateHash,
            'backup_path'    => $backupPath,
            'applied_at'     => date('Y-m-d H:i:s'),
            'confirmed_at'   => null,
        ]);

        return $id;
    }

    /**
     * The open transaction awaiting confirmation, if there is one.
     */
    public function getActivePending(): ?array
    {
        return $this->where('status', 'pending_confirm')
                    ->orderBy('applied_at', 'DESC')
                    ->first();
    }

    /**
     * Close a transaction as rolled back, recording when that happened.
     *
     * A rollback is a resolution, and `confirmed_at` is the only column this
     * table has for one, so that is what it stamps. Leaving it null is what made
     * a discarded transaction indistinguishable from one still awaiting
     * confirmation; `applied_at` keeps the separate fact of when the configuration
     * that was discarded had been applied, and is deliberately not rewritten.
     *
     * The caller passes the transaction it is closing (normally the row from
     * `getActivePending()`); this does not re-check the status, so it cannot be
     * used to restamp a transaction that was already resolved.
     */
    public function markRolledBack(string $id): bool
    {
        return (bool) $this->update($id, [
            'status'       => 'rolled_back',
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Confirm a transaction permanently, recording when it was confirmed.
     */
    public function markConfirmed(string $id): bool
    {
        return (bool) $this->update($id, [
            'status'       => 'confirmed',
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The most recent rolled-back transaction, for the dashboard.
     *
     * Ordered by the resolution stamp, so "last rollback" means the last one to
     * *happen* rather than the last row written. Transactions rolled back before
     * this stamp existed have a null `confirmed_at` (SQLite sorts nulls last in
     * a DESC order), so they fall back to their apply time instead of being
     * dropped from the answer.
     */
    public function lastRollback(): ?array
    {
        return $this->where('status', 'rolled_back')
                    ->orderBy('confirmed_at', 'DESC')
                    ->orderBy('applied_at', 'DESC')
                    ->first();
    }
}
