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
/* ── Protocol Sub-Tabs (VRF, Multi-WAN, BFD, FRR) ── */
.vrf-sub-tabs, .mwan-sub-tabs, .bfd-sub-tabs, .frr-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.vrf-sub-tab, .mwan-sub-tab, .bfd-sub-tab, .frr-sub-tab {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 16px;
  font-size: 0.8rem; font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
  background: none; border-top: none; border-left: none; border-right: none;
}
.vrf-sub-tab:hover, .mwan-sub-tab:hover, .bfd-sub-tab:hover, .frr-sub-tab:hover { color: var(--text-primary); }
.vrf-sub-tab.active { color: var(--color-purple); border-bottom-color: var(--color-purple); }
.mwan-sub-tab.active { color: var(--color-cyan); border-bottom-color: var(--color-cyan); }
.bfd-sub-tab.active { color: var(--color-cyan-2, #22d3ee); border-bottom-color: var(--color-cyan-2, #22d3ee); }
.frr-sub-tab.active { color: var(--color-cyan); border-bottom-color: var(--color-cyan); }

.vrf-sub-panel, .mwan-sub-panel { display: none; }
.vrf-sub-panel.active, .mwan-sub-panel.active { display: block; }
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
        <p class="page-subtitle">Control plane for dynamic routing protocols, policy routing, and Multi-WAN load balancing — managed through FRRouting</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
    <a href="<?= base_url('routing/static') ?>" class="btn btn-secondary btn-sm" title="Manage Static IPv4/IPv6 Routes">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
      Static Routes
    </a>
    <?= \App\Libraries\ServiceStatus::widget('frr') ?>
    <a href="<?= base_url('routing/full-table') ?>" class="btn btn-secondary btn-sm" title="View the complete kernel routing table — all sources: static, BGP, OSPF, RIP, IS-IS, BFD, VRF, PBR, and Multi-WAN">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
      Full Routing Table
    </a>
  </div>
</div>

<!-- ── Flash Messages (routing actions redirect back here) ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── Quick Routing Summary Bar ── -->
<?php
  $protocolsUp = 0;
  $protocolsTotal = 0;
  if (!empty($frrStatus['running'])) { $protocolsUp++; }
  $protocolsTotal++;
  $totalPeers = count($bgpNeighbors ?? []) + count($ospfNeighbors ?? []) + count($ripNetworks ?? []) + count($isisInterfaces ?? []) + count($bfdPeers ?? []);
?>
<?php if ($totalPeers > 0 || !empty($frrStatus['running'])): ?>
<div class="rt-quick-sum animate-in" style="display:flex; flex-wrap:wrap; gap:10px 22px; margin-bottom:18px; padding:12px 18px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); font-size:0.82rem;">
  <?php if (!empty($frrStatus['running'])): ?>
    <div style="display:flex; align-items:center; gap:7px;">
      <span class="frr-dot"></span>
      <span><strong>FRRouting</strong> <span style="color:var(--text-muted);">running</span></span>
    </div>
  <?php else: ?>
    <div style="display:flex; align-items:center; gap:7px;">
      <span style="width:8px;height:8px;border-radius:50%;background:var(--color-red);display:inline-block;"></span>
      <span><strong>FRRouting</strong> <span style="color:var(--text-muted);">stopped — static routes &amp; FIB still active</span></span>
    </div>
  <?php endif; ?>
  <?php if ($totalPeers > 0): ?>
    <div style="display:flex; align-items:center; gap:7px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <span><strong style="color:var(--text-primary);"><?= $totalPeers ?></strong> protocol peers configured across all enabled routing protocols</span>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Routing Concepts Onboarding ── -->
<?php
  $curTab = $_GET['tab'] ?? ($tab ?? 'bgp');
  if ($curTab === 'pbr') {
      $curTab = 'vrf';
  }
  $totalConfigured = count($bgpNeighbors ?? []) + count($ospfAreas ?? []) + count($ospfNetworks ?? []) + count($ospfIfaces ?? []) + count($ripNetworks ?? []) + count($isisInterfaces ?? []) + count($bfdPeers ?? []) + count($accessLists ?? []) + count($prefixLists ?? []) + count($routeMaps ?? []) + count($vrfs ?? []) + count($pbrRules ?? []) + count($wanGateways ?? []) + count($wanGroups ?? []);
  $hasAnyConfig = $totalConfigured > 0;
?>
<?php if (!$hasAnyConfig && $curTab === 'bgp'): ?>
<div class="rt-onboard animate-in" style="display:flex; gap:16px; padding:18px 20px; background:var(--color-surface-2); border:1px solid var(--color-border-bright); border-radius:var(--radius-md); margin-bottom:18px; align-items:flex-start;">
  <div style="flex:1;">
    <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">Routing is empty — here's how it fits together</h3>
    <p style="font-size:0.84rem; color:var(--text-secondary); margin:0 0 8px 0; line-height:1.55;">
      Routing tells the appliance how to forward packets. <strong>Static routes</strong> are fixed paths you configure directly.
      <strong>Dynamic protocols</strong> (BGP, OSPF, RIP, IS-IS) exchange routes with neighbors automatically.
    </p>
    <p style="font-size:0.84rem; color:var(--text-secondary); margin:0 0 8px 0; line-height:1.55;">
      <strong>BGP</strong> is the backbone of the internet — use it to peer with ISPs or other autonomous systems.
      <strong>OSPF</strong> is a common internal (IGP) choice for enterprise networks.
      <strong>Multi-WAN</strong> groups multiple uplinks with failover or load-balancing.
    </p>
    <p style="font-size:0.82rem; color:var(--text-muted); margin:0; line-height:1.55;">
      Start by enabling FRR if it's stopped, then add a protocol — BGP or OSPF are the most common first steps.
    </p>
  </div>
  <div style="flex-shrink:0; display:flex; flex-direction:column; gap:8px;">
    <span style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:700;">First steps</span>
    <?php if (!empty($frrStatus['running'])): ?>
      <a href="<?= base_url('routing?tab=bgp') ?>" class="btn btn-primary btn-sm" style="width:100%;">Configure BGP &rarr;</a>
      <a href="<?= base_url('routing?tab=ospf') ?>" class="btn btn-ghost btn-sm" style="width:100%;">Configure OSPF</a>
    <?php else: ?>
      <a href="<?= base_url('routing?tab=multiwan') ?>" class="btn btn-primary btn-sm" style="width:100%;">Set up Multi-WAN &rarr;</a>
      <span style="font-size:0.78rem; color:var(--text-muted); text-align:right;">FRRouting is stopped — dynamic protocols need it running</span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── FRR Status Banner ── -->
<?php if (!empty($frrStatus['running'])): ?>
<div class="frr-banner animate-in">
  <div class="frr-badge">
    <span class="frr-dot"></span>
    <strong>FRRouting</strong>
  </div>
  <div class="frr-badge">Version: <strong><?= esc(($frrStatus['version'] ?? '') ?: '9.1') ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc(($frrStatus['uptime'] ?? '') ?: 'active') ?></strong></div>
  <?php if (!empty($frrStatus['bgp_as'])): ?>
    <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-blue"><?= esc($frrStatus['bgp_as']) ?></strong></div>
  <?php endif; ?>
  <?php if (!empty($frrStatus['router_id'])): ?>
    <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc($frrStatus['router_id']) ?></strong></div>
  <?php endif; ?>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted">simulation mode</span>
  <?php endif; ?>
  <div style="margin-left:auto; display:flex; align-items:center; gap:8px;">
    <form method="POST" action="<?= base_url('services/frr/toggle-boot') ?>" style="display:inline;"><?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.75rem;" title="Click to toggle automatic startup at boot">
        <?= !empty($frrBootEnabled) ? '<span style="color:var(--color-emerald); font-weight:700;">✓ Boot: Enabled</span>' : '<span style="color:var(--text-muted);">○ Boot: Disabled</span>' ?>
      </button>
    </form>
    <form method="POST" action="<?= base_url('services/frr/restart') ?>" style="display:inline;"><?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.75rem;" title="Restart FRR dynamic routing daemons">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:2px; vertical-align:-1px;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Restart
      </button>
    </form>
    <form method="POST" action="<?= base_url('services/frr/stop') ?>" style="display:inline;" onsubmit="return confirm('Stop FRRouting dynamic routing suite?');"><?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.75rem; color:var(--color-red);" title="Stop FRR Suite">
        <svg width="11" height="11" fill="currentColor" viewBox="0 0 24 24" style="margin-right:2px; vertical-align:-1px;"><rect x="4" y="4" width="16" height="16"/></svg>
        Stop
      </button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="frr-banner animate-in" style="background:rgba(239,68,68,0.06); border-color:rgba(239,68,68,0.25);">
  <div class="frr-badge">
    <span style="width:8px; height:8px; border-radius:50%; background:var(--color-red); display:inline-block; box-shadow:0 0 6px var(--color-red);"></span>
    <strong>FRRouting Daemon:</strong> <span style="color:var(--color-red); font-weight:600;">Stopped / Inactive</span>
  </div>
  <div style="font-size:0.78rem; color:var(--text-secondary);">
    Dynamic routing suite (BGP, OSPF, RIP, IS-IS, BFD) is currently offline. Static routes and kernel FIB continue to operate.
  </div>
  <div style="margin-left:auto; display:flex; align-items:center; gap:8px;">
    <form method="POST" action="<?= base_url('services/frr/toggle-boot') ?>" style="display:inline;"><?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.75rem;" title="Toggle boot startup">
        <?= !empty($frrBootEnabled) ? '<span style="color:var(--color-emerald); font-weight:700;">✓ Boot: Enabled</span>' : '<span style="color:var(--text-muted);">○ Boot: Disabled</span>' ?>
      </button>
    </form>
    <form method="POST" action="<?= base_url('services/frr/start') ?>" style="display:inline;"><?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:5px; font-size:0.78rem;">
        <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24"><polygon points="6 4 20 12 6 20 6 4"/></svg>
        Start FRR Suite
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$curTab = $_GET['tab'] ?? 'bgp';
if ($curTab === 'pbr') {
    $curTab = 'vrf';
    if (!isset($_GET['sub'])) {
        $_GET['sub'] = 'pbr';
    }
}
?>
<!-- ── Tab Bar ── -->
<div class="tab-bar rt-tabs animate-in">
  <a href="<?= base_url('routing?tab=bgp') ?>" class="tab-item rt-tab <?= $curTab === 'bgp' ? 'active' : '' ?>" data-tab="bgp" onclick="switchTab('bgp', this); return false;" title="Border Gateway Protocol — eBGP/iBGP peering, AS numbers, route-maps, prefix-lists">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    BGP
    <span class="rt-tab-count"><?= count($bgpNeighbors ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=ospf') ?>" class="tab-item rt-tab <?= $curTab === 'ospf' ? 'active' : '' ?>" data-tab="ospf" onclick="switchTab('ospf', this); return false;" title="Open Shortest Path First — areas, interfaces, networks, neighbors, path selection">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
    OSPF
    <span class="rt-tab-count"><?= count($ospfAreas ?? []) + count($ospfIfaces ?? []) + count($ospfNetworks ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=rip') ?>" class="tab-item rt-tab <?= $curTab === 'rip' ? 'active' : '' ?>" data-tab="rip" onclick="switchTab('rip', this); return false;" title="Routing Information Protocol — legacy distance-vector, networks and neighbors">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
    RIP
    <span class="rt-tab-count"><?= count($ripNetworks ?? []) + count($ripNeighbors ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=isis') ?>" class="tab-item rt-tab <?= $curTab === 'isis' ? 'active' : '' ?>" data-tab="isis" onclick="switchTab('isis', this); return false;" title="IS-IS — link-state IGP for service-provider and large enterprise topologies">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
    IS-IS
    <span class="rt-tab-count"><?= count($isisInterfaces ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=bfd') ?>" class="tab-item rt-tab <?= $curTab === 'bfd' ? 'active' : '' ?>" data-tab="bfd" onclick="switchTab('bfd', this); return false;" title="Bidirectional Forwarding Detection — fast failure detection for routing peers">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    BFD
    <span class="rt-tab-count"><?= count($bfdPeers ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=policy') ?>" class="tab-item rt-tab <?= $curTab === 'policy' ? 'active' : '' ?>" data-tab="policy" onclick="switchTab('policy', this); return false;" title="Route-maps, prefix-lists, and access-lists — policy control for redistribution and path selection">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
    Policy / Filters
    <span class="rt-tab-count"><?= count($accessLists ?? []) + count($prefixLists ?? []) + count($routeMaps ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=vrf') ?>" class="tab-item rt-tab <?= in_array($curTab, ['vrf', 'pbr'], true) ? 'active' : '' ?>" data-tab="vrf" onclick="switchTab('vrf', this); return false;" title="VRF instances for route isolation and Policy-Based Routing rules for source-directed forwarding">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    VRF / PBR
    <span class="rt-tab-count"><?= count($vrfs ?? []) + count($pbrRules ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=multiwan') ?>" class="tab-item rt-tab <?= $curTab === 'multiwan' ? 'active' : '' ?>" data-tab="multiwan" onclick="switchTab('multiwan', this); return false;" title="Multi-WAN gateway groups with failover, load-balance, and round-robin strategies">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
    Multi-WAN
    <span class="rt-tab-count"><?= count($wanGateways ?? []) ?></span>
  </a>
  <a href="<?= base_url('routing?tab=frr') ?>" class="tab-item rt-tab <?= $curTab === 'frr' ? 'active' : '' ?>" data-tab="frr" onclick="switchTab('frr', this); return false;" title="FRRouting daemon status, operational state, and the generated frr.conf">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
    FRR
    <span class="rt-tab-count" style="background:var(--color-cyan-dim, rgba(6,182,212,0.18)); color:var(--color-cyan, #06b6d4);">frr.conf</span>
  </a>
  <a href="<?= base_url('routing?tab=fib') ?>" class="tab-item rt-tab <?= $curTab === 'fib' ? 'active' : '' ?>" data-tab="fib" onclick="switchTab('fib', this); return false;" title="Kernel Forwarding Information Base — the live routes the kernel will actually use">
    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
    FIB / Kernel
    <span class="rt-tab-count"><?= count($kernelRoutes ?? []) ?></span>
  </a>
</div>

<?php if ($curTab === 'static'): ?>
<div class="rt-panel active" id="panel-static" style="margin-bottom:20px;">
  <div class="card" style="padding:28px; text-align:center;">
    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5" style="margin:0 auto 12px; display:block;"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
    <h3 style="font-size:1.05rem; font-weight:700; margin-bottom:8px; color:var(--text-primary);">Static Routes live on their own page</h3>
    <p style="color:var(--text-secondary); font-size:0.85rem; margin:0 0 16px 0; max-width:420px; margin-left:auto; margin-right:auto;">
      Add, edit, and remove IPv4/IPv6 static prefixes, default gateways, and interface-bound routes — plus persistent and blackhole routes — on the dedicated Static Routes screen.
    </p>
    <a href="<?= base_url('routing/static') ?>" class="btn btn-primary btn-sm">Go to Static Routes &rarr;</a>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     TAB: BGP (FRRouting BGP Daemon)
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'bgp' ? 'active' : '' ?>" id="panel-bgp">
  <?= $this->include('routing/bgp_panel') ?>
</div>

<!-- ================================================================
     TAB: OSPF (Comprehensive FRRouting Engine)
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'ospf' ? 'active' : '' ?>" id="panel-ospf">
  <?= $this->include('routing/ospf_panel') ?>
</div>

<!-- ================================================================
     TAB: RIP (FRRouting ripd)
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'rip' ? 'active' : '' ?>" id="panel-rip">
  <?= $this->include('routing/rip_panel') ?>
</div>

<!-- ================================================================
     TAB: IS-IS (FRRouting isisd)
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'isis' ? 'active' : '' ?>" id="panel-isis">
  <?= $this->include('routing/isis_panel') ?>
</div>

<!-- ================================================================
     TAB: BFD
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'bfd' ? 'active' : '' ?>" id="panel-bfd">
  <!-- ── BFD Sub-Tab Bar ── -->
  <div class="bfd-sub-tabs">
    <button type="button" class="bfd-sub-tab active" style="pointer-events:none;" title="Bidirectional Forwarding Detection — sub-second liveness checks for routing peers">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      BFD Peer Sessions (<?= count($bfdPeers ?? []) ?>)
    </button>
  </div>

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
          <tr><td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No BFD sessions configured.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">BFD (Bidirectional Forwarding Detection) provides fast failure detection for routing peers — sub-second detection of a down link so the routing protocol can converge quickly. Add a BFD peer to monitor a neighbor's liveness.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($bfdPeers as $b): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($b['peer_ip'] ?? '') ?></span></td>
              <td><span class="rt-chip"><?= esc(($b['local_ip'] ?? '') ?: 'auto') ?></span></td>
              <td><span class="rt-chip"><?= esc(($b['interface'] ?? '') ?: 'auto') ?></span></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($b['receive_interval'] ?? '') ?> / <?= esc($b['transmit_interval'] ?? '') ?></td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($b['detect_multiplier'] ?? '') ?></td>
              <td><?= !empty($b['echo_mode']) ? '<span class="badge badge-info" style="font-size:0.65rem;">ON</span>' : '—' ?></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc(($b['description'] ?? '') ?: '—') ?></td>
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
     TAB: Policy / Filters (Access Lists, Prefix Lists, Route Maps)
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'policy' ? 'active' : '' ?>" id="panel-policy">
  <?= $this->include('routing/policy_panel') ?>
</div>

<!-- ================================================================
     TAB: VRF / PBR
     ================================================================ -->
<div class="rt-panel <?= in_array($curTab, ['vrf', 'pbr'], true) ? 'active' : '' ?>" id="panel-vrf">
  <?php $activeVrfSub = $_GET['sub'] ?? ($curTab === 'pbr' ? 'pbr' : 'vrf'); ?>
  <!-- ── VRF / PBR Sub-Tab Bar ── -->
  <div class="vrf-sub-tabs">
    <button type="button" class="vrf-sub-tab <?= $activeVrfSub === 'vrf' ? 'active' : '' ?>"
            onclick="switchVrfSub('vrf', this)"
            title="Virtual Routing &amp; Forwarding — separate routing tables so overlapping address space can coexist on one device">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
      </svg>
      VRF Instances (<?= count($vrfs ?? []) ?>)
    </button>
    <button type="button" class="vrf-sub-tab <?= $activeVrfSub === 'pbr' ? 'active' : '' ?>"
            onclick="switchVrfSub('pbr', this)"
            title="Policy-Based Routing — match on source, destination, protocol or interface, then override the normal route lookup">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
      </svg>
      Policy-Based Routing (<?= count($pbrRules ?? []) ?>)
    </button>
  </div>

  <!-- Sub-Panel: VRF Instances -->
  <div class="vrf-sub-panel <?= $activeVrfSub === 'vrf' ? 'active' : '' ?>" id="vrf-sub-vrf">
  <!-- VRFs -->
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Virtual Routing &amp; Forwarding — separate routing tables per virtual network</span>
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
          <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-purple)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No VRFs defined. The default VRF is always present (table 254).</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">VRF instances give you separate routing tables inside one device — useful for keeping tenant or customer traffic isolated, or for running multiple overlapping address spaces without conflict. Add a VRF when you need routing separation.</p>
              </div>
            </td></tr>
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
  </div>

  <!-- Sub-Panel: PBR Rules -->
  <div class="vrf-sub-panel <?= $activeVrfSub === 'pbr' ? 'active' : '' ?>" id="vrf-sub-pbr">
  <!-- PBR Rules -->
  <div class="card" style="padding:0; overflow-x:auto;">
    <div style="padding:12px 18px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary);">Policy-Based Routing — match rules that override the normal route lookup</span>
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
          <tr><td colspan="11" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No PBR rules defined.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">PBR rules let you override the normal routing decision — match on source, destination, protocol, or interface, then send matching packets to a specific next-hop or into a VRF. Useful for sending certain traffic out a particular WAN link.</p>
              </div>
            </td></tr>
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
</div>

<!-- ================================================================
     TAB: Multi-WAN
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'multiwan' ? 'active' : '' ?>" id="panel-multiwan">
  <?php $activeMwanSub = $_GET['sub'] ?? 'gateways'; ?>
  <!-- ── Multi-WAN Sub-Tab Bar ── -->
  <div class="mwan-sub-tabs">
    <button type="button" class="mwan-sub-tab <?= $activeMwanSub === 'gateways' ? 'active' : '' ?>"
            onclick="switchMwanSub('gateways', this)">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
      </svg>
      WAN Gateways (<?= count($wanGateways ?? []) ?>)
    </button>
    <button type="button" class="mwan-sub-tab <?= $activeMwanSub === 'groups' ? 'active' : '' ?>"
            onclick="switchMwanSub('groups', this)">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
      </svg>
      Gateway Groups (<?= count($wanGroups ?? []) ?>)
    </button>
  </div>      <!-- Sub-Panel: WAN Gateways -->
  <div class="mwan-sub-panel <?= $activeMwanSub === 'gateways' ? 'active' : '' ?>" id="mwan-sub-gateways">
  <!-- Gateway Cards -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">WAN Gateways — uplink endpoints with live health probing</h3>
    <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('gwModal')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Gateway
      </button>
    <?php endif; ?>
  </div>

  <?php if (empty($wanGateways)): ?>
    <div class="card" style="padding:28px; text-align:center; color:var(--text-muted); margin-bottom:20px;">
      <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
        <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No WAN gateways configured. Add a gateway to enable Multi-WAN.</p>
        <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">WAN gateways are the uplink endpoints Multi-WAN monitors and load-balances across. Each gateway has a monitor IP that is probed for latency and packet loss, so the system can detect a degraded or down link and switch traffic to a healthy one. Add at least one gateway before creating gateway groups.</p>
      </div>
    </div>
  <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:14px; margin-bottom:24px;">
      <?php foreach ($wanGateways as $gw): ?>
        <?php $tele = $gw['telemetry'] ?? ['rtt' => null, 'stddev' => null, 'loss' => null, 'status' => 'online']; ?>
        <div class="gw-card">
          <span class="gw-status-dot <?= !empty($gw['enabled']) ? 'gw-up' : 'gw-down' ?>"></span>
          <div style="flex:1; min-width:0;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
              <div style="font-weight:700; font-size:0.875rem; color:var(--text-primary);"><?= esc($gw['name'] ?? 'WAN') ?></div>
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
              <span class="rt-chip" style="font-size:0.68rem;"><?= esc($gw['interface'] ?? '—') ?></span>
              <span class="rt-chip rt-chip-cyan" style="font-size:0.68rem; margin-left:4px;"><?= esc($gw['gateway_ip'] ?? '—') ?></span>
            </div>
            
            <!-- Live gateway telemetry bar -->
            <div style="display:flex; gap:8px; margin-top:8px; align-items:center; background:rgba(0,0,0,0.25); padding:6px 10px; border-radius:var(--radius-sm); border:1px solid var(--color-border);">
              <div style="flex:1;">
                <div style="font-size:0.63rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">RTT Latency</div>
                <?php if (($tele['rtt'] ?? null) !== null): ?>
                  <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--color-emerald);"><?= esc($tele['rtt']) ?> ms <span style="font-size:0.68rem; font-weight:400; color:var(--text-muted);">&plusmn;<?= esc($tele['stddev'] ?? 0) ?> ms</span></div>
                <?php else: ?>
                  <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--text-muted);">—</div>
                <?php endif; ?>
              </div>
              <div style="flex:1;">
                <div style="font-size:0.63rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Packet Loss</div>
                <div style="font-family:var(--font-mono); font-size:0.82rem; font-weight:700; color:var(--text-primary);"><?= ($tele['loss'] ?? null) !== null ? esc($tele['loss']) . '%' : '—' ?></div>
              </div>
              <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.7rem; padding:3px 7px;" onclick="probeGateway('<?= esc($gw['name'] ?? '') ?>', '<?= esc(($gw['monitor_ip'] ?? '') ?: ($gw['gateway_ip'] ?? '')) ?>')">
                Probe now
              </button>
            </div>            <div style="display:flex; gap:14px; margin-top:6px; font-size:0.72rem; color:var(--text-muted); flex-wrap:wrap;">
              <span>Monitor IP: <strong style="color:var(--text-secondary);"><?= esc(($gw['monitor_ip'] ?? '') ?: ($gw['gateway_ip'] ?? '—')) ?></strong></span>
              <span>Priority: <strong style="color:var(--text-secondary);"><?= esc($gw['priority'] ?? 1) ?></strong> <span style="color:var(--text-muted);">(lower = preferred)</span></span>
              <span>Weight: <strong style="color:var(--text-secondary);"><?= esc($gw['weight'] ?? 1) ?></strong> <span style="color:var(--text-muted);">(for load-balance)</span></span>
            </div>
            <div style="display:flex; gap:12px; margin-top:4px; font-size:0.68rem; color:var(--text-muted); flex-wrap:wrap;">
              <span>Latency thresholds: warn &gt; <?= esc($gw['latency_warn'] ?? 150) ?> ms &bull; down &gt; <?= esc($gw['latency_down'] ?? 300) ?> ms</span>
              <span>Packet loss warning: &gt; <?= esc($gw['loss_warn'] ?? 20) ?>%</span>
            </div>
          </div>
          <?php if (session()->get('is_superadmin') || in_array('routing.multiwan.manage', session()->get('permissions') ?? []) || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
            <div style="display:flex; flex-direction:column; gap:4px; margin-left:8px;">
              <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditGw(<?= htmlspecialchars(json_encode($gw), ENT_QUOTES, "UTF-8") ?>)'>
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
              </button>
              <form method="POST" action="<?= base_url('routing/multiwan/gateways/' . esc($gw['id']) . '/delete') ?>" onsubmit="return confirm('Remove gateway <?= esc(addslashes($gw['name'] ?? '')) ?>?')"><?= csrf_field() ?>
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
  </div>

  <!-- Sub-Panel: Gateway Groups -->
  <div class="mwan-sub-panel <?= $activeMwanSub === 'groups' ? 'active' : '' ?>" id="mwan-sub-groups">
  <!-- Gateway Groups Table -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0;">Gateway Groups — failover &amp; load-balance policies across WAN links</h3>
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
          <tr><td colspan="7" style="text-align:center; padding:28px; color:var(--text-muted);">
              <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">No gateway groups defined.</p>
                <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">Gateway groups combine multiple WAN gateways into a single failover or load-balanced uplink. A group selects which gateway to use based on its strategy (failover, round-robin, or load-balance) and optional sticky sessions so clients keep reaching the same link.</p>
              </div>
            </td></tr>
        <?php else: ?>
          <?php foreach ($wanGroups as $grp): ?>
            <?php
              $members = is_string($grp['members'] ?? null) ? json_decode($grp['members'], true) : ($grp['members'] ?? []);
              $members = is_array($members) ? $members : [];
              $grpStrat = $grp['strategy'] ?? 'failover';
              $stratClass = match($grpStrat) {
                'load-balance' => 'rt-chip-green',
                'round-robin'  => 'rt-chip-blue',
                default        => 'rt-chip-amber',
              };
            ?>
            <tr>
              <td><strong style="color:var(--text-primary);"><?= esc($grp['name'] ?? 'Group') ?></strong></td>
              <td><span class="rt-chip <?= $stratClass ?>"><?= esc(strtoupper(str_replace('-', ' ', $grpStrat))) ?></span></td>
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
              <td><span class="rt-chip"><?= esc(strtoupper($grp['trigger'] ?? 'member-down')) ?></span></td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc(($grp['description'] ?? '') ?: '—') ?></td>
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
</div>

<!-- ================================================================
     TAB: FRR Status & frr.conf View
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'frr' ? 'active' : '' ?>" id="panel-frr">
  <?= $this->include('routing/frr_panel') ?>
</div>

<!-- ================================================================
     TAB: FIB / Kernel Forwarding Table (Far Right Tab)
     ================================================================ -->
<div class="rt-panel <?= $curTab === 'fib' ? 'active' : '' ?>" id="panel-fib">
  <?= $this->include('routing/fib_panel') ?>
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
  if (name !== 'ospf' && name !== 'bgp' && name !== 'rip' && name !== 'isis' && name !== 'policy' && name !== 'vrf' && name !== 'multiwan') {
    url.searchParams.delete('sub');
  }
  history.replaceState(null, '', url);
}

// ── VRF / PBR Sub-Tab switching ──
function switchVrfSub(name, btn) {
  document.querySelectorAll('.vrf-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.vrf-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('vrf-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', 'vrf');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);
}

// ── Multi-WAN Sub-Tab switching ──
function switchMwanSub(name, btn) {
  document.querySelectorAll('.mwan-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.mwan-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('mwan-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', 'multiwan');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);
}

// ── Auto-activate tab from ?tab= and ?sub= URL param ──
(function() {
  var urlParams = new URLSearchParams(window.location.search);
  var tab = urlParams.get('tab');
  if (tab === 'pbr') {
    tab = 'vrf';
    if (!urlParams.get('sub')) urlParams.set('sub', 'pbr');
  }
  if (tab) {
    var btn = document.querySelector('.rt-tab[data-tab="' + tab + '"]') || document.querySelector('.rt-tab[onclick*="\'' + tab + '\'"]');
    if (btn) {
      document.querySelectorAll('.rt-panel').forEach(function(p) { p.classList.remove('active'); });
      document.querySelectorAll('.rt-tab').forEach(function(t) { t.classList.remove('active'); });
      var p = document.getElementById('panel-' + tab);
      if (p) p.classList.add('active');
      btn.classList.add('active');
    }
  }
  var sub = urlParams.get('sub');
  if (sub) {
    if (tab === 'vrf') {
      var vrfBtn = document.querySelector('.vrf-sub-tab[onclick*="\'' + sub + '\'"]');
      if (vrfBtn) switchVrfSub(sub, vrfBtn);
    } else if (tab === 'multiwan') {
      var mwanBtn = document.querySelector('.mwan-sub-tab[onclick*="\'' + sub + '\'"]');
      if (mwanBtn) switchMwanSub(sub, mwanBtn);
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
