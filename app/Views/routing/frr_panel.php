<?php
/**
 * FRR Panel — Included by routing/index.php
 *
 * Detailed FRR Configuration Status with frr.conf file view.
 */

$isFrrRunning = !empty($frrStatus['running']);
$frrLines = explode("\n", trim($frrConf ?? ''));
$lineCount = count($frrLines);
$charCount = strlen($frrConf ?? '');

// Active daemons summary
$activeProtocols = [];
if (!empty($ospfSettings['enabled']))   $activeProtocols[] = 'OSPFv2';
if (!empty($bgpSettings['enabled']))    $activeProtocols[] = 'BGP';
if (!empty($ripSettings['enabled']))    $activeProtocols[] = 'RIPv2';
if (!empty($isisSettings['enabled']))   $activeProtocols[] = 'IS-IS';
if (!empty($bfdPeers))                  $activeProtocols[] = 'BFD';
$activeCount = count($activeProtocols);
?>

<style>
/* ── FRR Panel Custom Styles ── */
.frr-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.frr-stat-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: relative;
  overflow: hidden;
  transition: border-color var(--transition-fast), transform var(--transition-fast);
}
.frr-stat-card:hover {
  border-color: var(--color-border-bright);
  transform: translateY(-1px);
}

.frr-stat-hdr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.frr-stat-title {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
}

.frr-stat-val {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.frr-stat-desc {
  font-size: 0.72rem;
  color: var(--text-secondary);
  line-height: 1.4;
}

/* Daemon Matrix */
.frr-daemon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.frr-daemon-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: all var(--transition-fast);
}
.frr-daemon-card:hover {
  border-color: var(--color-border-bright);
  box-shadow: var(--shadow-sm);
}

.frr-daemon-card-hdr {
  padding: 12px 18px;
  background: var(--color-surface-3);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.frr-daemon-name {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}

.frr-daemon-body {
  padding: 14px 18px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.frr-kv-list {
  display: grid;
  grid-template-columns: 110px 1fr;
  row-gap: 6px;
  column-gap: 12px;
  font-size: 0.75rem;
}
.frr-kv-key {
  color: var(--text-muted);
  font-weight: 500;
}
.frr-kv-val {
  color: var(--text-primary);
  font-family: var(--font-mono);
  word-break: break-all;
}

/* Conf file editor / viewer card */
.frr-conf-card {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  margin-bottom: 24px;
}

.frr-conf-toolbar {
  padding: 12px 18px;
  background: var(--color-surface-2);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.frr-conf-meta {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.frr-conf-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.frr-code-viewer {
  display: flex;
  background: #0d1117;
  color: #c9d1d9;
  font-family: var(--font-mono);
  font-size: 0.8rem;
  line-height: 1.6;
  max-height: 560px;
  overflow-y: auto;
  border-radius: 0;
}

.frr-gutter {
  padding: 16px 12px;
  background: #080c10;
  border-right: 1px solid #21262d;
  color: #484f58;
  text-align: right;
  user-select: none;
  font-size: 0.74rem;
  line-height: 1.6;
  flex-shrink: 0;
}

.frr-code-content {
  padding: 16px 20px;
  margin: 0;
  overflow-x: auto;
  flex: 1;
  white-space: pre;
  tab-size: 2;
}

.frr-line-comment { color: #8b949e; font-style: italic; }
.frr-line-keyword { color: #ff7b72; font-weight: 600; }
.frr-line-proto   { color: #79c0ff; font-weight: 600; }
.frr-line-net     { color: #7ee787; }
</style>

<!-- ── FRR Sub-Tab Bar ── -->
<div class="frr-sub-tabs">
  <button type="button" class="frr-sub-tab active" style="pointer-events:none;">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
    </svg>
    frr.conf Active Configuration
  </button>
</div>

<!-- ── FRR Status Header & Control Actions ── -->
<div class="card" style="padding:16px 20px; margin-bottom:20px; background:var(--color-surface-2); border-color:var(--color-border);">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="display:inline-flex; width:10px; height:10px; border-radius:50%; background:<?= $isFrrRunning ? 'var(--color-emerald)' : 'var(--color-red)' ?>; box-shadow:0 0 8px <?= $isFrrRunning ? 'var(--color-emerald)' : 'var(--color-red)' ?>;"></span>
        <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-primary); margin:0;">Detailed FRR Configuration Status</h2>
        <span class="badge <?= $isFrrRunning ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.7rem;">
          <?= $isFrrRunning ? 'Daemon Active' : 'Stopped' ?>
        </span>
      </div>
      <p style="font-size:0.78rem; color:var(--text-secondary); margin:4px 0 0 20px;">
        FRRouting dynamic routing suite status, sub-daemon telemetry, compiled configuration, and live Netlink FIB sync.
      </p>
    </div>

    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <a href="<?= base_url('routing/frr/download') ?>" class="btn btn-secondary btn-sm" title="Download frr.conf configuration file">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Download frr.conf
      </a>

      <button type="button" class="btn btn-secondary btn-sm" onclick="copyFrrConfig()" id="btnCopyFrrTop">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        Copy Config
      </button>

      <?php if (session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? [])): ?>
        <form method="POST" action="<?= base_url('routing/frr/reload') ?>" style="display:inline;"><?= csrf_field() ?>
          <button type="submit" class="btn btn-primary btn-sm" title="Recompile frr.conf and reload FRRouting daemons">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Reload FRR
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── FRR Telemetry Cards ── -->
<div class="frr-dashboard-grid">
  <!-- Card 1: Daemon Health -->
  <div class="frr-stat-card">
    <div class="frr-stat-hdr">
      <span class="frr-stat-title">Suite Runtime</span>
      <span class="badge <?= $isFrrRunning ? 'badge-success' : 'badge-danger' ?>">
        <?= $isFrrRunning ? 'ONLINE' : 'OFFLINE' ?>
      </span>
    </div>
    <div class="frr-stat-val">
      <?= esc(($frrStatus['uptime'] ?? '') ?: ($isFrrRunning ? 'Active' : '—')) ?>
    </div>
    <div class="frr-stat-desc">
      Systemd service: <strong style="color:var(--text-primary); font-family:var(--font-mono);">frr.service</strong>
      <?php if (!empty($frrBootEnabled)): ?>
        <span style="color:var(--color-emerald); font-weight:600;">(auto-start at boot)</span>
      <?php else: ?>
        <span style="color:var(--text-muted);">(boot startup off)</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Card 2: Version & Architecture -->
  <div class="frr-stat-card">
    <div class="frr-stat-hdr">
      <span class="frr-stat-title">FRR Engine</span>
      <span class="badge badge-info" style="font-size:0.68rem;">Debian 13 Trixie</span>
    </div>
    <div class="frr-stat-val">
      <?= esc(($frrStatus['version'] ?? '') ?: 'FRRouting 9.1') ?>
    </div>
    <div class="frr-stat-desc">
      Integrated configuration via <strong style="color:var(--text-primary); font-family:var(--font-mono);">vtysh</strong> shell
    </div>
  </div>

  <!-- Card 3: Router Identity -->
  <div class="frr-stat-card">
    <div class="frr-stat-hdr">
      <span class="frr-stat-title">Router Identity</span>
      <span class="badge badge-muted" style="font-size:0.68rem;">BGP / OSPF</span>
    </div>
    <div class="frr-stat-val" style="font-family:var(--font-mono); font-size:1.15rem;">
      <?= esc(($frrStatus['router_id'] ?? '') ?: ($ospfSettings['router_id'] ?? 'Automatic / Loopback')) ?>
    </div>
    <div class="frr-stat-desc">
      Local Autonomous System: <strong class="rt-chip rt-chip-blue" style="font-size:0.75rem;"><?= esc(($frrStatus['bgp_as'] ?? '') ?: ($bgpSettings['local_as'] ?? 'None')) ?></strong>
    </div>
  </div>

  <!-- Card 4: Compiled Ruleset -->
  <div class="frr-stat-card">
    <div class="frr-stat-hdr">
      <span class="frr-stat-title">Configuration Size</span>
      <span class="badge badge-success" style="font-size:0.68rem;"><?= $lineCount ?> lines</span>
    </div>
    <div class="frr-stat-val">
      <?= number_format($charCount) ?> <span style="font-size:0.8rem; font-weight:500; color:var(--text-muted);">chars</span>
    </div>
    <div class="frr-stat-desc">
      Target: <code style="color:var(--color-cyan); font-size:0.72rem;">/etc/frr/frr.conf</code> (mode 0640)
    </div>
  </div>
</div>

<!-- ── FRR Sub-Daemons Matrix ── -->
<div style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
  <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Integrated Routing Daemons</span>
  <span style="font-size:0.75rem; color:var(--text-muted);"><?= $activeCount ?> active protocol<?= $activeCount === 1 ? '' : 's' ?></span>
</div>

<div class="frr-daemon-grid">
  <!-- 1. zebra -->
  <div class="frr-daemon-card">
    <div class="frr-daemon-card-hdr">
      <div class="frr-daemon-name">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        <span>zebra</span>
      </div>
      <span class="badge badge-success" style="font-size:0.65rem;">CORE DAEMON</span>
    </div>
    <div class="frr-daemon-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">
        Multiprotocol routing manager. Manages kernel routing table synchronization, interface state, and route redistribution.
      </div>
      <div class="frr-kv-list">
        <span class="frr-kv-key">Role:</span>
        <span class="frr-kv-val">Netlink FIB Driver</span>
        <span class="frr-kv-key">FIB Routes:</span>
        <span class="frr-kv-val"><?= count($kernelRoutes ?? []) ?> active</span>
        <span class="frr-kv-key">Interfaces:</span>
        <span class="frr-kv-val"><?= count($allInterfaces ?? $interfaces ?? []) ?> tracked</span>
      </div>
    </div>
  </div>

  <!-- 2. ospfd -->
  <div class="frr-daemon-card">
    <div class="frr-daemon-card-hdr">
      <div class="frr-daemon-name">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
        </svg>
        <span>ospfd</span>
      </div>
      <span class="badge <?= !empty($ospfSettings['enabled']) ? 'badge-amber' : 'badge-muted' ?>" style="font-size:0.65rem;">
        <?= !empty($ospfSettings['enabled']) ? 'ENABLED' : 'STANDBY' ?>
      </span>
    </div>
    <div class="frr-daemon-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">
        OSPFv2 Link-State routing protocol for internal networks. Supports multi-area routing, MD5 auth, and VPN tunnel neighbors.
      </div>
      <div class="frr-kv-list">
        <span class="frr-kv-key">Router-ID:</span>
        <span class="frr-kv-val"><?= esc($ospfSettings['router_id'] ?? 'auto') ?></span>
        <span class="frr-kv-key">Areas:</span>
        <span class="frr-kv-val"><?= count($ospfAreas ?? []) ?> defined</span>
        <span class="frr-kv-key">Interfaces:</span>
        <span class="frr-kv-val"><?= count($ospfIfaces ?? []) ?> assigned</span>
        <span class="frr-kv-key">Networks:</span>
        <span class="frr-kv-val"><?= count($ospfNetworks ?? []) ?> advertised</span>
      </div>
    </div>
  </div>

  <!-- 3. bgpd -->
  <div class="frr-daemon-card">
    <div class="frr-daemon-card-hdr">
      <div class="frr-daemon-name">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple, #a855f7);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
        </svg>
        <span>bgpd</span>
      </div>
      <span class="badge <?= !empty($bgpSettings['enabled']) ? 'badge-purple' : 'badge-muted' ?>" style="font-size:0.65rem;">
        <?= !empty($bgpSettings['enabled']) ? 'ENABLED' : 'DISABLED' ?>
      </span>
    </div>
    <div class="frr-daemon-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">
        Border Gateway Protocol (BGP-4) peering engine for inter-AS routing, multihoming uplinks, and Internet route exchange.
      </div>
      <div class="frr-kv-list">
        <span class="frr-kv-key">Local AS:</span>
        <span class="frr-kv-val"><?= esc($bgpSettings['local_as'] ?? '—') ?></span>
        <span class="frr-kv-key">Router-ID:</span>
        <span class="frr-kv-val"><?= esc($bgpSettings['router_id'] ?? '—') ?></span>
        <span class="frr-kv-key">Neighbors:</span>
        <span class="frr-kv-val"><?= count($bgpNeighbors ?? []) ?> peers</span>
      </div>
    </div>
  </div>

  <!-- 4. ripd -->
  <div class="frr-daemon-card">
    <div class="frr-daemon-card-hdr">
      <div class="frr-daemon-name">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
        </svg>
        <span>ripd</span>
      </div>
      <span class="badge <?= !empty($ripSettings['enabled']) ? 'badge-cyan' : 'badge-muted' ?>" style="font-size:0.65rem;">
        <?= !empty($ripSettings['enabled']) ? 'ENABLED' : 'DISABLED' ?>
      </span>
    </div>
    <div class="frr-daemon-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">
        Routing Information Protocol (RIPv2) distance-vector protocol for legacy and branch networks.
      </div>
      <div class="frr-kv-list">
        <span class="frr-kv-key">Version:</span>
        <span class="frr-kv-val">RIPv2</span>
        <span class="frr-kv-key">Networks:</span>
        <span class="frr-kv-val"><?= count($ripNetworks ?? []) ?> configured</span>
        <span class="frr-kv-key">Neighbors:</span>
        <span class="frr-kv-val"><?= count($ripNeighbors ?? []) ?> unicast</span>
      </div>
    </div>
  </div>

  <!-- 5. isisd -->
  <div class="frr-daemon-card">
    <div class="frr-daemon-card-hdr">
      <div class="frr-daemon-name">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-blue, #3b82f6);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        <span>isisd</span>
      </div>
      <span class="badge <?= !empty($isisSettings['enabled']) ? 'badge-blue' : 'badge-muted' ?>" style="font-size:0.65rem;">
        <?= !empty($isisSettings['enabled']) ? 'ENABLED' : 'DISABLED' ?>
      </span>
    </div>
    <div class="frr-daemon-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">
        IS-IS link-state protocol designed for carrier-grade IP backbones and data center fabrics.
      </div>
      <div class="frr-kv-list">
        <span class="frr-kv-key">System-ID:</span>
        <span class="frr-kv-val"><?= esc($isisSettings['system_id'] ?? '—') ?></span>
        <span class="frr-kv-key">NET Area:</span>
        <span class="frr-kv-val"><?= esc($isisSettings['net_area'] ?? '—') ?></span>
        <span class="frr-kv-key">Interfaces:</span>
        <span class="frr-kv-val"><?= count($isisInterfaces ?? []) ?> circuits</span>
      </div>
    </div>
  </div>

  <!-- 6. bfdd -->
  <div class="frr-daemon-card">
    <div class="frr-daemon-card-hdr">
      <div class="frr-daemon-name">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <span>bfdd</span>
      </div>
      <span class="badge <?= count($bfdPeers ?? []) > 0 ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.65rem;">
        <?= count($bfdPeers ?? []) > 0 ? 'ACTIVE' : 'IDLE' ?>
      </span>
    </div>
    <div class="frr-daemon-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">
        Bidirectional Forwarding Detection. Provides sub-second path failure detection for BGP and OSPF adjacencies.
      </div>
      <div class="frr-kv-list">
        <span class="frr-kv-key">Protocol:</span>
        <span class="frr-kv-val">RFC 5880 BFD</span>
        <span class="frr-kv-key">Peers:</span>
        <span class="frr-kv-val"><?= count($bfdPeers ?? []) ?> registered</span>
        <span class="frr-kv-key">Interval:</span>
        <span class="frr-kv-val">300ms / 3x</span>
      </div>
    </div>
  </div>
</div>

<!-- ── frr.conf File View ── -->
<div class="frr-conf-card">
  <div class="frr-conf-toolbar">
    <div class="frr-conf-meta">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <strong style="color:var(--text-primary); font-size:0.875rem;">frr.conf</strong>
        <span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.68rem;">/etc/frr/frr.conf</span>
      </div>
      <span style="font-size:0.75rem; color:var(--text-muted);">&bull; <?= $lineCount ?> lines &bull; <?= number_format($charCount) ?> bytes &bull; UTF-8</span>
    </div>

    <div class="frr-conf-actions">
      <!-- Search inside code -->
      <div style="position:relative;">
        <input type="text" id="frrSearchInput" placeholder="Filter config..." oninput="filterFrrConfig(this.value)"
               style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:4px 10px 4px 26px; border-radius:var(--radius-sm); font-size:0.75rem; width:170px;">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
             style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
      </div>

      <button type="button" class="btn btn-ghost btn-sm" onclick="toggleWrapFrrConfig()" id="btnWrapFrr" title="Toggle line wrapping" style="font-size:0.75rem;">
        Wrap
      </button>

      <button type="button" class="btn btn-primary btn-sm" onclick="copyFrrConfig()" id="btnCopyFrr" style="font-size:0.75rem;">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:2px;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        Copy
      </button>
    </div>
  </div>

  <div class="frr-code-viewer" id="frrCodeViewer">
    <div class="frr-gutter" aria-hidden="true" id="frrGutter">
      <?php for ($i = 1; $i <= max(1, $lineCount); $i++): ?>
        <div><?= $i ?></div>
      <?php endfor; ?>
    </div>
    <pre class="frr-code-content" id="frrCodeContent"><?php
      foreach ($frrLines as $line) {
          $escLine = esc($line);
          $trimmed = trim($line);
          if (str_starts_with($trimmed, '!') || str_starts_with($trimmed, '#')) {
              echo '<span class="frr-line-comment">' . $escLine . "</span>\n";
          } elseif (preg_match('/^(router\s+[a-z0-9_-]+|line\s+vty|frr\s+defaults|service\s+|log\s+|hostname\s+)/i', $trimmed)) {
              echo '<span class="frr-line-proto">' . $escLine . "</span>\n";
          } elseif (preg_match('/^\s*(interface\s+|network\s+|neighbor\s+|redistribute\s+|ip\s+ospf|ip\s+prefix-list|route-map)/i', $line)) {
              echo '<span class="frr-line-keyword">' . $escLine . "</span>\n";
          } else {
              echo $escLine . "\n";
          }
      }
    ?></pre>
  </div>
</div>

<script>
// Raw config text for copy/download
const RAW_FRR_CONF = <?= json_encode($frrConf ?? '', JSON_UNESCAPED_SLASHES) ?>;

function copyFrrConfig() {
  const btn = document.getElementById('btnCopyFrr');
  const btnTop = document.getElementById('btnCopyFrrTop');
  
  const setSuccess = () => {
    if (btn) btn.innerHTML = '✓ Copied!';
    if (btnTop) btnTop.innerHTML = '✓ Copied!';
    setTimeout(() => {
      if (btn) btn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>Copy';
      if (btnTop) btnTop.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>Copy Config';
    }, 2000);
  };

  if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(RAW_FRR_CONF).then(setSuccess).catch(legacyCopy);
  } else {
    legacyCopy();
  }

  function legacyCopy() {
    try {
      const ta = document.createElement('textarea');
      ta.value = RAW_FRR_CONF;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      setSuccess();
    } catch (e) {
      alert('Unable to copy automatically. Please select the configuration and press Ctrl+C.');
    }
  }
}

let _frrWrapped = false;
function toggleWrapFrrConfig() {
  const code = document.getElementById('frrCodeContent');
  const btn = document.getElementById('btnWrapFrr');
  if (!code) return;
  _frrWrapped = !_frrWrapped;
  code.style.whiteSpace = _frrWrapped ? 'pre-wrap' : 'pre';
  if (btn) btn.classList.toggle('active', _frrWrapped);
}

function filterFrrConfig(query) {
  const code = document.getElementById('frrCodeContent');
  if (!code) return;
  const q = (query || '').toLowerCase().trim();
  const lines = RAW_FRR_CONF.split('\n');
  
  if (!q) {
    // Restore original rendering
    let html = '';
    lines.forEach(function(line) {
      const trimmed = line.trim();
      const escLine = line.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      if (trimmed.startsWith('!') || trimmed.startsWith('#')) {
        html += '<span class="frr-line-comment">' + escLine + "</span>\n";
      } else if (/^(router\s+[a-z0-9_-]+|line\s+vty|frr\s+defaults|service\s+|log\s+|hostname\s+)/i.test(trimmed)) {
        html += '<span class="frr-line-proto">' + escLine + "</span>\n";
      } else if (/^\s*(interface\s+|network\s+|neighbor\s+|redistribute\s+|ip\s+ospf|ip\s+prefix-list|route-map)/i.test(line)) {
        html += '<span class="frr-line-keyword">' + escLine + "</span>\n";
      } else {
        html += escLine + "\n";
      }
    });
    code.innerHTML = html;
    return;
  }

  let filteredHtml = '';
  lines.forEach(function(line) {
    if (line.toLowerCase().includes(q)) {
      const escLine = line.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      filteredHtml += '<mark style="background:rgba(245,158,11,0.25); color:#fff; border-radius:2px;">' + escLine + "</mark>\n";
    }
  });
  code.innerHTML = filteredHtml || '<span style="color:#8b949e; font-style:italic;">No lines matching "' + query.replace(/</g, '&lt;') + '"</span>';
}
</script>
