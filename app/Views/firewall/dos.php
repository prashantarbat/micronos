<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<?php
// Detail tooltip lookups for referenced interfaces & schedules.
$__ifaceMap = [];
foreach (($interfaces ?? []) as $__i) {
    foreach ([$__i['id'] ?? '', $__i['name'] ?? '', $__i['physical_device'] ?? ''] as $__k) {
        if ($__k !== '') { $__ifaceMap[strtolower((string) $__k)] = $__i; }
    }
}
$__schedMap = [];
foreach (($schedules ?? []) as $__s) {
    $__schedMap[strtolower((string) $__s['name'])] = $__s;
}
$__ifaceTip = static function (string $n) use ($__ifaceMap): string {
    return $n !== '' && $n !== 'all' && isset($__ifaceMap[strtolower($n)]) ? \App\Libraries\ObjectTip::iface($__ifaceMap[strtolower($n)]) : '';
};
$__schedTip = static function (string $n) use ($__schedMap): string {
    return $n !== '' && isset($__schedMap[strtolower($n)]) ? \App\Libraries\ObjectTip::schedule($__schedMap[strtolower($n)]) : '';
};
?>

<style>
/* ============================================================
   DoS / DDoS Policy Management Styling
   ============================================================ */
.mno-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.mno-kpi-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  transition: all var(--transition-normal);
}
.mno-kpi-card:hover {
  border-color: var(--color-border-bright);
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}
.mno-kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.mno-kpi-val {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.2;
}
.mno-kpi-label {
  font-size: 0.75rem;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-top: 2px;
}

.mno-obj-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 0.72rem;
  font-weight: 500;
  font-family: var(--font-mono);
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.12);
  color: var(--text-primary);
  margin: 2px 2px 2px 0;
  white-space: nowrap;
}
.mno-chip-active {
  background: rgba(59, 130, 246, 0.15);
  border-color: rgba(59, 130, 246, 0.35);
  color: #93c5fd;
}
.mno-chip-inactive {
  background: rgba(148, 163, 184, 0.08);
  border-color: rgba(148, 163, 184, 0.15);
  color: var(--text-muted);
  opacity: 0.65;
}
.mno-chip-danger {
  background: rgba(239, 68, 68, 0.15);
  border-color: rgba(239, 68, 68, 0.35);
  color: #fca5a5;
}
.mno-chip-warning {
  background: rgba(245, 158, 11, 0.15);
  border-color: rgba(245, 158, 11, 0.35);
  color: #fcd34d;
}
.mno-chip-success {
  background: rgba(16, 185, 129, 0.15);
  border-color: rgba(16, 185, 129, 0.35);
  color: #6ee7b7;
}

.mno-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.8125rem;
}
.mno-table th {
  background: var(--color-surface-3);
  color: var(--text-secondary);
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 12px 14px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}
.mno-table td {
  padding: 12px 14px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.mno-table tbody tr:hover {
  background: rgba(255,255,255,0.02);
}

.mno-switch {
  position: relative;
  display: inline-block;
  width: 36px;
  height: 20px;
}
.mno-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.mno-slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #334155;
  transition: .2s;
  border-radius: 20px;
}
.mno-slider:before {
  position: absolute;
  content: "";
  height: 14px;
  width: 14px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .2s;
  border-radius: 50%;
}
input:checked + .mno-slider {
  background-color: var(--color-emerald, #10b981);
}
input:checked + .mno-slider:before {
  transform: translateX(16px);
}

/* Modals */
.mno-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.75);
  backdrop-filter: blur(4px);
  z-index: 1000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.mno-modal-backdrop.active {
  display: flex;
}
.mno-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 820px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
  overflow: hidden;
  animation: modalSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
  from { opacity: 0; transform: translateY(-20px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.mno-modal-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--color-surface-2);
}
.mno-modal-body {
  padding: 24px;
  overflow-y: auto;
  flex: 1 1 auto;
}
.mno-modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-2);
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  flex: 0 0 auto;
}

.preset-btn-group {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  background: var(--color-surface-2);
  padding: 6px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
}
.preset-btn {
  flex: 1;
  padding: 8px 12px;
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-size: 0.8rem;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all var(--transition-fast);
}
.preset-btn:hover {
  background: var(--color-surface-1);
  color: var(--text-primary);
  border-color: var(--color-border-bright);
}
.preset-btn.active {
  background: var(--color-primary);
  color: #fff;
  border-color: var(--color-primary);
}

.form-section-title {
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-secondary);
  margin: 18px 0 10px 0;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  gap: 8px;
}
.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.form-grid-3 {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 14px;
}
@media (max-width: 640px) {
  .form-grid-2, .form-grid-3 {
    grid-template-columns: 1fr;
  }
}
</style>

<!-- Flash Alerts -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div><?= esc(session()->getFlashdata('success')) ?></div>
  </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
    </svg>
    <div><?= esc(session()->getFlashdata('error')) ?></div>
  </div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px;">
        <h1 class="page-title">DDoS &amp; DoS Protection Policies</h1>
        <span class="badge badge-info" style="font-size:0.75rem;"><?= count($policies) ?> Policies</span>
      </div>
      <p class="page-subtitle">Layer 4 flood mitigation, TCP synproxy rate limits, per-source connection caps, and dynamic attacker quarantine</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button type="button" class="btn btn-primary btn-sm" onclick="openCreateModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create DoS Policy
      </button>

      <form method="POST" action="<?= base_url('firewall/dos/apply') ?>" style="display:inline;" onsubmit="return confirm('Compile and activate DoS policies in live Netfilter table?');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-accent btn-sm" title="Compile and activate DoS policies in live Netfilter table">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
          </svg>
          Apply to Firewall
        </button>
      </form>

      <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm" title="View standard firewall access policies">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Firewall Rules
      </a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<?php
$activeCount = 0;
$quarantineCount = 0;
$synCount = 0;
foreach ($policies as $p) {
    if ((int)$p['enabled'] === 1) {
        $activeCount++;
        if ((int)$p['ban_duration'] > 0) $quarantineCount++;
        if ((int)$p['syn_flood_enabled'] === 1) $synCount++;
    }
}
?>
<div class="mno-kpi-grid animate-in animate-in-delay-1">
  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(59,130,246,0.15); color:#60a5fa;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= count($policies) ?></div>
      <div class="mno-kpi-label">Total DoS Policies</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(16,185,129,0.15); color:#34d399;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= $activeCount ?></div>
      <div class="mno-kpi-label">Active Enforcing</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(239,68,68,0.15); color:#f87171;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= $quarantineCount ?></div>
      <div class="mno-kpi-label">Dynamic Blacklist Set</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(168,85,247,0.15); color:#c084fc;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val">Kernel L4</div>
      <div class="mno-kpi-label">nftables Data Plane</div>
    </div>
  </div>
</div>

<!-- Toolbar & Search -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <div style="position:relative;">
      <input type="text" id="dosSearchInput" placeholder="Search policies (name, interface, comment)..."
             onkeyup="filterDosTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:260px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <select id="interfaceFilter" onchange="filterDosTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All Interfaces</option>
      <option value="all_ifaces">Global (All)</option>
      <?php foreach ($interfaces as $iface): ?>
        <option value="<?= esc($iface['name']) ?>"><?= esc($iface['name']) ?> (<?= esc($iface['type'] ?? 'interface') ?>)</option>
      <?php endforeach; ?>
    </select>

    <select id="statusFilter" onchange="filterDosTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All Statuses</option>
      <option value="enabled">Enabled Only</option>
      <option value="disabled">Disabled Only</option>
    </select>
  </div>

  <div style="font-size:0.75rem; color:var(--text-muted);">
    Total: <strong style="color:var(--text-primary);" id="filteredCount"><?= count($policies) ?></strong> policies
  </div>
</div>

<!-- DoS Policies Table -->
<div class="card card-glass animate-in animate-in-delay-2" style="padding:0; overflow-x:auto;">
  <table class="mno-table" id="dosTable">
    <thead>
      <tr>
        <th style="width:65px; text-align:center;">Status</th>
        <th style="min-width:220px;">Policy Name</th>
        <th style="width:110px;">Interface</th>
        <th style="min-width:320px;">L4 Attack Thresholds</th>
        <th style="width:140px;">Action / Ban</th>
        <th style="width:90px; text-align:center;">Logging</th>
        <th style="width:120px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($policies)): ?>
        <tr>
          <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
            No DoS policies configured. Click "Create DoS Policy" to set up layer 4 mitigation.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($policies as $policy): ?>
          <tr data-name="<?= strtolower(esc($policy['name'])) ?>"
              data-interface="<?= strtolower(esc($policy['interface_id'])) ?>"
              data-status="<?= (int)$policy['enabled'] === 1 ? 'enabled' : 'disabled' ?>"
              data-desc="<?= strtolower(esc($policy['description'] ?? '')) ?>">
            
            <!-- Status Toggle -->
            <td style="text-align:center;">
              <form method="POST" action="<?= base_url('firewall/dos/' . $policy['id'] . '/toggle') ?>" style="margin:0;">
                <?= csrf_field() ?>
                <label class="mno-switch" title="Click to toggle policy state">
                  <input type="checkbox" onchange="this.form.submit()" <?= (int)$policy['enabled'] === 1 ? 'checked' : '' ?>>
                  <span class="mno-slider"></span>
                </label>
              </form>
            </td>

            <!-- Policy Name & Description -->
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:600; color:var(--text-primary); font-size:0.875rem;">
                  <?= esc($policy['name']) ?>
                </span>
                <span class="badge" style="font-size:0.65rem; background:rgba(255,255,255,0.08); font-family:var(--font-mono);">
                  <?= esc($policy['id']) ?>
                </span>
              </div>
              <?php if (!empty($policy['description'])): ?>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                  <?= esc($policy['description']) ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($policy['exempt_addresses'])): ?>
                <div style="font-size:0.72rem; color:var(--color-cyan, #06b6d4); margin-top:3px; display:flex; align-items:center; gap:4px;">
                  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                  </svg>
                  Exempt: <?= esc(substr($policy['exempt_addresses'], 0, 32)) ?><?= strlen($policy['exempt_addresses']) > 32 ? '...' : '' ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- Interface -->
            <td>
              <?php if ($policy['interface_id'] === 'all'): ?>
                <span class="badge badge-info" style="font-size:0.72rem;">Global (All)</span>
              <?php else: ?>
                <span class="badge badge-secondary" style="font-size:0.72rem; font-family:var(--font-mono);"<?= $__ifaceTip((string) $policy['interface_id']) ?>>
                  <?= esc($policy['interface_id']) ?>
                </span>
              <?php endif; ?>
              <?php $pSched = $policy['schedule'] ?? 'always'; $pAlways = $pSched === '' || strtolower($pSched) === 'always'; ?>
              <div style="margin-top:4px;">
                <span class="mno-obj-chip <?= $pAlways ? 'mno-chip-inactive' : 'mno-chip-active' ?>" title="Schedule: <?= esc($pSched) ?>"<?= $__schedTip((string) $pSched) ?>>
                  &#128339; <?= esc(strtoupper($pAlways ? 'always' : $pSched)) ?>
                </span>
              </div>
            </td>

            <!-- Thresholds & Sensors -->
            <td>
              <div style="display:flex; flex-wrap:wrap; gap:4px;">
                <!-- SYN Flood -->
                <?php if ((int)$policy['syn_flood_enabled'] === 1): ?>
                  <span class="mno-obj-chip mno-chip-active" title="SYN Flood Limit">
                    ⚡ SYN: <?= (int)$policy['syn_flood_rate'] ?>/s (b:<?= (int)$policy['syn_flood_burst'] ?>)
                  </span>
                <?php else: ?>
                  <span class="mno-obj-chip mno-chip-inactive">SYN: Off</span>
                <?php endif; ?>

                <!-- Concurrent Conn Limit -->
                <?php if ((int)$policy['conn_limit_enabled'] === 1): ?>
                  <span class="mno-obj-chip mno-chip-warning" title="Per-host concurrent connection limit">
                    🔗 Conns: max <?= (int)$policy['conn_limit_max'] ?>
                  </span>
                <?php else: ?>
                  <span class="mno-obj-chip mno-chip-inactive">Conns: Off</span>
                <?php endif; ?>

                <!-- New Conn Rate -->
                <?php if ((int)$policy['conn_rate_enabled'] === 1): ?>
                  <span class="mno-obj-chip mno-chip-warning" title="Per-host new connection rate">
                    ⏱️ Rate: <?= (int)$policy['conn_rate_max'] ?>/m
                  </span>
                <?php else: ?>
                  <span class="mno-obj-chip mno-chip-inactive">Rate: Off</span>
                <?php endif; ?>

                <!-- UDP Flood -->
                <?php if ((int)$policy['udp_flood_enabled'] === 1): ?>
                  <span class="mno-obj-chip mno-chip-active" title="UDP Flood Threshold">
                    📡 UDP: <?= (int)$policy['udp_flood_rate'] ?>/s
                  </span>
                <?php endif; ?>

                <!-- ICMP Flood -->
                <?php if ((int)$policy['icmp_flood_enabled'] === 1): ?>
                  <span class="mno-obj-chip mno-chip-active" title="ICMP Echo Flood Threshold">
                    🏓 ICMP: <?= (int)$policy['icmp_flood_rate'] ?>/s
                  </span>
                <?php endif; ?>

                <!-- Port Scan -->
                <?php if ((int)$policy['port_scan_enabled'] === 1): ?>
                  <span class="mno-obj-chip mno-chip-success" title="Detect and drop NULL, XMAS, SYN-FIN, SYN-RST scans">
                    🛡️ Scan Filter
                  </span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Action & Quarantine -->
            <td>
              <div>
                <?php if (strtolower($policy['action']) === 'reject'): ?>
                  <span class="badge badge-warning" style="font-size:0.7rem; text-transform:uppercase;">REJECT</span>
                <?php else: ?>
                  <span class="badge badge-danger" style="font-size:0.7rem; text-transform:uppercase;">DROP</span>
                <?php endif; ?>
              </div>
              <div style="margin-top:4px;">
                <?php if ((int)$policy['ban_duration'] > 0): ?>
                  <span class="mno-obj-chip mno-chip-danger" title="Dynamic nftables timeout ban">
                    🚫 Ban <?= (int)$policy['ban_duration'] ?>s
                  </span>
                <?php else: ?>
                  <span class="mno-obj-chip mno-chip-inactive" title="No temporary blacklist">
                    No Blacklist
                  </span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Logging -->
            <td style="text-align:center;">
              <?php if ((int)$policy['log_enabled'] === 1): ?>
                <span class="badge badge-success" style="font-size:0.68rem;">LOG</span>
              <?php else: ?>
                <span class="badge" style="font-size:0.68rem; background:rgba(255,255,255,0.06); color:var(--text-muted);">OFF</span>
              <?php endif; ?>
            </td>

            <!-- Actions -->
            <td style="text-align:right;">
              <div style="display:inline-flex; gap:6px;">
                <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 8px;"
                        onclick='openEditModal(<?= json_encode($policy) ?>)' title="Edit Policy">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                  </svg>
                </button>

                <form method="POST" action="<?= base_url('firewall/dos/' . $policy['id'] . '/delete') ?>"
                      onsubmit="return confirm('Delete DoS mitigation policy &quot;<?= esc($policy['name']) ?>&quot;?');" style="display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px; color:var(--color-danger, #ef4444);" title="Delete Policy">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ============================================================
     CREATE POLICY MODAL
     ============================================================ -->
<div class="mno-modal-backdrop" id="createPolicyModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-primary);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <h3 style="margin:0; font-size:1.05rem; font-weight:600; color:var(--text-primary);">Create DoS Mitigation Policy</h3>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeCreateModal()">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <form method="POST" action="<?= base_url('firewall/dos/store') ?>" id="createPolicyForm">
      <?= csrf_field() ?>
      <div class="mno-modal-body">

        <!-- Quick Presets -->
        <label style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:600; display:block; margin-bottom:6px;">
          Quick Sensitivity Presets:
        </label>
        <div class="preset-btn-group">
          <button type="button" class="preset-btn" onclick="applyPreset('create', 'relaxed')">
            <span>🛡️</span> Relaxed (High Traffic)
          </button>
          <button type="button" class="preset-btn active" onclick="applyPreset('create', 'balanced')">
            <span>⚖️</span> Balanced (Recommended)
          </button>
          <button type="button" class="preset-btn" onclick="applyPreset('create', 'aggressive')">
            <span>🔒</span> Aggressive (High Threat)
          </button>
        </div>

        <!-- 1. General Info -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          General Configuration
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="create_name">Policy Name *</label>
            <input type="text" class="form-control" id="create_name" name="name" required placeholder="e.g. WAN DoS Mitigation" value="WAN DoS Defense">
          </div>

          <div class="form-group">
            <label class="form-label" for="create_interface_id">Target Interface *</label>
            <select class="form-control" id="create_interface_id" name="interface_id" data-fw-iface>
              <option value="all">Global (All Interfaces)</option>
              <?php foreach ($interfaces as $iface): ?>
                <option value="<?= esc($iface['name']) ?>"<?= \App\Libraries\ObjectTip::iface($iface) ?>><?= esc($iface['name']) ?> (<?= esc(($iface['physical_device'] ?? '') ?: ($iface['type'] ?? 'interface')) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid-2" style="margin-top:10px;">
          <div class="form-group">
            <label class="form-label" for="create_action">Mitigation Action</label>
            <select class="form-control" id="create_action" name="action">
              <option value="drop" selected>Drop (Silent Discard - Recommended)</option>
              <option value="reject">Reject (Send TCP RST / ICMP Unreachable)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="create_description">Description / Notes</label>
            <input type="text" class="form-control" id="create_description" name="description" placeholder="Optional notes about this policy">
          </div>
        </div>

        <div class="form-group" style="margin-top:10px;">
          <label class="form-label" for="create_schedule">Schedule</label>
          <select class="form-control" id="create_schedule" name="schedule" data-fw-select>
            <?php foreach (($schedules ?? []) as $sc): ?>
              <option value="<?= esc($sc['name']) ?>" <?= $sc['name'] === 'always' ? 'selected' : '' ?><?= \App\Libraries\ObjectTip::schedule($sc) ?>>
                <?= esc(ucwords(str_replace('_', ' ', $sc['name']))) ?> (<?= esc(\App\Models\ScheduleModel::formatSummary($sc)) ?>)<?= (int) ($sc['enabled'] ?? 1) === 0 ? ' [disabled]' : '' ?>
              </option>
            <?php endforeach; ?>
            <?php if (empty($schedules)): ?><option value="always" selected>Always</option><?php endif; ?>
          </select>
          <small class="text-muted" style="font-size:0.72rem;">Mitigation rules are only enforced during this time window. Manage objects in <a href="<?= base_url('firewall/schedules') ?>">Schedules</a>.</small>
        </div>

        <!-- 2. SYN Flood -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          TCP SYN Flood Protection
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <label class="mno-switch">
            <input type="checkbox" name="syn_flood_enabled" id="create_syn_flood_enabled" value="1" checked>
            <span class="mno-slider"></span>
          </label>
          <label for="create_syn_flood_enabled" style="font-size:0.85rem; font-weight:500; cursor:pointer;">
            Enable TCP SYN rate limiting &amp; synproxy meter
          </label>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="create_syn_flood_rate">SYN Rate Threshold (packets/sec)</label>
            <input type="number" class="form-control" id="create_syn_flood_rate" name="syn_flood_rate" value="100" min="1" max="100000">
            <small class="text-muted" style="font-size:0.72rem;">Maximum allowed TCP SYN packets per second from a single IP</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="create_syn_flood_burst">SYN Burst Allowance (packets)</label>
            <input type="number" class="form-control" id="create_syn_flood_burst" name="syn_flood_burst" value="200" min="1" max="100000">
            <small class="text-muted" style="font-size:0.72rem;">Peak burst packets before triggering rate mitigation</small>
          </div>
        </div>

        <!-- 3. Connection Limits -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
          Host Connection Caps &amp; Frequency
        </div>

        <div class="form-grid-2">
          <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
              <label class="mno-switch">
                <input type="checkbox" name="conn_limit_enabled" id="create_conn_limit_enabled" value="1" checked>
                <span class="mno-slider"></span>
              </label>
              <label for="create_conn_limit_enabled" style="font-size:0.82rem; font-weight:500; cursor:pointer;">
                Concurrent Connection Cap
              </label>
            </div>
            <div class="form-group">
              <label class="form-label" for="create_conn_limit_max">Max Concurrent Conns / Host</label>
              <input type="number" class="form-control" id="create_conn_limit_max" name="conn_limit_max" value="50" min="1" max="10000">
              <small class="text-muted" style="font-size:0.72rem;">Drop new connections if host has more than this number active</small>
            </div>
          </div>

          <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
              <label class="mno-switch">
                <input type="checkbox" name="conn_rate_enabled" id="create_conn_rate_enabled" value="1" checked>
                <span class="mno-slider"></span>
              </label>
              <label for="create_conn_rate_enabled" style="font-size:0.82rem; font-weight:500; cursor:pointer;">
                New Connection Rate
              </label>
            </div>
            <div class="form-group">
              <label class="form-label" for="create_conn_rate_max">Max New Conns / Minute / Host</label>
              <input type="number" class="form-control" id="create_conn_rate_max" name="conn_rate_max" value="60" min="1" max="10000">
              <small class="text-muted" style="font-size:0.72rem;">Throttles rapid connection establishment attempts</small>
            </div>
          </div>
        </div>

        <!-- 4. Protocol Floods & Port Scans -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          Protocol Floods &amp; Port Sweep Defense
        </div>

        <div class="form-grid-3">
          <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <input type="checkbox" name="udp_flood_enabled" id="create_udp_flood_enabled" value="1" checked>
              <label for="create_udp_flood_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">UDP Flood Limit</label>
            </div>
            <div class="form-group">
              <input type="number" class="form-control" id="create_udp_flood_rate" name="udp_flood_rate" value="500" min="1" placeholder="pkts/s">
              <small class="text-muted" style="font-size:0.7rem;">Packets/sec</small>
            </div>
          </div>

          <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <input type="checkbox" name="icmp_flood_enabled" id="create_icmp_flood_enabled" value="1" checked>
              <label for="create_icmp_flood_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">ICMP Flood Limit</label>
            </div>
            <div class="form-group">
              <input type="number" class="form-control" id="create_icmp_flood_rate" name="icmp_flood_rate" value="20" min="1" placeholder="pkts/s">
              <small class="text-muted" style="font-size:0.7rem;">Packets/sec</small>
            </div>
          </div>

          <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <input type="checkbox" name="port_scan_enabled" id="create_port_scan_enabled" value="1" checked>
              <label for="create_port_scan_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">Scan Protection</label>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted); line-height:1.4; padding-top:4px;">
              Drops abnormal TCP packets (SYN-FIN, SYN-RST, NULL, XMAS scans).
            </div>
          </div>
        </div>

        <!-- 5. Blacklisting & Exemptions -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          Attacker Quarantine &amp; Whitelist
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="create_ban_duration">Blacklist Quarantine Duration (seconds)</label>
            <input type="number" class="form-control" id="create_ban_duration" name="ban_duration" value="600" min="0" max="86400">
            <small class="text-muted" style="font-size:0.72rem;">Set to 0 to only rate-drop without temporary host IP ban. 600s = 10 mins.</small>
          </div>

          <div class="form-group">
            <label class="form-label" for="create_exempt_addresses">Exempt Addresses / CIDRs</label>
            <input type="text" class="form-control" id="create_exempt_addresses" name="exempt_addresses" placeholder="e.g. 192.168.1.0/24, 8.8.8.8, 10.0.0.1">
            <small class="text-muted" style="font-size:0.72rem;">Comma-separated IPs/subnets never subject to DoS throttling</small>
          </div>
        </div>

        <div style="display:flex; gap:20px; align-items:center; margin-top:14px; padding:12px; background:var(--color-surface-2); border-radius:var(--radius-sm); border:1px solid var(--color-border);">
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="log_enabled" id="create_log_enabled" value="1" checked>
            <label for="create_log_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">
              Log dropped attack packets to Netfilter journal
            </label>
          </div>

          <div style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="enabled" id="create_enabled" value="1" checked>
            <label for="create_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">
              Enable Policy Immediately
            </label>
          </div>
        </div>

      </div>
      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCreateModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Policy</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     EDIT POLICY MODAL
     ============================================================ -->
<div class="mno-modal-backdrop" id="editPolicyModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-primary);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
        <h3 style="margin:0; font-size:1.05rem; font-weight:600; color:var(--text-primary);">Edit DoS Policy: <span id="edit_modal_title_name" style="color:var(--color-cyan, #06b6d4);"></span></h3>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeEditModal()">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <form method="POST" action="" id="editPolicyForm">
      <?= csrf_field() ?>
      <div class="mno-modal-body">

        <!-- Quick Presets -->
        <label style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:600; display:block; margin-bottom:6px;">
          Apply Quick Sensitivity Preset:
        </label>
        <div class="preset-btn-group">
          <button type="button" class="preset-btn" onclick="applyPreset('edit', 'relaxed')">
            <span>🛡️</span> Relaxed
          </button>
          <button type="button" class="preset-btn" onclick="applyPreset('edit', 'balanced')">
            <span>⚖️</span> Balanced
          </button>
          <button type="button" class="preset-btn" onclick="applyPreset('edit', 'aggressive')">
            <span>🔒</span> Aggressive
          </button>
        </div>

        <!-- 1. General Info -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          General Configuration
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="edit_name">Policy Name *</label>
            <input type="text" class="form-control" id="edit_name" name="name" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="edit_interface_id">Target Interface *</label>
            <select class="form-control" id="edit_interface_id" name="interface_id" data-fw-iface>
              <option value="all">Global (All Interfaces)</option>
              <?php foreach ($interfaces as $iface): ?>
                <option value="<?= esc($iface['name']) ?>"<?= \App\Libraries\ObjectTip::iface($iface) ?>><?= esc($iface['name']) ?> (<?= esc(($iface['physical_device'] ?? '') ?: ($iface['type'] ?? 'interface')) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid-2" style="margin-top:10px;">
          <div class="form-group">
            <label class="form-label" for="edit_action">Mitigation Action</label>
            <select class="form-control" id="edit_action" name="action">
              <option value="drop">Drop (Silent Discard)</option>
              <option value="reject">Reject (Send TCP RST / ICMP Unreachable)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="edit_description">Description / Notes</label>
            <input type="text" class="form-control" id="edit_description" name="description">
          </div>
        </div>

        <div class="form-group" style="margin-top:10px;">
          <label class="form-label" for="edit_schedule">Schedule</label>
          <select class="form-control" id="edit_schedule" name="schedule" data-fw-select>
            <?php foreach (($schedules ?? []) as $sc): ?>
              <option value="<?= esc($sc['name']) ?>"<?= \App\Libraries\ObjectTip::schedule($sc) ?>>
                <?= esc(ucwords(str_replace('_', ' ', $sc['name']))) ?> (<?= esc(\App\Models\ScheduleModel::formatSummary($sc)) ?>)<?= (int) ($sc['enabled'] ?? 1) === 0 ? ' [disabled]' : '' ?>
              </option>
            <?php endforeach; ?>
            <?php if (empty($schedules)): ?><option value="always">Always</option><?php endif; ?>
          </select>
          <small class="text-muted" style="font-size:0.72rem;">Mitigation rules are only enforced during this time window.</small>
        </div>

        <!-- 2. SYN Flood -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          TCP SYN Flood Protection
        </div>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <label class="mno-switch">
            <input type="checkbox" name="syn_flood_enabled" id="edit_syn_flood_enabled" value="1">
            <span class="mno-slider"></span>
          </label>
          <label for="edit_syn_flood_enabled" style="font-size:0.85rem; font-weight:500; cursor:pointer;">
            Enable TCP SYN rate limiting &amp; synproxy meter
          </label>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="edit_syn_flood_rate">SYN Rate Threshold (packets/sec)</label>
            <input type="number" class="form-control" id="edit_syn_flood_rate" name="syn_flood_rate" min="1" max="100000">
          </div>
          <div class="form-group">
            <label class="form-label" for="edit_syn_flood_burst">SYN Burst Allowance (packets)</label>
            <input type="number" class="form-control" id="edit_syn_flood_burst" name="syn_flood_burst" min="1" max="100000">
          </div>
        </div>

        <!-- 3. Connection Limits -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
          Host Connection Caps &amp; Frequency
        </div>

        <div class="form-grid-2">
          <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
              <label class="mno-switch">
                <input type="checkbox" name="conn_limit_enabled" id="edit_conn_limit_enabled" value="1">
                <span class="mno-slider"></span>
              </label>
              <label for="edit_conn_limit_enabled" style="font-size:0.82rem; font-weight:500; cursor:pointer;">
                Concurrent Connection Cap
              </label>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit_conn_limit_max">Max Concurrent Conns / Host</label>
              <input type="number" class="form-control" id="edit_conn_limit_max" name="conn_limit_max" min="1" max="10000">
            </div>
          </div>

          <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
              <label class="mno-switch">
                <input type="checkbox" name="conn_rate_enabled" id="edit_conn_rate_enabled" value="1">
                <span class="mno-slider"></span>
              </label>
              <label for="edit_conn_rate_enabled" style="font-size:0.82rem; font-weight:500; cursor:pointer;">
                New Connection Rate
              </label>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit_conn_rate_max">Max New Conns / Minute / Host</label>
              <input type="number" class="form-control" id="edit_conn_rate_max" name="conn_rate_max" min="1" max="10000">
            </div>
          </div>
        </div>

        <!-- 4. Protocol Floods & Port Scans -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          Protocol Floods &amp; Port Sweep Defense
        </div>

        <div class="form-grid-3">
          <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <input type="checkbox" name="udp_flood_enabled" id="edit_udp_flood_enabled" value="1">
              <label for="edit_udp_flood_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">UDP Flood Limit</label>
            </div>
            <div class="form-group">
              <input type="number" class="form-control" id="edit_udp_flood_rate" name="udp_flood_rate" min="1">
            </div>
          </div>

          <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <input type="checkbox" name="icmp_flood_enabled" id="edit_icmp_flood_enabled" value="1">
              <label for="edit_icmp_flood_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">ICMP Flood Limit</label>
            </div>
            <div class="form-group">
              <input type="number" class="form-control" id="edit_icmp_flood_rate" name="icmp_flood_rate" min="1">
            </div>
          </div>

          <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <input type="checkbox" name="port_scan_enabled" id="edit_port_scan_enabled" value="1">
              <label for="edit_port_scan_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">Scan Protection</label>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted); line-height:1.4; padding-top:4px;">
              Drops abnormal TCP flags (SYN-FIN, SYN-RST, NULL, XMAS).
            </div>
          </div>
        </div>

        <!-- 5. Blacklisting & Exemptions -->
        <div class="form-section-title">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          Attacker Quarantine &amp; Whitelist
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="edit_ban_duration">Blacklist Quarantine Duration (seconds)</label>
            <input type="number" class="form-control" id="edit_ban_duration" name="ban_duration" min="0" max="86400">
            <small class="text-muted" style="font-size:0.72rem;">0 = rate-drop only without blacklist set insertion.</small>
          </div>

          <div class="form-group">
            <label class="form-label" for="edit_exempt_addresses">Exempt Addresses / CIDRs</label>
            <input type="text" class="form-control" id="edit_exempt_addresses" name="exempt_addresses">
            <small class="text-muted" style="font-size:0.72rem;">Comma-separated IPs or subnets</small>
          </div>
        </div>

        <div style="display:flex; gap:20px; align-items:center; margin-top:14px; padding:12px; background:var(--color-surface-2); border-radius:var(--radius-sm); border:1px solid var(--color-border);">
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="log_enabled" id="edit_log_enabled" value="1">
            <label for="edit_log_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">
              Log dropped attack packets to Netfilter journal
            </label>
          </div>

          <div style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="enabled" id="edit_enabled" value="1">
            <label for="edit_enabled" style="font-size:0.8rem; font-weight:500; cursor:pointer;">
              Policy Enabled
            </label>
          </div>
        </div>

      </div>
      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
const PRESETS = <?= json_encode($presets) ?>;

function applyPreset(prefix, key) {
  const p = PRESETS[key];
  if (!p) return;

  document.getElementById(prefix + '_syn_flood_rate').value  = p.syn_flood_rate;
  document.getElementById(prefix + '_syn_flood_burst').value = p.syn_flood_burst;
  document.getElementById(prefix + '_conn_limit_max').value  = p.conn_limit_max;
  document.getElementById(prefix + '_conn_rate_max').value   = p.conn_rate_max;
  document.getElementById(prefix + '_udp_flood_rate').value  = p.udp_flood_rate;
  document.getElementById(prefix + '_icmp_flood_rate').value = p.icmp_flood_rate;
  document.getElementById(prefix + '_ban_duration').value    = p.ban_duration;

  // Highlight active preset button inside modal
  const modal = document.getElementById(prefix === 'create' ? 'createPolicyModal' : 'editPolicyModal');
  const buttons = modal.querySelectorAll('.preset-btn');
  buttons.forEach(btn => {
    btn.classList.toggle('active', btn.textContent.toLowerCase().includes(key));
  });
}

function openCreateModal() {
  document.getElementById('createPolicyModal').classList.add('active');
}

function closeCreateModal() {
  document.getElementById('createPolicyModal').classList.remove('active');
}

function openEditModal(policy) {
  const form = document.getElementById('editPolicyForm');
  form.action = '<?= base_url('firewall/dos') ?>/' + policy.id + '/update';

  document.getElementById('edit_modal_title_name').textContent = policy.name;
  document.getElementById('edit_name').value = policy.name || '';
  document.getElementById('edit_interface_id').value = policy.interface_id || 'all';
  document.getElementById('edit_action').value = policy.action || 'drop';
  document.getElementById('edit_description').value = policy.description || '';
  document.getElementById('edit_schedule').value = policy.schedule || 'always';

  document.getElementById('edit_syn_flood_enabled').checked = parseInt(policy.syn_flood_enabled, 10) === 1;
  document.getElementById('edit_syn_flood_rate').value = policy.syn_flood_rate || 100;
  document.getElementById('edit_syn_flood_burst').value = policy.syn_flood_burst || 200;

  document.getElementById('edit_conn_limit_enabled').checked = parseInt(policy.conn_limit_enabled, 10) === 1;
  document.getElementById('edit_conn_limit_max').value = policy.conn_limit_max || 50;

  document.getElementById('edit_conn_rate_enabled').checked = parseInt(policy.conn_rate_enabled, 10) === 1;
  document.getElementById('edit_conn_rate_max').value = policy.conn_rate_max || 60;

  document.getElementById('edit_udp_flood_enabled').checked = parseInt(policy.udp_flood_enabled, 10) === 1;
  document.getElementById('edit_udp_flood_rate').value = policy.udp_flood_rate || 500;

  document.getElementById('edit_icmp_flood_enabled').checked = parseInt(policy.icmp_flood_enabled, 10) === 1;
  document.getElementById('edit_icmp_flood_rate').value = policy.icmp_flood_rate || 20;

  document.getElementById('edit_port_scan_enabled').checked = parseInt(policy.port_scan_enabled, 10) === 1;
  document.getElementById('edit_ban_duration').value = policy.ban_duration !== null ? policy.ban_duration : 600;
  document.getElementById('edit_exempt_addresses').value = policy.exempt_addresses || '';
  document.getElementById('edit_log_enabled').checked = parseInt(policy.log_enabled, 10) === 1;
  document.getElementById('edit_enabled').checked = parseInt(policy.enabled, 10) === 1;

  document.getElementById('editPolicyModal').classList.add('active');
}

function closeEditModal() {
  document.getElementById('editPolicyModal').classList.remove('active');
}

// Close modals when clicking backdrop
document.querySelectorAll('.mno-modal-backdrop').forEach(modal => {
  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.classList.remove('active');
    }
  });
});

// ESC key to close modals
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeCreateModal();
    closeEditModal();
  }
});

// Table live search & filter
function filterDosTable() {
  const query = (document.getElementById('dosSearchInput').value || '').toLowerCase().trim();
  const ifaceFilter = (document.getElementById('interfaceFilter').value || 'all').toLowerCase();
  const statusFilter = (document.getElementById('statusFilter').value || 'all').toLowerCase();

  const rows = document.querySelectorAll('#dosTable tbody tr');
  let visibleCount = 0;

  rows.forEach(row => {
    if (!row.dataset.name) return; // skip empty state row

    const name  = row.dataset.name || '';
    const iface = row.dataset.interface || '';
    const status = row.dataset.status || '';
    const desc  = row.dataset.desc || '';

    const matchesQuery = query === '' || name.includes(query) || iface.includes(query) || desc.includes(query);
    const matchesIface = ifaceFilter === 'all' || 
                         (ifaceFilter === 'all_ifaces' && iface === 'all') ||
                         iface === ifaceFilter;
    const matchesStatus = statusFilter === 'all' || status === statusFilter;

    if (matchesQuery && matchesIface && matchesStatus) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  const countElem = document.getElementById('filteredCount');
  if (countElem) {
    countElem.textContent = visibleCount;
  }
}
</script>

<?= $this->endSection() ?>
