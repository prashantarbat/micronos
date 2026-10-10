<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
/* ============================================================
   Schedule Objects Styling
   ============================================================ */
.mno-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
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

.day-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 22px;
  border-radius: 4px;
  font-size: 0.7rem;
  font-weight: 600;
  font-family: var(--font-mono);
  background: rgba(255,255,255,0.06);
  color: var(--text-muted);
  border: 1px solid rgba(255,255,255,0.1);
  margin-right: 2px;
}
.day-badge.active {
  background: rgba(59, 130, 246, 0.2);
  color: #93c5fd;
  border-color: rgba(59, 130, 246, 0.4);
}

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
  max-width: 680px;
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

.days-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 8px 0;
}
.days-picker label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  font-size: 0.8rem;
  font-weight: 500;
  cursor: pointer;
  user-select: none;
  transition: all var(--transition-fast);
}
.days-picker label:has(input:checked) {
  background: rgba(59, 130, 246, 0.2);
  border-color: rgba(59, 130, 246, 0.5);
  color: #93c5fd;
}

/* Enable / disable switch */
.sched-switch {
  position: relative;
  display: inline-block;
  width: 38px;
  height: 20px;
  border: none;
  padding: 0;
  border-radius: 999px;
  background: var(--color-surface-3);
  box-shadow: inset 0 0 0 1px var(--color-border);
  cursor: pointer;
  transition: background var(--transition-fast), box-shadow var(--transition-fast);
  vertical-align: middle;
}
.sched-switch::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--text-muted);
  transition: transform var(--transition-fast), background var(--transition-fast);
}
.sched-switch.on {
  background: rgba(16, 185, 129, 0.25);
  box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.55);
}
.sched-switch.on::after {
  transform: translateX(18px);
  background: #34d399;
}
.sched-switch:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.sched-switch:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
.sched-status-label {
  font-size: 0.7rem;
  font-weight: 600;
  margin-left: 8px;
  vertical-align: middle;
}
#schedTable tr.is-disabled td:not(.sched-status-cell):not(.sched-actions-cell) {
  opacity: 0.5;
}
</style>

<!-- Flash Alerts -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px;">
        <h1 class="page-title">Firewall Schedules</h1>
        <span class="badge badge-info" style="font-size:0.75rem;"><?= count($schedules) ?> Objects</span>
      </div>
      <p class="page-subtitle">Configure recurring and one-time time schedules for Firewall Rules, Destination NAT, Traffic Shaper (QoS), and DoS Policies</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button type="button" class="btn btn-primary btn-sm" onclick="openCreateModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create Schedule
      </button>

      <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm" title="View Firewall Policies">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Firewall Rules
      </a>

      <a href="<?= base_url('nat') ?>" class="btn btn-ghost btn-sm" title="View NAT Rules">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
        NAT
      </a>

      <a href="<?= base_url('qos') ?>" class="btn btn-ghost btn-sm" title="View QoS Shaper">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
        Traffic Shaper
      </a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<?php
$recurringCount = 0;
$onetimeCount   = 0;
$totalRefs      = 0;
$activeCount    = 0;
foreach ($schedules as $s) {
    if (($s['type'] ?? 'recurring') === 'onetime') $onetimeCount++;
    else $recurringCount++;
    $totalRefs += ($usage[$s['id']]['total'] ?? 0);
    if ((int) ($s['enabled'] ?? 1) === 1) $activeCount++;
}
$disabledCount = count($schedules) - $activeCount;
?>
<div class="mno-kpi-grid animate-in animate-in-delay-1">
  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(59,130,246,0.15); color:#60a5fa;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= count($schedules) ?></div>
      <div class="mno-kpi-label">Total Schedules</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(16,185,129,0.15); color:#34d399;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5.636 5.636a9 9 0 1012.728 0M12 3v9"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= $activeCount ?><span style="font-size:0.85rem; color:var(--text-muted); font-weight:500;"> / <?= $disabledCount ?> off</span></div>
      <div class="mno-kpi-label">Enabled Schedules</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(16,185,129,0.15); color:#34d399;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= $recurringCount ?></div>
      <div class="mno-kpi-label">Recurring Schedules</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(245,158,11,0.15); color:#fbbf24;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= $onetimeCount ?></div>
      <div class="mno-kpi-label">One-Time Windows</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background:rgba(168,85,247,0.15); color:#c084fc;">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
      </svg>
    </div>
    <div>
      <div class="mno-kpi-val"><?= $totalRefs ?></div>
      <div class="mno-kpi-label">Subsystem References</div>
    </div>
  </div>
</div>

<!-- Search & Filter Bar -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <div style="position:relative;">
      <input type="text" id="schedSearchInput" placeholder="Search schedules (name, description)..."
             onkeyup="filterSchedTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:260px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <select id="typeFilter" onchange="filterSchedTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All Types</option>
      <option value="recurring">Recurring</option>
      <option value="onetime">One-Time</option>
    </select>

    <select id="statusFilter" onchange="filterSchedTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All Statuses</option>
      <option value="enabled">Enabled</option>
      <option value="disabled">Disabled</option>
    </select>
  </div>

  <div style="font-size:0.75rem; color:var(--text-muted);">
    Total: <strong style="color:var(--text-primary);" id="filteredCount"><?= count($schedules) ?></strong> schedules
  </div>
</div>

<!-- Schedules Table -->
<div class="card card-glass animate-in animate-in-delay-2" style="padding:0; overflow-x:auto;">
  <table class="mno-table" id="schedTable">
    <thead>
      <tr>
        <th style="width:130px;">Status</th>
        <th style="min-width:180px;">Schedule Name</th>
        <th style="width:110px;">Type</th>
        <th style="min-width:240px;">Days &amp; Active Time</th>
        <th style="min-width:190px;">Subsystem Usage</th>
        <th>Description</th>
        <th style="width:100px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($schedules)): ?>
        <tr>
          <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
            No schedules defined. Click "Create Schedule" to add one.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($schedules as $s): ?>
          <?php
            $isSys = (int) ($s['is_system'] ?? 0) === 1 || strtolower($s['name']) === 'always';
            $isOnetime = ($s['type'] ?? 'recurring') === 'onetime';
            $days = strtolower($s['days_of_week'] ?? 'all');
            $ref = $usage[$s['id']] ?? ['firewall' => 0, 'nat' => 0, 'qos' => 0, 'total' => 0];
            $isEnabled = (int) ($s['enabled'] ?? 1) === 1;
          ?>
          <tr data-name="<?= strtolower(esc($s['name'])) ?>"
              data-type="<?= $isOnetime ? 'onetime' : 'recurring' ?>"
              data-status="<?= $isEnabled ? 'enabled' : 'disabled' ?>"
              data-desc="<?= strtolower(esc($s['description'] ?? '')) ?>"
              class="<?= $isEnabled ? '' : 'is-disabled' ?>">

            <!-- Status / Toggle -->
            <td class="sched-status-cell">
              <?php if ($isSys): ?>
                <button type="button" class="sched-switch on" disabled
                        role="switch" aria-checked="true"
                        aria-label="<?= esc($s['name']) ?> is a system schedule and is always enabled"
                        title="System schedule cannot be disabled"></button>
                <span class="sched-status-label" style="color:#34d399;">ENABLED</span>
              <?php else: ?>
                <form method="POST" action="<?= base_url('firewall/schedules/' . $s['id'] . '/toggle') ?>"
                      style="display:inline;"
                      data-name="<?= esc($s['name']) ?>"
                      data-enabled="<?= $isEnabled ? '1' : '0' ?>"
                      data-fw="<?= (int) $ref['firewall'] ?>"
                      data-nat="<?= (int) $ref['nat'] ?>"
                      data-qos="<?= (int) $ref['qos'] ?>"
                      onsubmit="return confirmScheduleToggle(this);">
                  <?= csrf_field() ?>
                  <button type="submit" id="sched-toggle-<?= esc($s['id']) ?>"
                          class="sched-switch <?= $isEnabled ? 'on' : '' ?>"
                          role="switch" aria-checked="<?= $isEnabled ? 'true' : 'false' ?>"
                          aria-label="<?= $isEnabled ? 'Disable' : 'Enable' ?> schedule <?= esc($s['name']) ?>"
                          title="<?= $isEnabled ? 'Disable' : 'Enable' ?> schedule"></button>
                </form>
                <span class="sched-status-label" style="color:<?= $isEnabled ? '#34d399' : 'var(--text-muted)' ?>;">
                  <?= $isEnabled ? 'ENABLED' : 'DISABLED' ?>
                </span>
              <?php endif; ?>
            </td>

            <!-- Name -->
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:600; color:var(--text-primary); font-size:0.875rem;">
                  <?= esc($s['name']) ?>
                </span>
                <?php if ($isSys): ?>
                  <span class="badge" style="background:rgba(59,130,246,0.15); color:#93c5fd; font-size:0.65rem;">SYSTEM</span>
                <?php endif; ?>
                <?php if (!$isEnabled && $ref['total'] > 0): ?>
                  <span class="badge badge-warning" style="font-size:0.65rem;" title="Rules using this schedule are paused"><?= (int) $ref['total'] ?> PAUSED</span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Type -->
            <td>
              <?php if ($isOnetime): ?>
                <span class="badge badge-warning" style="font-size:0.7rem;">ONE-TIME</span>
              <?php else: ?>
                <span class="badge badge-info" style="font-size:0.7rem;">RECURRING</span>
              <?php endif; ?>
            </td>

            <!-- Schedule Timing / Days -->
            <td>
              <?php if ($isOnetime): ?>
                <div style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan, #06b6d4);">
                  <?= !empty($s['start_datetime']) ? esc(date('Y-m-d H:i', strtotime($s['start_datetime']))) : '—' ?>
                  &rarr;
                  <?= !empty($s['end_datetime']) ? esc(date('Y-m-d H:i', strtotime($s['end_datetime']))) : '—' ?>
                </div>
              <?php else: ?>
                <div style="margin-bottom:4px;">
                  <?php if ($days === 'all'): ?>
                    <span class="badge" style="background:rgba(255,255,255,0.08); font-size:0.7rem;">Every Day</span>
                  <?php else: ?>
                    <span class="day-badge <?= str_contains($days, 'mon') ? 'active' : '' ?>">M</span>
                    <span class="day-badge <?= str_contains($days, 'tue') ? 'active' : '' ?>">T</span>
                    <span class="day-badge <?= str_contains($days, 'wed') ? 'active' : '' ?>">W</span>
                    <span class="day-badge <?= str_contains($days, 'thu') ? 'active' : '' ?>">T</span>
                    <span class="day-badge <?= str_contains($days, 'fri') ? 'active' : '' ?>">F</span>
                    <span class="day-badge <?= str_contains($days, 'sat') ? 'active' : '' ?>">S</span>
                    <span class="day-badge <?= str_contains($days, 'sun') ? 'active' : '' ?>">S</span>
                  <?php endif; ?>
                </div>
                <div style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                  <?php
                    $start = substr($s['start_time'] ?? '00:00:00', 0, 5);
                    $end   = substr($s['end_time'] ?? '23:59:59', 0, 5);
                    if ($start === '00:00' && $end === '23:59') {
                        echo '<span style="color:var(--text-muted);">24 Hours (All Day)</span>';
                    } else {
                        echo esc($start) . ' &ndash; ' . esc($end);
                    }
                  ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- Usage References -->
            <td>
              <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <span class="badge" style="font-size:0.68rem; background:rgba(59,130,246,0.12); color:#93c5fd;" title="Referenced by Firewall Rules">
                  FW: <?= $ref['firewall'] ?>
                </span>
                <span class="badge" style="font-size:0.68rem; background:rgba(6,182,212,0.12); color:#67e8f9;" title="Referenced by Destination NAT Rules">
                  NAT: <?= $ref['nat'] ?>
                </span>
                <span class="badge" style="font-size:0.68rem; background:rgba(16,185,129,0.12); color:#6ee7b7;" title="Referenced by QoS Priority Rules">
                  QoS: <?= $ref['qos'] ?>
                </span>
                <span class="badge" style="font-size:0.68rem; background:rgba(239,68,68,0.12); color:#fca5a5;" title="Referenced by DoS Policies">
                  DoS: <?= (int) ($ref['dos'] ?? 0) ?>
                </span>
              </div>
            </td>

            <!-- Description -->
            <td style="color:var(--text-muted); font-size:0.78rem;">
              <?= esc($s['description'] ?? '—') ?>
            </td>

            <!-- Actions -->
            <td class="sched-actions-cell" style="text-align:right;">
              <div style="display:inline-flex; gap:6px;">
                <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 8px;"
                        onclick='openEditModal(<?= json_encode($s) ?>)' title="Edit Schedule">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                  </svg>
                </button>

                <?php if (!$isSys): ?>
                  <form method="POST" action="<?= base_url('firewall/schedules/' . $s['id'] . '/delete') ?>"
                        onsubmit="return confirm('Delete schedule &quot;<?= esc($s['name']) ?>&quot;? Any policies using it will revert to Always active.');" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px; color:var(--color-danger, #ef4444);" title="Delete Schedule">
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
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ============================================================
     CREATE SCHEDULE MODAL
     ============================================================ -->
<div class="mno-modal-backdrop" id="createSchedModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-primary);">
          <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
        </svg>
        <h3 style="margin:0; font-size:1.05rem; font-weight:600; color:var(--text-primary);">Create Schedule Object</h3>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeCreateModal()">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('firewall/schedules/store') ?>">
      <?= csrf_field() ?>
      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <div class="form-group">
            <label class="form-label" for="create_name">Schedule Name *</label>
            <input type="text" class="form-control" id="create_name" name="name" required placeholder="e.g. night_backup_window">
            <small class="text-muted" style="font-size:0.72rem;">Unique identifier (alphanumeric, lowercase)</small>
          </div>

          <div class="form-group">
            <label class="form-label" for="create_type">Schedule Type</label>
            <select class="form-control" id="create_type" name="type" onchange="toggleSchedType('create')">
              <option value="recurring" selected>Recurring (Weekly Schedule)</option>
              <option value="onetime">One-Time (Date Range)</option>
            </select>
          </div>
        </div>

        <!-- Recurring Fields -->
        <div id="create_recurring_section">
          <label class="form-label">Days of Week</label>
          <div style="display:flex; gap:6px; margin-bottom:8px;">
            <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="setDaysPreset('create', 'all')">Every Day</button>
            <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="setDaysPreset('create', 'work')">Mon–Fri</button>
            <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="setDaysPreset('create', 'weekend')">Sat–Sun</button>
          </div>

          <div class="days-picker" id="create_days_picker">
            <label><input type="checkbox" name="days[]" value="mon" checked> Mon</label>
            <label><input type="checkbox" name="days[]" value="tue" checked> Tue</label>
            <label><input type="checkbox" name="days[]" value="wed" checked> Wed</label>
            <label><input type="checkbox" name="days[]" value="thu" checked> Thu</label>
            <label><input type="checkbox" name="days[]" value="fri" checked> Fri</label>
            <label><input type="checkbox" name="days[]" value="sat"> Sat</label>
            <label><input type="checkbox" name="days[]" value="sun"> Sun</label>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:14px;">
            <div class="form-group">
              <label class="form-label" for="create_start_time">Start Time</label>
              <input type="time" class="form-control" id="create_start_time" name="start_time" value="08:00">
            </div>
            <div class="form-group">
              <label class="form-label" for="create_end_time">End Time</label>
              <input type="time" class="form-control" id="create_end_time" name="end_time" value="18:00">
            </div>
          </div>
        </div>

        <!-- One-Time Fields -->
        <div id="create_onetime_section" style="display:none;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group">
              <label class="form-label" for="create_start_datetime">Start Date &amp; Time</label>
              <input type="datetime-local" class="form-control" id="create_start_datetime" name="start_datetime">
            </div>
            <div class="form-group">
              <label class="form-label" for="create_end_datetime">End Date &amp; Time</label>
              <input type="datetime-local" class="form-control" id="create_end_datetime" name="end_datetime">
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label" for="create_description">Description / Notes</label>
          <input type="text" class="form-control" id="create_description" name="description" placeholder="Optional explanation of this schedule">
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCreateModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     EDIT SCHEDULE MODAL
     ============================================================ -->
<div class="mno-modal-backdrop" id="editSchedModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-primary);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
        <h3 style="margin:0; font-size:1.05rem; font-weight:600; color:var(--text-primary);">Edit Schedule: <span id="edit_modal_title" style="color:var(--color-cyan, #06b6d4);"></span></h3>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeEditModal()">&times;</button>
    </div>

    <form method="POST" action="" id="editSchedForm">
      <?= csrf_field() ?>
      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <div class="form-group">
            <label class="form-label" for="edit_name">Schedule Name *</label>
            <input type="text" class="form-control" id="edit_name" name="name" required>
            <small id="edit_sys_hint" class="text-muted" style="display:none; font-size:0.72rem; color:var(--color-cyan) !important;">System protected schedule</small>
          </div>

          <div class="form-group">
            <label class="form-label" for="edit_type">Schedule Type</label>
            <select class="form-control" id="edit_type" name="type" onchange="toggleSchedType('edit')">
              <option value="recurring">Recurring (Weekly Schedule)</option>
              <option value="onetime">One-Time (Date Range)</option>
            </select>
          </div>
        </div>

        <!-- Recurring Fields -->
        <div id="edit_recurring_section">
          <label class="form-label">Days of Week</label>
          <div style="display:flex; gap:6px; margin-bottom:8px;">
            <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="setDaysPreset('edit', 'all')">Every Day</button>
            <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="setDaysPreset('edit', 'work')">Mon–Fri</button>
            <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="setDaysPreset('edit', 'weekend')">Sat–Sun</button>
          </div>

          <div class="days-picker" id="edit_days_picker">
            <label><input type="checkbox" name="days[]" value="mon"> Mon</label>
            <label><input type="checkbox" name="days[]" value="tue"> Tue</label>
            <label><input type="checkbox" name="days[]" value="wed"> Wed</label>
            <label><input type="checkbox" name="days[]" value="thu"> Thu</label>
            <label><input type="checkbox" name="days[]" value="fri"> Fri</label>
            <label><input type="checkbox" name="days[]" value="sat"> Sat</label>
            <label><input type="checkbox" name="days[]" value="sun"> Sun</label>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:14px;">
            <div class="form-group">
              <label class="form-label" for="edit_start_time">Start Time</label>
              <input type="time" class="form-control" id="edit_start_time" name="start_time">
            </div>
            <div class="form-group">
              <label class="form-label" for="edit_end_time">End Time</label>
              <input type="time" class="form-control" id="edit_end_time" name="end_time">
            </div>
          </div>
        </div>

        <!-- One-Time Fields -->
        <div id="edit_onetime_section" style="display:none;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group">
              <label class="form-label" for="edit_start_datetime">Start Date &amp; Time</label>
              <input type="datetime-local" class="form-control" id="edit_start_datetime" name="start_datetime">
            </div>
            <div class="form-group">
              <label class="form-label" for="edit_end_datetime">End Date &amp; Time</label>
              <input type="datetime-local" class="form-control" id="edit_end_datetime" name="end_datetime">
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label" for="edit_description">Description / Notes</label>
          <input type="text" class="form-control" id="edit_description" name="description">
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
function confirmScheduleToggle(form) {
  const name    = form.dataset.name;
  const enabled = form.dataset.enabled === '1';
  const fw  = parseInt(form.dataset.fw, 10)  || 0;
  const nat = parseInt(form.dataset.nat, 10) || 0;
  const qos = parseInt(form.dataset.qos, 10) || 0;
  const total = fw + nat + qos;

  // Enabling never needs a warning; disabling an unused schedule is harmless.
  if (!enabled || total === 0) return true;

  const lines = [];
  if (fw)  lines.push('  \u2022 ' + fw  + ' firewall polic' + (fw === 1 ? 'y' : 'ies'));
  if (nat) lines.push('  \u2022 ' + nat + ' destination NAT rule' + (nat === 1 ? '' : 's'));
  if (qos) lines.push('  \u2022 ' + qos + ' QoS priority rule' + (qos === 1 ? '' : 's'));

  return confirm(
    'Disable schedule "' + name + '"?\n\n' +
    'The following rules will be PAUSED (they stop matching completely) until you enable it again:\n' +
    lines.join('\n') + '\n\n' +
    'Paused block rules stop blocking and paused allow rules stop allowing. ' +
    'Apply the firewall configuration afterwards for the change to take effect.'
  );
}
function toggleSchedType(prefix) {
  const type = document.getElementById(prefix + '_type').value;
  document.getElementById(prefix + '_recurring_section').style.display = (type === 'recurring') ? 'block' : 'none';
  document.getElementById(prefix + '_onetime_section').style.display   = (type === 'onetime') ? 'block' : 'none';
}

function setDaysPreset(prefix, preset) {
  const container = document.getElementById(prefix + '_days_picker');
  const checkboxes = container.querySelectorAll('input[type="checkbox"]');

  checkboxes.forEach(cb => {
    if (preset === 'all') {
      cb.checked = true;
    } else if (preset === 'work') {
      cb.checked = ['mon', 'tue', 'wed', 'thu', 'fri'].includes(cb.value);
    } else if (preset === 'weekend') {
      cb.checked = ['sat', 'sun'].includes(cb.value);
    }
  });
}

function openCreateModal() {
  document.getElementById('createSchedModal').classList.add('active');
  toggleSchedType('create');
}

function closeCreateModal() {
  document.getElementById('createSchedModal').classList.remove('active');
}

function openEditModal(schedule) {
  const form = document.getElementById('editSchedForm');
  form.action = '<?= base_url('firewall/schedules') ?>/' + schedule.id + '/update';

  document.getElementById('edit_modal_title').textContent = schedule.name;
  document.getElementById('edit_name').value = schedule.name;
  document.getElementById('edit_type').value = schedule.type || 'recurring';
  document.getElementById('edit_description').value = schedule.description || '';

  const isSys = parseInt(schedule.is_system, 10) === 1 || schedule.name.toLowerCase() === 'always';
  const nameInput = document.getElementById('edit_name');
  const sysHint   = document.getElementById('edit_sys_hint');
  if (isSys) {
    nameInput.readOnly = true;
    sysHint.style.display = 'block';
  } else {
    nameInput.readOnly = false;
    sysHint.style.display = 'none';
  }

  // Days
  const days = (schedule.days_of_week || 'all').toLowerCase();
  const checkboxes = document.getElementById('edit_days_picker').querySelectorAll('input[type="checkbox"]');
  checkboxes.forEach(cb => {
    cb.checked = (days === 'all' || days.includes(cb.value));
  });

  document.getElementById('edit_start_time').value = (schedule.start_time || '00:00:00').substring(0, 5);
  document.getElementById('edit_end_time').value   = (schedule.end_time || '23:59:59').substring(0, 5);

  if (schedule.start_datetime) {
    document.getElementById('edit_start_datetime').value = schedule.start_datetime.substring(0, 16);
  }
  if (schedule.end_datetime) {
    document.getElementById('edit_end_datetime').value = schedule.end_datetime.substring(0, 16);
  }

  toggleSchedType('edit');
  document.getElementById('editSchedModal').classList.add('active');
}

function closeEditModal() {
  document.getElementById('editSchedModal').classList.remove('active');
}

// Backdrop and ESC key close
document.querySelectorAll('.mno-modal-backdrop').forEach(modal => {
  modal.addEventListener('click', (e) => {
    if (e.target === modal) modal.classList.remove('active');
  });
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeCreateModal();
    closeEditModal();
  }
});

function filterSchedTable() {
  const query = (document.getElementById('schedSearchInput').value || '').toLowerCase().trim();
  const typeFilter = (document.getElementById('typeFilter').value || 'all').toLowerCase();
  const statusFilter = (document.getElementById('statusFilter').value || 'all').toLowerCase();

  const rows = document.querySelectorAll('#schedTable tbody tr');
  let visibleCount = 0;

  rows.forEach(row => {
    if (!row.dataset.name) return;

    const name = row.dataset.name || '';
    const type = row.dataset.type || '';
    const desc = row.dataset.desc || '';
    const status = row.dataset.status || 'enabled';

    const matchesQuery  = query === '' || name.includes(query) || desc.includes(query);
    const matchesType   = typeFilter === 'all' || type === typeFilter;
    const matchesStatus = statusFilter === 'all' || status === statusFilter;

    if (matchesQuery && matchesType && matchesStatus) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  const countElem = document.getElementById('filteredCount');
  if (countElem) countElem.textContent = visibleCount;
}
</script>

<?= $this->endSection() ?>
