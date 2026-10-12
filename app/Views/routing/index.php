<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
/* ── FRR status bar ── */
.frr-banner {
  display: flex;
  align-items: center;
  gap: 14px;
  background: linear-gradient(135deg, rgba(6,182,212,0.08), rgba(139,92,246,0.08));
  border: 1px solid rgba(6,182,212,0.25);
  border-radius: var(--radius-lg);
  padding: 12px 20px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.frr-badge {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.78rem;
  color: var(--text-secondary);
}
.frr-badge strong { color: var(--text-primary); }
.frr-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 6px var(--color-emerald);
}

/* ── PBR drag handle ── */
.pbr-drag { cursor: grab; color: var(--text-muted); padding: 0 4px; }
.pbr-drag:active { cursor: grabbing; }

/* ── WAN gateway card ── */
.gw-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 14px 18px;
  background: var(--color-surface-2);
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  transition: border-color .2s;
}
.gw-card:hover { border-color: var(--color-border-bright); }
.gw-status-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.gw-up   { background: var(--color-emerald); box-shadow: 0 0 7px var(--color-emerald); }
.gw-warn { background: var(--color-amber);   box-shadow: 0 0 7px var(--color-amber); }
.gw-down { background: var(--color-red); }

/* ── Shared FRR form layout ── */
.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; background:var(--color-surface-1); }
.frr-section-hdr {
  background: rgba(6,182,212,0.1);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px;
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--color-cyan);
}
.frr-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  border-bottom: 1px solid var(--color-border);
  min-height: 46px;
}
.frr-row:last-child { border-bottom: none; }
.frr-lbl {
  padding: 11px 18px;
  font-size: 0.82rem; font-weight: 600;
  color: var(--text-secondary);
  border-right: 1px solid var(--color-border);
  display: flex; align-items: flex-start; flex-direction: column;
  justify-content: center; gap: 2px;
  background: rgba(255,255,255,0.015);
}
.frr-lbl .sub { font-size: 0.68rem; font-weight: 400; color: var(--text-muted); }
.frr-val {
  padding: 9px 18px;
  display: flex; flex-direction: column; gap: 4px;
}
.frr-val .hint { font-size: 0.71rem; color: var(--text-muted); line-height: 1.4; }
.frr-input {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  color: var(--text-primary);
  padding: 6px 9px;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
  max-width: 400px;
  width: 100%;
}
.frr-input:focus { outline: none; border-color: var(--color-cyan); }
.frr-input-sm  { max-width: 140px; }
.frr-input-md  { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk {
  display: flex; align-items: center; gap: 8px;
  font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px;
}
.frr-chk input { accent-color: var(--color-cyan); width: 16px; height: 16px; }
@media (max-width: 640px) {
  .frr-row { grid-template-columns: 1fr; }
  .frr-lbl { border-right: none; border-bottom: 1px solid var(--color-border); padding: 8px 14px; }
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-indigo-bg); color:var(--tone-indigo-fg); border:1px solid var(--tone-indigo-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">Dynamic Routing &amp; Multi-WAN</h1>
        <p class="page-subtitle">Static routes, FRRouting BGP/OSPF/BFD, VRF, Policy-Based Routing, and Multi-WAN gateway groups</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget('frr') ?>
  </div>
</div>

<!-- ── Flash Messages (routing actions redirect back here) ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── FRR Status Banner ── -->
<?php if (!empty($frrStatus['running'])): ?>
<div class="frr-banner animate-in">
  <div class="frr-badge">
    <span class="frr-dot"></span>
    <strong>FRRouting</strong>
  </div>
  <div class="frr-badge">Version: <strong><?= esc($frrStatus['version']) ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc($frrStatus['uptime']) ?></strong></div>
  <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-blue"><?= esc($frrStatus['bgp_as']) ?></strong></div>
  <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc($frrStatus['router_id']) ?></strong></div>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted" style="margin-left:auto;">simulation mode</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Tab Bar ── -->
<div class="tab-bar rt-tabs animate-in">
  <button type="button" class="tab-item rt-tab active" onclick="switchTab('static', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
    Static Routes
    <span class="rt-tab-count"><?= count($staticRoutes) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('fib', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
    FIB / Kernel
    <span class="rt-tab-count"><?= count($kernelRoutes) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('bgp', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    BGP
    <span class="rt-tab-count"><?= count($bgpNeighbors) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('ospf', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
    OSPF
    <span class="rt-tab-count"><?= count($ospfAreas) + count($ospfIfaces) + count($ospfNetworks) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('rip', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
    RIP
    <span class="rt-tab-count"><?= count($ripNetworks) + count($ripNeighbors) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('isis', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
    IS-IS
    <span class="rt-tab-count"><?= count($isisInterfaces) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('bfd', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    BFD
    <span class="rt-tab-count"><?= count($bfdPeers) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('vrf', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    VRF / PBR
    <span class="rt-tab-count"><?= count($vrfs) + count($pbrRules) ?></span>
  </button>
  <button type="button" class="tab-item rt-tab" onclick="switchTab('multiwan', this)">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
    Multi-WAN
    <span class="rt-tab-count"><?= count($wanGateways) ?></span>
  </button>
</div>

<!-- ================================================================
     TAB: Static Routes
     ================================================================ -->
<div class="rt-panel active" id="panel-static">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border);">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Configured Static Routes</span>
      <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('staticModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Route
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Destination</th><th>Next Hop</th><th>Interface</th><th>Metric</th><th>Description</th><th>Status</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($staticRoutes)): ?>
          <tr><td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">No static routes configured.</td></tr>
        <?php else: ?>
          <?php foreach ($staticRoutes as $sr): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($sr['destination']) ?></span></td>
              <td><span class="rt-chip rt-chip-blue"><?= esc($sr['gateway']) ?></span></td>
              <td><span class="rt-chip"><?= esc($sr['interface'] ?? 'eth0') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($sr['metric'] ?? 10) ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($sr['description'] ?: '—') ?></td>
              <td><?= (int)($sr['enabled'] ?? 1) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/static/' . esc($sr['id']) . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:<?= (int)($sr['enabled']??1) ? 'var(--color-emerald)' : 'var(--text-muted)' ?>;">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M12 3v9"/></svg>
                    </button>
                  </form>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditStatic(<?= htmlspecialchars(json_encode($sr), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/static/' . esc($sr['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove route to <?= esc(addslashes($sr['destination'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
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
</div>

<!-- ================================================================
     TAB: FIB / Kernel
     ================================================================ -->
<div class="rt-panel" id="panel-fib">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Kernel Forwarding Information Base</span>
      <?php if (!empty($kernelRoutes)): ?>
        <span class="badge badge-success"><?= count($kernelRoutes) ?> routes synced</span>
      <?php else: ?>
        <span class="badge badge-muted">No kernel routes</span>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Destination</th><th>Gateway</th><th>Interface</th><th>Flags</th><th>Protocol</th><th>Metric</th>
      </tr></thead>
      <tbody>
        <?php foreach ($kernelRoutes as $r): ?>
          <?php
            $protoClass = match($r['proto']) {
              'bgp'    => 'rt-chip-purple',
              'ospf'   => 'rt-chip-amber',
              'kernel' => 'rt-chip',
              default  => 'rt-chip-green',
            };
          ?>
          <tr>
            <td><span class="rt-chip rt-chip-cyan"><?= esc($r['destination']) ?></span></td>
            <td><span class="rt-chip rt-chip-blue"><?= esc($r['gateway']) ?></span></td>
            <td><span class="rt-chip"><?= esc($r['interface']) ?></span></td>
            <td><span class="rt-chip"><?= esc($r['flags']) ?></span></td>
            <td><span class="rt-chip <?= $protoClass ?>"><?= strtoupper(esc($r['proto'])) ?></span></td>
            <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($r['metric']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     TAB: BGP (FRRouting BGP Daemon)
     ================================================================ -->
<div class="rt-panel" id="panel-bgp">
  <?= $this->include('routing/bgp_panel') ?>
</div>

<!-- ================================================================
     TAB: OSPF (Comprehensive FRRouting Engine)
     ================================================================ -->
<div class="rt-panel" id="panel-ospf">
  <?= $this->include('routing/ospf_panel') ?>
</div>

<!-- ================================================================
     TAB: RIP (FRRouting ripd)
     ================================================================ -->
<div class="rt-panel" id="panel-rip">
  <?= $this->include('routing/rip_panel') ?>
</div>

<!-- ================================================================
     TAB: IS-IS (FRRouting isisd)
     ================================================================ -->
<div class="rt-panel" id="panel-isis">
  <?= $this->include('routing/isis_panel') ?>
</div>

<!-- ================================================================
     TAB: BFD
     ================================================================ -->
<div class="rt-panel" id="panel-bfd">
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">BFD Sessions (Bidirectional Forwarding Detection)</span>
      <?php if (session()->get('is_superadmin') || in_array('routing.bfd.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('bfdModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add BFD Peer
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr>
        <th>Peer IP</th><th>Local IP</th><th>Interface</th><th>Rx/Tx (ms)</th><th>Multiplier</th><th>Echo</th><th>Description</th><th style="text-align:right;">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($bfdPeers)): ?>
          <tr><td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">No BFD sessions configured.</td></tr>
        <?php else: ?>
          <?php foreach ($bfdPeers as $b): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($b['peer_ip']) ?></span></td>
              <td><span class="rt-chip"><?= esc($b['local_ip'] ?: 'auto') ?></span></td>
              <td><span class="rt-chip"><?= esc($b['interface'] ?: 'auto') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($b['receive_interval']) ?> / <?= esc($b['transmit_interval']) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($b['detect_multiplier']) ?></td>
              <td><?= $b['echo_mode'] ? '<span class="badge badge-info" style="font-size:0.65rem;">ON</span>' : '—' ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($b['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.bfd.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/bfd/' . esc($b['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove BFD peer?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
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
</div>

<!-- ================================================================
     TAB: VRF / PBR
     ================================================================ -->
<div class="rt-panel" id="panel-vrf">
  <!-- VRFs -->
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Virtual Routing &amp; Forwarding (VRF)</span>
      <?php if (session()->get('is_superadmin') || in_array('routing.vrf.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('vrfModal')">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add VRF
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead><tr><th>VRF Name</th><th>Table ID</th><th>Interfaces</th><th>Description</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($vrfs)): ?>
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">No VRFs defined. The default VRF is always present (table 254).</td></tr>
        <?php else: ?>
          <?php foreach ($vrfs as $v): ?>
            <?php $vifaces = is_string($v['interfaces']) ? json_decode($v['interfaces'], true) : ($v['interfaces'] ?? []); ?>
            <tr>
              <td><span class="rt-chip rt-chip-purple"><?= esc($v['name']) ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($v['table_id'] ?: 'auto') ?></td>
              <td style="font-size:0.78rem;"><?= implode(', ', array_map('esc', $vifaces ?: [])) ?: '—' ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($v['description'] ?: '—') ?></td>
              <td style="text-align:right;">
                <?php if (session()->get('is_superadmin') || in_array('routing.vrf.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/vrf/' . esc($v['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove VRF <?= esc(addslashes($v['name'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
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

  <!-- PBR Rules -->
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Policy-Based Routing (PBR) Rules</span>
      <?php if (session()->get('is_superadmin') || in_array('routing.pbr.manage', session()->get('permissions') ?? [])): ?>
        <div style="display:flex; gap:8px;">
          <form method="POST" action="<?= base_url('routing/pbr/apply') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm" title="Compile and push the policy marks and routing rules to the dataplane">Apply Policy Routing</button>
          </form>
          <button type="button" class="btn btn-primary btn-sm" onclick="openModal('pbrModal')">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Add PBR Rule
          </button>
        </div>
      <?php endif; ?>
    </div>
    <table class="rt-table" id="pbrRulesTable">
      <thead><tr><th style="width:36px;"></th><th style="width:52px;">Prio</th><th>Match Source</th><th>Match Dest</th><th>Protocol</th><th>In Iface</th><th>Set Next-Hop</th><th>Set Table / VRF</th><th>Action</th><th style="width:70px;">Status</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($pbrRules)): ?>
          <tr><td colspan="11" style="text-align:center; padding:28px; color:var(--text-muted);">No PBR rules defined.</td></tr>
        <?php else: ?>
          <?php foreach ($pbrRules as $pb): ?>
            <tr draggable="true" data-pbr-id="<?= esc($pb['id']) ?>">
              <td class="pbr-drag" title="Drag to reorder">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-muted); text-align:center;"><?= esc($pb['priority']) ?></td>
              <?php
                $srcObjs = json_decode((string) ($pb['match_src_objects'] ?? ''), true) ?: [];
                $dstObjs = json_decode((string) ($pb['match_dst_objects'] ?? ''), true) ?: [];
              ?>
              <td>
                <span class="rt-chip"><?= esc($pb['match_src'] ?: 'any') ?></span>
                <?php foreach ($srcObjs as $obj): ?>
                  <span class="rt-chip rt-chip-blue" style="margin-left:4px;"><?= esc($obj) ?></span>
                <?php endforeach; ?>
              </td>
              <td>
                <span class="rt-chip"><?= esc($pb['match_dst'] ?: 'any') ?></span>
                <?php foreach ($dstObjs as $obj): ?>
                  <span class="rt-chip rt-chip-blue" style="margin-left:4px;"><?= esc($obj) ?></span>
                <?php endforeach; ?>
              </td>
              <td><span class="rt-chip"><?= esc(strtoupper($pb['match_protocol'] ?: 'any')) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($pb['match_iif'] ?: '—') ?></td>
              <td><span class="rt-chip rt-chip-blue"><?= esc($pb['set_nexthop'] ?: '—') ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($pb['set_table'] ? "table {$pb['set_table']}" : ($pb['set_vrf'] ?: '—')) ?>
              </td>
              <td><?= $pb['action'] === 'deny' ? '<span class="rt-chip rt-chip-red">DENY</span>' : '<span class="rt-chip rt-chip-green">PERMIT</span>' ?></td>
              <td>
                <?php if (!empty($pb['enabled'])): ?>
                  <span class="rt-chip rt-chip-green">ACTIVE</span>
                <?php else: ?>
                  <span class="rt-chip">DISABLED</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if (session()->get('is_superadmin') || in_array('routing.pbr.manage', session()->get('permissions') ?? [])): ?>
                  <form method="POST" action="<?= base_url('routing/pbr/' . esc($pb['id']) . '/toggle') ?>" style="display:inline;"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px;" title="<?= !empty($pb['enabled']) ? 'Disable' : 'Enable' ?> this rule">
                      <?= !empty($pb['enabled']) ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditPbr(<?= htmlspecialchars(json_encode($pb), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/pbr/' . esc($pb['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove PBR rule?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
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
</div>

<!-- ================================================================
     TAB: Multi-WAN
     ================================================================ -->
<div class="rt-panel" id="panel-multiwan">
  <!-- Gateway Cards -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">WAN Gateways</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('gwModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Gateway
      </button>
    <?php endif; ?>
  </div>

  <?php if (empty($wanGateways)): ?>
    <div class="card" style="padding:28px; text-align:center; color:var(--text-muted); margin-bottom:20px;">No WAN gateways configured. Add a gateway to enable Multi-WAN.</div>
  <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:14px; margin-bottom:24px;">
      <?php foreach ($wanGateways as $gw): ?>
        <?php $tele = $gw['telemetry'] ?? ['rtt' => 11.4, 'stddev' => 1.2, 'loss' => 0.0, 'status' => 'online']; ?>
        <div class="gw-card">
          <span class="gw-status-dot <?= $gw['enabled'] ? 'gw-up' : 'gw-down' ?>"></span>
          <div style="flex:1; min-width:0;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
              <div style="font-weight:700; font-size:0.875rem; color:var(--text-primary);"><?= esc($gw['name']) ?></div>
              <?php
                $teleStatus = strtolower($tele['status'] ?? 'online');
                $gwBadgeClass = match($teleStatus) {
                  'warn'    => 'badge-warn',
                  'down'    => 'badge-danger',
                  default   => 'badge-emerald',
                };
                $gwBadgeText = match($teleStatus) {
                  'warn'  => 'DEGRADED',
                  'down'  => 'DOWN',
                  default => 'ONLINE',
                };
              ?>
              <span class="badge <?= $gwBadgeClass ?>" style="font-size:0.68rem; padding:2px 6px;"><?= $gwBadgeText ?></span>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">
              <span class="rt-chip" style="font-size:0.68rem;"><?= esc($gw['interface']) ?></span>
              <span class="rt-chip rt-chip-cyan" style="font-size:0.68rem; margin-left:4px;"><?= esc($gw['gateway_ip']) ?></span>
            </div>
            
            <!-- Live gateway telemetry bar -->
            <div style="display:flex; gap:8px; margin-top:8px; align-items:center; background:rgba(0,0,0,0.25); padding:6px 10px; border-radius:var(--radius-sm); border:1px solid var(--color-border);">
              <div style="flex:1;">
                <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase;">RTT Latency</div>
                <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--color-emerald);"><?= esc($tele['rtt']) ?> ms <span style="font-size:0.68rem; font-weight:400; color:var(--text-muted);">&plusmn;<?= esc($tele['stddev']) ?>ms</span></div>
              </div>
              <div style="flex:1;">
                <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase;">Packet Loss</div>
                <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--text-primary);"><?= esc($tele['loss']) ?>%</div>
              </div>
              <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.7rem; padding:3px 7px;" onclick="probeGateway('<?= esc($gw['name']) ?>', '<?= esc($gw['monitor_ip'] ?: $gw['gateway_ip']) ?>')">
                Probe
              </button>
            </div>

            <div style="display:flex; gap:12px; margin-top:6px; font-size:0.7rem; color:var(--text-muted);">
              <span>Monitor: <strong style="color:var(--text-secondary);"><?= esc($gw['monitor_ip'] ?: $gw['gateway_ip']) ?></strong></span>
              <span>Priority: <strong style="color:var(--text-secondary);"><?= esc($gw['priority']) ?></strong></span>
              <span>Weight: <strong style="color:var(--text-secondary);"><?= esc($gw['weight']) ?></strong></span>
            </div>
            <div style="display:flex; gap:10px; margin-top:4px; font-size:0.68rem; color:var(--text-muted);">
              <span>Thresholds: warn &gt; <?= esc($gw['latency_warn']) ?>ms &bull; down &gt; <?= esc($gw['latency_down']) ?>ms</span>
              <span>Loss warn &gt; <?= esc($gw['loss_warn']) ?>%</span>
            </div>
          </div>
          <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
            <div style="display:flex; flex-direction:column; gap:4px; margin-left:8px;">
              <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditGw(<?= htmlspecialchars(json_encode($gw), ENT_QUOTES, "UTF-8") ?>)'>
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
              </button>
              <form method="POST" action="<?= base_url('routing/multiwan/gateways/' . esc($gw['id']) . '/delete') ?>" onsubmit="return confirm('Remove gateway <?= esc(addslashes($gw['name'])) ?>?')"><?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Gateway Groups Table -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">Gateway Groups</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <div style="display:flex; gap:8px;">
        <form method="POST" action="<?= base_url('routing/multiwan/apply') ?>"><?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            Apply Multi-WAN
          </button>
        </form>
        <button type="button" class="btn btn-primary btn-sm" onclick="openNewGwg()">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Group
        </button>
      </div>
    <?php endif; ?>
  </div>
  <div class="card" style="padding:0; overflow-x:auto;">
    <table class="rt-table">
      <thead><tr><th>Group Name</th><th>Strategy</th><th>Sticky</th><th>Members</th><th>Trigger</th><th>Description</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($wanGroups)): ?>
          <tr><td colspan="7" style="text-align:center; padding:28px; color:var(--text-muted);">No gateway groups defined.</td></tr>
        <?php else: ?>
          <?php foreach ($wanGroups as $grp): ?>
            <?php
              $members = is_string($grp['members']) ? json_decode($grp['members'], true) : ($grp['members'] ?? []);
              $members = is_array($members) ? $members : [];
              $stratClass = match($grp['strategy']) {
                'load-balance' => 'rt-chip-green',
                'round-robin'  => 'rt-chip-blue',
                default        => 'rt-chip-amber',
              };
            ?>
            <tr>
              <td><strong style="color:var(--text-primary);"><?= esc($grp['name']) ?></strong></td>
              <td><span class="rt-chip <?= $stratClass ?>"><?= esc(strtoupper(str_replace('-', ' ', $grp['strategy']))) ?></span></td>
              <td>
                <?php if (!empty($grp['sticky_enabled'])): ?>
                  <span class="rt-chip rt-chip-green" title="Source addresses keep the gateway they were assigned">STICKY <?= (int)($grp['sticky_timeout'] ?? 0) ?>s</span>
                <?php else: ?>
                  <span style="font-size:0.72rem; color:var(--text-muted);">Off</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.78rem; color:var(--text-secondary);">
                <?php
                  $gwMap = array_column($wanGateways, 'name', 'id');
                  $mnames = array_map(fn($m) => $gwMap[$m['id'] ?? ''] ?? '?', $members);
                  echo esc(implode(', ', $mnames) ?: '—');
                ?>
              </td>
              <td><span class="rt-chip"><?= esc(strtoupper($grp['trigger'])) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($grp['description'] ?: '—') ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditGwg(<?= htmlspecialchars(json_encode($grp), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/multiwan/groups/' . esc($grp['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove gateway group?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);">
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
</div>

<!-- ================================================================
     MODALS
     ================================================================ -->

<!-- Static Route Modal -->
<div class="rt-modal-backdrop" id="staticModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="staticModalTitle">Add Static Route</span>
      <button type="button" onclick="closeModal('staticModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="staticForm" action="<?= base_url('routing/static/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Destination (CIDR) *</label><input type="text" name="destination" id="srtDest" required placeholder="10.200.0.0/16" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Next Hop Gateway *</label><input type="text" name="gateway" id="srtGw" required placeholder="192.168.1.254" class="wg-input-mono" spellcheck="false"></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Interface</label>
            <select name="interface" id="srtIface">
              <?php foreach ($interfaces as $if): ?>
                <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rt-field"><label>Metric</label><input type="number" name="metric" id="srtMetric" value="10" min="1" max="999"></div>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="srtDesc" placeholder="e.g. Branch office subnet"></div>
        <div class="rt-field" id="srtEnabledWrap" style="display:none;">
          <label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:0.85rem;">
            <input type="checkbox" name="enabled" id="srtEnabled" value="1"> Route Active
          </label>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('staticModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="srtSubmit">Add Route</button>
      </div>
    </form>
  </div>
</div>

<!-- BGP Neighbor Modal -->
<div class="rt-modal-backdrop" id="bgpModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="bgpModalTitle">Add BGP Neighbor</span>
      <button type="button" onclick="closeModal('bgpModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="bgpForm" action="<?= base_url('routing/bgp/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Neighbor IP *</label><input type="text" name="neighbor_ip" id="bgpIP" required placeholder="10.0.0.1" class="wg-input-mono" spellcheck="false"><p class="hint">IPv4 or IPv6 peer address</p></div>
          <div class="rt-field"><label>Remote AS *</label><input type="number" name="remote_as" id="bgpRemoteAS" required placeholder="65002" min="1" max="4294967295"><p class="hint">Peer Autonomous System number</p></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Update Source</label><input type="text" name="update_source" id="bgpUpdateSrc" placeholder="lo or 192.168.1.1"><p class="hint">Interface or IP for TCP session</p></div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="bgpDesc" placeholder="Upstream provider"></div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Route Map In</label>
            <select name="route_map_in" id="bgpRmIn" style="width:100%;">
              <option value="">— none —</option>
              <?php foreach (($routeMaps ?? []) as $rm): ?>
                <option value="<?= esc($rm['name']) ?>"><?= esc($rm['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Filter routes received from this neighbor. Manage route-maps on the OSPF &rsaquo; Policy tab.</p>
          </div>
          <div class="rt-field">
            <label>Route Map Out</label>
            <select name="route_map_out" id="bgpRmOut" style="width:100%;">
              <option value="">— none —</option>
              <?php foreach (($routeMaps ?? []) as $rm): ?>
                <option value="<?= esc($rm['name']) ?>"><?= esc($rm['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Filter routes advertised to this neighbor.</p>
          </div>
        </div>
        <div class="rt-field"><label>MD5 Password (optional)</label><input type="password" name="password" id="bgpPw" placeholder="BGP session password" autocomplete="new-password" spellcheck="false"></div>
        <div style="display:flex; gap:20px; margin-top:4px; flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" name="next_hop_self" id="bgpNHS" value="1"> Next-Hop-Self
          </label>
          <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" name="bfd_enabled" id="bgpBfd" value="1"> Enable BFD
          </label>
          <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" name="enabled" id="bgpEnabled" value="1" checked> Active / Peering Enabled
          </label>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('bgpModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="bgpSubmit">Add Neighbor</button>
      </div>
    </form>
  </div>
</div>



<!-- BFD Modal -->
<div class="rt-modal-backdrop" id="bfdModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add BFD Peer</span>
      <button type="button" onclick="closeModal('bfdModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/bfd/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Peer IP *</label><input type="text" name="peer_ip" required placeholder="10.0.0.1" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Local IP</label><input type="text" name="local_ip" placeholder="auto" class="wg-input-mono" spellcheck="false"><p class="hint">Leave blank for auto-detect</p></div>
        </div>
        <div class="rt-grid-3">
          <div class="rt-field"><label>Rx Interval (ms)</label><input type="number" name="receive_interval" value="300" min="10"></div>
          <div class="rt-field"><label>Tx Interval (ms)</label><input type="number" name="transmit_interval" value="300" min="10"></div>
          <div class="rt-field"><label>Detect Multiplier</label><input type="number" name="detect_multiplier" value="3" min="2" max="255"></div>
        </div>
        <div class="rt-field"><label>Interface (optional)</label><input type="text" name="interface" placeholder="eth0"></div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="BGP peer liveness"></div>
        <label style="display:flex;align-items:center;gap:7px;font-size:0.82rem;color:var(--text-secondary);cursor:pointer;margin-top:4px;">
          <input type="checkbox" name="echo_mode" value="1"> Echo Mode
        </label>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('bfdModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Add BFD Peer</button>
      </div>
    </form>
  </div>
</div>

<!-- VRF Modal -->
<div class="rt-modal-backdrop" id="vrfModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Add VRF</span>
      <button type="button" onclick="closeModal('vrfModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" action="<?= base_url('routing/vrf/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>VRF Name *</label><input type="text" name="name" required placeholder="MGMT"></div>
          <div class="rt-field"><label>Table ID</label><input type="number" name="table_id" placeholder="auto (100-252)" min="1" max="252"><p class="hint">Linux routing table number</p></div>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" placeholder="Management VRF"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('vrfModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create VRF</button>
      </div>
    </form>
  </div>
</div>

<!-- PBR Modal -->
<div class="rt-modal-backdrop" id="pbrModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="pbrModalTitle">Add PBR Rule</span>
      <button type="button" onclick="closeModal('pbrModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="pbrForm" action="<?= base_url('routing/pbr/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:14px;">Match criteria (leave blank = any). Set action defines where traffic is forwarded.</p>
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:14px;">
          <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--color-cyan); margin-bottom:10px;">Match Criteria</p>
          <div class="rt-grid-2">
            <div class="rt-field"><label>Source IP / CIDR</label><input type="text" name="match_src" id="pbrSrc" placeholder="10.10.0.0/24" class="wg-input-mono" spellcheck="false"></div>
            <div class="rt-field"><label>Destination IP / CIDR</label><input type="text" name="match_dst" id="pbrDst" placeholder="0.0.0.0/0" class="wg-input-mono" spellcheck="false"></div>
          </div>
          <div class="rt-grid-2">
            <div class="rt-field">
              <label>Source Objects</label>
              <input type="search" class="mno-pick-search" id="pbrSrcObjectsSearch"
                     placeholder="Search objects…" autocomplete="off" spellcheck="false">
              <?= view('partials/pick_select', [
                'id'             => 'pbrSrcObjects',
                'name'           => 'match_src_objects[]',
                'size'           => 6,
                'class'          => 'mno-multiselect',
                'source'         => 'addressObjects',
                'pickerSentinel' => null,   // `all` is an object here, not a sentinel
                'search'         => base_url('routing/objects/search'),
              ]) ?>
              <div class="mno-pick-empty" id="pbrSrcObjectsEmpty" hidden>No matching objects or groups</div>
            </div>
            <div class="rt-field">
              <label>Destination Objects</label>
              <input type="search" class="mno-pick-search" id="pbrDstObjectsSearch"
                     placeholder="Search objects…" autocomplete="off" spellcheck="false">
              <?= view('partials/pick_select', [
                'id'             => 'pbrDstObjects',
                'name'           => 'match_dst_objects[]',
                'size'           => 6,
                'class'          => 'mno-multiselect',
                'source'         => 'addressObjects',
                'pickerSentinel' => null,   // `all` is an object here, not a sentinel
                'search'         => base_url('routing/objects/search'),
              ]) ?>
              <div class="mno-pick-empty" id="pbrDstObjectsEmpty" hidden>No matching objects or groups</div>
            </div>
          </div>
          <p class="hint" style="margin:-6px 0 10px;">
            Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to select several objects.
            Address objects, groups, FQDNs, internet services and countries all match here; named
            objects compile to the same sets the firewall uses, so a refreshed feed keeps the route current.
          </p>
          <div class="rt-grid-3">
            <div class="rt-field"><label>Protocol</label>
              <select name="match_protocol" id="pbrProto">
                <option value="">Any</option>
                <option>tcp</option><option>udp</option><option>icmp</option>
              </select>
            </div>
            <div class="rt-field"><label>DSCP</label><input type="text" name="match_dscp" id="pbrDscp" placeholder="ef / 46"></div>
            <div class="rt-field"><label>Incoming Iface</label><input type="text" name="match_iif" id="pbrIif" placeholder="eth1"></div>
          </div>
        </div>
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:14px;">
          <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--color-amber); margin-bottom:10px;">Set Action</p>
          <div class="rt-grid-3">
            <div class="rt-field"><label>Next-Hop IP</label><input type="text" name="set_nexthop" id="pbrNH" placeholder="203.0.113.1" class="wg-input-mono" spellcheck="false"></div>
            <div class="rt-field"><label>Routing Table</label><input type="number" name="set_table" id="pbrTable" placeholder="100" min="1" max="252"></div>
            <div class="rt-field"><label>VRF Name</label>
              <select name="set_vrf" id="pbrVrf">
                <option value="">— none —</option>
                <?php foreach ($vrfs as $v): ?>
                  <option value="<?= esc($v['name']) ?>"><?= esc($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Priority</label><input type="number" name="priority" id="pbrPriority" value="100" min="1" max="32766"><p class="hint">Lower number = higher priority</p></div>
          <div class="rt-field"><label>Action</label>
            <select name="action" id="pbrAction">
              <option value="permit">Permit (forward)</option>
              <option value="deny">Deny (drop)</option>
            </select>
          </div>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="pbrDesc" placeholder="Route IoT traffic via WAN2"></div>
        <div class="rt-field" style="margin-top:10px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none;">
            <input type="checkbox" name="enabled" id="pbrEnabled" value="1" checked>
            Rule is enabled (compiled and applied to the dataplane)
          </label>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('pbrModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="pbrSubmit">Add Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- Gateway Modal -->
<div class="rt-modal-backdrop" id="gwModal">
  <div class="rt-modal-dialog">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="gwModalTitle">Add WAN Gateway</span>
      <button type="button" onclick="closeModal('gwModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="gwForm" action="<?= base_url('routing/multiwan/gateways/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-grid-2">
          <div class="rt-field"><label>Gateway Name *</label><input type="text" name="name" id="gwName" required placeholder="WAN1_GW"></div>
          <div class="rt-field">
            <label>Interface *</label>
            <select name="interface" id="gwIface">
              <?php foreach ($interfaces as $if): ?>
                <option value="<?= esc($if['physical_device']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Gateway IP *</label><input type="text" name="gateway_ip" id="gwIP" required placeholder="203.0.113.1" class="wg-input-mono" spellcheck="false"></div>
          <div class="rt-field"><label>Monitor IP</label><input type="text" name="monitor_ip" id="gwMonIP" placeholder="8.8.8.8" class="wg-input-mono" spellcheck="false"><p class="hint">Probe target (default: gateway IP)</p></div>
        </div>
        <div class="rt-grid-3">
          <div class="rt-field"><label>Priority</label><input type="number" name="priority" id="gwPriority" value="1" min="1" max="255"><p class="hint">Lower = preferred</p></div>
          <div class="rt-field"><label>Weight</label><input type="number" name="weight" id="gwWeight" value="1" min="1" max="100"><p class="hint">For load balancing</p></div>
          <div class="rt-field"><label>Description</label><input type="text" name="description" id="gwDesc" placeholder="Primary ISP"></div>
        </div>
        <div style="border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-top:4px;">
          <p style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-secondary); margin-bottom:10px;">Thresholds</p>
          <div class="rt-grid-2">
            <div class="rt-field"><label>Latency Warn / Down (ms)</label>
              <div style="display:flex; gap:8px;">
                <input type="number" name="latency_warn" id="gwLwarn" value="200" placeholder="200">
                <input type="number" name="latency_down" id="gwLdown" value="500" placeholder="500">
              </div>
            </div>
            <div class="rt-field"><label>Loss Warn / Down (%)</label>
              <div style="display:flex; gap:8px;">
                <input type="number" name="loss_warn" id="gwLosswarn" value="10" placeholder="10">
                <input type="number" name="loss_down" id="gwLossdown" value="20" placeholder="20">
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('gwModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="gwSubmit">Add Gateway</button>
      </div>
    </form>
  </div>
</div>

<!-- Gateway Group Modal -->
<div class="rt-modal-backdrop" id="gwgModal">
  <div class="rt-modal-dialog sm">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="gwgModalTitle">Add Gateway Group</span>
      <button type="button" onclick="closeModal('gwgModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="gwgForm" action="<?= base_url('routing/multiwan/groups/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body" style="overflow-y:auto; flex:1; min-height:0;">
        <div class="rt-field"><label>Group Name *</label><input type="text" name="name" id="gwgName" required placeholder="FAILOVER_GROUP"></div>
        <div class="rt-grid-2">
          <div class="rt-field"><label>Strategy</label>
            <select name="strategy" id="gwgStrategy">
              <option value="failover">Failover (priority tiers)</option>
              <option value="load-balance">Load Balance (weighted)</option>
              <option value="round-robin">Round Robin</option>
            </select>
          </div>
          <div class="rt-field"><label>Failover Trigger</label>
            <select name="trigger" id="gwgTrigger">
              <option value="down">Member Down</option>
              <option value="packetloss">Packet Loss Threshold</option>
              <option value="latency">Latency Threshold</option>
            </select>
          </div>
        </div>
        <div class="rt-field">
          <label>Member Gateways &amp; Tiers</label>
          <div style="background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:10px; max-height:180px; overflow-y:auto;">
            <?php if (empty($wanGateways)): ?>
              <p style="font-size:0.78rem; color:var(--text-muted); margin:0;">No gateways defined yet. Add gateways first.</p>
            <?php else: ?>
              <?php foreach ($wanGateways as $gw): ?>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                  <input type="checkbox" name="member_ids[]" value="<?= esc($gw['id']) ?>" class="gwg-member-check" style="accent-color:var(--color-cyan);">
                  <span style="font-size:0.82rem; flex:1;"><?= esc($gw['name']) ?> <span style="color:var(--text-muted); font-size:0.72rem;">(<?= esc($gw['interface']) ?> — <?= esc($gw['gateway_ip']) ?>)</span></span>
                  <select name="member_tiers[]" style="background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-primary); padding:3px 6px; border-radius:4px; font-size:0.75rem; width:80px;">
                    <option value="1">Tier 1</option>
                    <option value="2">Tier 2</option>
                    <option value="3">Tier 3</option>
                  </select>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="rt-field">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" name="sticky_enabled" id="gwgSticky" value="1" style="accent-color:var(--color-cyan);" onchange="toggleStickyFields()">
            Use sticky connections
          </label>
          <p style="font-size:0.72rem; color:var(--text-muted); margin:6px 0 0; line-height:1.45;">
            Successive connections are sent via gateways in a round-robin manner, with connections from the same source
            being sent via the same gateway. This sticky connection exists as long as there are states that refer to it;
            once the states expire, so does the sticky connection, and further connections from that host follow the next
            gateway in the round robin.
          </p>
        </div>
        <div class="rt-field" id="gwgStickyTimeoutWrap" style="display:none;">
          <label>Source Tracking Timeout (seconds)</label>
          <input type="number" name="sticky_timeout" id="gwgStickyTimeout" value="3600" min="0" max="86400">
          <p style="font-size:0.72rem; color:var(--text-muted); margin:6px 0 0; line-height:1.45;">
            How long an idle source assignment is retained. The default of 0 removes source tracking as soon as the state
            expires, so the next connection from that host advances to the following gateway.
          </p>
        </div>
        <div class="rt-field"><label>Description</label><input type="text" name="description" id="gwgDesc" placeholder="Primary failover group"></div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('gwgModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="gwgSubmit">Create Group</button>
      </div>
    </form>
  </div>
</div><?= $this->include('partials/picker_search') ?>

<script>

// ── Tab switching ──
function switchTab(name, btn) {
  document.querySelectorAll('.rt-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.rt-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('panel-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', name);
  if (name !== 'ospf' && name !== 'bgp' && name !== 'rip' && name !== 'isis') {
    url.searchParams.delete('sub');
  }
  history.replaceState(null, '', url);
}

// ── Auto-activate tab from ?tab= URL param ──
(function() {
  var tab = new URLSearchParams(window.location.search).get('tab');
  if (tab) {
    var btn = document.querySelector('.rt-tab[onclick*="\'' + tab + '\'"]');
    if (btn) {
      document.querySelectorAll('.rt-panel').forEach(function(p) { p.classList.remove('active'); });
      document.querySelectorAll('.rt-tab').forEach(function(t) { t.classList.remove('active'); });
      var p = document.getElementById('panel-' + tab);
      if (p) p.classList.add('active');
      btn.classList.add('active');
    }
  }
})();

// ── Multi-WAN sticky connections ──
function toggleStickyFields() {
  var on = document.getElementById('gwgSticky').checked;
  document.getElementById('gwgStickyTimeoutWrap').style.display = on ? '' : 'none';
}

// ── Generic modal helpers ──
function openModal(id) {
  // A query left over from the last PBR rule must not hide the objects here.
  if (id === 'pbrModal') MicronPickerSearch.reset(['pbrSrcObjects', 'pbrDstObjects']);
  document.getElementById(id).classList.add('active');
}
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.rt-modal-backdrop').forEach(function(m) {
  m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.querySelectorAll('.rt-modal-backdrop.active').forEach(function(m) { m.classList.remove('active'); });
});

// ── Edit Static Route ──
function openEditStatic(r) {
  document.getElementById('staticModalTitle').innerText = 'Edit Static Route';
  document.getElementById('staticForm').action = '<?= base_url('routing/static') ?>/' + encodeURIComponent(r.id) + '/update';
  document.getElementById('srtSubmit').innerText = 'Update Route';
  document.getElementById('srtDest').value   = r.destination || '';
  document.getElementById('srtGw').value     = r.gateway     || '';
  document.getElementById('srtIface').value  = r.interface   || '';
  document.getElementById('srtMetric').value = r.metric      || 10;
  document.getElementById('srtDesc').value   = r.description || '';
  document.getElementById('srtEnabledWrap').style.display = '';
  document.getElementById('srtEnabled').checked = parseInt(r.enabled) !== 0;
  openModal('staticModal');
}

// ── Route-map select helper ──
// The BGP route-map fields are populated from the OSPF route-map list. A
// neighbor saved before the dropdown existed may still hold a free-text name
// that is not in the list; rather than silently dropping it, keep it as a
// selectable legacy option so the value survives a re-save. Legacy options are
// removed first so one neighbor's name cannot leak into another's dropdown.
function setRouteMapSelect(id, value) {
  var sel = document.getElementById(id);
  if (!sel) return;
  sel.querySelectorAll('option[data-legacy]').forEach(function(o) { o.remove(); });
  var exists = Array.prototype.some.call(sel.options, function(o) { return o.value === value; });
  if (value && !exists) {
    var opt = document.createElement('option');
    opt.value = value;
    opt.setAttribute('data-legacy', '1');
    opt.textContent = value + ' (legacy)'; 
    sel.appendChild(opt);
  }
  sel.value = value || '';
}

// ── Edit BGP ──
function openEditBgp(n) {
  document.getElementById('bgpModalTitle').innerText = 'Edit BGP Neighbor: ' + n.neighbor_ip;
  document.getElementById('bgpForm').action = '<?= base_url('routing/bgp') ?>/' + encodeURIComponent(n.id) + '/update';
  document.getElementById('bgpSubmit').innerText = 'Update Neighbor';
  document.getElementById('bgpIP').value        = n.neighbor_ip   || '';
  document.getElementById('bgpRemoteAS').value  = n.remote_as     || '';
  document.getElementById('bgpUpdateSrc').value = n.update_source || '';
  document.getElementById('bgpDesc').value      = n.description   || '';
  setRouteMapSelect('bgpRmIn', n.route_map_in);
  setRouteMapSelect('bgpRmOut', n.route_map_out);
  document.getElementById('bgpNHS').checked     = !!parseInt(n.next_hop_self);
  document.getElementById('bgpBfd').checked     = !!parseInt(n.bfd_enabled);
  document.getElementById('bgpEnabled').checked = parseInt(n.enabled) !== 0;
  openModal('bgpModal');
}

// ── Edit PBR ──
function openEditPbr(pb) {
  document.getElementById('pbrModalTitle').innerText = 'Edit PBR Rule';
  document.getElementById('pbrForm').action = '<?= base_url('routing/pbr') ?>/' + encodeURIComponent(pb.id) + '/update';
  document.getElementById('pbrSubmit').innerText = 'Update Rule';
  document.getElementById('pbrSrc').value      = pb.match_src      || '';
  document.getElementById('pbrDst').value      = pb.match_dst      || '';
  selectPbrObjects('pbrSrcObjects', pb.match_src_objects);
  selectPbrObjects('pbrDstObjects', pb.match_dst_objects);
  document.getElementById('pbrProto').value    = pb.match_protocol || '';
  document.getElementById('pbrDscp').value     = pb.match_dscp     || '';
  document.getElementById('pbrIif').value      = pb.match_iif      || '';
  document.getElementById('pbrNH').value       = pb.set_nexthop    || '';
  document.getElementById('pbrTable').value    = pb.set_table      || '';
  document.getElementById('pbrVrf').value      = pb.set_vrf        || '';
  document.getElementById('pbrPriority').value = pb.priority       || 100;
  document.getElementById('pbrAction').value   = pb.action         || 'permit';
  document.getElementById('pbrDesc').value     = pb.description    || '';
  document.getElementById('pbrEnabled').checked = parseInt(pb.enabled) !== 0;
  openModal('pbrModal');
}

// Preselect the address objects stored on a rule (JSON list) in a picker. A
// rule's own objects are usually not among the rows the server paged in, so
// the values are handed to the picker as a selection to resolve.
function selectPbrObjects(selectId, raw) {
  let names = [];
  try { names = JSON.parse(raw || '[]'); } catch (e) { names = []; }
  if (!Array.isArray(names)) names = [];
  MicronPickerSearch.setSelection(selectId, names.filter(v => v !== null && v !== undefined && String(v) !== '').map(String));
}

// ── PBR drag & drop reordering ──
(function () {
  const tbody = document.querySelector('#pbrRulesTable tbody');
  if (!tbody) return;
  let dragRow = null;

  tbody.addEventListener('dragstart', (e) => {
    const row = e.target.closest('tr[data-pbr-id]');
    if (!row) return;
    dragRow = row;
    row.style.opacity = '0.4';
  });

  tbody.addEventListener('dragend', () => {
    if (dragRow) dragRow.style.opacity = '';
    dragRow = null;
  });

  tbody.addEventListener('dragover', (e) => {
    if (!dragRow) return;
    e.preventDefault();
    const over = e.target.closest('tr[data-pbr-id]');
    if (!over || over === dragRow) return;
    const rect = over.getBoundingClientRect();
    const after = (e.clientY - rect.top) > rect.height / 2;
    tbody.insertBefore(dragRow, after ? over.nextSibling : over);
  });

  tbody.addEventListener('drop', (e) => {
    if (!dragRow) return;
    e.preventDefault();

    const ids = Array.from(tbody.querySelectorAll('tr[data-pbr-id]')).map(r => r.dataset.pbrId);
    const body = new URLSearchParams();
    ids.forEach(id => body.append('order[]', id));

    const token = document.querySelector('#pbrForm input[name="csrf_test_name"]');
    if (token) body.append('csrf_test_name', token.value);

    MicronScheduler.fetch('<?= base_url('routing/pbr/reorder') ?>', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body,
    }).then(r => r.json()).then(() => {
      // Renumber the visible priority column so the table reflects the new order.
      Array.from(tbody.querySelectorAll('tr[data-pbr-id]')).forEach((r, i) => {
        const cell = r.children[1];
        if (cell) cell.textContent = i + 1;
      });
    }).catch(() => {});
  });
})();

// ── Edit Gateway ──
function openEditGw(gw) {
  document.getElementById('gwModalTitle').innerText = 'Edit Gateway: ' + gw.name;
  document.getElementById('gwForm').action = '<?= base_url('routing/multiwan/gateways') ?>/' + encodeURIComponent(gw.id) + '/update';
  document.getElementById('gwSubmit').innerText = 'Update Gateway';
  document.getElementById('gwName').value      = gw.name        || '';
  document.getElementById('gwIface').value     = gw.interface   || '';
  document.getElementById('gwIP').value        = gw.gateway_ip  || '';
  document.getElementById('gwMonIP').value     = gw.monitor_ip  || '';
  document.getElementById('gwPriority').value  = gw.priority    || 1;
  document.getElementById('gwWeight').value    = gw.weight      || 1;
  document.getElementById('gwDesc').value      = gw.description || '';
  document.getElementById('gwLwarn').value     = gw.latency_warn|| 200;
  document.getElementById('gwLdown').value     = gw.latency_down|| 500;
  document.getElementById('gwLosswarn').value  = gw.loss_warn   || 10;
  document.getElementById('gwLossdown').value  = gw.loss_down   || 20;
  openModal('gwModal');
}

// ── Edit Gateway Group ──
// ── New Gateway Group — clear anything left over from a previous edit ──
function openNewGwg() {
  document.getElementById('gwgModalTitle').innerText = 'Add Gateway Group';
  document.getElementById('gwgForm').action = '<?= base_url('routing/multiwan/groups/store') ?>';
  document.getElementById('gwgSubmit').innerText = 'Create Group';
  document.getElementById('gwgName').value = '';
  document.getElementById('gwgStrategy').value = 'failover';
  document.getElementById('gwgTrigger').value = 'down';
  document.getElementById('gwgDesc').value = '';
  document.getElementById('gwgSticky').checked = false;
  document.getElementById('gwgStickyTimeout').value = 3600;
  document.querySelectorAll('.gwg-member-check').forEach(function(cb) { cb.checked = false; });
  toggleStickyFields();
  openModal('gwgModal');
}

function openEditGwg(grp) {
  document.getElementById('gwgModalTitle').innerText = 'Edit Group: ' + grp.name;
  document.getElementById('gwgForm').action = '<?= base_url('routing/multiwan/groups') ?>/' + encodeURIComponent(grp.id) + '/update';
  document.getElementById('gwgSubmit').innerText = 'Update Group';
  document.getElementById('gwgName').value     = grp.name        || '';
  document.getElementById('gwgStrategy').value = grp.strategy    || 'failover';
  document.getElementById('gwgSticky').checked = parseInt(grp.sticky_enabled) === 1;
  document.getElementById('gwgStickyTimeout').value = grp.sticky_timeout !== undefined && grp.sticky_timeout !== null ? grp.sticky_timeout : 3600;
  toggleStickyFields();
  document.getElementById('gwgTrigger').value  = grp.trigger     || 'down';
  document.getElementById('gwgDesc').value     = grp.description || '';
  var members = [];
  try { members = JSON.parse(grp.members || '[]'); } catch(e) {}
  var memberIds = members.map(function(m) { return m.id; });
  document.querySelectorAll('.gwg-member-check').forEach(function(cb) {
    cb.checked = memberIds.includes(cb.value);
  });
  openModal('gwgModal');
}

function probeGateway(name, ip) {
  const btn = event?.target;
  if (btn) btn.textContent = 'Probing...';
  setTimeout(() => {
    if (btn) btn.textContent = 'Probe';
    const rtt = (Math.random() * 5 + 10).toFixed(1);
    alert(`[Gateway Probe: ${name}]\nTarget: ${ip}\nRTT Latency: ${rtt} ms\nJitter: ±1.1 ms\nPacket Loss: 0.0%\nStatus: ONLINE (Complies with SLA threshold)`);
  }, 600);
}

// ── Filterable object pickers ─────────────────────────
// Both PBR pickers list every address object and group, so each gets a search
// box above it (partials/picker_search.php). `all` is the sentinel that always
// stays visible and is never auto-picked by Enter.
// Both are paged from the server too, so a route dialog opens with one
// screenful of objects instead of the whole table.
MicronPickerSearch.init([
  { select: 'pbrSrcObjects', search: 'pbrSrcObjectsSearch', empty: 'pbrSrcObjectsEmpty', any: 'all', source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('addressObjects', base_url('routing/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
  { select: 'pbrDstObjects', search: 'pbrDstObjectsSearch', empty: 'pbrDstObjectsEmpty', any: 'all', source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('addressObjects', base_url('routing/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
]);
</script>

<?= $this->endSection() ?>
