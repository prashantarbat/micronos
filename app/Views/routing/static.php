<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.rt-stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 16px;
  margin-bottom: 22px;
}
.rt-stat-card {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  transition: transform .15s, border-color .15s, box-shadow .15s;
}
.rt-stat-card:hover {
  border-color: var(--color-border-bright);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}
.rt-stat-icon {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.rt-stat-num {
  font-size: 1.45rem;
  font-weight: 700;
  line-height: 1.2;
  color: var(--text-primary);
  font-family: var(--font-mono, monospace);
}
.rt-stat-label {
  font-size: 0.76rem;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
}

/* Static routes table */
.st-table-wrap {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  /* overflow:hidden clipped the Actions column on narrower viewports; scroll
     instead so every action stays reachable. */
  overflow-x: auto;
  overflow-y: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.st-table-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  padding: 14px 20px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-2);
  flex-wrap: wrap;
}
.st-search-box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 6px 12px;
  max-width: 320px;
  width: 100%;
}
.st-search-box input {
  border: none;
  background: transparent;
  color: var(--text-primary);
  font-size: 0.82rem;
  outline: none;
  width: 100%;
}

.st-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.84rem;
}
.st-table th {
  text-align: left;
  padding: 11px 18px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
  background: var(--color-surface-2);
  border-bottom: 1px solid var(--color-border);
}
.st-table td {
  padding: 12px 18px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.st-table tbody tr:last-child td { border-bottom: none; }
.st-table tbody tr:hover { background: rgba(255,255,255,0.018); }

.rt-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: var(--radius-sm);
  font-size: 0.78rem;
  font-family: var(--font-mono, monospace);
  font-weight: 500;
  background: var(--color-surface-3);
  color: var(--text-primary);
  border: 1px solid var(--color-border);
}
.rt-chip-cyan   { background: rgba(6,182,212,0.12); color: var(--color-cyan, #06b6d4); border-color: rgba(6,182,212,0.25); font-weight: 600; }
.rt-chip-blue   { background: rgba(59,130,246,0.12); color: var(--color-blue, #3b82f6); border-color: rgba(59,130,246,0.25); }
.rt-chip-purple { background: rgba(139,92,246,0.12); color: var(--color-purple, #8b5cf6); border-color: rgba(139,92,246,0.25); }
.rt-chip-amber  { background: rgba(245,158,11,0.12); color: var(--color-amber, #f59e0b); border-color: rgba(245,158,11,0.25); }
.rt-chip-red    { background: rgba(239,68,68,0.12); color: var(--color-red, #ef4444); border-color: rgba(239,68,68,0.25); }
.rt-chip-emerald{ background: rgba(16,185,129,0.12); color: var(--color-emerald, #10b981); border-color: rgba(16,185,129,0.25); }

/* Gateway Reachability Status Indicators */
.gw-status-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 7px;
  border-radius: 9999px;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  transition: all 0.25s ease;
  user-select: none;
}
.gw-status-pill .gw-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}
.gw-status-pill.gw-checking {
  background: rgba(148, 163, 184, 0.12);
  color: var(--text-muted, #94a3b8);
  border: 1px solid rgba(148, 163, 184, 0.25);
}
.gw-status-pill.gw-checking .gw-dot {
  background: #94a3b8;
  animation: gw-pulse 1.4s infinite ease-in-out;
}
.gw-status-pill.gw-online {
  background: rgba(16, 185, 129, 0.12);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.3);
}
.gw-status-pill.gw-online .gw-dot {
  background: #10b981;
  box-shadow: 0 0 6px rgba(16, 185, 129, 0.6);
}
.gw-status-pill.gw-offline {
  background: rgba(239, 68, 68, 0.12);
  color: #ef4444;
  border: 1px solid rgba(239, 68, 68, 0.3);
}
.gw-status-pill.gw-offline .gw-dot {
  background: #ef4444;
  box-shadow: 0 0 5px rgba(239, 68, 68, 0.5);
}
.gw-status-pill.gw-interface {
  background: rgba(6, 182, 212, 0.1);
  color: #06b6d4;
  border: 1px solid rgba(6, 182, 212, 0.25);
}
.gw-status-pill.gw-interface .gw-dot {
  background: #06b6d4;
}
.gw-status-pill.gw-null {
  background: rgba(100, 116, 139, 0.12);
  color: #64748b;
  border: 1px solid rgba(100, 116, 139, 0.25);
}
.gw-status-pill.gw-null .gw-dot {
  background: #64748b;
}
.gw-rtt {
  font-family: var(--font-mono, monospace);
  font-size: 0.68rem;
  opacity: 0.85;
  margin-left: 2px;
}
@keyframes gw-pulse {
  0%, 100% { opacity: 0.35; transform: scale(0.85); }
  50% { opacity: 1; transform: scale(1.15); }
}
@keyframes gw-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* Modal styles */
.rt-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.65);
  backdrop-filter: blur(4px);
  z-index: 1050;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.rt-modal-backdrop.open { display: flex; }
.rt-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-xl);
  width: 100%; max-width: 600px;
  display: flex; flex-direction: column;
  overflow: hidden;
  max-height: 90vh;
}
.rt-modal-header {
  padding: 16px 22px;
  border-bottom: 1px solid var(--color-border);
  display: flex; justify-content: space-between; align-items: center;
  background: var(--color-surface-2);
}
.rt-modal-body {
  padding: 22px;
  overflow-y: auto;
  display: flex; flex-direction: column; gap: 16px;
}
.rt-modal-footer {
  padding: 14px 22px;
  border-top: 1px solid var(--color-border);
  display: flex; justify-content: flex-end; gap: 10px;
  background: var(--color-surface-2);
}
.rt-field {
  display: flex; flex-direction: column; gap: 6px;
}
.rt-field label {
  font-size: 0.78rem; font-weight: 600;
  color: var(--text-secondary);
  display: flex; align-items: center; justify-content: space-between;
}
.rt-field label .badge-req {
  font-size: 0.65rem; color: var(--color-cyan); font-weight: 500;
}
.rt-field input, .rt-field select, .rt-field textarea {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--text-primary);
  padding: 8px 12px;
  font-size: 0.85rem;
  transition: border-color .15s, box-shadow .15s;
}
.rt-field input:focus, .rt-field select:focus, .rt-field textarea:focus {
  outline: none; border-color: var(--color-cyan);
  box-shadow: 0 0 0 2px rgba(6,182,212,0.18);
}
.rt-field .hint {
  font-size: 0.72rem; color: var(--text-muted); line-height: 1.4; margin: 2px 0 0 0;
}
.rt-grid-2 {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}
@media (max-width: 640px) {
  .rt-grid-2 { grid-template-columns: 1fr; }
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Static Routes</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:rgba(6,182,212,0.12); color:var(--color-cyan); border:1px solid rgba(6,182,212,0.25);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">Static Routes</h1>
        <p class="page-subtitle">Configure explicit destination prefixes, default gateways, interface routes, and blackhole filters</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <a href="<?= base_url('routing') ?>" class="btn btn-secondary btn-sm" title="Go to Dynamic Routing (BGP, OSPF, Policy)">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
      Dynamic Routing
    </a>
    <a href="<?= base_url('routing?tab=fib') ?>" class="btn btn-secondary btn-sm" title="Inspect Kernel FIB Table">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
      Kernel FIB Table
    </a>
    <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openCreateStatic()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Static Route
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── Metrics Overview ── -->
<?php
$activeCount   = count(array_filter($staticRoutes, static fn($r) => !empty($r['enabled'])));
$gwCount       = count(array_filter($staticRoutes, static fn($r) => ($r['type'] ?? 'gateway') === 'gateway'));
$directCount   = count(array_filter($staticRoutes, static fn($r) => ($r['type'] ?? '') === 'interface'));
$dropCount     = count(array_filter($staticRoutes, static fn($r) => in_array($r['type'] ?? '', ['blackhole', 'reject'], true)));
?>
<div class="rt-stat-grid animate-in">
  <div class="rt-stat-card">
    <div class="rt-stat-icon" style="background:rgba(6,182,212,0.12); color:var(--color-cyan);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
    </div>
    <div>
      <div class="rt-stat-num"><?= count($staticRoutes) ?></div>
      <div class="rt-stat-label">Total Routes (<?= $activeCount ?> active)</div>
    </div>
  </div>
  <div class="rt-stat-card">
    <div class="rt-stat-icon" style="background:rgba(59,130,246,0.12); color:var(--color-blue);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
    </div>
    <div>
      <div class="rt-stat-num"><?= $gwCount ?></div>
      <div class="rt-stat-label">Gateway Next-Hop</div>
    </div>
  </div>
  <div class="rt-stat-card">
    <div class="rt-stat-icon" style="background:rgba(16,185,129,0.12); color:var(--color-emerald);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16m-7 6h7"/></svg>
    </div>
    <div>
      <div class="rt-stat-num"><?= $directCount ?></div>
      <div class="rt-stat-label">Interface Connected</div>
    </div>
  </div>
  <div class="rt-stat-card">
    <div class="rt-stat-icon" style="background:rgba(239,68,68,0.12); color:var(--color-red);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
    </div>
    <div>
      <div class="rt-stat-num"><?= $dropCount ?></div>
      <div class="rt-stat-label">Blackhole / Reject</div>
    </div>
  </div>
</div>

<!-- ── Configured Static Routes Table ── -->
<div class="st-table-wrap animate-in">
  <div class="st-table-toolbar">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <div>
        <span style="font-weight:700; font-size:0.92rem; color:var(--text-primary);">Configured Static Routes</span>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Explicit paths that stay installed regardless of what BGP, OSPF or any other protocol decides</div>
      </div>
      <span class="badge badge-neutral"><?= count($staticRoutes) ?> routes</span>
      <div id="gwHealthBadge" style="display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; padding:3px 9px; border-radius:9999px; background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-secondary);" title="Automatic gateway reachability status">
        <span class="gw-dot" style="width:7px; height:7px; border-radius:50%; background:#94a3b8; display:inline-block;"></span>
        <span id="gwHealthText">Gateways: Checking...</span>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="btnRecheckGw" onclick="checkGatewaysReachability(true)" title="Probe gateway reachability now" style="padding:3px 8px; font-size:0.75rem; gap:4px; display:inline-flex; align-items:center;">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" id="gwRefreshIcon"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Re-check
      </button>
    </div>
    <div class="st-search-box">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>
      <input type="text" id="routeSearchInput" placeholder="Filter by prefix, gateway, interface..." oninput="filterStaticTable(this.value)">
    </div>
  </div>

  <?php if (!empty($staticRoutes)): ?>
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:8px 16px; border-bottom:1px solid var(--color-border); font-size:0.72rem; color:var(--text-muted);">
    <span style="font-weight:600; color:var(--text-secondary);">Route type:</span>
    <span class="rt-chip rt-chip-blue">Gateway</span> <span>via a next-hop router</span>
    <span class="rt-chip rt-chip-emerald">Connected</span> <span>directly attached interface</span>
    <span class="rt-chip rt-chip-red">Blackhole</span> <span>silently dropped</span>
    <span class="rt-chip rt-chip-amber">Reject</span> <span>dropped with ICMP unreachable</span>
  </div>
  <?php endif; ?>

  <table class="st-table" id="staticRoutesTable">
    <thead>
      <tr>
        <th title="The network or host this route matches, in CIDR form (0.0.0.0/0 is the default route)">Destination Prefix</th>
        <th title="How matching traffic is handled: via a gateway, a connected interface, a silent blackhole, or an explicit reject">Route Type</th>
        <th title="Next-hop router address, 'direct' for a connected link, or the null target for blackhole/reject routes">Gateway / Next-Hop</th>
        <th title="Outgoing device, or 'auto' to let the system choose">Interface</th>
        <th title="Administrative distance — the lower value wins when two routes match the same prefix">Metric / Dist</th>
        <th title="Kernel routing table or VRF instance; table 254 is the main table">VRF / Table</th>
        <th>Description</th>
        <th title="Whether this route is currently installed in the kernel forwarding table">Status</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($staticRoutes)): ?>
        <tr>
          <td colspan="9" style="text-align:center; padding:44px 20px; color:var(--text-muted);">
            <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5" style="display:block; margin:0 auto 10px;"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            <div style="font-size:1.05rem; font-weight:600; margin-bottom:4px; color:var(--text-secondary);">No Static Routes Configured</div>
            <div style="font-size:0.8rem; margin-bottom:14px; max-width:540px; margin-left:auto; margin-right:auto; line-height:1.5;">
              A static route steers traffic to a remote subnet, an upstream gateway or a VPN peer without waiting for a routing protocol to learn it.
              Use <strong>Gateway</strong> when the next hop is a router, <strong>Interface</strong> for a directly attached link, or <strong>Blackhole / Reject</strong> to drop unwanted traffic.
            </div>
            <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
              <button type="button" class="btn btn-primary btn-sm" onclick="openCreateStatic()">Add First Route</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($staticRoutes as $sr): ?>
          <?php
            $rType = $sr['type'] ?? 'gateway';
            $typeBadge = match($rType) {
              'interface' => '<span class="rt-chip rt-chip-emerald">Connected</span>',
              'blackhole' => '<span class="rt-chip rt-chip-red">Blackhole</span>',
              'reject'    => '<span class="rt-chip rt-chip-amber">Reject</span>',
              default     => '<span class="rt-chip rt-chip-blue">Gateway</span>',
            };
          ?>
          <tr data-search="<?= esc(strtolower($sr['destination'] . ' ' . $sr['gateway'] . ' ' . ($sr['interface'] ?? '') . ' ' . ($sr['description'] ?? ''))) ?>">
            <td>
              <span class="rt-chip rt-chip-cyan"><?= esc($sr['destination']) ?></span>
            </td>
            <td><?= $typeBadge ?></td>
            <td>
              <?php if (in_array($rType, ['blackhole', 'reject'], true)): ?>
                <div style="display:inline-flex; align-items:center; gap:6px;">
                  <span style="color:var(--text-muted); font-style:italic;">null0 / drop</span>
                  <span class="gw-status-pill gw-null" title="Drop / Discard target"><span class="gw-dot"></span> Discard</span>
                </div>
              <?php elseif ($rType === 'interface'): ?>
                <div style="display:inline-flex; align-items:center; gap:6px;">
                  <span class="rt-chip rt-chip-emerald">direct</span>
                  <span class="gw-status-pill gw-interface" title="Directly attached interface link"><span class="gw-dot"></span> Link Up</span>
                </div>
              <?php else: ?>
                <div style="display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;">
                  <span class="rt-chip rt-chip-blue"><?= esc($sr['gateway']) ?></span>
                  <span class="gw-status-pill gw-checking" data-gw="<?= esc($sr['gateway']) ?>" title="Checking gateway reachability...">
                    <span class="gw-dot"></span>
                    <span class="gw-text">Checking</span>
                  </span>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="rt-chip"><?= esc($sr['interface'] ?: 'auto') ?></span>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
              <?= esc($sr['metric'] ?? 10) ?>
            </td>
            <td>
              <span style="font-size:0.75rem; color:var(--text-muted);">
                <?= !empty($sr['table_id']) && (int)$sr['table_id'] !== 254 ? 'Table ' . esc($sr['table_id']) : 'main' ?>
              </span>
            </td>
            <td style="font-size:0.8rem; color:var(--text-secondary); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= esc($sr['description'] ?? '') ?>">
              <?= esc($sr['description'] ?: '—') ?>
            </td>
            <td>
              <?= (int)($sr['enabled'] ?? 1)
                  ? '<span class="badge badge-success">Active</span>'
                  : '<span class="badge badge-muted">Disabled</span>' ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                <!-- Toggle -->
                <form method="POST" action="<?= base_url('routing/static/' . esc($sr['id']) . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:<?= (int)($sr['enabled']??1) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>;" title="Toggle Route Enable/Disable">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M12 3v9"/></svg>
                  </button>
                </form>
                <!-- Edit -->
                <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditStatic(<?= htmlspecialchars(json_encode($sr), ENT_QUOTES, "UTF-8") ?>)' title="Edit Route">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                </button>
                <!-- Delete -->
                <form method="POST" action="<?= base_url('routing/static/' . esc($sr['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove static route to <?= esc(addslashes($sr['destination'])) ?>?')"><?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete Route">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ================================================================
     MODAL: Add / Edit Static Route
     ================================================================ -->
<div class="rt-modal-backdrop" id="staticModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="staticModalTitle">Add Static Route</span>
      <button type="button" onclick="closeModal('staticModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="staticForm" action="<?= base_url('routing/static/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        
        <!-- Destination & Type -->
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Destination Subnet / Host <span class="badge-req">Required</span></label>
            <input type="text" name="destination" id="srtDest" required placeholder="10.200.0.0/16 or 0.0.0.0/0" spellcheck="false" style="font-family:var(--font-mono);">
            <p class="hint">IPv4 or IPv6 CIDR prefix (e.g. 192.168.10.0/24 or 0.0.0.0/0 for default route)</p>
          </div>
          <div class="rt-field">
            <label>Route Type <span class="badge-req">Required</span></label>
            <select name="type" id="srtType" onchange="onRouteTypeChanged(this.value)">
              <option value="gateway">Gateway IP (Next-Hop)</option>
              <option value="interface">Directly Connected Interface</option>
              <option value="blackhole">Blackhole (Null0 / Silent Drop)</option>
              <option value="reject">Reject (ICMP Unreachable)</option>
            </select>
            <p class="hint">How traffic matching this destination should be handled</p>
          </div>
        </div>

        <!-- Next Hop Gateway & Outgoing Interface -->
        <div class="rt-grid-2">
          <div class="rt-field" id="srtGwWrap">
            <label id="srtGwLabel">Next Hop Gateway <span class="badge-req" id="srtGwReq">Required</span></label>
            <input type="text" name="gateway" id="srtGw" required placeholder="192.168.1.254" spellcheck="false" style="font-family:var(--font-mono);">
            <p class="hint" id="srtGwHint">IP address of the next-hop router in the shared broadcast domain</p>
          </div>
          <div class="rt-field">
            <label>Outgoing Interface</label>
            <select name="interface" id="srtIface">
              <option value="">auto (System Selected)</option>
              <?php foreach (($allInterfaces ?? $interfaces ?? []) as $if): ?>
                <?php
                  $dev = $if['physical_device'] ?? $if['dev'] ?? $if['name'];
                  $label = ($if['name'] ?? $dev) . ' (' . $dev . ')';
                ?>
                <option value="<?= esc($dev) ?>"><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Specific network device or VPN interface for outgoing packets</p>
          </div>
        </div>

        <!-- Metric & Routing Table / VRF -->
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Metric / Distance</label>
            <input type="number" name="metric" id="srtMetric" value="10" min="1" max="255">
            <p class="hint">Administrative distance / metric (1-255). Lower values have higher priority.</p>
          </div>
          <div class="rt-field">
            <label>Routing Table / VRF</label>
            <select name="table_id" id="srtTable">
              <option value="254">main (Table 254 - Default)</option>
              <?php foreach (($vrfs ?? []) as $v): ?>
                <option value="<?= esc($v['table_id'] ?? 254) ?>"><?= esc($v['name']) ?> (Table <?= esc($v['table_id'] ?? 254) ?>)</option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Target Linux routing table or VRF instance</p>
          </div>
        </div>

        <!-- Description -->
        <div class="rt-field">
          <label>Description / Note</label>
          <input type="text" name="description" id="srtDesc" placeholder="e.g. Branch office LAN or AWS VPC interconnection">
        </div>

        <!-- Status -->
        <div class="rt-field">
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:0.85rem;cursor:pointer;">
            <input type="checkbox" name="enabled" id="srtEnabled" value="1" checked style="width:16px;height:16px;accent-color:var(--color-cyan);">
            <strong>Route Active</strong> — Install into kernel FIB forwarding table
          </label>
        </div>

      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('staticModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="srtSubmit">Save Route</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

function onRouteTypeChanged(type) {
  var gwWrap = document.getElementById('srtGwWrap');
  var gwInput = document.getElementById('srtGw');
  var gwReq = document.getElementById('srtGwReq');
  var gwHint = document.getElementById('srtGwHint');

  if (type === 'blackhole' || type === 'reject') {
    gwInput.required = false;
    gwInput.value = type;
    gwWrap.style.display = 'none';
  } else if (type === 'interface') {
    gwInput.required = false;
    gwInput.value = 'direct';
    gwWrap.style.display = 'none';
  } else {
    gwWrap.style.display = 'flex';
    gwInput.required = true;
    if (gwInput.value === 'blackhole' || gwInput.value === 'reject' || gwInput.value === 'direct') {
      gwInput.value = '';
    }
    gwReq.style.display = 'inline';
    gwHint.textContent = 'IP address of the next-hop router in the shared broadcast domain';
  }
}

function openCreateStatic() {
  document.getElementById('staticModalTitle').textContent = 'Add Static Route';
  document.getElementById('staticForm').action = '<?= base_url('routing/static/store') ?>';
  document.getElementById('srtDest').value = '';
  document.getElementById('srtType').value = 'gateway';
  document.getElementById('srtGw').value = '';
  document.getElementById('srtIface').value = '';
  document.getElementById('srtMetric').value = '10';
  document.getElementById('srtTable').value = '254';
  document.getElementById('srtDesc').value = '';
  document.getElementById('srtEnabled').checked = true;
  document.getElementById('srtSubmit').textContent = 'Add Route';
  onRouteTypeChanged('gateway');
  openModal('staticModal');
}

function openEditStatic(r) {
  document.getElementById('staticModalTitle').textContent = 'Edit Static Route';
  document.getElementById('staticForm').action = '<?= base_url('routing/static') ?>/' + r.id + '/update';
  document.getElementById('srtDest').value = r.destination || '';
  
  var rType = r.type || 'gateway';
  if (r.gateway === 'blackhole') rType = 'blackhole';
  if (r.gateway === 'reject') rType = 'reject';
  if (r.gateway === 'direct') rType = 'interface';

  document.getElementById('srtType').value = rType;
  document.getElementById('srtGw').value = r.gateway || '';
  document.getElementById('srtIface').value = r.interface || '';
  document.getElementById('srtMetric').value = r.metric !== null ? r.metric : '10';
  document.getElementById('srtTable').value = r.table_id || '254';
  document.getElementById('srtDesc').value = r.description || '';
  document.getElementById('srtEnabled').checked = parseInt(r.enabled) === 1;
  document.getElementById('srtSubmit').textContent = 'Save Changes';
  onRouteTypeChanged(rType);
  openModal('staticModal');
}

function filterStaticTable(q) {
  q = (q || '').toLowerCase().trim();
  var rows = document.querySelectorAll('#staticRoutesTable tbody tr[data-search]');
  rows.forEach(function(r) {
    var txt = r.getAttribute('data-search') || '';
    r.style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
  });
}

// ── Automatic Gateway Reachability Checker ──────────────────────────────
let gwCheckTimer = null;
let isGwChecking = false;

async function checkGatewaysReachability(manual = false) {
  if (isGwChecking) return;
  isGwChecking = true;

  const refreshIcon = document.getElementById('gwRefreshIcon');
  if (refreshIcon) refreshIcon.style.animation = 'gw-spin 0.8s linear infinite';

  const healthText = document.getElementById('gwHealthText');
  if (healthText && manual) healthText.textContent = 'Probing gateways...';

  try {
    const res = await MicronScheduler.fetch('<?= base_url('routing/static/check-gateways') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (data.gateways) {
      let onlineCount = 0;
      let offlineCount = 0;

      // Update every matching pill in the table rows
      document.querySelectorAll('.gw-status-pill[data-gw]').forEach(pill => {
        const gwIp = pill.getAttribute('data-gw');
        const info = data.gateways[gwIp];
        if (!info) return;

        const isOnline = info.status === 'online';
        pill.className = 'gw-status-pill ' + (isOnline ? 'gw-online' : 'gw-offline');
        pill.title = (info.message || (isOnline ? 'Online' : 'Offline')) + ' • Checked at ' + (data.time_human || '');

        let html = '<span class="gw-dot"></span>';
        if (isOnline) {
          html += '<span class="gw-text">Online</span>';
          if (info.rtt) {
            html += '<span class="gw-rtt">' + info.rtt + '</span>';
          }
        } else {
          html += '<span class="gw-text">Offline</span>';
        }
        pill.innerHTML = html;
      });

      // Tally unique probed gateways
      Object.values(data.gateways).forEach(g => {
        if (g.status === 'online') onlineCount++;
        else offlineCount++;
      });

      const totalGw = onlineCount + offlineCount;
      if (healthText) {
        if (totalGw === 0) {
          healthText.textContent = 'No gateway IP routes';
        } else if (offlineCount === 0) {
          healthText.textContent = `Gateways: All ${onlineCount} Online`;
        } else {
          healthText.textContent = `Gateways: ${onlineCount} Online, ${offlineCount} Down`;
        }
      }

      const healthBadge = document.getElementById('gwHealthBadge');
      if (healthBadge) {
        const dot = healthBadge.querySelector('.gw-dot');
        if (dot) {
          dot.style.background = offlineCount > 0 ? '#ef4444' : '#10b981';
          dot.style.boxShadow = offlineCount > 0 ? '0 0 6px rgba(239,68,68,0.6)' : '0 0 6px rgba(16,185,129,0.6)';
        }
      }
    }
  } catch (err) {
    console.warn('[Static Routes] Gateway check error:', err);
    if (healthText) healthText.textContent = 'Gateways: Check error';
  } finally {
    isGwChecking = false;
    if (refreshIcon) refreshIcon.style.animation = '';
  }
}

// Automatically check on initial load & poll in background every 25 seconds
document.addEventListener('DOMContentLoaded', () => {
  checkGatewaysReachability();

  if (gwCheckTimer) clearInterval(gwCheckTimer);
  gwCheckTimer = setInterval(() => {
    if (!document.hidden) {
      checkGatewaysReachability();
    }
  }, 25000);
});
</script>

<?= $this->endSection() ?>
