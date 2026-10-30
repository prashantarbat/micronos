<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
  $canManage = session()->get('is_superadmin') || in_array('vpn.wireguard.manage', session()->get('permissions') ?? []);
  $tunnelCount = count($tunnels);

  // Group peers by tunnel_id for nested views
  $peersByTunnel = [];
  foreach ($peers as $p) {
      $peersByTunnel[$p['tunnel_id']][] = $p;
  }

  // Lowest free wgN
  $usedIfaces = [];
  foreach ($tunnels as $__t) {
      $usedIfaces[strtolower((string) ($__t['interface_name'] ?? ''))] = true;
  }
  $nextIface = '';
  for ($__i = 0; $__i < 255; $__i++) {
      if (! isset($usedIfaces['wg' . $__i])) {
          $nextIface = 'wg' . $__i;
          break;
      }
  }
  if ($nextIface === '') {
      $nextIface = 'wg' . $tunnelCount;
  }

  if (!function_exists('formatWgBytes')) {
      function formatWgBytes($bytes) {
          $bytes = (float) $bytes;
          if ($bytes <= 0) return '0 B';
          $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
          $i = floor(log($bytes, 1024));
          $i = min($i, count($units) - 1);
          return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
      }
  }

  if (!function_exists('wgTimeAgo')) {
      function wgTimeAgo($epoch) {
          if (!$epoch || $epoch <= 0) return 'No handshake';
          $diff = time() - $epoch;
          if ($diff < 0) return 'just now';
          if ($diff < 60) return $diff . ' seconds ago';
          if ($diff < 3600) {
              $mins = floor($diff / 60);
              $secs = $diff % 60;
              return $mins . ' minute' . ($mins > 1 ? 's' : '') . ($secs > 0 ? ', ' . $secs . ' seconds ago' : ' ago');
          }
          if ($diff < 86400) {
              $hours = floor($diff / 3600);
              return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
          }
          $days = floor($diff / 86400);
          return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
      }
  }

  $currentTab = $activeTab ?? 'tunnels';
?>

<style>
/* ── pfSense-style Tabs Navigation ── */
.pfs-tabs-nav {
  display: flex;
  gap: 28px;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  padding: 0 4px;
}
.pfs-tab-link {
  display: inline-flex;
  align-items: center;
  padding: 8px 6px 12px;
  font-size: 0.95rem;
  font-weight: 600;
  color: #b02a37;
  text-decoration: none;
  cursor: pointer;
  position: relative;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  transition: all 0.15s ease;
}
.pfs-tab-link:hover {
  color: #891b27;
  text-decoration: none;
}
.pfs-tab-link.active {
  color: #d9230f;
  border-bottom: 3px solid #d9230f;
  font-weight: 700;
}
.tab-panel {
  display: none;
}
.tab-panel.active {
  display: block !important;
}

/* ── pfSense Card & Tables ── */
.pfs-card {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  overflow: hidden;
  margin-bottom: 24px;
}
.pfs-card-header {
  background: #343a40;
  color: #ffffff;
  font-weight: 700;
  font-size: 0.92rem;
  padding: 10px 16px;
  letter-spacing: 0.02em;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
/* Subtitles and the status legend sit on the dark header bar. */
.pfs-card-header span { color: #ffffff; }
.pfs-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.84rem;
}
.pfs-table th {
  background: var(--color-surface-2);
  color: var(--text-primary);
  font-weight: 700;
  padding: 9px 14px;
  border-bottom: 2px solid var(--color-border);
  text-align: left;
  white-space: nowrap;
}
.pfs-table td {
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  color: var(--text-primary);
  vertical-align: middle;
}
.pfs-table tr:hover > td {
  background: rgba(255, 255, 255, 0.02);
}
.pfs-btn-green {
  background-color: #198754;
  color: #ffffff !important;
  font-weight: 600;
  font-size: 0.82rem;
  padding: 6px 14px;
  border-radius: 4px;
  border: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  text-decoration: none;
  transition: background-color 0.15s ease;
}
.pfs-btn-green:hover {
  background-color: #157347;
  color: #ffffff;
}

/* ── Nested Subtable for Peers ── */
.pfs-subtable-container {
  padding: 0;
  background: var(--color-surface-2);
}
.pfs-subtable {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
.pfs-subtable th {
  background: var(--color-surface-3);
  color: var(--text-secondary);
  font-weight: 600;
  padding: 7px 12px;
  font-size: 0.74rem;
  border-bottom: 1px solid var(--color-border);
}
.pfs-subtable td {
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-border);
  color: var(--text-primary);
}

/* ── Actions & Helpers ── */
.pfs-action-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 3px 5px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #0d6efd;
  transition: color 0.15s;
  text-decoration: none;
}
.pfs-action-btn:hover {
  color: #0a58ca;
}
.pfs-action-btn.btn-delete {
  color: #dc3545;
}
.pfs-action-btn.btn-delete:hover {
  color: #b02a37;
}

.pfs-note {
  color: #d9230f;
  font-size: 0.75rem;
  margin-top: 4px;
  font-style: italic;
  line-height: 1.4;
}
.pfs-help {
  color: var(--text-muted);
  font-size: 0.75rem;
  margin-top: 3px;
  line-height: 1.4;
}

.pfs-form-row {
  display: grid;
  grid-template-columns: 240px 1fr;
  border-bottom: 1px solid var(--color-border);
}
.pfs-form-row:last-child {
  border-bottom: none;
}
.pfs-form-label {
  padding: 14px 18px;
  font-weight: 700;
  font-size: 0.85rem;
  color: var(--text-primary);
  text-align: right;
  border-right: 1px solid var(--color-border);
  background: var(--color-surface-2);
}
.pfs-form-control {
  padding: 12px 18px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

/* ── Form Modal layout ── */
.wg-form-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  align-items: start;
  gap: 0;
  border-bottom: 1px solid var(--color-border);
}
.wg-form-row:last-child { border-bottom: none; }
.wg-form-label {
  padding: 13px 20px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-secondary);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.wg-form-label abbr {
  font-size: 0.72rem;
  font-weight: 400;
  color: var(--text-muted);
  text-decoration: none;
}
.wg-form-control {
  padding: 10px 20px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.wg-form-control .hint {
  font-size: 0.72rem;
  color: var(--text-muted);
  margin: 0;
  line-height: 1.45;
}
.wg-subheader {
  background: var(--color-surface-3);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 20px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--text-secondary);
}
.wg-input {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  color: var(--text-primary);
  padding: 7px 10px;
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  width: 100%;
  max-width: 440px;
  transition: border-color .2s;
}
.wg-input:focus { outline: none; border-color: var(--color-cyan); }
.wg-input-mono { font-family: var(--font-mono); font-size: 0.82rem; }
.wg-input-sm   { max-width: 180px; }
.wg-input-xs   { max-width: 110px; }
.wg-inline     { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
.wg-keys-row   { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.wg-keys-row .wg-input { flex: 1; min-width: 200px; }

.wg-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 3px 0;
}
.wg-toggle input[type="checkbox"] {
  width: 18px; height: 18px;
  accent-color: var(--color-emerald);
  cursor: pointer;
}
.wg-toggle span { font-size: 0.85rem; color: var(--text-secondary); }

.wg-empty { text-align: center; padding: 34px 20px; color: var(--text-muted); }

.wg-status-dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  margin-right: 5px;
}
.wg-status-up   { background: var(--color-emerald); box-shadow: 0 0 6px var(--color-emerald); }
.wg-status-down { background: var(--color-red); }
.wg-status-idle { background: var(--color-amber); }

@media (max-width: 768px) {
  .pfs-form-row { grid-template-columns: 1fr; }
  .pfs-form-label { text-align: left; border-right: none; border-bottom: 1px solid var(--color-border); }
  .wg-form-row { grid-template-columns: 1fr; }
  .wg-form-label { border-right: none; border-bottom: 1px solid var(--color-border); }
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <h1 class="page-title">WireGuard VPN</h1>
      <span class="badge badge-info" style="font-size:0.73rem;"><?= $tunnelCount ?> tunnel<?= $tunnelCount === 1 ? '' : 's' ?></span>
    </div>
    <p class="page-subtitle">Fast, modern, secure VPN tunnel management</p>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget('wireguard') ?>
  </div>
</div>

<!-- ── Quick Status Bar ── -->
<?php if ($tunnelCount > 0): ?>
<div class="wg-quick-stats animate-in" style="display:flex; flex-wrap:wrap; gap:12px 24px; margin-bottom:20px; padding:14px 20px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md);">
  <?php
    $upCount = 0;
    $totalPeers = 0;
    foreach ($tunnels as $t) {
        if (isset($liveStatus['interfaces'][$t['interface_name']])) $upCount++;
        $totalPeers += count($peersByTunnel[$t['id']] ?? []);
    }
  ?>
  <div style="display:flex; align-items:center; gap:8px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--color-emerald)" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
    <span><strong style="color:var(--text-primary)"><?= $upCount ?></strong> of <strong style="color:var(--text-secondary)"><?= $tunnelCount ?></strong> tunnels up</span>
  </div>
  <div style="display:flex; align-items:center; gap:8px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span><strong style="color:var(--text-primary)"><?= $totalPeers ?></strong> peers configured</span>
  </div>
  <div style="display:flex; align-items:center; gap:8px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--color-amber)" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span>Status updates every refresh</span>
  </div>
</div>
<?php endif; ?>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>
<?php if ($isMock): ?>
  <div class="alert animate-in" data-persist style="margin-bottom:18px; background:var(--color-surface-2); border:1px solid var(--color-border); color:var(--text-secondary); font-size:0.8rem;">
    ⚠ Mock mode — the daemon is simulated on this host. Interface addresses are compiled and stored, but no kernel interface is created here.
  </div>
<?php endif; ?>

<div class="alert animate-in" id="wgNotice" data-persist style="display:none; margin-bottom:18px; background:var(--color-surface-2); border:1px solid var(--color-amber); color:var(--text-secondary); font-size:0.82rem; align-items:flex-start; gap:10px;">
  <span id="wgNoticeText" style="flex:1;"></span>
  <button type="button" onclick="document.getElementById('wgNotice').style.display='none'"
          title="Dismiss" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1rem; line-height:1; padding:0 2px;">✕</button>
</div>

<!-- ── Tabs Navigation Bar ── -->
<div class="pfs-tabs-nav animate-in">
  <a href="?tab=tunnels" class="pfs-tab-link <?= $currentTab === 'tunnels' ? 'active' : '' ?>" onclick="switchWgTab(event, 'tunnels')">
    <span>Tunnels</span>
    <?php if ($tunnelCount > 0): ?><span class="tab-count"><?= $tunnelCount ?></a><?php endif; ?>
  </a>
  <a href="?tab=peers" class="pfs-tab-link <?= $currentTab === 'peers' ? 'active' : '' ?>" onclick="switchWgTab(event, 'peers')">
    <span>Peers</span>
    <?php if (count($peers) > 0): ?><span class="tab-count"><?= count($peers) ?></span><?php endif; ?>
  </a>
  <a href="?tab=settings" class="pfs-tab-link <?= $currentTab === 'settings' ? 'active' : '' ?>" onclick="switchWgTab(event, 'settings')">Settings</a>
  <a href="?tab=status" class="pfs-tab-link <?= $currentTab === 'status' ? 'active' : '' ?>" onclick="switchWgTab(event, 'status')">Status</a>
</div>

<!-- ── Onboarding Panel ── -->
<?php if ($tunnelCount === 0 && $currentTab === 'tunnels'): ?>
<div class="wg-onboarding animate-in" style="display:flex; gap:16px; padding:20px; background:var(--color-surface-2); border:1px solid var(--color-border-bright); border-radius:var(--radius-md); margin-bottom:20px; align-items:flex-start;">
  <div style="flex:1;">
    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">Welcome to WireGuard VPN</h3>
    <p style="font-size:0.85rem; color:var(--text-secondary); margin:0 0 10px 0; line-height:1.55;">
      WireGuard is a fast, lightweight VPN protocol. Set up <strong>tunnels</strong> (interfaces) first, then add <strong>peers</strong>
      (remote endpoints) to each tunnel. Each peer gets a public/private key pair and an allowed IP range.
    </p>
    <p style="font-size:0.82rem; color:var(--text-muted); margin:0; line-height:1.55;">
      A typical setup: create one tunnel per site or purpose (e.g. <strong>HQ-Branch</strong>, <strong>Remote-Access</strong>),
      assign it a tunnel IP range, then add peers that connect to it.
    </p>
  </div>
  <div style="flex-shrink:0; display:flex; flex-direction:column; gap:8px;">
    <?php if ($canManage): ?>
      <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:700;">Quick start</span>
      <button type="button" class="pfs-btn-green" onclick="openTunnelModal(null)" style="width:100%;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Create your first tunnel
      </button>
    <?php else: ?>
      <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:700;">Required role</span>
      <span style="font-size:0.82rem; color:var(--text-secondary);">Contact your administrator to add tunnels.</span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ================================================================
     TAB 1 — TUNNELS
     ================================================================ -->
<div id="panel-tunnels" class="tab-panel animate-in <?= $currentTab === 'tunnels' ? 'active' : '' ?>" style="<?= $currentTab === 'tunnels' ? 'display:block;' : 'display:none;' ?>">
  <div class="pfs-card">
    <div class="pfs-card-header">
      <span>WireGuard Tunnels</span>
      <span style="font-size:0.72rem; font-weight:400; color:#ffffff; margin-left:8px;">
        Click the interface name to expand/collapse peers
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table class="pfs-table">
        <thead>
          <tr>
            <th style="width:150px;">Interface</th>
            <th style="min-width:150px;">Description</th>
            <th style="min-width:220px;">Public Key</th>
            <th style="min-width:180px;">Tunnel Address</th>
            <th style="width:100px;">Listen Port</th>
            <th style="width:70px; text-align:center;">Peers</th>
            <th style="width:150px; text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tunnels)): ?>
            <tr>
              <td colspan="7" class="wg-empty">
                <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                  <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  <p style="font-size:0.9rem; color:var(--text-secondary); margin:0;">No tunnels set up yet.</p>
                  <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">A tunnel is a virtual network interface. Create one to get started — you can add peers (remote endpoints) afterwards.</p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($tunnels as $t): ?>
              <?php
                $tunnelPeers = $peersByTunnel[$t['id']] ?? [];
                $addresses = App\Libraries\Cidr::split((string) ($t['addresses'] ?? ''));
                if ($addresses === []) {
                    $legacy = trim((string) ($t['ipv4_address'] ?? ''));
                    if ($legacy !== '') {
                        if (! str_contains($legacy, '/') && ! empty($t['ipv4_subnet'])) {
                            $legacy .= '/' . (int) $t['ipv4_subnet'];
                        }
                        $addresses = [$legacy];
                    }
                }
              ?>
              <!-- Tunnel Main Row -->
              <tr>
                <td>
                  <span style="cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-weight:700; color:var(--text-primary);"
                        onclick="toggleTunnelPeers('<?= esc($t['id']) ?>')">
                    <svg id="chevron-<?= esc($t['id']) ?>" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="transition:transform 0.2s; transform:<?= ($settings['hide_peers'] ?? '1') === '1' ? 'rotate(-90deg)' : 'rotate(0deg)' ?>;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                    <?= esc($t['interface_name']) ?>
                  </span>
                </td>
                <td><?= esc($t['name'] ?: ($t['description'] ?: '<span style="color:var(--text-muted)">No name</span>')) ?></td>
                <td>
                  <?php if (!empty($t['public_key'])): ?>
                    <span style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);" title="<?= esc($t['public_key']) ?>">
                      <?= esc(substr((string) $t['public_key'], 0, 24)) ?>…
                    </span>
                    <button type="button" onclick="copyText('<?= esc(addslashes((string) $t['public_key'])) ?>')" title="Copy Public Key" class="pfs-action-btn" style="padding:0 2px;">
                      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                  <?php else: ?>
                    <span style="color:var(--text-muted); font-size:0.75rem;">(no key generated)</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span style="display:inline-flex; align-items:center; gap:6px;">
                    <svg width="15" height="15" fill="currentColor" viewBox="0 0 20 20" style="color:var(--text-secondary); opacity:0.8;">
                      <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v1h8v-1zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 16.5a4.5 4.5 0 00-3-4.24V14a5 5 0 013 2.5zM4 16.5A4.5 4.5 0 017 12.26V14a5 5 0 00-3 2.5z"/>
                    </svg>
                    <span style="color:#0d6efd; font-weight:600; font-family:var(--font-mono); font-size:0.8rem;">
                      <?= !empty($addresses) ? esc($addresses[0]) : esc($t['name'] ?: $t['interface_name']) ?>
                    </span>
                  </span>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.82rem;"><?= (int) ($t['listen_port'] ?? 51820) ?></td>
                <td style="text-align:center; font-weight:600;"><?= count($tunnelPeers) ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <?php if ($canManage): ?>
                    <!-- Add Peer to this Tunnel -->
                    <button type="button" class="pfs-action-btn" title="Add Peer to this Tunnel" onclick="openPeerModalForTunnel('<?= esc($t['id']) ?>')">
                      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                      </svg>
                    </button>
                    <!-- Edit Tunnel -->
                    <button type="button" class="pfs-action-btn" title="Edit Tunnel" onclick='openTunnelModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)'>
                      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                      </svg>
                    </button>
                    <!-- Download Config -->
                    <a href="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/config') ?>" class="pfs-action-btn" title="Download Interface Config">
                      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                      </svg>
                    </a>
                    <!-- Delete Tunnel -->
                    <form method="POST" action="<?= base_url('vpn/wireguard/tunnels/' . esc($t['id']) . '/delete') ?>" style="display:inline;"
                          onsubmit="return confirm('Delete tunnel <?= esc(addslashes($t['interface_name'])) ?> and all of its peers?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="pfs-action-btn btn-delete" title="Delete Tunnel">
                        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>

              <!-- Nested Peers Sub-Table Row -->
              <tr id="peers-row-<?= esc($t['id']) ?>" style="<?= ($settings['hide_peers'] ?? '1') === '1' ? 'display:none;' : '' ?>">
                <td style="padding:10px 14px 10px 24px; vertical-align:top; font-weight:700; color:var(--text-secondary); background:var(--color-surface-2);">
                  Peers
                </td>
                <td colspan="6" class="pfs-subtable-container">
                  <table class="pfs-subtable">
                    <thead>
                      <tr>
                        <th style="min-width:140px;">Description</th>
                        <th style="min-width:200px;">Public Key</th>
                        <th style="width:110px;">Tunnel</th>
                        <th style="min-width:160px;">Allowed IPs</th>
                        <th style="min-width:170px;">Endpoint</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($tunnelPeers)): ?>
                        <tr>
                          <td colspan="5" style="color:var(--text-muted); font-style:italic; padding:8px 12px;">
                            No peers configured for this tunnel. Click the <strong>+</strong> icon to add one.
                          </td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($tunnelPeers as $tp): ?>
                          <tr>
                            <td style="font-weight:600;"><?= esc($tp['name']) ?></td>
                            <td>
                              <span style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);" title="<?= esc($tp['public_key']) ?>">
                                <?= esc(substr((string) $tp['public_key'], 0, 24)) ?>…
                              </span>
                            </td>
                            <td><span style="font-family:var(--font-mono); font-size:0.75rem;"><?= esc($t['interface_name']) ?></span></td>
                            <td style="font-family:var(--font-mono); font-size:0.75rem; color:#0d6efd;">
                              <?= esc(implode(', ', array_filter([
                                  trim((string) ($tp['peer_address'] ?? '')),
                                  trim((string) ($tp['allowed_ips'] ?? '')),
                              ], static fn ($entry) => $entry !== '')) ?: '0.0.0.0/0') ?>
                            </td>
                            <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                              <?= esc($tp['endpoint'] ?: 'Dynamic') ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($canManage): ?>
    <div style="display:flex; justify-content:flex-end; margin-bottom:24px;">
      <button type="button" class="pfs-btn-green" onclick="openTunnelModal(null)">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add Tunnel
      </button>
    </div>
  <?php endif; ?>
</div>

<!-- ================================================================
     TAB 2 — PEERS
     ================================================================ -->
<div id="panel-peers" class="tab-panel animate-in <?= $currentTab === 'peers' ? 'active' : '' ?>" style="<?= $currentTab === 'peers' ? 'display:block;' : 'display:none;' ?>">
  <div class="pfs-card">
    <div class="pfs-card-header">
      <span>WireGuard Peers</span>
    </div>
    <div style="overflow-x:auto;">
      <table class="pfs-table">
        <thead>
          <tr>
            <th style="min-width:160px;">Peer</th>
            <th style="min-width:220px;">Public Key</th>
            <th style="width:120px;">Tunnel</th>
            <th style="min-width:180px;">Allowed IPs</th>
            <th style="min-width:180px;">Endpoint</th>
            <th style="width:140px; text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($peers)): ?>
            <tr>
              <td colspan="6" class="wg-empty">
                <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                  <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                  <p style="font-size:0.9rem; color:var(--text-secondary); margin:0;">No peers yet.</p>
                  <p style="font-size:0.8rem; color:var(--text-muted); margin:0; max-width:400px;">Peers are remote WireGuard endpoints. Add a peer to a tunnel to allow a client or site to connect.</p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($peers as $p): ?>
              <?php
                $peerEnabled = (int) ($p['enabled'] ?? 1) === 1;
                $tunnelIface = $tunnelInterfaces[$p['tunnel_id']] ?? ($tunnelNames[$p['tunnel_id']] ?? '—');
                $allowedDisplay = implode(', ', array_filter([
                    trim((string) ($p['peer_address'] ?? '')),
                    trim((string) ($p['allowed_ips'] ?? '')),
                ], static fn ($entry) => $entry !== ''));
              ?>
              <tr <?= $peerEnabled ? '' : 'style="opacity:0.6;"' ?>>
                <td style="font-weight:600; color:var(--text-primary);"><?= esc($p['name']) ?></td>
                <td>
                  <span style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);" title="<?= esc($p['public_key']) ?>">
                    <?= esc(substr((string) $p['public_key'], 0, 20)) ?>…
                  </span>
                  <button type="button" onclick="copyText('<?= esc(addslashes((string) $p['public_key'])) ?>')" title="Copy Public Key" class="pfs-action-btn" style="padding:0 2px;">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                  </button>
                </td>
                <td><span class="badge badge-muted" style="font-family:var(--font-mono);"><?= esc($tunnelIface) ?></span></td>
                <td style="font-family:var(--font-mono); font-size:0.78rem; color:#0d6efd;"><?= esc($allowedDisplay ?: '0.0.0.0/0') ?></td>
                <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);"><?= esc($p['endpoint'] ?: 'Dynamic') ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <?php if ($canManage): ?>
                    <!-- Edit Peer -->
                    <button type="button" class="pfs-action-btn" title="Edit Peer" onclick='openPeerModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)'>
                      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                      </svg>
                    </button>
                    <!-- Toggle Enable / Disable -->
                    <form method="POST" action="<?= base_url('vpn/wireguard/peers/' . esc($p['id']) . '/toggle') ?>" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="pfs-action-btn" title="<?= $peerEnabled ? 'Disable Peer' : 'Enable Peer' ?>" style="color:<?= $peerEnabled ? '#0d6efd' : 'var(--text-muted)' ?>;">
                        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                      </button>
                    </form>
                    <!-- Delete Peer -->
                    <form method="POST" action="<?= base_url('vpn/wireguard/peers/' . esc($p['id']) . '/delete') ?>" style="display:inline;"
                          onsubmit="return confirm('Remove peer <?= esc(addslashes($p['name'])) ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="pfs-action-btn btn-delete" title="Delete Peer">
                        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
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

  <?php if ($canManage): ?>
    <div style="display:flex; justify-content:flex-end; margin-bottom:24px;">
      <button type="button" class="pfs-btn-green" onclick="openPeerModal(null)">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add Peer
      </button>
    </div>
  <?php endif; ?>
</div>

<!-- ================================================================
     TAB 3 — SETTINGS
     ================================================================ -->
<div id="panel-settings" class="tab-panel animate-in <?= $currentTab === 'settings' ? 'active' : '' ?>" style="<?= $currentTab === 'settings' ? 'display:block;' : 'display:none;' ?>">
  <form method="POST" action="<?= base_url('vpn/wireguard/settings') ?>">
    <?= csrf_field() ?>

    <!-- General Settings -->
    <div class="pfs-card">
      <div class="pfs-card-header">
        <span>General Settings</span>
        <span style="font-size:0.72rem; font-weight:400; color:#ffffff;">Service-wide WireGuard behaviour</span>
      </div>
      <div>
        <!-- Enable -->
        <div class="pfs-form-row">
          <div class="pfs-form-label">Service<br>Enabled</div>
          <div class="pfs-form-control">
            <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
              <input type="checkbox" name="enable_wireguard" value="1" <?= ($settings['enable_wireguard'] ?? '1') === '1' ? 'checked' : '' ?>>
              Enable WireGuard service
            </label>
            <div class="pfs-help">When disabled, no tunnel interfaces are brought up and the daemon stops managing existing tunnels.</div>
            <div class="pfs-note">WireGuard cannot be disabled if any tunnel is assigned to a pfSense interface.</div>
          </div>
        </div>

        <!-- Keep Configuration -->
        <div class="pfs-form-row">
          <div class="pfs-form-label">Keep<br>Configs</div>
          <div class="pfs-form-control">
            <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
              <input type="checkbox" name="keep_configuration" value="1" <?= ($settings['keep_configuration'] ?? '1') === '1' ? 'checked' : '' ?>>
              Preserve configs on uninstall
            </label>
            <div class="pfs-help">When enabled (default), tunnel configurations and package settings survive package install/uninstall cycles.</div>
            <div class="pfs-note">Disable this to wipe all WireGuard settings when removing the package.</div>
          </div>
        </div>

        <!-- Endpoint Hostname Resolve Interval -->
        <div class="pfs-form-row">
          <div class="pfs-form-label">DNS Resolve<br>Interval</div>
          <div class="pfs-form-control">
            <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
              <div style="flex:1; min-width:240px; max-width:360px;">
                <input type="number" name="resolve_interval" class="wg-input wg-input-sm" value="<?= esc($settings['resolve_interval'] ?? '300') ?>" min="0">
                <div class="pfs-help">Seconds between re-resolving peer endpoint hostnames (0 = never re-resolve).</div>
                <div class="pfs-note">Default: 300 seconds (5 min).</div>
              </div>
              <div style="flex:1; min-width:240px;">
                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
                  <input type="checkbox" name="track_system_resolve" value="1" <?= ($settings['track_system_resolve'] ?? '0') === '1' ? 'checked' : '' ?>>
                  Follow system interval
                </label>
                <div class="pfs-help">Use the system-wide 'Aliases Hostnames Resolve Interval' instead of this custom value.</div>
                <div class="pfs-note">Configured under System &gt; Advanced &gt; Firewall &amp; NAT.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Interface Group Membership -->
        <div class="pfs-form-row">
          <div class="pfs-form-label">Interface<br>Group</div>
          <div class="pfs-form-control">
            <select name="interface_group_membership" class="wg-input" style="max-width:320px;">
              <option value="all" <?= ($settings['interface_group_membership'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Tunnels</option>
              <option value="unassigned" <?= ($settings['interface_group_membership'] ?? '') === 'unassigned' ? 'selected' : '' ?>>Unassigned Tunnels Only</option>
              <option value="assigned" <?= ($settings['interface_group_membership'] ?? '') === 'assigned' ? 'selected' : '' ?>>Assigned Tunnels Only</option>
              <option value="none" <?= ($settings['interface_group_membership'] ?? '') === 'none' ? 'selected' : '' ?>>No Tunnels</option>
            </select>
            <div class="pfs-help">Controls which tunnels belong to the 'WireGuard' interface group — used for group firewall rules.</div>
            <div class="pfs-note">Group rules are evaluated before per-interface rules. Default: All Tunnels.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- User Interface Settings -->
    <div class="pfs-card">
      <div class="pfs-card-header">
        <span>Display Settings</span>
        <span style="font-size:0.72rem; font-weight:400; color:#ffffff;">What to show or hide in the UI</span>
      </div>
      <div>
        <!-- Hide Secrets -->
        <div class="pfs-form-row">
          <div class="pfs-form-label">Hide<br>Secrets</div>
          <div class="pfs-form-control">
            <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
              <input type="checkbox" name="hide_secrets" value="1" <?= ($settings['hide_secrets'] ?? '1') === '1' ? 'checked' : '' ?>>
              Mask private and pre-shared keys
            </label>
            <div class="pfs-help">When enabled, sensitive key material (private keys, pre-shared keys) is hidden from the interface — useful for shared-screen or audit scenarios.</div>
            <div class="pfs-note">Keys are still stored and used by the daemon; only the UI display is affected.</div>
          </div>
        </div>

        <!-- Hide Peers -->
        <div class="pfs-form-row">
          <div class="pfs-form-label">Collapse<br>Peers</div>
          <div class="pfs-form-control">
            <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
              <input type="checkbox" name="hide_peers" value="1" <?= ($settings['hide_peers'] ?? '1') === '1' ? 'checked' : '' ?>>
              Fold peer lists by default
            </label>
            <div class="pfs-help">When enabled (default), peer lists under each tunnel are collapsed on first load — click the interface name or arrow to expand.</div>
            <div class="pfs-note">Applies to the Tunnels and Status tabs.</div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($canManage): ?>
      <div style="margin-top:16px; margin-bottom:32px; display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary" style="padding:8px 24px; font-weight:600;">Save Settings</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- ================================================================
     TAB 4 — STATUS
     ================================================================ -->
<div id="panel-status" class="tab-panel animate-in <?= $currentTab === 'status' ? 'active' : '' ?>" style="<?= $currentTab === 'status' ? 'display:block;' : 'display:none;' ?>">
  <!-- WireGuard Status -->
  <div class="pfs-card">
    <div class="pfs-card-header" style="justify-content:space-between;">
      <span>WireGuard Status</span>
      <span style="font-size:0.72rem; font-weight:400; color:#ffffff; display:flex; align-items:center; gap:6px;">
        <span style="display:inline-flex; align-items:center; gap:4px;"><span class="wg-status-dot wg-status-up"></span> Up (active)</span>
        <span style="display:inline-flex; align-items:center; gap:4px;"><span class="wg-status-dot wg-status-idle"></span> Idle (no recent handshake)</span>
        <span style="display:inline-flex; align-items:center; gap:4px;"><span class="wg-status-dot wg-status-down"></span> Down</span>
      </span>
    </div>
    <div style="overflow-x:auto;">
      <table class="pfs-table">
        <thead>
          <tr>
            <th style="width:130px;">Interface</th>
            <th style="min-width:130px;">Description</th>
            <th style="width:70px; text-align:center;">Peers</th>
            <th style="min-width:200px;">Public Key</th>
            <th style="min-width:170px;">Tunnel Address</th>
            <th style="width:60px; text-align:center;">MTU</th>
            <th style="width:100px;">Port</th>
            <th style="width:100px; text-align:right;">RX</th>
            <th style="width:100px; text-align:right;">TX</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tunnels)): ?>
            <tr>
              <td colspan="9" class="wg-empty">
                <div style="display:flex; flex-direction:column; align-items:center; gap:6px;">
                  <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="var(--color-muted)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
                  <p style="font-size:0.9rem; color:var(--text-secondary); margin:0;">No tunnels to show.</p>
                  <p style="font-size:0.8rem; color:var(--text-muted); margin:0;">Create a tunnel first, then its live status will appear here.</p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($tunnels as $t): ?>
              <?php
                $tunnelPeers = $peersByTunnel[$t['id']] ?? [];
                $addresses = App\Libraries\Cidr::split((string) ($t['addresses'] ?? ''));
                $isUp = isset($liveStatus['interfaces'][$t['interface_name']]);

                // Calculate tunnel RX / TX total from peers
                $tunnelRx = 0;
                $tunnelTx = 0;
                foreach ($tunnelPeers as $p) {
                    $liveP = $liveData[$p['public_key']] ?? null;
                    if ($liveP) {
                        $tunnelRx += (float) ($liveP['rx_bytes'] ?? 0);
                        $tunnelTx += (float) ($liveP['tx_bytes'] ?? 0);
                    }
                }
              ?>
              <!-- Tunnel Status Row -->
              <tr>
                <td>
                  <span style="display:inline-flex; align-items:center; gap:6px; font-weight:700; <?= !empty($tunnelPeers) ? 'cursor:pointer;' : '' ?>" <?= !empty($tunnelPeers) ? "onclick=\"toggleStatusPeers('" . esc($t['id']) . "')\"" : '' ?>>
                    <?php if (!empty($tunnelPeers)): ?>
                      <svg id="status-chevron-<?= esc($t['id']) ?>" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="transition:transform 0.2s; transform:<?= ($settings['hide_peers'] ?? '1') === '1' ? 'rotate(-90deg)' : 'rotate(0deg)' ?>;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                      </svg>
                    <?php endif; ?>
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" style="color:<?= $isUp ? '#198754' : '#dc3545' ?>;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="<?= $isUp ? 'M5 10l7-7m0 0l7 7m-7-7v18' : 'M19 14l-7 7m0 0l-7-7m7 7V3' ?>"/>
                    </svg>
                    <span style="color:#0d6efd;"><?= esc($t['interface_name']) ?></span>
                    <span class="badge <?= $isUp ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.68rem; margin-left:4px;"><?= $isUp ? 'Tunnel up' : 'Tunnel down' ?></span>
                  </span>
                </td>
                <td><?= esc($t['name'] ?: ($t['description'] ?: '<span style="color:var(--text-muted)">No name</span>')) ?></td>
                <td style="text-align:center; font-weight:600;"><?= count($tunnelPeers) ?></td>
                <td>
                  <span style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);" title="<?= esc($t['public_key']) ?>">
                    <?= esc(substr((string) $t['public_key'], 0, 20)) ?>…
                  </span>
                </td>
                <td>
                  <span style="display:inline-flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 20 20" style="color:var(--text-secondary); opacity:0.8;">
                      <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v1h8v-1zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 16.5a4.5 4.5 0 00-3-4.24V14a5 5 0 013 2.5zM4 16.5A4.5 4.5 0 017 12.26V14a5 5 0 00-3 2.5z"/>
                    </svg>
                    <span style="color:#0d6efd; font-weight:600; font-family:var(--font-mono); font-size:0.8rem;">
                      <?= !empty($addresses) ? esc($addresses[0]) : esc($t['interface_name']) ?>
                    </span>
                  </span>
                </td>
                <td style="text-align:center; font-family:var(--font-mono);"><?= (int) ($t['mtu'] ?? 1420) ?></td>
                <td style="font-family:var(--font-mono); font-size:0.82rem;"><?= (int) ($t['listen_port'] ?? 51820) ?></td>
                <td style="text-align:right; font-family:var(--font-mono); font-size:0.82rem;"><?= formatWgBytes($tunnelRx) ?></td>
                <td style="text-align:right; font-family:var(--font-mono); font-size:0.82rem;"><?= formatWgBytes($tunnelTx) ?></td>
              </tr>

              <!-- Peer Status Sub-Table -->
              <?php if (!empty($tunnelPeers)): ?>
                <tr id="status-peers-row-<?= esc($t['id']) ?>" style="<?= ($settings['hide_peers'] ?? '1') === '1' ? 'display:none;' : '' ?>">
                  <td style="padding:10px 14px 10px 24px; vertical-align:top; font-weight:700; color:var(--text-secondary); background:var(--color-surface-2);">
                    Peers
                  </td>
                  <td colspan="8" class="pfs-subtable-container">
                    <table class="pfs-subtable">
                      <thead>          <tr>
            <th style="min-width:140px;">Peer</th>
            <th style="min-width:160px;">Last Handshake</th>
            <th style="min-width:180px;">Public Key</th>
            <th style="min-width:160px;">Endpoint</th>
            <th style="min-width:140px;">Allowed IPs</th>
            <th style="width:90px; text-align:right;">RX</th>
            <th style="width:90px; text-align:right;">TX</th>
          </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($tunnelPeers as $tp): ?>
                          <?php
                            $liveP = $liveData[$tp['public_key']] ?? null;
                            $handshake = (int) ($liveP['latest_handshake'] ?? 0);
                            $pRx = (float) ($liveP['rx_bytes'] ?? 0);
                            $pTx = (float) ($liveP['tx_bytes'] ?? 0);
                            $hasActiveHandshake = ($handshake > 0 && (time() - $handshake) < 180);
                          ?>
                          <tr>
                            <td>
                              <span style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
                                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:<?= $hasActiveHandshake ? '#198754' : 'var(--text-muted)' ?>;">
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                </svg>
                                <?= esc($tp['name']) ?>
                              </span>
                            </td>
                            <td style="font-size:0.75rem; color:<?= $hasActiveHandshake ? '#198754' : 'var(--text-muted)' ?>;">
                              <?= wgTimeAgo($handshake) ?>
                            </td>
                            <td>
                              <span style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);" title="<?= esc($tp['public_key']) ?>">
                                <?= esc(substr((string) $tp['public_key'], 0, 18)) ?>…
                              </span>
                            </td>
                            <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                              <?= esc($tp['endpoint'] ?: 'Dynamic') ?>
                            </td>
                            <td style="font-family:var(--font-mono); font-size:0.75rem; color:#0d6efd;">
                              <?= esc(implode(', ', array_filter([
                                  trim((string) ($tp['peer_address'] ?? '')),
                                  trim((string) ($tp['allowed_ips'] ?? '')),
                              ], static fn ($entry) => $entry !== '')) ?: '0.0.0.0/0') ?>
                            </td>
                            <td style="text-align:right; font-family:var(--font-mono); font-size:0.75rem;"><?= formatWgBytes($pRx) ?></td>
                            <td style="text-align:right; font-family:var(--font-mono); font-size:0.75rem;"><?= formatWgBytes($pTx) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Package Versions -->
  <div class="pfs-card">
    <div class="pfs-card-header">
      <span>Installed Components</span>
      <span style="font-size:0.72rem; font-weight:400; color:#ffffff;">WireGuard packages and kernel modules on this system</span>
    </div>
    <div style="overflow-x:auto;">
      <table class="pfs-table">
        <thead>
          <tr>
            <th style="width:240px;">Name</th>
            <th style="width:180px;">Version</th>
            <th>Comment</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="font-weight:600;">pfSense-pkg-WireGuard</td>
            <td style="font-family:var(--font-mono);">0.2.9_6</td>
            <td style="color:var(--text-secondary);">pfSense package WireGuard</td>
          </tr>
          <tr>
            <td style="font-weight:600;">wireguard-tools</td>
            <td style="font-family:var(--font-mono);">1.0.20210914</td>
            <td style="color:var(--text-secondary);">WireGuard command-line control tools (wg, wg-quick)</td>
          </tr>
          <tr>
            <td style="font-weight:600;">wireguard kernel module</td>
            <td style="font-family:var(--font-mono);">builtin / 1.0.0</td>
            <td style="color:var(--text-secondary);">In-kernel WireGuard subsystem</td>
          </tr>
          <tr>
            <td style="font-weight:600;">micronos-wireguard-service</td>
            <td style="font-family:var(--font-mono);">1.2.0</td>
            <td style="color:var(--text-secondary);">MicronOS WireGuard Manager &amp; Synchronization Daemon</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ================================================================
     MODAL — Tunnel (interface) add / edit
     ================================================================ -->
<div class="wg-modal-backdrop" id="tunnelModal">
  <div class="wg-modal-dialog">
    <div class="wg-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="tunnelModalTitle">Add WireGuard Tunnel</span>
      <button type="button" onclick="closeTunnelModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.25rem; line-height:1;">&times;</button>
    </div>

    <form method="POST" id="tunnelForm" action="<?= base_url('vpn/wireguard/tunnels/store') ?>">
      <?= csrf_field() ?>
      <div class="wg-modal-body modal-scroll">

        <div class="wg-subheader">Tunnel Settings</div>

        <div class="wg-form-row">
          <div class="wg-form-label">Enable<abbr>Interface state</abbr></div>
          <div class="wg-form-control">
            <div class="wg-toggle">
              <input type="checkbox" name="enabled" id="tunEnabled" value="1" checked>
              <span>Bring tunnel up on apply</span>
            </div>
            <p class="hint">
              Uncheck to keep this interface down after applying. The tunnel record, keys and peers stay intact —
              the apply script simply skips bringing the interface up, so no traffic flows over it.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Name<abbr>Tunnel label</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="name" id="tunName" class="wg-input" required maxlength="64" placeholder="e.g. HQ-Branch, Remote-Access">
            <p class="hint">A human-readable label for this tunnel — used in the UI and status pages.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Description<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="description" id="tunDescription" class="wg-input" maxlength="190" placeholder="e.g. Site-to-site link to branch office">
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Interface<abbr>Kernel device name</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="interface_name" id="tunIface" class="wg-input wg-input-mono wg-input-sm" required maxlength="15" value="<?= esc($nextIface)?>" spellcheck="false">
            <p class="hint">The kernel device name — <code>wg0</code>, <code>wg1</code>, etc. Auto-filled with the next free name.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Tunnel Address(es)<abbr>IPs assigned to this interface</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="addresses" id="tunAddresses" class="wg-input wg-input-mono" placeholder="10.9.0.1/24, fd00::1/64" style="max-width:100%;" spellcheck="false">
            <p class="hint">
              IP addresses for this tunnel interface — comma-separated (e.g. <code>10.9.0.1/24, fd00::1/64</code>).
              Each entry becomes an <code>ip address add</code> in the apply script and an <code>Address =</code> line.
              Subnets need a prefix; bare IPs default to /24 (IPv4) or /64 (IPv6).
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Listen Port<abbr>UDP</abbr></div>
          <div class="wg-form-control">
            <input type="number" name="listen_port" id="tunListenPort" class="wg-input wg-input-mono wg-input-sm" value="51820" min="1" max="65535" required spellcheck="false">
            <p class="hint">UDP port the interface listens on for incoming WireGuard packets. Default: 51820.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">MTU<abbr>Optional — default 1420</abbr></div>
          <div class="wg-form-control">
            <input type="number" name="mtu" id="tunMtu" class="wg-input wg-input-mono wg-input-xs" value="1420" min="576" max="9000" spellcheck="false">
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Interface Keys<abbr>Keypair</abbr></div>
          <div class="wg-form-control">
            <div class="wg-keys-row">
              <div style="flex:1; min-width:210px;">
                <p style="font-size:0.7rem; color:var(--text-muted); margin:0 0 4px;">Private key (base64)</p>
                <div style="position:relative;">
                  <input type="password" name="private_key" id="tunPrivateKey" class="wg-input wg-input-mono" placeholder="Paste private key" autocomplete="new-password" spellcheck="false" onchange="wgDerivePublicKey(this.value)" style="padding-right:32px;">
                  <button type="button" onclick="wgToggleKeyVisibility('tunPrivateKey', this)" title="Show/Hide Private Key" style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--text-muted); padding:4px; display:flex; align-items:center;">
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  </button>
                </div>
              </div>
              <div style="flex:1; min-width:210px;">
                <p style="font-size:0.7rem; color:var(--text-muted); margin:0 0 4px;">Public key (base64)</p>
                <input type="text" name="public_key" id="tunPublicKey" class="wg-input wg-input-mono" placeholder="Paste public key" autocomplete="off" spellcheck="false">
              </div>
              <div style="align-self:flex-end; padding-bottom:1px;">
                <button type="button" class="btn btn-ghost btn-sm" id="tunGenKeyBtn" onclick="wgGenerateKeypair()"
                        title="Generate a fresh daemon keypair (wg genkey | wg pubkey) and fill both fields"
                        style="white-space:nowrap;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                  Generate
                </button>
              </div>
            </div>
            <p class="hint">
              <strong>Generate</strong> fills both fields with a new keypair from the daemon
              (<code>wg genkey | wg pubkey</code>) — works before the tunnel is saved. You can also paste an existing keypair,
              or use the <strong>Keys</strong> button on a saved tunnel's row to re-key it later.
              <br>Editing a tunnel: leave the private key blank to keep the stored one — it is never sent to the browser.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Pre-shared Key<abbr>Optional — tunnel default</abbr></div>
          <div class="wg-form-control">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <input type="text" name="preshared_key" id="tunPresharedKey" class="wg-input wg-input-mono" placeholder="Leave blank to skip" autocomplete="off" style="flex:1; min-width:220px; max-width:none;" spellcheck="false">
              <button type="button" class="btn btn-ghost btn-sm" id="tunGenPskBtn" onclick="wgGenerateTunnelPsk()"
                      title="Generate a random pre-shared key (wg genpsk) as this tunnel's default"
                      style="white-space:nowrap;">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Generate
              </button>
            </div>
            <p class="hint">
              Optional symmetric key for post-quantum resistance. WireGuard keeps the PSK on the peer, so this value is the
              tunnel's <em>default</em> — every peer created under it inherits it unless the peer has its own.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">DNS Servers<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="dns_servers" id="tunDns" class="wg-input wg-input-mono" placeholder="10.9.0.1, 1.1.1.1" spellcheck="false">
            <p class="hint">Handed to clients that download their profile from this tunnel.</p>
          </div>
        </div>

      </div>

      <div class="wg-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeTunnelModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="tunnelSubmitBtn" style="min-width:130px;">Save Tunnel</button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================
     MODAL — Peer add / edit
     ================================================================ -->
<div class="wg-modal-backdrop" id="peerModal">
  <div class="wg-modal-dialog">
    <div class="wg-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="peerModalTitle">Add WireGuard Peer</span>
      <button type="button" onclick="closePeerModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.25rem; line-height:1;">&times;</button>
    </div>

    <form method="POST" id="peerForm" action="<?= base_url('vpn/wireguard/peers/store') ?>">
      <?= csrf_field() ?>
      <div class="wg-modal-body modal-scroll">

        <div class="wg-subheader">Peer Configuration</div>

        <div class="wg-form-row">
          <div class="wg-form-label">Enable<abbr>Peer state</abbr></div>
          <div class="wg-form-control">
            <div class="wg-toggle">
              <input type="checkbox" name="enabled" id="peerEnabled" value="1" checked>
              <span>Enable Peer</span>
            </div>
            <p class="hint" style="color:var(--color-red);">
              Note: Uncheck this option to disable this peer without removing it from the list.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Tunnel<abbr>Parent interface</abbr></div>
          <div class="wg-form-control">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <select name="tunnel_id" id="peerTunnel" class="wg-input" required style="flex:1; min-width:220px; max-width:440px;">
                <?php foreach ($tunnels as $t): ?>
                  <option value="<?= esc($t['id']) ?>"><?= esc(($t['name'] ?: $t['interface_name']) . ' (' . $t['interface_name'] . ')') ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-ghost btn-sm"
                      onclick="closePeerModal(); openTunnelModal(null);"
                      title="Save a tunnel first, then attach peers to it"
                      style="white-space:nowrap;">Create a New Tunnel</button>
            </div>
            <p class="hint">WireGuard tunnel this peer belongs to. The tunnel owns the interface, its IP address and its listen port.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Description<abbr>Peer / user name</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="name" id="peerName" class="wg-input" required maxlength="64" placeholder="e.g. branch-office or alice-laptop">
            <p class="hint">Peer description for administrative reference (not parsed).</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Dynamic Endpoint<abbr>NAT / road warrior</abbr></div>
          <div class="wg-form-control">
            <div class="wg-toggle">
              <input type="checkbox" name="dynamic_endpoint" id="peerDynamicEndpoint" value="1" onchange="wgSyncEndpointState()">
              <span>Dynamic</span>
            </div>
            <p class="hint" style="color:var(--color-red);">
              Note: Uncheck this option to assign an endpoint address and port for this peer.
            </p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Endpoint<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <div style="display:grid; grid-template-columns:minmax(200px, 320px) 130px; gap:8px 10px; align-items:start;">
              <input type="text" name="endpoint_host" id="peerEndpointHost" class="wg-input wg-input-mono" autocomplete="off" spellcheck="false" style="max-width:100%;">
              <input type="number" name="endpoint_port" id="peerEndpointPort" class="wg-input wg-input-mono" placeholder="51820" min="1" max="65535" spellcheck="false" style="max-width:100%;">
              <p class="hint">
                Hostname, IPv4, or IPv6 address of this peer.<br>
                Leave endpoint and port blank if unknown (dynamic endpoints).
              </p>
              <p class="hint">
                Port used by this peer.<br>
                Leave blank for default (51820).
              </p>
            </div>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Keep Alive<abbr>Seconds</abbr></div>
          <div class="wg-form-control">
            <input type="number" name="keepalive_secs" id="peerKeepalive" class="wg-input wg-input-mono wg-input-sm" value="2" min="0" max="3600">
            <p class="hint">Interval (in seconds) for Keep Alive packets sent to this peer. 2 is recommended behind NAT; 0 disables them.</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Public Key<abbr>Base64, 44 chars</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="public_key" id="peerPublicKey" class="wg-input wg-input-mono" required placeholder="e.g. 4+gK7uXmZ1K8…=" autocomplete="off" spellcheck="false">
            <p class="hint">The peer's WireGuard public key (generated on the remote device).</p>
          </div>
        </div>

        <div class="wg-form-row">
          <div class="wg-form-label">Pre-shared Key<abbr>Optional</abbr></div>
          <div class="wg-form-control">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <input type="text" name="preshared_key" id="peerPresharedKey" class="wg-input wg-input-mono" placeholder="Leave blank to skip" autocomplete="off" style="flex:1; min-width:220px; max-width:none;" spellcheck="false">
              <button type="button" class="btn btn-ghost btn-sm" id="peerGenPskBtn" onclick="wgGeneratePsk()"
                      title="Generate a random pre-shared key (wg genpsk) and paste the same value on the remote end"
                      style="white-space:nowrap;">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Generate
              </button>
            </div>
            <p class="hint">
              Optional symmetric key for post-quantum resistance — identical on both ends.
            </p>
          </div>
        </div>

        <div class="wg-subheader">Address Configuration</div>

        <div class="wg-form-row" style="border-bottom:none;">
          <div class="wg-form-label">Allowed IPs<abbr>Optional tunnel host route + routed networks</abbr></div>
          <div class="wg-form-control">
            <input type="text" name="allowed_ips" id="peerAllowedIPs" class="wg-input wg-input-mono" placeholder="10.9.0.2/32, 192.168.20.0/24, fd00:20::/64" style="max-width:100%;" spellcheck="false">
            <p class="hint">
              List the addresses and networks associated with this peer, comma-separated.
            </p>
          </div>
        </div>

      </div>

      <div class="wg-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closePeerModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="peerSubmitBtn" style="min-width:110px;">Save Peer</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Tab Switching ──
function switchWgTab(event, tabName) {
  if (event) event.preventDefault();
  document.querySelectorAll('.tab-panel').forEach(function(p) {
    p.classList.remove('active');
    p.style.display = 'none';
  });
  document.querySelectorAll('.pfs-tab-link').forEach(function(l) { l.classList.remove('active'); });

  var panel = document.getElementById('panel-' + tabName);
  if (panel) {
    panel.classList.add('active');
    panel.style.display = 'block';
  }

  var currentLink = document.querySelector('.pfs-tab-link[href="?tab=' + tabName + '"]');
  if (currentLink) {
    currentLink.classList.add('active');
  }

  if (window.history && window.history.pushState) {
    var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?tab=' + tabName;
    window.history.pushState({ path: newUrl }, '', newUrl);
  }
}

window.addEventListener('popstate', function() {
  var params = new URLSearchParams(window.location.search);
  var tab = params.get('tab') || 'tunnels';
  switchWgTab(null, tab);
});

// ── Toggle Nested Peer Rows in Tunnels Tab ──
function toggleTunnelPeers(tunnelId) {
  var row = document.getElementById('peers-row-' + tunnelId);
  var icon = document.getElementById('chevron-' + tunnelId);
  if (!row) return;
  var isHidden = row.style.display === 'none' || (window.getComputedStyle(row).display === 'none');
  if (isHidden) {
    row.style.display = '';
    if (icon) icon.style.transform = 'rotate(0deg)';
  } else {
    row.style.display = 'none';
    if (icon) icon.style.transform = 'rotate(-90deg)';
  }
}

// ── Toggle Nested Peer Rows in Status Tab ──
function toggleStatusPeers(tunnelId) {
  var row = document.getElementById('status-peers-row-' + tunnelId);
  var icon = document.getElementById('status-chevron-' + tunnelId);
  if (!row) return;
  var isHidden = row.style.display === 'none' || (window.getComputedStyle(row).display === 'none');
  if (isHidden) {
    row.style.display = '';
    if (icon) icon.style.transform = 'rotate(0deg)';
  } else {
    row.style.display = 'none';
    if (icon) icon.style.transform = 'rotate(-90deg)';
  }
}

// ── Open Peer Modal Pre-selected for Tunnel ──
function openPeerModalForTunnel(tunnelId) {
  openPeerModal(null);
  var sel = document.getElementById('peerTunnel');
  if (sel) {
    sel.value = tunnelId;
  }
}

// ── Copy helper ──
function copyText(text) {
  navigator.clipboard.writeText(text).then(function () {
    var el = window.event ? window.event.currentTarget : null;
    if (!el) return;
    var orig = el.style.color;
    el.style.color = 'var(--color-emerald)';
    setTimeout(function () { el.style.color = orig; }, 900);
  });
}

// ── Key / PSK generation (AJAX) ──
function wgSyncCsrf(token) {
  if (!token) return;
  document.querySelectorAll('input[name="csrf_test_name"]').forEach(function (input) {
    input.value = token;
  });
}

function wgRequestKey(params, button) {
  var tokenInput = document.querySelector('input[name="csrf_test_name"]');
  var original = button ? button.innerHTML : null;
  if (button) { button.disabled = true; button.classList.add('is-loading'); }

  var body = new URLSearchParams(params);
  if (tokenInput) body.set('csrf_test_name', tokenInput.value);

  return MicronScheduler.fetch('<?= base_url('vpn/wireguard/keygen') ?>', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: body
  }).then(function (response) {
    return response.json().then(function (json) {
      return { ok: response.ok, json: json };
    });
  }).then(function (res) {
    wgSyncCsrf(res.json && res.json.csrf);
    if (!res.ok || !res.json || res.json.ok === false) {
      throw new Error((res.json && res.json.error) || 'Key generation failed.');
    }
    return res.json;
  }).finally(function () {
    if (button) { button.disabled = false; button.classList.remove('is-loading'); button.innerHTML = original; }
  });
}

function wgToggleKeyVisibility(inputId, btn) {
  var input = document.getElementById(inputId);
  if (!input) return;
  if (input.type === 'password') {
    input.type = 'text';
    btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>';
  } else {
    input.type = 'password';
    btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
  }
}

function wgDerivePublicKey(privateKey) {
  if (!privateKey || privateKey.trim().length < 40) return;
  var pubEl = document.getElementById('tunPublicKey');
  if (pubEl && (!pubEl.value || pubEl.value.trim() === '')) {
    wgRequestKey({ kind: 'pubkey', private_key: privateKey.trim() }).then(function (data) {
      if (data && data.public_key) {
        pubEl.value = data.public_key;
        var origBorder = pubEl.style.borderColor;
        pubEl.style.borderColor = 'var(--color-emerald)';
        setTimeout(function () { pubEl.style.borderColor = origBorder; }, 900);
      }
    }).catch(function () {});
  }
}

function wgGenerateKeypair() {
  if (document.getElementById('tunPrivateKey').dataset.editing === '1'
      && !confirm('Generate a new keypair for this tunnel?\n\nIts peers are configured with the current public key and will stop handshaking until they are updated.')) {
    return;
  }
  var btn = document.getElementById('tunGenKeyBtn');
  wgRequestKey({ kind: 'keypair' }, btn).then(function (data) {
    var privEl = document.getElementById('tunPrivateKey');
    var pubEl  = document.getElementById('tunPublicKey');
    privEl.value = data.private_key;
    pubEl.value  = data.public_key;
    [privEl, pubEl].forEach(function(el) {
      var origBorder = el.style.borderColor;
      el.style.borderColor = 'var(--color-emerald)';
      setTimeout(function () { el.style.borderColor = origBorder; }, 900);
    });
  }).catch(function (err) {
    wgNotice('<strong style="color:var(--color-amber);">Could not generate a keypair.</strong> ' + err.message);
  });
}

function wgGeneratePsk() {
  wgRequestKey({ kind: 'psk' }, document.getElementById('peerGenPskBtn')).then(function (data) {
    document.getElementById('peerPresharedKey').value = data.preshared_key;
  }).catch(function (err) {
    wgNotice('<strong style="color:var(--color-amber);">Could not generate a pre-shared key.</strong> ' + err.message);
  });
}

function wgGenerateTunnelPsk() {
  wgRequestKey({ kind: 'psk' }, document.getElementById('tunGenPskBtn')).then(function (data) {
    document.getElementById('tunPresharedKey').value = data.preshared_key;
  }).catch(function (err) {
    wgNotice('<strong style="color:var(--color-amber);">Could not generate a pre-shared key.</strong> ' + err.message);
  });
}

// ── Tunnel modal ──
function openTunnelModal(tunnel) {
  var form = document.getElementById('tunnelForm');
  var editing = !!tunnel;

  form.action = editing
    ? '<?= base_url('vpn/wireguard/tunnels') ?>/' + encodeURIComponent(tunnel.id) + '/update'
    : '<?= base_url('vpn/wireguard/tunnels/store') ?>';

  var addresses = (tunnel && tunnel.addresses) ? tunnel.addresses : '';
  if (!addresses && tunnel && tunnel.ipv4_address) {
    addresses = tunnel.ipv4_address;
    if (addresses.indexOf('/') === -1 && tunnel.ipv4_subnet) {
      addresses += '/' + tunnel.ipv4_subnet;
    }
  }

  document.getElementById('tunnelModalTitle').innerText = editing ? 'Edit WireGuard Tunnel' : 'Add WireGuard Tunnel';
  document.getElementById('tunnelSubmitBtn').innerText = editing ? 'Update Tunnel' : 'Save Tunnel';

  document.getElementById('tunName').value        = (tunnel && tunnel.name) || '';
  document.getElementById('tunDescription').value = (tunnel && tunnel.description) || '';
  document.getElementById('tunIface').value       = (tunnel && tunnel.interface_name) || '<?= esc($nextIface) ?>';
  document.getElementById('tunAddresses').value   = addresses;
  document.getElementById('tunListenPort').value  = (tunnel && tunnel.listen_port) || 51820;
  document.getElementById('tunMtu').value         = (tunnel && tunnel.mtu) || 1420;

  var hideSecrets = <?= ($settings['hide_secrets'] ?? '1') === '1' ? 'true' : 'false' ?>;
  var privEl = document.getElementById('tunPrivateKey');
  var pskEl = document.getElementById('tunPresharedKey');

  if (tunnel && tunnel.private_key && !hideSecrets) {
    privEl.value = tunnel.private_key;
    privEl.type = 'text';
    privEl.placeholder = 'Paste private key';
  } else {
    privEl.value = '';
    privEl.type = 'password';
    privEl.placeholder = editing ? 'Leave blank to keep the stored key' : 'Paste private key';
  }
  privEl.dataset.editing = editing ? '1' : '0';
  document.getElementById('tunPublicKey').value   = (tunnel && tunnel.public_key) || '';

  if (tunnel && tunnel.preshared_key && !hideSecrets) {
    pskEl.value = tunnel.preshared_key;
    pskEl.placeholder = 'Leave blank to skip';
  } else {
    pskEl.value = '';
    pskEl.placeholder = editing ? 'Leave blank to keep the stored key' : 'Leave blank to skip';
  }
  document.getElementById('tunDns').value         = (tunnel && tunnel.dns_servers) || '';
  document.getElementById('tunEnabled').checked   = !tunnel || (parseInt(tunnel.enabled) !== 0);

  document.getElementById('tunnelModal').classList.add('active');
  document.querySelector('#tunnelModal .wg-modal-body').scrollTop = 0;
}
function closeTunnelModal() {
  document.getElementById('tunnelModal').classList.remove('active');
}


// ── Inline notice ──
function wgNotice(message) {
  var box = document.getElementById('wgNotice');
  var text = document.getElementById('wgNoticeText');
  if (!box || !text) return;
  text.innerHTML = message;
  box.style.display = 'flex';
}

// ── Peer modal ──
function openPeerModal(peer) {
  var form = document.getElementById('peerForm');
  var editing = !!peer;
  var select = document.getElementById('peerTunnel');

  if (!editing && select.options.length === 0) {
    wgNotice('<strong style="color:var(--color-amber);">A WireGuard peer needs a tunnel interface first.</strong> '
      + 'Opening the Add Tunnel form — save a tunnel, then add peers to it.');
    openTunnelModal(null);
    return;
  }

  if (editing && peer.tunnel_id && !Array.prototype.some.call(select.options, function (o) { return o.value === peer.tunnel_id; })) {
    var orphan = document.createElement('option');
    orphan.value = peer.tunnel_id;
    orphan.textContent = '⚠ missing tunnel (' + peer.tunnel_id + ')';
    select.appendChild(orphan);
  }

  form.action = editing
    ? '<?= base_url('vpn/wireguard/peers') ?>/' + encodeURIComponent(peer.id) + '/update'
    : '<?= base_url('vpn/wireguard/peers/store') ?>';

  document.getElementById('peerModalTitle').innerText = editing ? 'Edit Peer — ' + peer.name : 'Add WireGuard Peer';
  document.getElementById('peerSubmitBtn').innerText  = editing ? 'Update Peer' : 'Save Peer';

  select.value                                     = (peer && peer.tunnel_id) || (select.options.length ? select.options[0].value : '');
  document.getElementById('peerName').value        = (peer && peer.name) || '';
  document.getElementById('peerPublicKey').value   = (peer && peer.public_key) || '';

  var endpoint = wgSplitEndpoint(peer && peer.endpoint);
  document.getElementById('peerEndpointHost').value = endpoint.host;
  document.getElementById('peerEndpointPort').value = endpoint.port;
  document.getElementById('peerDynamicEndpoint').checked = !peer || !endpoint.host;
  wgSyncEndpointState();

  document.getElementById('peerKeepalive').value   = (peer && peer.persistent_keepalive != null) ? peer.persistent_keepalive : 2;
  document.getElementById('peerEnabled').checked   = !peer || (parseInt(peer.enabled) !== 0);

  var allowed = [];
  if (peer && peer.peer_address) { allowed.push(peer.peer_address); }
  if (peer && peer.allowed_ips)  { allowed.push(peer.allowed_ips); }
  document.getElementById('peerAllowedIPs').value = allowed.join(', ');

  var hideSecrets = <?= ($settings['hide_secrets'] ?? '1') === '1' ? 'true' : 'false' ?>;
  var pskEl = document.getElementById('peerPresharedKey');
  if (peer && peer.preshared_key && !hideSecrets) {
    pskEl.value = peer.preshared_key;
    pskEl.placeholder = 'Leave blank to skip';
  } else {
    pskEl.value = '';
    pskEl.placeholder = editing ? 'Leave blank to keep the stored key' : 'Leave blank to skip';
  }

  document.getElementById('peerModal').classList.add('active');
  document.querySelector('#peerModal .wg-modal-body').scrollTop = 0;
}
function closePeerModal() {
  document.getElementById('peerModal').classList.remove('active');
}


// ── Endpoint helpers ──
function wgSplitEndpoint(value) {
  var v = (value || '').trim();
  if (v === '') { return { host: '', port: '' }; }

  var m = v.match(/^\[([^\]]+)\]:(\d{1,5})$/);
  if (m) { return { host: m[1], port: m[2] }; }

  if (v.indexOf(':') === v.lastIndexOf(':') && v.indexOf('[') === -1) {
    m = v.match(/^(.+):(\d{1,5})$/);
    if (m) { return { host: m[1], port: m[2] }; }
  }

  return { host: v, port: '' };
}

function wgSyncEndpointState() {
  var dynamic = document.getElementById('peerDynamicEndpoint').checked;
  ['peerEndpointHost', 'peerEndpointPort'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) { return; }
    el.disabled = dynamic;
    el.style.opacity = dynamic ? '.5' : '1';
  });
}

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { closeTunnelModal(); closePeerModal(); }
});
</script>

<?= $this->endSection() ?>
