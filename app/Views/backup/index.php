<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<style>
/* ── Summary Stats Grid ── */
.backup-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

/* ── Form Inputs & Cards ── */
.backup-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  margin-bottom: 20px;
  overflow: hidden;
}
.backup-card-header {
  padding: 14px 18px;
  background: var(--color-surface-3);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}
.backup-card-title {
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}
.backup-card-body {
  padding: 20px;
}

.mno-form-group {
  margin-bottom: 16px;
}
.mno-form-label {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-secondary);
  margin-bottom: 6px;
}
.mno-input, .mno-select {
  width: 100%;
  padding: 8px 12px;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--text-primary);
  font-size: 0.8125rem;
  transition: border-color var(--transition-fast);
  box-sizing: border-box;
}
.mno-input:focus, .mno-select:focus {
  outline: none;
  border-color: var(--color-cyan);
  box-shadow: 0 0 0 2px rgba(0, 212, 255, 0.2);
}

/* Checkbox pill selector */
.selective-checkbox-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: 0.8125rem;
  color: var(--text-primary);
  cursor: pointer;
  transition: all var(--transition-fast);
}
.selective-checkbox-item:hover {
  border-color: rgba(0, 212, 255, 0.35);
  background: rgba(0, 212, 255, 0.03);
}

/* NIC Remapping row */
.nic-map-row {
  display: grid;
  grid-template-columns: 1fr auto 1fr auto;
  gap: 10px;
  align-items: center;
  margin-bottom: 8px;
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>System</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Disaster Recovery</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-purple-bg); color:var(--tone-purple-fg); border:1px solid var(--tone-purple-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
        </svg>
      </div>
      <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <h1 class="page-title">Backup &amp; Disaster Recovery</h1>
          <span class="badge badge-purple" style="font-size:0.75rem;">AES-256-GCM ENCRYPTED</span>
          <span class="badge badge-success" style="font-size:0.75rem;">WATCHDOG ROLLBACK (60S)</span>
          <span class="badge badge-cyan" style="font-size:0.75rem;"><?= count($backups) ?> Snapshots</span>
        </div>
        <p class="page-subtitle">Cryptographic appliance state archives, dissimilar hardware NIC remapping, and selective component restoration</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <?php if (session()->get('is_superadmin') || in_array('backup.create', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('createBackupModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create Snapshot
      </button>
    <?php endif; ?>

    <?php if (session()->get('is_superadmin') || in_array('backup.restore', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-warning btn-sm" onclick="switchBackupTab('tab-restore', document.querySelectorAll('.backup-tab')[2])">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Restore Archive
      </button>
    <?php endif; ?>

    <a href="<?= base_url('system/commit/status') ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Commit Status
    </a>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:8px; flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-error animate-in" style="margin-bottom:16px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:8px; flex-shrink:0;">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<?php
  $encryptedCount = 0;
  $totalBytes = 0;
  foreach ($backups as $bk) {
    if (!empty($bk['is_encrypted'])) $encryptedCount++;
    $totalBytes += (int)($bk['file_size_bytes'] ?? 0);
  }
?>

<!-- ── Summary Metric Cards ── -->
<div class="backup-stats-grid animate-in animate-in-delay-1">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-cyan);"><?= count($backups) ?> Snapshots</div>
    <div class="stat-label">Stored Archives</div>
    <div class="stat-sublabel">Total Size: <?= round($totalBytes / 1024, 1) ?> KB &bull; Local Storage</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);">AES-256-GCM</div>
    <div class="stat-label">Encryption Cipher</div>
    <div class="stat-sublabel"><?= $encryptedCount ?> Encrypted &bull; PBKDF2 Key Derivation</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-emerald);">Active (60s)</div>
    <div class="stat-label">Watchdog Guard</div>
    <div class="stat-sublabel">Automated Rollback on Loss of Connectivity</div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-amber);">NIC Translation</div>
    <div class="stat-label">Dissimilar Hardware</div>
    <div class="stat-sublabel">Cross-appliance interface mapping ready</div>
  </div>
</div>

<!-- ── Navigation Tabs ── -->
<div class="tab-bar backup-tabs animate-in animate-in-delay-1">
  <button type="button" class="tab-item backup-tab active" onclick="switchBackupTab('tab-snapshots', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
    </svg>
    Configuration Snapshots (<?= count($backups) ?>)
  </button>
  <button type="button" class="tab-item backup-tab" onclick="switchBackupTab('tab-create', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
    </svg>
    Create Snapshot
  </button>
  <button type="button" class="tab-item backup-tab" onclick="switchBackupTab('tab-restore', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
    </svg>
    Restore &amp; Disaster Recovery
  </button>
  <button type="button" class="tab-item backup-tab" onclick="switchBackupTab('tab-guide', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    Architecture &amp; Best Practices
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 1: AVAILABLE SNAPSHOTS
══════════════════════════════════════════════════════════════ -->
<div class="backup-panel active animate-in animate-in-delay-2" id="tab-snapshots">
  <div class="backup-card">
    <div class="backup-card-header">
      <span class="backup-card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
        </svg>
        Appliance State Archives
      </span>
      <span class="badge badge-muted"><?= count($backups) ?> Archive<?= count($backups) !== 1 ? 's' : '' ?></span>
    </div>

    <div style="overflow-x:auto;">
      <?php if (empty($backups)): ?>
        <div style="padding:48px 20px; text-align:center;">
          <div style="width:56px; height:56px; border-radius:50%; background:rgba(0, 212, 255, 0.1); border:1px solid rgba(0, 212, 255, 0.25); display:inline-flex; align-items:center; justify-content:center; color:var(--color-cyan); margin-bottom:14px;">
            <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
            </svg>
          </div>
          <h4 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">No Backup Archives Found</h4>
          <p style="font-size:0.8125rem; color:var(--text-secondary); max-width:440px; margin:0 auto 16px;">
            Create your first cryptographic configuration snapshot to safeguard firewall rules, VPN credentials, and interface routing.
          </p>
          <button type="button" class="btn btn-primary btn-sm" onclick="openModal('createBackupModal')">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Create Snapshot Now
          </button>
        </div>
      <?php else: ?>
        <table class="mno-table">
          <thead>
            <tr>
              <th>Archive Filename</th>
              <th>Created At</th>
              <th>Size</th>
              <th>Encryption</th>
              <th>SHA-256 Checksum</th>
              <th>Created By</th>
              <th>Notes / Description</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($backups as $b): ?>
              <tr>
                <td>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span class="mno-obj-chip mno-obj-chip-lan">
                      <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                      </svg>
                      <?= esc($b['filename']) ?>
                    </span>
                  </div>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary); white-space:nowrap;">
                  <?= esc($b['created_at']) ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-primary);">
                  <span class="mno-obj-chip mno-obj-chip-success">
                    <?= number_format(($b['file_size_bytes'] ?? 0) / 1024, 1) ?> KB
                  </span>
                </td>
                <td>
                  <?php if (!empty($b['is_encrypted'])): ?>
                    <span class="badge badge-purple" style="font-size:0.7rem;">AES-256-GCM</span>
                  <?php else: ?>
                    <span class="badge badge-warning" style="font-size:0.7rem;">Unencrypted</span>
                  <?php endif; ?>
                  <?php if (!empty($b['credentials_included'])): ?>
                    <span class="badge badge-info" style="font-size:0.7rem;" title="Contains connector credentials and the connector master key inside the encrypted envelope">Creds</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted);" title="<?= esc($b['sha256_checksum'] ?? '') ?>">
                  <?= esc(substr($b['sha256_checksum'] ?? '', 0, 16)) ?>…
                </td>
                <td style="font-size:0.78rem; color:var(--text-secondary);">
                  <?= esc($b['created_by'] ?? 'system') ?>
                </td>
                <td style="font-size:0.75rem; color:var(--text-secondary); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                  <?= esc($b['description'] ?? '—') ?>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <div style="display:inline-flex; align-items:center; gap:6px;">
                    <a href="<?= base_url('backup/download/' . esc($b['id'])) ?>" class="btn btn-ghost btn-sm" style="color:var(--color-cyan); font-size:0.75rem;" title="Download Archive">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                      </svg>
                      Download
                    </a>
                    <?php if (session()->get('is_superadmin') || in_array('backup.delete', session()->get('permissions') ?? [])): ?>
                      <form method="POST" action="<?= base_url('backup/' . esc($b['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Permanently delete backup snapshot archive <?= esc($b['filename']) ?>?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:4px 8px;" title="Delete Archive">
                          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                          </svg>
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 2: CREATE SNAPSHOT
══════════════════════════════════════════════════════════════ -->
<div class="backup-panel animate-in animate-in-delay-2" id="tab-create">
  <div class="backup-card">
    <div class="backup-card-header">
      <span class="backup-card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Generate Appliance State Snapshot
      </span>
      <span class="badge badge-cyan" style="font-size:0.7rem;">Live In-Memory Export</span>
    </div>

    <div class="backup-card-body">
      <p style="font-size:0.8125rem; color:var(--text-secondary); line-height:1.6; margin-bottom:20px;">
        Backups include all database state across firewall policies, NAT rules, VPN tunnels, PKI authorities, network routing, and user permissions. For strict compliance, apply an AES-256-GCM encryption passphrase.
      </p>

      <form method="POST" action="<?= base_url('backup/create') ?>">
        <?= csrf_field() ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
          <div class="mno-form-group">
            <label class="mno-form-label">Snapshot Description / Reason</label>
            <input type="text" name="description" class="mno-input" placeholder="e.g. Pre-maintenance core routing upgrade" />
          </div>
          <div class="mno-form-group">
            <label class="mno-form-label">Encryption Passphrase (Optional)</label>
            <input type="password" name="encryption_password" class="mno-input" placeholder="Leave empty for plaintext JSON archive" autocomplete="new-password" minlength="12" />
            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">
              At least 12 characters. An encrypted archive carries connector credentials and the connector master key inside its envelope; a plaintext archive carries neither. Store the password separately from the archive.
            </div>
          </div>
        </div>

        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px; margin-bottom:20px;">
          <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--color-cyan); letter-spacing:0.05em; margin-bottom:10px;">
            Included Configuration Subsystems
          </div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; font-size:0.78rem;">
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              Firewall &amp; NAT Rules
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              WireGuard &amp; OpenVPN Tunnels
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              VRRP &amp; High Availability
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              CAKE Traffic Shaper
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              PKI Certificates &amp; CAs
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              Users, Roles &amp; LDAP
            </div>
            <div style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              Package Repositories &amp; Signing Keys
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
          </svg>
          Create Backup Snapshot Now
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 3: RESTORE & DISASTER RECOVERY
══════════════════════════════════════════════════════════════ -->
<div class="backup-panel animate-in animate-in-delay-2" id="tab-restore">
  <div class="backup-card">
    <div class="backup-card-header">
      <span class="backup-card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Restore Configuration Archive
      </span>
      <span class="badge badge-warning" style="font-size:0.7rem;">Automated Rollback Protected</span>
    </div>

    <div class="backup-card-body">
      <form method="POST" action="<?= base_url('backup/restore') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
          <div class="mno-form-group">
            <label class="mno-form-label">Upload Backup Archive (.mbk / .mbk.enc / .json)</label>
            <input type="file" name="backup_file" class="mno-input" accept=".mbk,.enc,.json" required />
          </div>
          <div class="mno-form-group">
            <label class="mno-form-label">Decryption Passphrase</label>
            <input type="password" name="encryption_password" class="mno-input" placeholder="Leave empty if archive is not encrypted" autocomplete="current-password" />
          </div>
        </div>

        <!-- Selective Component Restore -->
        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px; margin-bottom:20px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-primary); letter-spacing:0.05em;">
              Selective Component Restore
            </span>
            <span class="badge badge-info" style="font-size:0.68rem;">Granular Control</span>
          </div>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:10px;">
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="all" checked onchange="toggleAllSections(this.checked)" />
              <strong>Full System Restore (All)</strong>
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="firewall" class="selective-item" checked />
              Firewall &amp; NAT Rules
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="vpn" class="selective-item" checked />
              VPN Tunnels &amp; Peers
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="ha" class="selective-item" checked />
              High Availability (VRRP)
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="qos" class="selective-item" checked />
              Traffic Shaper (CAKE)
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="certificates" class="selective-item" checked />
              PKI Certificates &amp; CAs
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="identity" class="selective-item" checked />
              Users, Roles &amp; LDAP
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="webfilter" class="selective-item" checked />
              Web Filter &amp; Categories
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="network" class="selective-item" checked />
              Network Interfaces
            </label>
            <label class="selective-checkbox-item">
              <input type="checkbox" name="sections[]" value="repositories" class="selective-item" checked />
              Package Repositories &amp; Signing Keys
            </label>
          </div>
        </div>

        <!-- Dissimilar Hardware NIC Remapping -->
        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px; margin-bottom:20px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--color-amber); letter-spacing:0.05em; display:flex; align-items:center; gap:6px;">
              <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
              </svg>
              Hardware Interface Remapping Wizard (Dissimilar Migration)
            </span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="addNicMapRow()" style="font-size:0.72rem; padding:2px 8px;">
              + Add Interface Map
            </button>
          </div>
          <p style="font-size:0.75rem; color:var(--text-secondary); line-height:1.5; margin-bottom:12px;">
            When migrating this archive onto different physical hardware (e.g., from an appliance with <code style="color:var(--color-cyan);">eth0, eth1</code> to PCIe NICs <code style="color:var(--color-cyan);">enp1s0, enp2s0</code>), define the interface translation map below:
          </p>

          <div id="nic-mapping-container">
            <div class="nic-map-row">
              <input type="text" name="nic_map_src[]" class="mno-input" placeholder="Backup Source (e.g. eth0)" style="font-family:var(--font-mono); font-size:0.78rem;" />
              <span style="color:var(--text-muted); font-size:0.8rem;">➔</span>
              <input type="text" name="nic_map_dst[]" class="mno-input" placeholder="Target NIC (e.g. enp1s0)" style="font-family:var(--font-mono); font-size:0.78rem;" />
              <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:4px 8px;" onclick="this.closest('.nic-map-row').remove()">✕</button>
            </div>
            <div class="nic-map-row">
              <input type="text" name="nic_map_src[]" class="mno-input" placeholder="Backup Source (e.g. eth1)" style="font-family:var(--font-mono); font-size:0.78rem;" />
              <span style="color:var(--text-muted); font-size:0.8rem;">➔</span>
              <input type="text" name="nic_map_dst[]" class="mno-input" placeholder="Target NIC (e.g. enp2s0)" style="font-family:var(--font-mono); font-size:0.78rem;" />
              <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:4px 8px;" onclick="this.closest('.nic-map-row').remove()">✕</button>
            </div>
          </div>
        </div>

        <!-- Connector master key conflict handling (§7.6) -->
        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px; margin-bottom:20px;">
          <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--color-purple); letter-spacing:0.05em; margin-bottom:8px;">
            Connector Credentials
          </div>
          <p style="font-size:0.75rem; color:var(--text-secondary); line-height:1.5; margin-bottom:10px;">
            If the archive carries a connector master key, it is installed when this appliance has none and matched when it is the same. If it <strong>differs</strong>, connector credentials are left untouched and must be re-entered unless you explicitly confirm replacement below.
          </p>
          <label class="selective-checkbox-item">
            <input type="checkbox" name="replace_master_key" value="1" />
            Replace this appliance's connector master key with the archive's key
          </label>
        </div>

        <div style="background:rgba(245, 158, 11, 0.08); border:1px solid rgba(245, 158, 11, 0.25); border-radius:var(--radius-sm); padding:12px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber); flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          <div style="font-size:0.75rem; color:var(--text-primary); line-height:1.45;">
            <strong>Watchdog Safeguard Active:</strong> A 60-second watchdog window starts on restoration. If connectivity is broken, the appliance will automatically roll back to its previous operational state.
          </div>
        </div>

        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Are you sure you want to restore? Current configuration will be replaced by the archive.');">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Execute Restoration Archive
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 4: ARCHITECTURE & BEST PRACTICES
══════════════════════════════════════════════════════════════ -->
<div class="backup-panel animate-in animate-in-delay-2" id="tab-guide">
  <div class="backup-card">
    <div class="backup-card-header">
      <span class="backup-card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Disaster Recovery Architecture &amp; Automation
      </span>
    </div>

    <div class="backup-card-body">
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px;">
        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
            <div style="width:28px; height:28px; border-radius:6px; background:rgba(0, 212, 255, 0.15); border:1px solid rgba(0, 212, 255, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-cyan);">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
            </div>
            <strong style="color:var(--text-primary); font-size:0.875rem;">Cryptographic Security</strong>
          </div>
          <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin-bottom:10px;">
            Every encrypted archive utilizes OpenSSL <strong>AES-256-GCM</strong> authenticated encryption with a 128-bit authentication tag and PBKDF2 with 100,000 iterations to resist brute-force attacks on external media.
          </p>
          <span class="mno-obj-chip mno-obj-chip-lan">Zero-Trust Cryptography</span>
        </div>

        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
            <div style="width:28px; height:28px; border-radius:6px; background:rgba(245, 158, 11, 0.15); border:1px solid rgba(245, 158, 11, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-amber);">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
              </svg>
            </div>
            <strong style="color:var(--text-primary); font-size:0.875rem;">Cross-Platform Portability</strong>
          </div>
          <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin-bottom:10px;">
            Dissimilar hardware migration allows deploying configurations from physical appliances into virtual hypervisors (KVM, VMware ESXi, Proxmox) or bare-metal appliances with automatic interface translation.
          </p>
          <span class="mno-obj-chip mno-obj-chip-wan">Hardware Decoupled</span>
        </div>

        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:16px;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
            <div style="width:28px; height:28px; border-radius:6px; background:rgba(16, 185, 129, 0.15); border:1px solid rgba(16, 185, 129, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-emerald);">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <strong style="color:var(--text-primary); font-size:0.875rem;">Cron &amp; Automated Sync</strong>
          </div>
          <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin-bottom:10px;">
            Automate nightly snapshots by setting up a cron job under <a href="<?= base_url('cron') ?>" style="color:var(--color-cyan); text-decoration:none;">System Cron</a> executing <code style="color:var(--color-cyan);">php spark backup:run</code> with automated offsite transport.
          </p>
          <span class="mno-obj-chip mno-obj-chip-success">Automated Offsite</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: QUICK CREATE BACKUP SNAPSHOT
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="createBackupModal">
  <div class="mno-modal-dialog">
    <form method="POST" action="<?= base_url('backup/create') ?>">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
          </svg>
          Create New Configuration Snapshot
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('createBackupModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <div class="mno-form-group">
          <label class="mno-form-label">Description / Reason</label>
          <input type="text" name="description" class="mno-input" placeholder="e.g. Manual pre-maintenance snapshot" required />
        </div>

        <div class="mno-form-group">
          <label class="mno-form-label">Encryption Passphrase (Optional)</label>
          <input type="password" name="encryption_password" class="mno-input" placeholder="Leave empty for unencrypted snapshot" autocomplete="new-password" minlength="12" />
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">
            Optional, 12+ characters. With a password, the snapshot is locked with AES-256-GCM and includes connector credentials and the connector master key; without one, it contains neither. Store the password separately — without it the archive cannot be restored.
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('createBackupModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
          </svg>
          Generate Snapshot
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── JavaScript for Tabs & Interactions ── -->
<script>
function switchBackupTab(tabId, el) {
  document.querySelectorAll('.backup-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.backup-panel').forEach(p => p.classList.remove('active'));

  if (el) el.classList.add('active');
  const panel = document.getElementById(tabId);
  if (panel) panel.classList.add('active');
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('active');
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('active');
}

function toggleAllSections(checked) {
  document.querySelectorAll('.selective-item').forEach(c => {
    c.checked = checked;
  });
}

function addNicMapRow() {
  const container = document.getElementById('nic-mapping-container');
  if (!container) return;
  const row = document.createElement('div');
  row.className = 'nic-map-row';
  row.innerHTML = `
    <input type="text" name="nic_map_src[]" class="mno-input" placeholder="Backup Source (e.g. eth2)" style="font-family:var(--font-mono); font-size:0.78rem;" />
    <span style="color:var(--text-muted); font-size:0.8rem;">➔</span>
    <input type="text" name="nic_map_dst[]" class="mno-input" placeholder="Target NIC (e.g. enp3s0)" style="font-family:var(--font-mono); font-size:0.78rem;" />
    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:4px 8px;" onclick="this.closest('.nic-map-row').remove()">✕</button>
  `;
  container.appendChild(row);
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.mno-modal-backdrop.active').forEach(m => m.classList.remove('active'));
  }
});
</script>

<?= $this->endSection() ?>
