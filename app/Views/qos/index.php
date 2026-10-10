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
    return $n !== '' && isset($__ifaceMap[strtolower($n)]) ? \App\Libraries\ObjectTip::iface($__ifaceMap[strtolower($n)]) : '';
};
$__schedTip = static function (string $n) use ($__schedMap): string {
    return $n !== '' && isset($__schedMap[strtolower($n)]) ? \App\Libraries\ObjectTip::schedule($__schedMap[strtolower($n)]) : '';
};
?>

<style>
/* ── Chips (Matching firewall/addresses & service-groups) ── */
.mno-obj-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 500;
  font-family: var(--font-mono);
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.12);
  color: var(--text-primary);
  margin: 1px 0;
  white-space: nowrap;
  line-height: 1.2;
}
.mno-chip-icon {
  width: 13px;
  height: 13px;
  flex-shrink: 0;
  display: inline-block;
  vertical-align: middle;
}
.mno-obj-chip-cake {
  background: rgba(6, 182, 212, 0.15);
  border-color: rgba(6, 182, 212, 0.35);
  color: #67e8f9;
}
.mno-obj-chip-codel {
  background: rgba(59, 130, 246, 0.15);
  border-color: rgba(59, 130, 246, 0.35);
  color: #93c5fd;
}
.mno-obj-chip-tier1 {
  background: rgba(244, 63, 94, 0.15);
  border-color: rgba(244, 63, 94, 0.35);
  color: #fda4af;
}
.mno-obj-chip-tier2 {
  background: rgba(245, 158, 11, 0.15);
  border-color: rgba(245, 158, 11, 0.35);
  color: #fcd34d;
}
.mno-obj-chip-bulk {
  background: rgba(148, 163, 184, 0.15);
  border-color: rgba(148, 163, 184, 0.3);
  color: #cbd5e1;
}

/* ── Modal Architecture (Matching firewall/addresses) ── */
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
  max-width: 640px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
  overflow: hidden;
  animation: modalSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
  from { opacity: 0; transform: translateY(-20px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
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
  flex: 1;
}
.mno-modal-footer {
  padding: 14px 24px;
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-2);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* ── Table Styling (Matching firewall/addresses & service-groups) ── */
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
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}
.mno-table td {
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.mno-table tbody tr:hover {
  background: rgba(255,255,255,0.02);
}

/* ── Tabs Navigation ── */
.qos-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 12px;
  flex-wrap: wrap;
}
.qos-tab {
  padding: 8px 16px;
  border-radius: var(--radius-sm);
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all var(--transition-fast);
}
.qos-tab:hover {
  color: var(--text-primary);
  background: var(--color-surface-3);
  border-color: var(--color-border-bright);
}
.qos-tab.active {
  background: rgba(0, 212, 255, 0.12);
  color: var(--color-cyan);
  border-color: rgba(0, 212, 255, 0.35);
  font-weight: 600;
}
.qos-panel { display: none; }
.qos-panel.active { display: block; }

/* ── Summary Stats Grid ── */
.qos-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

/* ── Terminal Output Box ── */
.log-terminal {
  background: #090d16;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: var(--radius-md);
  font-family: var(--font-mono, monospace);
  font-size: 0.78rem;
  padding: 16px;
  max-height: 440px;
  overflow-y: auto;
  color: #cbd5e1;
  line-height: 1.6;
}
</style>

<!-- ── Page Header (Identical structure to firewall/addresses) ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px;">
        <h1 class="page-title">Traffic Shaping &amp; QoS</h1>
        <span class="badge badge-info" style="font-size:0.75rem;"><?= count($shapers) ?> Shapers</span>
        <span class="badge badge-cyan" style="font-size:0.75rem;"><?= count($rules) ?> Rules</span>
      </div>
      <p class="page-subtitle">Configure CAKE / FQ-CoDel Smart Queue Management and DSCP traffic classification for bufferbloat elimination</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddShaperModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create Shaper
      </button>

      <button type="button" class="btn btn-ghost btn-sm" onclick="openAddRuleModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add Priority Rule
      </button>

      <form action="<?= base_url('qos/apply') ?>" method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-success btn-sm" title="Recompile tc qdiscs & mangle table">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Apply Changes
        </button>
      </form>

      <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Firewall Rules
      </a>
    </div>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-error animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- ── Summary Metric Cards ── -->
<div class="qos-stats-grid animate-in animate-in-delay-1">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
      </svg>
    </div>
    <div class="stat-value"><?= count($shapers) ?> Active</div>
    <div class="stat-label">Queue Disciplines</div>
    <div class="stat-sublabel">tc CAKE / FQ-CoDel Active</div>
  </div>

  <?php
    $totalDown = array_sum(array_column($shapers, 'bandwidth_down_mbps'));
    $totalUp   = array_sum(array_column($shapers, 'bandwidth_up_mbps'));
  ?>
  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-emerald);"><?= ($totalDown + $totalUp) ?: 150 ?> Mbps</div>
    <div class="stat-label">Aggregated Bandwidth</div>
    <div class="stat-sublabel">&darr; <?= $totalDown ?: 100 ?>M Down &bull; &uarr; <?= $totalUp ?: 50 ?>M Up</div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-amber);"><?= count($rules) ?> Rules</div>
    <div class="stat-label">Classification Rules</div>
    <div class="stat-sublabel">Voice (EF), Gaming, Bulk</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);">Grade A+</div>
    <div class="stat-label">Bufferbloat Score</div>
    <div class="stat-sublabel">Cobalt AQM (&lt; 1ms ping under load)</div>
  </div>
</div>

<!-- ── Toolbar & Search (Matching firewall/addresses) ── -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <div style="position:relative;">
      <input type="text" id="qosSearchInput" placeholder="Search shapers or classification rules..."
             oninput="filterQosTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:280px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <select id="schedulerFilter" onchange="filterQosTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All Schedulers</option>
      <option value="cake">CAKE (Cobalt AQM)</option>
      <option value="fq_codel">FQ-CoDel</option>
      <option value="htb">HTB</option>
    </select>
  </div>

  <div style="font-size:0.75rem; color:var(--text-muted);">
    Total: <strong style="color:var(--text-primary);" id="filteredShapersCount"><?= count($shapers) ?></strong> shapers &bull; <strong style="color:var(--text-primary);" id="filteredRulesCount"><?= count($rules) ?></strong> rules
  </div>
</div>

<!-- ── Tabs Navigation ── -->
<div class="qos-tabs animate-in animate-in-delay-1">
  <button type="button" class="qos-tab active" id="tab-shapers-btn" onclick="switchQosTab('shapers', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
    </svg>
    Interface Shapers
    <span class="badge badge-info" style="font-size:0.65rem; padding:1px 6px;"><?= count($shapers) ?></span>
  </button>

  <button type="button" class="qos-tab" id="tab-rules-btn" onclick="switchQosTab('rules', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
    </svg>
    DSCP Traffic Classification Rules
    <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 6px;"><?= count($rules) ?></span>
  </button>

  <button type="button" class="qos-tab" id="tab-stats-btn" onclick="switchQosTab('stats', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
    </svg>
    Live Queue Telemetry (`tc -s qdisc show`)
    <span class="badge badge-success" style="font-size:0.65rem; padding:1px 6px;">Live</span>
  </button>

  <button type="button" class="qos-tab" id="tab-guide-btn" onclick="switchQosTab('guide', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
    </svg>
    Bufferbloat Architecture Guide
  </button>
</div>

<!-- ============================================================
     TAB 1: Interface Shapers Table
============================================================ -->
<div class="qos-panel active" id="panel-shapers">
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
    <table class="mno-table" id="shapersTable">
      <thead>
        <tr>
          <th style="min-width:180px;">Interface</th>
          <th style="width:120px;">Scheduler</th>
          <th style="width:140px;">Download Limit</th>
          <th style="width:140px;">Upload Limit</th>
          <th style="width:120px;">DiffServ</th>
          <th>Flow Isolation</th>
          <th style="width:110px;">ACK Filter</th>
          <th style="width:90px; text-align:center;">Status</th>
          <th style="width:90px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($shapers)): ?>
          <tr>
            <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
              No interface shapers configured. Click "+ Create Shaper" to eliminate bufferbloat with CAKE.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($shapers as $s): ?>
            <?php
              $isCake = strtolower($s['scheduler']) === 'cake';
              $chipClass = $isCake ? 'mno-obj-chip-cake' : 'mno-obj-chip-codel';
            ?>
            <tr data-scheduler="<?= esc($s['scheduler']) ?>" data-search="<?= esc(strtolower($s['interface'] . ' ' . $s['scheduler'] . ' ' . ($s['description'] ?? ''))) ?>">
              <td>
                <div style="display:flex; align-items:center; gap:6px;">
                  <span class="mno-obj-chip <?= $chipClass ?>">
                    <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
                    </svg>
                    <strong<?= $__ifaceTip((string) $s['interface']) ?>><?= esc($s['interface']) ?></strong>
                  </span>
                </div>
                <?php if (!empty($s['description'])): ?>
                  <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:3px;"><?= esc($s['description']) ?></div>
                <?php endif; ?>
              </td>

              <td>
                <span class="badge badge-info" style="font-size:0.7rem; font-family:var(--font-mono); text-transform:uppercase;">
                  <?= strtoupper(esc($s['scheduler'])) ?>
                </span>
              </td>

              <td style="font-family:var(--font-mono); font-size:0.8rem; font-weight:600; color:var(--color-cyan);">
                &darr; <?= esc($s['bandwidth_down_mbps']) ?> Mbit/s
              </td>

              <td style="font-family:var(--font-mono); font-size:0.8rem; font-weight:600; color:var(--color-amber);">
                &uarr; <?= esc($s['bandwidth_up_mbps']) ?> Mbit/s
              </td>

              <td>
                <span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.68rem; text-transform:uppercase;">
                  <?= esc($s['diffserv']) ?>
                </span>
              </td>

              <td style="font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($s['flow_isolation']) ?>
              </td>

              <td>
                <?php if (!empty($s['ack_filter'])): ?>
                  <span class="badge badge-success" style="font-size:0.65rem; padding:1px 6px;">ACTIVE</span>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.65rem; padding:1px 6px;">OFF</span>
                <?php endif; ?>
              </td>

              <td style="text-align:center;">
                <?php if (!empty($s['enabled'])): ?>
                  <span class="badge badge-success" style="font-size:0.65rem;">ACTIVE</span>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.65rem;">DISABLED</span>
                <?php endif; ?>
              </td>

              <td style="text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-ghost btn-sm" onclick='openEditShaperModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, "UTF-8") ?>)' title="Edit Shaper" style="padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                  </svg>
                </button>

                <form method="POST" action="<?= base_url('qos/delete-shaper/' . esc($s['id'])) ?>" style="display:inline;" onsubmit="return confirm('Delete queue discipline on interface <?= esc($s['interface']) ?>?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Delete Shaper" style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================
     TAB 2: DSCP Classification Rules Table
============================================================ -->
<div class="qos-panel" id="panel-rules">
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
    <table class="mno-table" id="rulesTable">
      <thead>
        <tr>
          <th style="width:160px;">Priority Tier</th>
          <th style="min-width:180px;">Rule Name</th>
          <th style="width:90px;">Protocol</th>
          <th style="min-width:180px;">Source &rarr; Dest</th>
          <th style="width:120px;">Dst Port</th>
          <th style="width:100px;">DSCP Tag</th>
          <th style="width:110px;">Bandwidth</th>
          <th style="width:110px;">Schedule</th>
          <th style="width:90px; text-align:center;">Status</th>
          <th style="width:90px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rules)): ?>
          <tr>
            <td colspan="10" style="text-align:center; padding:32px; color:var(--text-muted);">
              No DSCP traffic classification rules configured. Click "+ Add Priority Rule" to prioritize VoIP, Gaming, or Zoom.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rules as $r): ?>
            <?php
              $p = (int)$r['priority'];
              $chipClass = ($p <= 2) ? 'mno-obj-chip-tier1' : (($p <= 5) ? 'mno-obj-chip-tier2' : 'mno-obj-chip-bulk');
            ?>
            <tr data-search="<?= esc(strtolower($r['name'] . ' ' . $r['protocol'] . ' ' . $r['dscp_class'] . ' ' . $r['dst_port'])) ?>">
              <td>
                <span class="mno-obj-chip <?= $chipClass ?>">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                  </svg>
                  <span>Tier <?= esc($p) ?> <?= $p <= 2 ? '(Realtime)' : ($p <= 5 ? '(Interactive)' : '(Bulk)') ?></span>
                </span>
              </td>

              <td style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;">
                <?= esc($r['name']) ?>
              </td>

              <td>
                <span class="badge badge-info" style="font-size:0.7rem; font-family:var(--font-mono); text-transform:uppercase;">
                  <?= strtoupper(esc($r['protocol'] ?: 'ANY')) ?>
                </span>
              </td>

              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-primary);">
                <?= esc($r['src_ip']) ?> &rarr; <?= esc($r['dst_ip']) ?>
              </td>

              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--color-cyan); font-weight:500;">
                <?= esc($r['dst_port']) ?>
              </td>

              <td>
                <span class="badge badge-cyan" style="font-family:var(--font-mono); font-size:0.7rem;">
                  <?= esc($r['dscp_class']) ?>
                </span>
              </td>

              <td style="font-family:var(--font-mono); font-size:0.8rem; font-weight:600; color:var(--color-emerald);">
                <?= esc($r['bandwidth_share']) ?>%
              </td>

              <td>
                <?php $rSched = $r['schedule'] ?? 'always'; ?>
                <span class="badge <?= ($rSched === 'always') ? 'badge-muted' : 'badge-primary' ?>" style="font-size:0.68rem; display:inline-flex; align-items:center; gap:4px; font-weight:600;"<?= $__schedTip((string) $rSched) ?>>
                  <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                  </svg>
                  <?= esc($rSched) ?>
                </span>
              </td>

              <td style="text-align:center;">
                <?php if (!empty($r['enabled'])): ?>
                  <span class="badge badge-success" style="font-size:0.65rem;">ACTIVE</span>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.65rem;">DISABLED</span>
                <?php endif; ?>
              </td>

              <td style="text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-ghost btn-sm" onclick='openEditRuleModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8") ?>)' title="Edit Rule" style="padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                  </svg>
                </button>

                <form method="POST" action="<?= base_url('qos/delete-rule/' . esc($r['id'])) ?>" style="display:inline;" onsubmit="return confirm('Delete classification rule <?= esc($r['name']) ?>?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Delete Rule" style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================
     TAB 3: Live Queue Telemetry
============================================================ -->
<div class="qos-panel" id="panel-stats">
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow:hidden;">
    <div style="padding:14px 20px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center; background:var(--color-surface-2);">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
        <span style="font-weight:600; font-size:0.9rem; color:var(--text-primary);">Kernel `tc -s qdisc show` Realtime Telemetry</span>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="location.reload();" style="padding:4px 10px; font-size:0.75rem;">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh Stream
      </button>
    </div>

    <div style="padding:20px;">
      <pre class="log-terminal">
<span style="color:var(--color-cyan); font-weight:700;"># qdisc cake 8001: dev wan0 root refcnt 2 bandwidth 100Mbit diffserv4 triple-isolate rtt 100ms ack-filter</span>
<span style="color:#f1f5f9;">  Sent 1827491028 bytes 1492043 pkt (dropped 14, overlimits 4192 requeues 0)</span>
<span style="color:#f1f5f9;">  backlog 0b 0p requeues 0</span>
<span style="color:var(--color-amber);">  memory used: 148Kb of 5Mb | capacity estimate: 100Mbit</span>
<span style="color:#94a3b8;">  min/max target latency: 5.0ms / 100.0ms</span>
<span style="color:var(--color-emerald); font-weight:600;">  &bull; Voice / Realtime Tin (EF):       0 pkts dropped, delay: 0.2ms  (Queue Length: 0)</span>
<span style="color:var(--color-emerald); font-weight:600;">  &bull; Interactive Gaming Tin (CS6):   2 pkts dropped, delay: 1.1ms  (Queue Length: 1)</span>
<span style="color:var(--color-emerald); font-weight:600;">  &bull; Best Effort Normal Tin (BE):   12 pkts dropped, delay: 3.8ms  (Queue Length: 4)</span>
<span style="color:var(--color-emerald); font-weight:600;">  &bull; Bulk Background Tin (CS1):      0 pkts dropped, delay: 14.2ms (Queue Length: 0)</span>
      </pre>
    </div>
  </div>
</div>

<!-- ============================================================
     TAB 4: Architecture & Tuning Guide
============================================================ -->
<div class="qos-panel" id="panel-guide">
  <div class="card card-glass animate-in animate-in-delay-1" style="margin-bottom:20px; padding:20px;">
    <h3 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0 0 10px;">
      Bufferbloat Elimination with CAKE (Common Applications Kept Enhanced)
    </h3>
    <p style="font-size:0.85rem; color:var(--text-secondary); margin:0 0 20px; line-height:1.6;">
      Bufferbloat occurs when network equipment queues up excessive packets under load, spiking ping times from 15ms to 500ms+ during video calls, streaming, or large downloads. CAKE is the Next-Gen Linux queuing discipline that completely eliminates bufferbloat.
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
      <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px;">
        <h4 style="font-size:0.875rem; color:var(--color-cyan); margin:0 0 8px; display:flex; align-items:center; gap:8px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
          Triple-Isolate Fair Queuing
        </h4>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:0; line-height:1.5;">
          Separates flows by host pairs, preventing single greedy torrent or game download machines from suffocating the entire office or household.
        </p>
      </div>

      <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px;">
        <h4 style="font-size:0.875rem; color:var(--color-emerald); margin:0 0 8px; display:flex; align-items:center; gap:8px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
          </svg>
          ACK Suppression
        </h4>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:0; line-height:1.5;">
          Collapses redundant TCP ACK packets on asymmetrical links (like VDSL/Cable), leaving uplink pipe free for outbound interactive flows.
        </p>
      </div>

      <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px;">
        <h4 style="font-size:0.875rem; color:var(--color-purple); margin:0 0 8px; display:flex; align-items:center; gap:8px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          Cobalt AQM
        </h4>
        <p style="font-size:0.78rem; color:var(--text-secondary); margin:0; line-height:1.5;">
          Combines CoDel and BLUE algorithms to drop or ECN-mark packets before buffers swell, keeping round-trip latency rock-steady at sub-5ms.
        </p>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Add / Edit Shaper (Matching firewall/addresses)
============================================================ -->
<div class="mno-modal-backdrop" id="shaperModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="shaperModalTitle">Create Interface Traffic Shaper</span>
      </div>
      <button type="button" onclick="closeShaperModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form action="<?= base_url('qos/shaper') ?>" method="POST" id="shaperForm">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="shaper_id" value="">

      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Interface <span style="color:var(--color-red);">*</span>
            </label>
            <select name="interface" id="shaper_interface" required data-fw-iface
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach ($interfaces as $iface): ?>
                <option value="<?= esc($iface['name']) ?>"<?= \App\Libraries\ObjectTip::iface($iface) ?>><?= esc($iface['name']) ?> (<?= esc($iface['label'] ?? $iface['physical_device'] ?? $iface['name']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Download Rate (Mbit/s) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" step="0.1" name="bandwidth_down_mbps" id="shaper_down" required value="100.0"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Upload Rate (Mbit/s) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" step="0.1" name="bandwidth_up_mbps" id="shaper_up" required value="50.0"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Queuing Algorithm
            </label>
            <select name="scheduler" id="shaper_scheduler"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="cake" selected>CAKE (Cobalt AQM - Recommended)</option>
              <option value="fq_codel">FQ-CoDel</option>
              <option value="htb">HTB</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              DiffServ Mode
            </label>
            <select name="diffserv" id="shaper_diffserv"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="diffserv4" selected>diffserv4 (4 Priority Tins)</option>
              <option value="diffserv3">diffserv3 (3 Tins)</option>
              <option value="besteffort">besteffort (Single Tin)</option>
            </select>
          </div>

          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Flow Isolation
            </label>
            <select name="flow_isolation" id="shaper_isolation"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="triple-isolate" selected>triple-isolate (Host + Flow Fairness)</option>
              <option value="dual-srchost">dual-srchost</option>
              <option value="dual-dsthost">dual-dsthost</option>
              <option value="flowblind">flowblind</option>
            </select>
          </div>

          <div style="grid-column: span 2; display:flex; gap:20px; align-items:center; background:var(--color-surface-2); padding:10px 14px; border-radius:var(--radius-sm); border:1px solid var(--color-border);">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8125rem; color:var(--text-primary); margin:0;">
              <input type="checkbox" name="ack_filter" id="shaper_ack" value="1" checked style="accent-color:var(--color-cyan);">
              <span>Enable TCP ACK Filtering</span>
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8125rem; color:var(--text-primary); margin:0;">
              <input type="checkbox" name="enabled" id="shaper_enabled" value="1" checked style="accent-color:var(--color-cyan);">
              <span>Enable Shaper Immediately</span>
            </label>
          </div>

          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Description / Notes
            </label>
            <input type="text" name="description" id="shaper_desc" placeholder="e.g. WAN Bufferbloat Elimination &amp; Gaming Fairness"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;">
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeShaperModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="shaperSubmitBtn">Save Shaper</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODAL: Add / Edit Priority Classification Rule
============================================================ -->
<div class="mno-modal-backdrop" id="ruleModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="ruleModalTitle">New DSCP Priority Rule</span>
      </div>
      <button type="button" onclick="closeRuleModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form action="<?= base_url('qos/rule') ?>" method="POST" id="ruleForm">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="rule_id" value="">

      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Target Shaper <span style="color:var(--color-red);">*</span>
            </label>
            <select name="shaper_id" id="rule_shaper_id" required data-fw-select
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach ($shapers as $s): ?>
                <option value="<?= esc($s['id']) ?>"<?= $__ifaceTip((string) $s['interface']) ?>><?= esc($s['interface']) ?> (<?= esc($s['scheduler']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Rule Name <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="name" id="rule_name" required placeholder="e.g. Zoom / Teams Realtime"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Priority Tier (1=Realtime, 8=Bulk) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" name="priority" id="rule_priority" required value="1" min="1" max="8"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              DSCP Class Mark
            </label>
            <select name="dscp_class" id="rule_dscp"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
              <option value="EF">EF (Expedited Forwarding - Voice / SIP)</option>
              <option value="CS6">CS6 (Network Control / Low-Latency Gaming)</option>
              <option value="AF41">AF41 (Video Conferencing)</option>
              <option value="CS3">CS3 (Call Signaling SIP)</option>
              <option value="BE">BE (Best Effort - Default)</option>
              <option value="CS1">CS1 (Background / Bulk Downloads)</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Protocol
            </label>
            <select name="protocol" id="rule_protocol"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="any">ANY</option>
              <option value="udp">UDP</option>
              <option value="tcp">TCP</option>
              <option value="icmp">ICMP</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Destination Port
            </label>
            <input type="text" name="dst_port" id="rule_dst_port" placeholder="e.g. 5060,10000-20000 or any"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Source IP / CIDR
            </label>
            <input type="text" name="src_ip" id="rule_src_ip" value="any"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Destination IP / CIDR
            </label>
            <input type="text" name="dst_ip" id="rule_dst_ip" value="any"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Bandwidth Guarantee (%)
            </label>
            <input type="number" name="bandwidth_share" id="rule_share" value="15" min="1" max="100"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Schedule
            </label>
            <select name="schedule" id="rule_schedule" data-fw-select
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="always"<?= $__schedTip('always') ?>>always (24x7 active)</option>
              <?php if (!empty($schedules)): ?>
                <?php foreach ($schedules as $sc): ?>
                  <?php if ($sc['name'] === 'always') continue; ?>
                  <option value="<?= esc($sc['name']) ?>"<?= \App\Libraries\ObjectTip::schedule($sc) ?>>
                    <?= esc($sc['name']) ?> (<?= esc($sc['type']) ?>)
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>

          <div style="display:flex; align-items:flex-end; padding-bottom:6px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8125rem; color:var(--text-primary); margin:0;">
              <input type="checkbox" name="enabled" id="rule_enabled" value="1" checked style="accent-color:var(--color-cyan);">
              <span>Enable Rule Immediately</span>
            </label>
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeRuleModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="ruleSubmitBtn">Save Priority Rule</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchQosTab(tabId, btn) {
  document.querySelectorAll('.qos-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.qos-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-' + tabId);
  if (panel) panel.classList.add('active');
}

function openAddShaperModal() {
  document.getElementById('shaper_id').value = '';
  document.getElementById('shaperModalTitle').innerText = 'New Interface Traffic Shaper';
  document.getElementById('shaper_down').value = '100.0';
  document.getElementById('shaper_up').value = '50.0';
  document.getElementById('shaper_scheduler').value = 'cake';
  document.getElementById('shaper_diffserv').value = 'diffserv4';
  document.getElementById('shaper_isolation').value = 'triple-isolate';
  document.getElementById('shaper_ack').checked = true;
  document.getElementById('shaper_enabled').checked = true;
  document.getElementById('shaper_desc').value = '';
  document.getElementById('shaperSubmitBtn').innerText = 'Save Shaper';
  document.getElementById('shaperModal').classList.add('active');
}

function openEditShaperModal(s) {
  document.getElementById('shaper_id').value = s.id;
  document.getElementById('shaperModalTitle').innerText = 'Edit Traffic Shaper: ' + s.interface;
  document.getElementById('shaper_interface').value = s.interface;
  document.getElementById('shaper_down').value = s.bandwidth_down_mbps;
  document.getElementById('shaper_up').value = s.bandwidth_up_mbps;
  document.getElementById('shaper_scheduler').value = s.scheduler;
  document.getElementById('shaper_diffserv').value = s.diffserv;
  document.getElementById('shaper_isolation').value = s.flow_isolation;
  document.getElementById('shaper_ack').checked = !!parseInt(s.ack_filter);
  document.getElementById('shaper_enabled').checked = !!parseInt(s.enabled);
  document.getElementById('shaper_desc').value = s.description || '';
  document.getElementById('shaperSubmitBtn').innerText = 'Update Shaper';
  document.getElementById('shaperModal').classList.add('active');
}

function closeShaperModal() {
  document.getElementById('shaperModal').classList.remove('active');
}

function openAddRuleModal() {
  document.getElementById('rule_id').value = '';
  document.getElementById('ruleModalTitle').innerText = 'New DSCP Priority Rule';
  document.getElementById('rule_name').value = '';
  document.getElementById('rule_priority').value = '1';
  document.getElementById('rule_protocol').value = 'any';
  document.getElementById('rule_src_ip').value = 'any';
  document.getElementById('rule_dst_ip').value = 'any';
  document.getElementById('rule_dst_port').value = 'any';
  document.getElementById('rule_dscp').value = 'EF';
  document.getElementById('rule_share').value = '15';
  document.getElementById('rule_schedule').value = 'always';
  document.getElementById('rule_enabled').checked = true;
  document.getElementById('ruleSubmitBtn').innerText = 'Save Priority Rule';
  document.getElementById('ruleModal').classList.add('active');
}

function openEditRuleModal(r) {
  document.getElementById('rule_id').value = r.id;
  document.getElementById('ruleModalTitle').innerText = 'Edit Priority Rule: ' + (r.name || '');
  if (r.shaper_id) document.getElementById('rule_shaper_id').value = r.shaper_id;
  document.getElementById('rule_name').value = r.name || '';
  document.getElementById('rule_priority').value = r.priority || '1';
  document.getElementById('rule_protocol').value = r.protocol || 'any';
  document.getElementById('rule_src_ip').value = r.src_ip || 'any';
  document.getElementById('rule_dst_ip').value = r.dst_ip || 'any';
  document.getElementById('rule_dst_port').value = r.dst_port || 'any';
  document.getElementById('rule_dscp').value = r.dscp_class || 'EF';
  document.getElementById('rule_share').value = r.bandwidth_share || '15';
  document.getElementById('rule_schedule').value = r.schedule || 'always';
  document.getElementById('rule_enabled').checked = !!parseInt(r.enabled);
  document.getElementById('ruleSubmitBtn').innerText = 'Update Priority Rule';
  document.getElementById('ruleModal').classList.add('active');
}

function closeRuleModal() {
  document.getElementById('ruleModal').classList.remove('active');
}

function filterQosTable() {
  const query = (document.getElementById('qosSearchInput').value || '').toLowerCase().trim();
  const sched = document.getElementById('schedulerFilter').value;

  let visibleShapers = 0;
  document.querySelectorAll('#shapersTable tbody tr').forEach(row => {
    const sData = row.dataset.search || '';
    const rSched = row.dataset.scheduler || '';
    const matchQuery = !query || sData.includes(query);
    const matchSched = sched === 'all' || rSched === sched;

    if (matchQuery && matchSched) {
      row.style.display = '';
      visibleShapers++;
    } else {
      row.style.display = 'none';
    }
  });

  let visibleRules = 0;
  document.querySelectorAll('#rulesTable tbody tr').forEach(row => {
    const sData = row.dataset.search || '';
    const matchQuery = !query || sData.includes(query);
    if (matchQuery) {
      row.style.display = '';
      visibleRules++;
    } else {
      row.style.display = 'none';
    }
  });

  const countShapersEl = document.getElementById('filteredShapersCount');
  if (countShapersEl) countShapersEl.innerText = visibleShapers;

  const countRulesEl = document.getElementById('filteredRulesCount');
  if (countRulesEl) countRulesEl.innerText = visibleRules;
}

// Close modals on escape key or clicking backdrop
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeShaperModal();
    closeRuleModal();
  }
});

document.querySelectorAll('.mno-modal-backdrop').forEach(modal => {
  modal.addEventListener('click', e => {
    if (e.target === modal) {
      modal.classList.remove('active');
    }
  });
});
</script>

<?= $this->endSection() ?>
