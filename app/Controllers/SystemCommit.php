<?php

namespace App\Controllers;

use App\Models\ConfigTransactionModel;
use App\Libraries\IpcClient;

class SystemCommit extends BaseController
{
    protected ConfigTransactionModel $txModel;
    protected IpcClient $ipc;

    public function __construct()
    {
        $this->txModel = new ConfigTransactionModel();
        $this->ipc     = \Config\Services::ipc();
    }

    public function status()
    {
        $pending = $this->txModel->getActivePending();
        if (!$pending) {
            return $this->response->setJSON(['active' => false]);
        }

        // `applied_at` is the only clock a transaction has — there is no
        // `created_at` on this table. An apply time that cannot be read is not
        // evidence that the window expired, so it does not trigger the
        // auto-rollback; the countdown is simply reported from the top.
        $appliedAt = $pending['applied_at'] ?? null;
        $elapsed   = is_string($appliedAt) && strtotime($appliedAt) !== false
            ? time() - (int) strtotime($appliedAt)
            : 0;
        $remaining = max(0, 60 - $elapsed);

        // If time expired, auto-mark as rolled_back
        if ($remaining <= 0) {
            $this->txModel->markRolledBack($pending['id']);
            $this->ipc->call('system.rollback');
            return $this->response->setJSON(['active' => false, 'expired' => true]);
        }

        return $this->response->setJSON([
            'active'         => true,
            'transaction_id' => $pending['id'],
            'admin_user'     => $pending['admin_user'],
            'remaining_sec'  => $remaining,
            'applied_at'     => $pending['applied_at'],
        ]);
    }

    public function commitWatchdog()
    {
        $user = session()->get('user')['username'] ?? 'admin';
        $hash = hash('sha256', $user . microtime());

        // Snapshot path
        $backupPath = WRITEPATH . 'backups/tx_snapshot_' . date('Ymd_His') . '.db';

        $txId = $this->txModel->startPending($user, $hash, $backupPath);

        $this->ipc->call('system.commit_watchdog', [
            'transaction_id' => $txId,
            'watchdog_sec'   => 60,
        ]);

        return $this->response->setJSON([
            'success'        => true,
            'transaction_id' => $txId,
            'remaining_sec'  => 60,
            'message'        => 'Commit watchdog started. Changes revert in 60 seconds unless confirmed.',
        ]);
    }

    public function confirmPermanent()
    {
        $pending = $this->txModel->getActivePending();
        if ($pending) {
            $this->txModel->markConfirmed((string) $pending['id']);
            $this->ipc->call('system.confirm', ['transaction_id' => $pending['id']]);
            $author = (string) (session('username') ?? 'ministrator');
            \Config\Services::configRevisionEngine()->commit($author, 'Confirmed staged configuration changes', 'system', true);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Configuration permanently confirmed.',
        ]);
    }

    public function rollback()
    {
        $pending = $this->txModel->getActivePending();
        if ($pending) {
            // Stamps the resolution time, so the dashboard can say when the last
            // rollback happened rather than just that one did.
            $this->txModel->markRolledBack((string) $pending['id']);
        }

        $this->ipc->call('system.rollback');

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Configuration changes rolled back to previous snapshot.',
        ]);
    }
}
