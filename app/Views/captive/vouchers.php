<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <a href="<?= base_url('captive') ?>">Captive Portal</a>
      <span class="sep">/</span>
      <span class="current">Vouchers</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-amber">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
        </svg>
      </div>
      Hotspot Voucher Engine
    </h1>
    <p class="page-subtitle">Batch roll generation with unambiguous alphanumeric codes, device concurrency limits, and printable guest passes.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('captive/vouchers/export-csv') ?>" class="btn btn-secondary btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
      Export CSV
    </a>
    <button type="button" class="btn btn-primary btn-sm" onclick="openBatchModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
      Generate Voucher Batch
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

<!-- Roll Summaries Section -->
<?php if (!empty($rollSummaries)): ?>
  <div class="card card-glass animate-in" style="margin-bottom:20px;">
    <div class="card-header">
      <span class="card-title">Voucher Rolls (Batches)</span>
      <span class="card-subtitle" style="display:block; font-size:0.75rem; color:var(--text-muted);">Print guest vouchers by batch or revoke unused allocations.</span>
    </div>
    <div class="table-responsive">
      <table class="table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr>
            <th>Roll ID</th>
            <th>Zone</th>
            <th>Total Count</th>
            <th>Active / Ready</th>
            <th>Used</th>
            <th>Expired</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rollSummaries as $roll): ?>
            <tr>
              <td style="font-weight:700; font-family:var(--font-mono); color:var(--text-primary);"><?= esc($roll['roll_id']) ?></td>
              <td><?= esc($roll['zone_name'] ?? 'Zone #' . $roll['zone_id']) ?></td>
              <td><span class="badge" style="background:var(--color-surface-2); font-weight:700;"><?= (int)$roll['total'] ?></span></td>
              <td><span class="badge" style="background:rgba(16,185,129,0.15); color:#10b981; font-weight:700;"><?= (int)$roll['active_count'] ?></span></td>
              <td><span class="badge" style="background:rgba(59,130,246,0.15); color:#3b82f6; font-weight:700;"><?= (int)$roll['used_count'] ?></span></td>
              <td><span class="badge" style="background:rgba(239,68,68,0.15); color:#ef4444; font-weight:700;"><?= (int)$roll['expired_count'] ?></span></td>
              <td>
                <a href="<?= base_url('captive/vouchers/print/' . rawurlencode($roll['roll_id'])) ?>" target="_blank" class="btn btn-secondary btn-sm" style="padding:4px 8px;">
                  🖨 Print Cards
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Vouchers Table -->
<div class="card card-glass animate-in">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
      <span class="card-title">Issued Vouchers</span>
      <span class="card-subtitle" style="display:block; font-size:0.75rem; color:var(--text-muted);">All active and past credentials across hotspot zones.</span>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th>Voucher Code</th>
          <th>Roll ID</th>
          <th>Zone</th>
          <th>Duration</th>
          <th>Quota</th>
          <th>Devices</th>
          <th>Status</th>
          <th>Created</th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($vouchers)): ?>
          <tr>
            <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
              No vouchers generated yet. Click <strong>"Generate Voucher Batch"</strong> to create codes.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($vouchers as $v): ?>
            <tr>
              <td>
                <span style="font-family:var(--font-mono); font-weight:800; font-size:0.95rem; letter-spacing:1px; color:var(--color-cyan);">
                  <?= esc($v['code']) ?>
                </span>
              </td>
              <td style="font-size:0.75rem; font-family:var(--font-mono); color:var(--text-muted);"><?= esc($v['roll_id'] ?: '-') ?></td>
              <td><?= esc($v['zone_name'] ?? 'Zone #' . $v['zone_id']) ?></td>
              <td style="font-size:0.8rem;"><?= (int)$v['duration_minutes'] ?> mins</td>
              <td style="font-size:0.8rem;"><?= (int)$v['data_quota_mb'] > 0 ? (int)$v['data_quota_mb'] . ' MB' : '∞ Unmetered' ?></td>
              <td style="font-size:0.8rem;"><?= (int)$v['used_count'] ?> / <?= (int)$v['max_devices'] ?></td>
              <td>
                <?php
                  $st = $v['status'];
                  $color = match($st) {
                    'active'  => 'rgba(16,185,129,0.2); color:#10b981;',
                    'used'    => 'rgba(59,130,246,0.2); color:#3b82f6;',
                    'revoked' => 'rgba(239,68,68,0.2); color:#ef4444;',
                    default   => 'rgba(156,163,175,0.2); color:#9ca3af;',
                  };
                ?>
                <span class="badge" style="font-weight:700; text-transform:uppercase; background:<?= $color ?>">
                  <?= esc($st) ?>
                </span>
              </td>
              <td style="font-size:0.72rem; color:var(--text-muted);"><?= esc(substr($v['created_at'], 0, 16)) ?></td>
              <td style="text-align:right;">
                <?php if ($v['status'] === 'active'): ?>
                  <form method="POST" action="<?= base_url('captive/vouchers/' . $v['id'] . '/revoke') ?>" style="margin:0; display:inline;" onsubmit="return confirm('Revoke voucher <?= esc($v['code']) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:3px 7px; font-size:0.72rem;">Revoke</button>
                  </form>
                <?php else: ?>
                  <span style="color:var(--text-muted); font-size:0.75rem;">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Batch Generator Modal -->
<div id="batchModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:540px; width:100%; border:1px solid var(--color-border); box-shadow:0 12px 36px rgba(0,0,0,0.5);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">Generate Hotspot Voucher Batch</span>
      <button type="button" class="btn-close" onclick="closeBatchModal()" style="background:transparent; border:none; color:var(--text-muted); font-size:1.2rem; cursor:pointer;">✕</button>
    </div>
    <form method="POST" action="<?= base_url('captive/vouchers/generate') ?>" style="padding:18px;">
      <?= csrf_field() ?>

      <div style="margin-bottom:12px;">
        <label class="form-label">Target Hotspot Zone <span style="color:var(--color-rose);">*</span></label>
        <select name="zone_id" class="form-control" required>
          <?php foreach ($zones as $z): ?>
            <option value="<?= esc($z['id']) ?>"><?= esc($z['name']) ?> (<?= esc($z['interface']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label">Batch Roll ID (Optional)</label>
          <input type="text" name="roll_id" class="form-control" placeholder="ROLL-HOTEL-2026" style="font-family:var(--font-mono);">
        </div>
        <div>
          <label class="form-label">Number of Vouchers <span style="color:var(--color-rose);">*</span></label>
          <input type="number" name="count" class="form-control" value="25" min="1" max="500" required>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label class="form-label">Session Duration (Minutes)</label>
          <input type="number" name="duration_minutes" class="form-control" value="120" min="5" max="43200" required>
          <small style="font-size:0.7rem; color:var(--text-muted);">e.g., 60 = 1h, 1440 = 24h</small>
        </div>
        <div>
          <label class="form-label">Data Quota (MB)</label>
          <input type="number" name="data_quota_mb" class="form-control" value="0" min="0">
          <small style="font-size:0.7rem; color:var(--text-muted);">0 = Unlimited bandwidth</small>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
        <div>
          <label class="form-label">Max Concurrent Devices</label>
          <input type="number" name="max_devices" class="form-control" value="1" min="1" max="10">
        </div>
        <div>
          <label class="form-label">Valid For (Days)</label>
          <input type="number" name="expires_days" class="form-control" value="30" min="1" max="365">
          <small style="font-size:0.7rem; color:var(--text-muted);">Expiration before first use</small>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeBatchModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Generate Vouchers</button>
      </div>
    </form>
  </div>
</div>

<script>
function openBatchModal() {
  document.getElementById('batchModal').style.display = 'flex';
}
function closeBatchModal() {
  document.getElementById('batchModal').style.display = 'none';
}
</script>

<?= $this->endSection() ?>
