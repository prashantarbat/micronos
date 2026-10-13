<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <a href="<?= base_url('captive') ?>">Captive Portal</a>
      <span class="sep">/</span>
      <span class="current">Live Sessions</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-emerald">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
      </div>
      Live Guest Sessions
    </h1>
    <p class="page-subtitle">Real-time view of authenticated wireless/wired clients authorized in the Linux kernel sets.</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-secondary btn-sm" onclick="location.reload();">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      Refresh
    </button>
  </div>
</div>

<?php if ((session()->getFlashdata('success') ?? session()->getFlashdata('message'))): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc((session()->getFlashdata('success') ?? session()->getFlashdata('message'))) ?>
  </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<div class="card card-glass animate-in">
  <div class="card-header">
    <span class="card-title">Authorized Clients</span>
    <span class="card-subtitle" style="display:block; font-size:0.75rem; color:var(--text-muted);">Clients actively granted bypass through `micronos_captive_auth` NFTables set.</span>
  </div>

  <div class="table-responsive">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Client IP &amp; MAC</th>
          <th>Zone</th>
          <th>Voucher / Method</th>
          <th>Authorized At</th>
          <th>Expires At</th>
          <th>Traffic (Rx / Tx)</th>
          <th>Status</th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sessions)): ?>
          <tr>
            <td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">
              No active guest sessions. Connected clients will appear here once authenticated.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($sessions as $s): ?>
            <tr>
              <td>
                <div style="font-family:var(--font-mono); font-weight:700; color:var(--color-cyan);"><?= esc($s['ip_address']) ?></div>
                <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted);"><?= esc($s['mac_address']) ?></div>
              </td>
              <td><?= esc($s['zone_name'] ?? 'Zone #' . $s['zone_id']) ?></td>
              <td>
                <?php if (!empty($s['voucher_code'])): ?>
                  <span class="badge" style="font-family:var(--font-mono); font-weight:700; background:rgba(245,158,11,0.15); color:#f59e0b;">
                    <?= esc($s['voucher_code']) ?>
                  </span>
                <?php else: ?>
                  <span class="badge" style="background:var(--color-surface-2);">Terms Agree</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.75rem; color:var(--text-secondary);"><?= esc(substr($s['authorized_at'], 0, 16)) ?></td>
              <td style="font-size:0.75rem; color:var(--text-secondary);">
                <?php
                  $expires = strtotime($s['expires_at']);
                  $diff = $expires - time();
                  $remText = $diff > 0 ? floor($diff / 60) . 'm left' : 'Expired';
                ?>
                <?= esc(substr($s['expires_at'], 0, 16)) ?>
                <div style="font-size:0.7rem; color:<?= $diff > 0 ? 'var(--color-emerald)' : 'var(--color-rose)' ?>; font-weight:700;">
                  (<?= $remText ?>)
                </div>
              </td>
              <td style="font-size:0.75rem; font-family:var(--font-mono); color:var(--text-muted);">
                ↓ <?= number_format($s['bytes_in'] / (1024 * 1024), 2) ?> MB<br>
                ↑ <?= number_format($s['bytes_out'] / (1024 * 1024), 2) ?> MB
              </td>
              <td>
                <span class="badge" style="font-weight:700; text-transform:uppercase; background:<?= $s['status'] === 'active' ? 'rgba(16,185,129,0.2); color:#10b981;' : 'rgba(239,68,68,0.2); color:#ef4444;' ?>">
                  <?= esc($s['status']) ?>
                </span>
              </td>
              <td style="text-align:right;">
                <?php if ($s['status'] === 'active'): ?>
                  <form method="POST" action="<?= base_url('captive/sessions/' . $s['id'] . '/terminate') ?>" style="margin:0; display:inline;" onsubmit="return confirm('Terminate and disconnect client <?= esc($s['ip_address']) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:4px 8px; font-size:0.72rem;">
                      Disconnect
                    </button>
                  </form>
                <?php else: ?>
                  <span style="color:var(--text-muted); font-size:0.75rem;">Terminated</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?= $this->endSection() ?>
