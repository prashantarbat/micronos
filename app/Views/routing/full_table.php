<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
/* ── Full Routing Table specific styles ── */
/* FRR status banner — base rules live in routing/index.php; this page renders the
   same banner markup but is served without that inline <style>, so it must carry
   its own copy or the banner collapses into unstyled blocks. */
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
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 6px var(--color-emerald);
}
.full-rt-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}
.full-rt-stat {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 12px 16px;
  text-align: center;
}
.full-rt-stat .count {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--color-cyan);
}
.full-rt-stat .label {
  font-size: 0.72rem;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-top: 4px;
}
.full-rt-section {
  margin-bottom: 24px;
}
.full-rt-section-header {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  position: relative;
  padding: 10px 116px 10px 16px;
  background: rgba(6,182,212,0.08);
  border: 1px solid rgba(6,182,212,0.2);
  border-radius: var(--radius-md);
  margin-bottom: 12px;
  cursor: pointer;
}
.full-rt-section-header:hover {
  background: rgba(6,182,212,0.12);
}
.full-rt-section-header h3 {
  margin: 0;
  font-size: 0.9rem;
  color: var(--color-cyan);
  font-weight: 600;
}
.full-rt-section-header .rt-chip {
  font-size: 0.7rem;
}
.full-rt-section-desc {
  flex-basis: 100%;
  margin: 0;
  font-size: 0.76rem;
  line-height: 1.45;
  color: var(--text-muted);
}
.full-rt-section-toggle {
  position: absolute;
  top: 11px;
  right: 16px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
}
.full-rt-section-toggle svg {
  transition: transform .15s ease;
}
.full-rt-section.collapsed .full-rt-section-content {
  display: none;
}
.full-rt-section.collapsed .full-rt-section-toggle svg {
  transform: rotate(-90deg);
}
.full-rt-legend {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin: -8px 0 22px;
  font-size: 0.75rem;
  color: var(--text-muted);
}
.full-rt-legend .rt-proto-badge {
  font-size: 0.65rem;
}
.full-rt-section-content {
  overflow-x: auto;
}
.full-rt-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
.full-rt-table th {
  background: var(--color-surface-2);
  padding: 8px 12px;
  text-align: left;
  font-weight: 600;
  color: var(--text-secondary);
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}
.full-rt-table td {
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.full-rt-table tr:hover td {
  background: rgba(6,182,212,0.04);
}
.rt-proto-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
}
.rt-proto-static { background: rgba(6,182,212,0.15); color: var(--color-cyan); }
.rt-proto-bgp { background: rgba(139,92,246,0.15); color: #a78bfa; }
.rt-proto-ospf { background: rgba(245,158,11,0.15); color: #fbbf24; }
.rt-proto-rip { background: rgba(16,185,129,0.15); color: #34d399; }
.rt-proto-isis { background: rgba(239,68,68,0.15); color: #f87171; }
.rt-proto-bfd { background: rgba(34,211,238,0.15); color: #22d3ee; }
.rt-proto-kernel { background: rgba(107,114,128,0.15); color: #9ca3af; }
.rt-proto-dhcp { background: rgba(251,146,60,0.15); color: #fb923c; }
.rt-proto-vrf { background: rgba(168,85,247,0.15); color: #c084fc; }
.rt-proto-pbr { background: rgba(249,115,22,0.15); color: #fb923c; }
.rt-proto-multiwan { background: rgba(34,197,94,0.15); color: #4ade80; }
.empty-state {
  text-align: center;
  padding: 28px 20px;
  color: var(--text-muted);
}
.empty-state svg {
  display: block;
  margin: 0 auto 10px;
}
.empty-state .es-title {
  margin: 0 0 4px;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-secondary);
}
.empty-state .es-note {
  margin: 0 auto;
  max-width: 560px;
  font-size: 0.8rem;
  line-height: 1.5;
}
.empty-state .es-note a {
  color: var(--color-cyan);
  text-decoration: none;
}
.empty-state .es-note a:hover {
  text-decoration: underline;
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
      <span>Full Routing Table</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-indigo-bg); color:var(--tone-indigo-fg); border:1px solid var(--tone-indigo-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">Full Routing Table</h1>
        <p class="page-subtitle">Complete view of all routing sources: static, kernel FIB, BGP, OSPF, RIP, IS-IS, BFD, VRF, PBR, and Multi-WAN</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('routing') ?>" class="btn btn-secondary btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      Back to Routing
    </a>
  </div>
</div>

<!-- ── Flash Messages ── -->
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
  <div class="frr-badge">Version: <strong><?= esc($frrStatus['version'] ?? '') ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc($frrStatus['uptime'] ?? '') ?></strong></div>
  <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-blue"><?= esc($frrStatus['bgp_as'] ?? '') ?></strong></div>
  <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc($frrStatus['router_id'] ?? '') ?></strong></div>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted" style="margin-left:auto;">simulation mode</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Summary Statistics ── -->
<div class="full-rt-summary animate-in">
  <div class="full-rt-stat">
    <div class="count"><?= count($kernelRoutes) ?></div>
    <div class="label">Kernel FIB Routes</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($staticRoutes) ?></div>
    <div class="label">Static Routes</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($bgpNeighbors) ?></div>
    <div class="label">BGP Peers</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($ospfAreas) + count($ospfIfaces) + count($ospfNetworks) ?></div>
    <div class="label">OSPF Entries</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($ripNetworks) + count($ripNeighbors) ?></div>
    <div class="label">RIP Entries</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($isisInterfaces) ?></div>
    <div class="label">IS-IS Interfaces</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($bfdPeers) ?></div>
    <div class="label">BFD Sessions</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($vrfs) ?></div>
    <div class="label">VRFs</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($pbrRules) ?></div>
    <div class="label">PBR Rules</div>
  </div>
  <div class="full-rt-stat">
    <div class="count"><?= count($wanGateways) ?></div>
    <div class="label">Multi-WAN Gateways</div>
  </div>
</div>

<!-- ── Protocol badge legend ── -->
<div class="full-rt-legend animate-in">
  <span style="font-weight:600; color:var(--text-secondary);">Protocol badge:</span>
  <span class="rt-proto-badge rt-proto-static">STATIC</span>
  <span class="rt-proto-badge rt-proto-kernel">KERNEL</span>
  <span class="rt-proto-badge rt-proto-dhcp">DHCP</span>
  <span class="rt-proto-badge rt-proto-bgp">BGP</span>
  <span class="rt-proto-badge rt-proto-ospf">OSPF</span>
  <span class="rt-proto-badge rt-proto-rip">RIP</span>
  <span class="rt-proto-badge rt-proto-isis">ISIS</span>
  <span>identifies which source installed each kernel entry</span>
</div>

<!-- ================================================================
     SECTION: Kernel FIB Routes
     ================================================================ -->
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
    <h3>Kernel FIB (Forwarding Information Base)</h3>
    <span class="rt-chip"><?= count($kernelRoutes) ?> routes</span>
    <p class="full-rt-section-desc">The routes the Linux kernel is actually using right now — every packet is forwarded by this table, whatever the routing daemons intended.</p>
  </div>
  <div class="full-rt-section-content">
    <?php if (empty($kernelRoutes)): ?>
      <div class="empty-state">
        <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
        <p class="es-title">No kernel routes found.</p>
        <p class="es-note">The kernel forwarding table is what actually sends packets. It fills up once an interface has an address, or once a static or dynamic route is installed.</p>
      </div>
    <?php else: ?>
      <table class="full-rt-table">
        <thead>
          <tr>
            <th>Destination</th>
            <th>Gateway</th>
            <th>Interface</th>
            <th>Metric</th>
            <th>Protocol</th>
            <th>Flags</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($kernelRoutes as $r): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($r['destination']) ?></span></td>
              <td><?= esc($r['gateway']) ?></td>
              <td><span class="rt-chip"><?= esc($r['interface']) ?></span></td>
              <td style="font-family:var(--font-mono);"><?= esc($r['metric']) ?></td>
              <td>
                <?php
                  $protoClass = match($r['proto']) {
                    'bgp'    => 'rt-proto-bgp',
                    'ospf'   => 'rt-proto-ospf',
                    'rip'    => 'rt-proto-rip',
                    'isis'   => 'rt-proto-isis',
                    'bfd'    => 'rt-proto-bfd',
                    'kernel' => 'rt-proto-kernel',
                    'dhcp'   => 'rt-proto-dhcp',
                    'static' => 'rt-proto-static',
                    default  => 'rt-proto-kernel',
                  };
                ?>
                <span class="rt-proto-badge <?= $protoClass ?>"><?= strtoupper(esc($r['proto'])) ?></span>
              </td>
              <td style="font-family:var(--font-mono);"><?= esc($r['flags']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ================================================================
     SECTION: Static Routes
     ================================================================ -->
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
    <h3>Configured Static Routes</h3>
    <span class="rt-chip"><?= count($staticRoutes) ?> routes</span>
    <p class="full-rt-section-desc">Fixed paths you configured by hand. They stay in place regardless of what BGP, OSPF or any other protocol decides.</p>
  </div>
  <div class="full-rt-section-content">
    <?php if (empty($staticRoutes)): ?>
      <div class="empty-state">
        <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        <p class="es-title">No static routes configured.</p>
        <p class="es-note">A static route pins traffic to a fixed next-hop or interface — useful for a remote subnet no protocol advertises, or a fallback default gateway. Add one on <a href="<?= base_url('routing/static') ?>">Static Routes</a>.</p>
      </div>
    <?php else: ?>
      <table class="full-rt-table">
        <thead>
          <tr>
            <th>Destination</th>
            <th>Next Hop</th>
            <th>Interface</th>
            <th>Metric</th>
            <th>Description</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staticRoutes as $sr): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($sr['destination']) ?></span></td>
              <td><span class="rt-chip rt-chip-blue"><?= esc($sr['gateway']) ?></span></td>
              <td><span class="rt-chip"><?= esc($sr['interface'] ?? 'eth0') ?></span></td>
              <td style="font-family:var(--font-mono);"><?= esc($sr['metric'] ?? 10) ?></td>
              <td style="color:var(--text-secondary);"><?= esc($sr['description'] ?: '—') ?></td>
              <td>
                <?php if ((int)($sr['enabled'] ?? 1)): ?>
                  <span class="badge badge-success">Active</span>
                <?php else: ?>
                  <span class="badge badge-muted">Disabled</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ================================================================
     SECTION: BGP
     ================================================================ -->
<?php if (!empty($bgpNeighbors) || !empty($frrStatus['running'])): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    <h3>BGP (Border Gateway Protocol)</h3>
    <span class="rt-chip"><?= count($bgpNeighbors) ?> peers</span>
    <p class="full-rt-section-desc">Peers you exchange internet-scale routes with. Each neighbour is a remote router identified by its IP and autonomous system number.</p>
  </div>
  <div class="full-rt-section-content">
    <?php if (empty($bgpNeighbors)): ?>
      <div class="empty-state">
        <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        <p class="es-title">No BGP neighbors configured.</p>
        <p class="es-note">This section lists <em>configured</em> peers, not live sessions. Add a neighbor on <a href="<?= base_url('routing?tab=bgp') ?>">Routing → BGP</a> to exchange routes with an ISP or another autonomous system.</p>
      </div>
    <?php else: ?>
      <table class="full-rt-table">
        <thead>
          <tr>
            <th>Neighbor IP</th>
            <th>Remote AS</th>
            <th>Local AS</th>
            <th>State</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bgpNeighbors as $bgp): ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($bgp['neighbor_ip'] ?? '—') ?></span></td>
              <td style="font-family:var(--font-mono);"><?= esc($bgp['remote_as'] ?? '—') ?></td>
              <td style="font-family:var(--font-mono);"><?= esc($bgp['local_as'] ?? $frrStatus['bgp_as'] ?? '—') ?></td>
              <td><span class="badge badge-success">Established</span></td>
              <td style="color:var(--text-secondary);"><?= esc($bgp['description'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: OSPF
     ================================================================ -->
<?php if (!empty($ospfAreas) || !empty($ospfIfaces) || !empty($ospfNetworks) || !empty($ospfSettings['enabled'])): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
    <h3>OSPF (Open Shortest Path First)</h3>
    <span class="rt-chip"><?= count($ospfAreas) ?> areas · <?= count($ospfIfaces) ?> ifaces · <?= count($ospfNetworks) ?> networks</span>
    <p class="full-rt-section-desc">The internal gateway protocol (IGP): areas group interfaces, and network statements decide which prefixes OSPF advertises to its neighbours.</p>
  </div>
  <div class="full-rt-section-content">
    <?php if (empty($ospfAreas) && empty($ospfIfaces) && empty($ospfNetworks)): ?>
      <div class="empty-state">
        <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
        <p class="es-title">No OSPF configuration found.</p>
        <p class="es-note">No areas, interfaces or network statements are defined. Enable OSPF on <a href="<?= base_url('routing?tab=ospf') ?>">Routing → OSPF</a> to advertise prefixes and form adjacencies with neighbours.</p>
      </div>
    <?php else: ?>
      <!-- OSPF Areas -->
      <?php if (!empty($ospfAreas)): ?>
        <h4 style="margin:16px 0 8px; color:var(--text-secondary); font-size:0.82rem;">OSPF Areas</h4>
        <table class="full-rt-table">
          <thead>
            <tr>
              <th>Area ID</th>
              <th>Type</th>
              <th>Description</th>
              <th>Default Cost</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ospfAreas as $area): ?>
              <tr>
                <td><span class="rt-chip rt-chip-purple"><?= esc($area['area_id']) ?></span></td>
                <td><?= esc(ucfirst($area['area_type'] ?? 'normal')) ?></td>
                <td style="color:var(--text-secondary);"><?= esc($area['description'] ?: '—') ?></td>
                <td style="font-family:var(--font-mono);"><?= esc($area['default_cost'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <!-- OSPF Interfaces -->
      <?php if (!empty($ospfIfaces)): ?>
        <h4 style="margin:16px 0 8px; color:var(--text-secondary); font-size:0.82rem;">OSPF Interfaces</h4>
        <table class="full-rt-table">
          <thead>
            <tr>
              <th>Interface</th>
              <th>Area</th>
              <th>Cost</th>
              <th>Network Type</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ospfIfaces as $iface): ?>
              <tr>
                <td><span class="rt-chip"><?= esc($iface['interface'] ?? '—') ?></span></td>
                <td><span class="rt-chip rt-chip-purple"><?= esc($iface['area_id'] ?? '—') ?></span></td>
                <td style="font-family:var(--font-mono);"><?= esc($iface['cost'] ?? '—') ?></td>
                <td><?= esc(ucfirst($iface['network_type'] ?? 'broadcast')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <!-- OSPF Networks -->
      <?php if (!empty($ospfNetworks)): ?>
        <h4 style="margin:16px 0 8px; color:var(--text-secondary); font-size:0.82rem;">OSPF Network Statements</h4>
        <table class="full-rt-table">
          <thead>
            <tr>
              <th>Network</th>
              <th>Area</th>
              <th>Mask</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ospfNetworks as $net): ?>
              <tr>
                <td><span class="rt-chip rt-chip-cyan"><?= esc($net['network'] ?? '—') ?></span></td>
                <td><span class="rt-chip rt-chip-purple"><?= esc($net['area_id'] ?? '—') ?></span></td>
                <td style="font-family:var(--font-mono);"><?= esc($net['mask'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: RIP
     ================================================================ -->
<?php if (!empty($ripNetworks) || !empty($ripNeighbors)): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
    <h3>RIP (Routing Information Protocol)</h3>
    <span class="rt-chip"><?= count($ripNetworks) ?> networks · <?= count($ripNeighbors) ?> neighbors</span>
    <p class="full-rt-section-desc">A legacy distance-vector protocol. It is simple to run but converges slowly and scales poorly — most networks use OSPF instead.</p>
  </div>
  <div class="full-rt-section-content">
    <?php if (empty($ripNetworks) && empty($ripNeighbors)): ?>
      <div class="empty-state">
        <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
        <p class="es-title">No RIP configuration found.</p>
        <p class="es-note">No RIP networks or unicast neighbours are defined. RIP is only worth enabling when peering with older equipment that still speaks it.</p>
      </div>
    <?php else: ?>
      <!-- RIP Networks -->
      <?php if (!empty($ripNetworks)): ?>
        <h4 style="margin:16px 0 8px; color:var(--text-secondary); font-size:0.82rem;">RIP Networks</h4>
        <table class="full-rt-table">
          <thead>
            <tr>
              <th>Network</th>
              <th>Version</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ripNetworks as $net): ?>
              <tr>
                <td><span class="rt-chip rt-chip-cyan"><?= esc($net['network'] ?? '—') ?></span></td>
                <td><?= esc($net['version'] ?? '2') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <!-- RIP Neighbors -->
      <?php if (!empty($ripNeighbors)): ?>
        <h4 style="margin:16px 0 8px; color:var(--text-secondary); font-size:0.82rem;">RIP Unicast Neighbors</h4>
        <table class="full-rt-table">
          <thead>
            <tr>
              <th>Neighbor IP</th>
              <th>Interface</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ripNeighbors as $neighbor): ?>
              <tr>
                <td><span class="rt-chip rt-chip-cyan"><?= esc($neighbor['neighbor_ip'] ?? '—') ?></span></td>
                <td><span class="rt-chip"><?= esc($neighbor['interface'] ?? '—') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: IS-IS
     ================================================================ -->
<?php if (!empty($isisInterfaces)): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
    <h3>IS-IS (Intermediate System to Intermediate System)</h3>
    <span class="rt-chip"><?= count($isisInterfaces) ?> interfaces</span>
    <p class="full-rt-section-desc">A link-state IGP common in service-provider and very large enterprise cores. Each interface carries a NET address, an area tag and a level.</p>
  </div>
  <div class="full-rt-section-content">
    <table class="full-rt-table">
      <thead>
        <tr>
          <th>Interface</th>
          <th>NET</th>
          <th>Area Tag</th>
          <th>Is-Type</th>
          <th>Metric Style</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($isisInterfaces as $isis): ?>
          <tr>
            <td><span class="rt-chip"><?= esc($isis['interface'] ?? '—') ?></span></td>
            <td style="font-family:var(--font-mono); font-size:0.75rem;"><?= esc($isis['net'] ?? '—') ?></td>
            <td><?= esc($isis['area_tag'] ?? '—') ?></td>
            <td><?= esc($isis['is_type'] ?? 'level-1-2') ?></td>
            <td><?= esc($isis['metric_style'] ?? 'narrow') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: BFD
     ================================================================ -->
<?php if (!empty($bfdPeers)): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    <h3>BFD (Bidirectional Forwarding Detection)</h3>
    <span class="rt-chip"><?= count($bfdPeers) ?> sessions</span>
    <p class="full-rt-section-desc">Fast liveness probes for routing peers. BFD notices a dead neighbour in milliseconds, so the routing protocol can converge before applications time out.</p>
  </div>
  <div class="full-rt-section-content">
    <table class="full-rt-table">
      <thead>
        <tr>
          <th>Peer IP</th>
          <th>Local IP</th>
          <th>Interface</th>
          <th>Rx/Tx (ms)</th>
          <th>Multiplier</th>
          <th>Echo</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bfdPeers as $b): ?>
          <tr>
            <td><span class="rt-chip rt-chip-cyan"><?= esc($b['peer_ip'] ?? '—') ?></span></td>
            <td><span class="rt-chip"><?= esc($b['local_ip'] ?: 'auto') ?></span></td>
            <td><span class="rt-chip"><?= esc($b['interface'] ?: 'auto') ?></span></td>
            <td style="font-family:var(--font-mono); font-size:0.78rem;"><?= esc($b['receive_interval'] ?? '—') ?> / <?= esc($b['transmit_interval'] ?? '—') ?></td>
            <td style="font-family:var(--font-mono);"><?= esc($b['detect_multiplier'] ?? '—') ?></td>
            <td><?= $b['echo_mode'] ? '<span class="badge badge-info" style="font-size:0.65rem;">ON</span>' : '—' ?></td>
            <td style="color:var(--text-secondary);"><?= esc($b['description'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: VRF
     ================================================================ -->
<?php if (!empty($vrfs)): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    <h3>VRF (Virtual Routing and Forwarding)</h3>
    <span class="rt-chip"><?= count($vrfs) ?> VRFs</span>
    <p class="full-rt-section-desc">Separate routing tables inside one appliance, so overlapping address ranges can coexist without leaking between tenants or segments.</p>
  </div>
  <div class="full-rt-section-content">
    <table class="full-rt-table">
      <thead>
        <tr>
          <th>VRF Name</th>
          <th>Table ID</th>
          <th>Interfaces</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vrfs as $v): ?>
          <?php $vifaces = is_string($v['interfaces']) ? json_decode($v['interfaces'], true) : ($v['interfaces'] ?? []); ?>
          <tr>
            <td><span class="rt-chip rt-chip-purple"><?= esc($v['name'] ?? '—') ?></span></td>
            <td style="font-family:var(--font-mono);"><?= esc($v['table_id'] ?: 'auto') ?></td>
            <td style="font-size:0.78rem;"><?= implode(', ', array_map('esc', $vifaces ?: [])) ?: '—' ?></td>
            <td style="color:var(--text-secondary);"><?= esc($v['description'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: PBR
     ================================================================ -->
<?php if (!empty($pbrRules)): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
    <h3>PBR (Policy-Based Routing)</h3>
    <span class="rt-chip"><?= count($pbrRules) ?> rules</span>
    <p class="full-rt-section-desc">Rules that override the normal route lookup — matched on source, destination, protocol or interface, then sent to a chosen next-hop or table.</p>
  </div>
  <div class="full-rt-section-content">
    <table class="full-rt-table">
      <thead>
        <tr>
          <th>Priority</th>
          <th>Match Source</th>
          <th>Match Dest</th>
          <th>Protocol</th>
          <th>In Iface</th>
          <th>Set Next-Hop</th>
          <th>Set Table/VRF</th>
          <th>Action</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pbrRules as $pb): ?>
          <tr>
            <td style="font-family:var(--font-mono); text-align:center;"><?= esc($pb['priority']) ?></td>
            <td>
              <span class="rt-chip"><?= esc($pb['match_src'] ?: 'any') ?></span>
            </td>
            <td>
              <span class="rt-chip"><?= esc($pb['match_dst'] ?: 'any') ?></span>
            </td>
            <td><span class="rt-chip"><?= esc(strtoupper($pb['match_protocol'] ?: 'any')) ?></span></td>
            <td style="color:var(--text-secondary);"><?= esc($pb['match_iif'] ?: '—') ?></td>
            <td><span class="rt-chip rt-chip-blue"><?= esc($pb['set_nexthop'] ?: '—') ?></span></td>
            <td style="color:var(--text-secondary);">
              <?= esc($pb['set_table'] ? "table {$pb['set_table']}" : ($pb['set_vrf'] ?: '—')) ?>
            </td>
            <td>
              <?php if ($pb['action'] === 'deny'): ?>
                <span class="rt-proto-badge rt-proto-isis">DENY</span>
              <?php else: ?>
                <span class="rt-proto-badge rt-proto-multiwan">PERMIT</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($pb['enabled'])): ?>
                <span class="rt-proto-badge rt-proto-multiwan">ACTIVE</span>
              <?php else: ?>
                <span class="rt-proto-badge rt-proto-kernel">DISABLED</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION: Multi-WAN
     ================================================================ -->
<?php if (!empty($wanGateways)): ?>
<div class="full-rt-section animate-in">
  <div class="full-rt-section-header">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
    <h3>Multi-WAN Gateway Groups</h3>
    <span class="rt-chip"><?= count($wanGateways) ?> groups</span>
    <p class="full-rt-section-desc">Groups of uplinks that share failover or load-balancing policy, so losing one WAN link does not take the network down.</p>
  </div>
  <div class="full-rt-section-content">
    <table class="full-rt-table">
      <thead>
        <tr>
          <th>Group Name</th>
          <th>Policy</th>
          <th>Members</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($wanGateways as $wg): ?>
          <tr>
            <td><span class="rt-chip rt-chip-blue"><?= esc($wg['name'] ?? '—') ?></span></td>
            <td>
              <?php
                $policyClass = match($wg['policy'] ?? 'failover') {
                  'loadbalance' => 'rt-proto-multiwan',
                  'failover'    => 'rt-proto-bgp',
                  'sticky'      => 'rt-proto-ospf',
                  default       => 'rt-proto-kernel',
                };
              ?>
              <span class="rt-proto-badge <?= $policyClass ?>"><?= esc(ucfirst($wg['policy'] ?? 'failover')) ?></span>
            </td>
            <td style="font-size:0.78rem;">
              <?php
                $rawMembers = is_string($wg['members'] ?? null) ? json_decode($wg['members'], true) : ($wg['members'] ?? []);
                $memberNames = [];
                foreach (($rawMembers ?: []) as $m) {
                    if (is_array($m)) {
                        $memberNames[] = (string) ($m['name'] ?? $m['id'] ?? $m['gateway_id'] ?? 'member');
                    } else {
                        $memberNames[] = (string) $m;
                    }
                }
              ?>
              <?= !empty($memberNames) ? esc(implode(', ', $memberNames)) : '—' ?>
            </td>
            <td style="color:var(--text-secondary);"><?= esc($wg['description'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Collapsible sections ── -->
<script>
(function () {
  document.querySelectorAll('.full-rt-section-header').forEach(function (header) {
    var section = header.closest('.full-rt-section');
    if (!section) { return; }

    var toggle = document.createElement('span');
    toggle.className = 'full-rt-section-toggle';
    toggle.innerHTML = '<span>Collapse</span><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>';
    header.appendChild(toggle);

    header.setAttribute('role', 'button');
    header.setAttribute('tabindex', '0');
    header.setAttribute('title', 'Collapse or expand this section');

    function toggleSection() {
      var collapsed = section.classList.toggle('collapsed');
      toggle.firstChild.textContent = collapsed ? 'Expand' : 'Collapse';
    }

    header.addEventListener('click', toggleSection);
    header.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggleSection();
      }
    });
  });
})();
</script>

<!-- ── Footer Note ── -->
<div style="margin-top:32px; padding:16px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); text-align:center;">
  <p style="margin:0; font-size:0.78rem; color:var(--text-secondary);">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="vertical-align:middle; margin-right:6px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    This view shows all routing sources configured on this system. The Kernel FIB section shows the actual routes active in the Linux kernel.
  </p>
</div>

<?= $this->endSection() ?>
