<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
  $storedAdminAccess = $iface['admin_access'] ?? '[]';
  $storedAdminAccess = is_array($storedAdminAccess) ? $storedAdminAccess : (json_decode((string) $storedAdminAccess, true) ?: []);
  $selectedAdminAccess = old('admin_access', $storedAdminAccess);
  $selectedAdminAccess = is_array($selectedAdminAccess) ? $selectedAdminAccess : $storedAdminAccess;

  $nameLower = strtolower((string) ($iface['name'] ?? ''));
  $idLower   = strtolower((string) ($iface['id'] ?? ''));

  // Determine initial role for FortiGate-style role selector
  $initialRole = 'undefined';
  if (str_starts_with($nameLower, 'wan') || str_starts_with($idLower, 'wan')) {
      $initialRole = 'wan';
  } elseif (str_starts_with($nameLower, 'lan') || str_starts_with($idLower, 'lan')) {
      $initialRole = 'lan';
  } elseif (str_starts_with($nameLower, 'dmz') || str_starts_with($idLower, 'dmz')) {
      $initialRole = 'dmz';
  }

  $isCoreInterface = in_array($idLower, ['wan', 'lan'], true);
  $isVlan          = ($iface['interface_type'] ?? '') === 'vlan';
  $currentDev      = $iface['physical_device'] ?? '';
  $nicInfo         = $hardwareNics[$currentDev] ?? null;
?>

<style>
/* ── FortiGate-inspired Interface Configuration Styling ── */
.fg-edit-container {
  max-width: 1040px;
  margin: 0 auto 60px auto;
}

.fg-header-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 24px;
}

.fg-telemetry-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  background: var(--color-surface-1, rgba(255, 255, 255, 0.03));
  border: 1px solid var(--color-border, rgba(255, 255, 255, 0.08));
  border-radius: var(--radius-md, 10px);
  padding: 14px 18px;
  margin-bottom: 24px;
}

.fg-telemetry-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.fg-telemetry-label {
  font-size: 0.68rem;
  color: var(--text-muted, #94a3b8);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 600;
}

.fg-telemetry-value {
  font-size: 0.88rem;
  font-weight: 700;
  color: var(--text-primary, #f1f5f9);
  font-family: var(--font-mono, monospace);
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Role Selector Grid */
.fg-role-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-top: 8px;
}

@media (max-width: 768px) {
  .fg-role-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

.fg-role-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 12px;
  background: var(--color-surface-1, rgba(255, 255, 255, 0.02));
  border: 1px solid var(--color-border, rgba(255, 255, 255, 0.1));
  border-radius: var(--radius-md, 8px);
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  text-align: center;
  position: relative;
}

.fg-role-card:hover {
  background: rgba(255, 255, 255, 0.05);
  border-color: rgba(255, 255, 255, 0.2);
  transform: translateY(-1px);
}

.fg-role-card.active {
  border-color: var(--role-color, var(--color-cyan, #06b6d4));
  background: var(--role-bg, rgba(6, 182, 212, 0.08));
  box-shadow: 0 0 16px var(--role-glow, rgba(6, 182, 212, 0.15));
}

.fg-role-icon {
  width: 28px;
  height: 28px;
  margin-bottom: 8px;
  color: var(--text-secondary, #94a3b8);
  transition: color 0.2s;
}

.fg-role-card.active .fg-role-icon {
  color: var(--role-color, var(--color-cyan, #06b6d4));
}

.fg-role-title {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--text-primary, #f1f5f9);
}

.fg-role-desc {
  font-size: 0.68rem;
  color: var(--text-muted, #64748b);
  margin-top: 2px;
}

/* Administrative Access Grid */
.fg-admin-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-top: 14px;
}

.fg-admin-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 14px;
  background: var(--color-surface-1, rgba(255, 255, 255, 0.02));
  border: 1px solid var(--color-border, rgba(255, 255, 255, 0.09));
  border-radius: var(--radius-md, 8px);
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}

.fg-admin-card:hover {
  background: rgba(255, 255, 255, 0.04);
  border-color: rgba(255, 255, 255, 0.18);
}

.fg-admin-card.checked {
  background: rgba(6, 182, 212, 0.08);
  border-color: rgba(6, 182, 212, 0.4);
  box-shadow: 0 0 10px rgba(6, 182, 212, 0.1);
}

.fg-admin-checkbox {
  width: 18px;
  height: 18px;
  margin-top: 2px;
  accent-color: var(--color-cyan, #06b6d4);
  cursor: pointer;
}

.fg-admin-body {
  flex: 1;
}

.fg-admin-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 2px;
}

.fg-admin-name {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--text-primary, #f1f5f9);
}

.fg-admin-port {
  font-size: 0.65rem;
  font-family: var(--font-mono, monospace);
  color: var(--text-muted, #94a3b8);
  background: rgba(255, 255, 255, 0.06);
  padding: 1px 5px;
  border-radius: 4px;
}

.fg-admin-desc {
  font-size: 0.7rem;
  color: var(--text-muted, #94a3b8);
  line-height: 1.35;
}

/* Presets Bar */
.fg-presets-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px dashed var(--color-border, rgba(255, 255, 255, 0.08));
}

.fg-preset-btn {
  font-size: 0.72rem;
  padding: 4px 10px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--color-border, rgba(255, 255, 255, 0.12));
  background: var(--color-surface-2, rgba(255, 255, 255, 0.04));
  color: var(--text-secondary, #cbd5e1);
  cursor: pointer;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.fg-preset-btn:hover {
  background: rgba(255, 255, 255, 0.09);
  color: var(--text-primary, #ffffff);
  border-color: rgba(255, 255, 255, 0.25);
}

/* Status switch */
.fg-switch-wrapper {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  background: var(--color-surface-1, rgba(255, 255, 255, 0.02));
  border: 1px solid var(--color-border, rgba(255, 255, 255, 0.08));
  border-radius: var(--radius-md, 8px);
  margin-bottom: 20px;
}

.fg-switch-label {
  display: flex;
  flex-direction: column;
  gap: 2px;
  cursor: pointer;
}

.fg-card-highlight {
  border-color: rgba(6, 182, 212, 0.35) !important;
  box-shadow: 0 4px 20px rgba(6, 182, 212, 0.06);
}

.pulse-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-emerald, #10b981);
  box-shadow: 0 0 8px var(--color-emerald, #10b981);
  animation: pulse-ring 2s infinite;
}

@keyframes pulse-ring {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
</style>

<div class="fg-edit-container animate-in">

  <!-- ── Top Header & Breadcrumbs ── -->
  <div class="fg-header-bar">
    <div>
      <div style="display:flex; align-items:center; gap:8px; font-size:0.75rem; color:var(--text-muted); margin-bottom:6px;">
        <a href="<?= base_url('interfaces') ?>" style="color:var(--text-muted); text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
          </svg>
          Network Interfaces
        </a>
        <span>/</span>
        <span style="color:var(--text-primary); font-weight:600;">
          <?= esc($isNew ? 'Add Interface / VLAN' : 'Edit: ' . $iface['name']) ?>
        </span>
      </div>
      <h1 class="page-title" style="display:flex; align-items:center; gap:12px; margin:0;">
        <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
        <?= $isNew ? 'Add Interface / VLAN' : 'Configure Interface: ' . esc($iface['name']) ?>
        <?php if (! $isNew): ?>
          <?php if (! empty($iface['enabled']) && ($iface['live_status'] ?? 'down') === 'up'): ?>
            <span class="badge badge-success" style="font-size:0.72rem; display:inline-flex; align-items:center; gap:5px;">
              <span class="pulse-dot"></span> Link UP
            </span>
          <?php elseif (empty($iface['enabled'])): ?>
            <span class="badge badge-muted" style="font-size:0.72rem;">Disabled</span>
          <?php else: ?>
            <span class="badge badge-danger" style="font-size:0.72rem;">Link Down</span>
          <?php endif; ?>
        <?php endif; ?>
      </h1>
      <p class="page-subtitle" style="margin-top:4px;">
        FortiGate-style network interface management: link assignment, Administrative Access policy, addressing, and hardware parameters
      </p>
    </div>

    <div style="display:flex; gap:10px; align-items:center;">
      <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;">
        Cancel
      </a>
      <button type="button" onclick="document.getElementById('interfaceForm').submit()" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px; min-width:130px; justify-content:center;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <?= $isNew ? 'Create Interface' : 'Save & Apply' ?>
      </button>
    </div>
  </div>

  <?php if (! $isNew): ?>
    <!-- ── FortiGate Live Telemetry Strip ── -->
    <div class="fg-telemetry-strip animate-in">
      <div class="fg-telemetry-item">
        <span class="fg-telemetry-label">Kernel Port Binding</span>
        <span class="fg-telemetry-value">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m7.156-7.156a4 4 0 00-5.656 0l-3 3a4 4 0 000 5.656l1.5 1.5"/>
          </svg>
          <?= esc($iface['physical_device']) ?>
          <?php if ($isVlan): ?>
            <span class="badge badge-purple" style="font-size:0.65rem;">VLAN <?= esc($iface['vlan_tag']) ?></span>
          <?php endif; ?>
        </span>
      </div>

      <div class="fg-telemetry-item">
        <span class="fg-telemetry-label">Active IPv4 Address</span>
        <span class="fg-telemetry-value" style="color:var(--color-emerald);">
          <?= esc($iface['live_ip'] ?? $iface['ipv4_address'] ?? '—') ?><?= !empty($iface['ipv4_netmask']) ? '/' . esc($iface['ipv4_netmask']) : '' ?>
        </span>
      </div>

      <div class="fg-telemetry-item">
        <span class="fg-telemetry-label">Hardware MAC Address</span>
        <span class="fg-telemetry-value" style="font-size:0.8rem;">
          <?= esc($iface['live_mac'] ?? '—') ?>
        </span>
      </div>

      <div class="fg-telemetry-item">
        <span class="fg-telemetry-label">Negotiated Speed</span>
        <span class="fg-telemetry-value">
          <?= esc($iface['live_speed'] ?? 'Auto') ?>
        </span>
      </div>

      <div class="fg-telemetry-item">
        <span class="fg-telemetry-label">Throughput (Rx / Tx)</span>
        <span class="fg-telemetry-value" style="font-size:0.78rem;">
          <span style="color:var(--color-emerald);">↓ <?= esc($iface['rx_human'] ?? '0 B') ?></span>&nbsp;·&nbsp;<span style="color:var(--color-cyan);">↑ <?= esc($iface['tx_human'] ?? '0 B') ?></span>
        </span>
      </div>

      <?php if (!empty($iface['lldp_peer_label'])): ?>
        <div class="fg-telemetry-item" style="grid-column: 1 / -1;">
          <span class="fg-telemetry-label">Discovered Switch / Peer (LLDP)</span>
          <span class="fg-telemetry-value" style="font-size:0.8rem; color:var(--text-secondary);">
            🔗 <?= esc($iface['lldp_peer_label']) ?>
          </span>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form id="interfaceForm" method="POST" action="<?= base_url($isNew ? 'interfaces/store' : 'interfaces/save') ?>">
    <?= csrf_field() ?>
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= esc($iface['id']) ?>"><?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════
         CARD 1: GENERAL & IDENTIFICATION (FortiGate Style)
    ══════════════════════════════════════════════════════════════ -->
    <div class="card card-glass" style="margin-bottom:24px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
          </svg>
          Identification &amp; Status
        </span>
        <span style="font-size:0.72rem; color:var(--text-muted);">Step 1 of 4</span>
      </div>
      <div class="card-body" style="padding:22px;">

        <!-- FortiGate Administrative Status Toggle -->
        <div class="fg-switch-wrapper">
          <input type="checkbox" id="enabled" name="enabled" value="1" <?= ((int)($iface['enabled'] ?? 1) === 1) ? 'checked' : '' ?>
                 style="width:20px; height:20px; accent-color:var(--color-emerald); cursor:pointer;">
          <label for="enabled" class="fg-switch-label">
            <span style="font-weight:700; font-size:0.92rem; color:var(--text-primary);">
              Administrative Status: <span id="statusText" style="color:var(--color-emerald);">Enabled (Up)</span>
            </span>
            <span style="font-size:0.75rem; color:var(--text-muted);">
              Controls whether the Linux kernel interface link carrier is brought UP and permitted to transmit/receive packets.
            </span>
          </label>
        </div>

        <!-- FortiGate Interface Role Selector -->
        <div style="margin-bottom:20px;">
          <label class="form-label" style="display:flex; align-items:center; justify-content:space-between;">
            <span>Interface Role (FortiGate Classification)</span>
            <span style="font-size:0.72rem; color:var(--text-muted); font-weight:normal;">Auto-recommends Administrative Access policies</span>
          </label>
          <div class="fg-role-grid">
            <!-- WAN Role -->
            <div class="fg-role-card <?= $initialRole === 'wan' ? 'active' : '' ?>" id="roleCardWan"
                 style="--role-color:#38bdf8; --role-bg:rgba(56,189,248,0.1); --role-glow:rgba(56,189,248,0.2);"
                 onclick="selectInterfaceRole('wan')">
              <svg class="fg-role-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span class="fg-role-title">WAN</span>
              <span class="fg-role-desc">Internet / Uplink</span>
            </div>

            <!-- LAN Role -->
            <div class="fg-role-card <?= $initialRole === 'lan' ? 'active' : '' ?>" id="roleCardLan"
                 style="--role-color:#10b981; --role-bg:rgba(16,185,129,0.1); --role-glow:rgba(16,185,129,0.2);"
                 onclick="selectInterfaceRole('lan')">
              <svg class="fg-role-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
              </svg>
              <span class="fg-role-title">LAN</span>
              <span class="fg-role-desc">Internal Trusted</span>
            </div>

            <!-- DMZ Role -->
            <div class="fg-role-card <?= $initialRole === 'dmz' ? 'active' : '' ?>" id="roleCardDmz"
                 style="--role-color:#f59e0b; --role-bg:rgba(245,158,11,0.1); --role-glow:rgba(245,158,11,0.2);"
                 onclick="selectInterfaceRole('dmz')">
              <svg class="fg-role-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              <span class="fg-role-title">DMZ</span>
              <span class="fg-role-desc">Server Perimeter</span>
            </div>

            <!-- Undefined Role -->
            <div class="fg-role-card <?= $initialRole === 'undefined' ? 'active' : '' ?>" id="roleCardUndefined"
                 style="--role-color:#94a3b8; --role-bg:rgba(148,163,184,0.1); --role-glow:rgba(148,163,184,0.2);"
                 onclick="selectInterfaceRole('undefined')">
              <svg class="fg-role-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
              </svg>
              <span class="fg-role-title">Undefined</span>
              <span class="fg-role-desc">Custom Purpose</span>
            </div>
          </div>
        </div>

        <!-- Name and Description -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="name">Interface Identifier Name <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="name" name="name" class="form-control" required
                   value="<?= esc(old('name', $iface['name'])) ?>" placeholder="e.g. WAN, LAN, DMZ, OPT1">
            <div class="form-hint">Unique name used across firewall policies, routing, and NAT rules.</div>
          </div>

          <?php if ($isNew): ?>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="new_id">System Identifier / Slug</label>
              <input type="text" id="new_id" name="id" class="form-control"
                     value="<?= esc(old('id', $iface['id'])) ?>" placeholder="e.g. wan, lan, dmz (auto-generated if blank)"
                     style="font-family:var(--font-mono);">
              <div class="form-hint">Internal lowercase ID (e.g. wan, lan, opt1).</div>
            </div>
          <?php else: ?>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="description">Alias / Descriptive Notes</label>
              <input type="text" id="description" name="description" class="form-control"
                     placeholder="e.g. Corporate Fiber Primary Uplink"
                     value="<?= esc(old('description', $iface['description'] ?? '')) ?>">
              <div class="form-hint">Descriptive alias displayed on dashboards and reports.</div>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($isNew): ?>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="description">Alias / Descriptive Notes</label>
            <input type="text" id="description" name="description" class="form-control"
                   placeholder="e.g. Corporate Fiber Primary Uplink"
                   value="<?= esc(old('description', $iface['description'] ?? '')) ?>">
            <div class="form-hint">Optional descriptive alias shown across monitors.</div>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         CARD 2: HARDWARE & TYPE BINDING
    ══════════════════════════════════════════════════════════════ -->
    <div class="card card-glass" style="margin-bottom:24px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
          </svg>
          Hardware &amp; Type Binding
        </span>
        <span style="font-size:0.72rem; color:var(--text-muted);">Step 2 of 4</span>
      </div>
      <div class="card-body" style="padding:22px;">

        <?php if ($isNew): ?>
          <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Interface Category</label>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
              <label class="fg-role-card" id="typeRadioPhysicalCard" style="cursor:pointer; padding:12px;">
                <input type="radio" name="interface_type" value="physical" checked onchange="toggleInterfaceType('physical')" style="display:none;">
                <span class="fg-role-title">Physical Hardware Port</span>
                <span class="fg-role-desc">Bind to an unassigned physical Ethernet NIC</span>
              </label>

              <label class="fg-role-card" id="typeRadioVlanCard" style="cursor:pointer; padding:12px;">
                <input type="radio" name="interface_type" value="vlan" onchange="toggleInterfaceType('vlan')" style="display:none;">
                <span class="fg-role-title">802.1Q Virtual VLAN</span>
                <span class="fg-role-desc">Tagged sub-interface on a trunk port or LAG bond</span>
              </label>
            </div>
          </div>

          <div id="physicalBindingSection" class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="physical_device">Physical Hardware NIC <span style="color:var(--color-red);">*</span></label>
            <select id="physical_device" name="physical_device" class="form-control" onchange="toggleCustomNicInput(this.value)">
              <option value="">Select an available hardware port</option>
              <?php foreach (($availableDevices ?? []) as $device): ?>
                <option value="<?= esc($device) ?>" <?= old('physical_device') === $device ? 'selected' : '' ?>>
                  <?= esc(isset($hardwareNics[$device]) ? \App\Libraries\NetworkHardware::describe($hardwareNics[$device]) : $device . ' (detected port)') ?>
                </option>
              <?php endforeach; ?>
              <optgroup label="Virtual &amp; Aggregated Devices">
                <option value="br0">br0 (Software Bridge)</option>
                <option value="bond0">bond0 (LACP LAG Bond)</option>
              </optgroup>
              <option value="__custom__">+ Enter custom device name (e.g. enp3s0)</option>
            </select>
            <input type="text" id="physical_device_custom" name="physical_device_custom" class="form-control"
                   value="<?= esc(old('physical_device_custom')) ?>" placeholder="e.g. enp3s0"
                   style="display:none; margin-top:8px; font-family:var(--font-mono);">
          </div>

          <div id="vlanBindingSection" style="display:none; grid-template-columns: 2fr 1fr 1fr; gap:16px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vlan_parent">Parent Physical / LAG Trunk <span style="color:var(--color-red);">*</span></label>
              <select id="vlan_parent" name="vlan_parent" class="form-control" onchange="updateVlanPreview()">
                <?php foreach (($parentDevices ?? []) as $device => $label): ?>
                  <option value="<?= esc($device) ?>" <?= old('vlan_parent') === $device ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vlan_tag">VLAN Tag (1 - 4094) <span style="color:var(--color-red);">*</span></label>
              <input type="number" id="vlan_tag" name="vlan_tag" class="form-control" min="1" max="4094"
                     value="<?= esc(old('vlan_tag', $iface['vlan_tag'])) ?>" oninput="updateVlanPreview()" style="font-family:var(--font-mono);">
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vlan_priority">Priority (PCP 0-7)</label>
              <select id="vlan_priority" name="vlan_priority" class="form-control" style="font-family:var(--font-mono);">
                <?php for ($p = 0; $p <= 7; $p++): ?>
                  <option value="<?= $p ?>" <?= (int) old('vlan_priority', $iface['vlan_priority']) === $p ? 'selected' : '' ?>><?= $p ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div style="grid-column:1/-1; margin-top:4px;">
              <div style="font-size:0.75rem; color:var(--text-muted); display:flex; align-items:center; gap:8px;">
                Kernel device identifier preview:
                <code id="vlanKernelPreview" class="badge badge-purple" style="font-family:var(--font-mono); font-size:0.78rem;">eth0.100</code>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- Editing existing interface binding -->
          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="physical_device">Physical Hardware NIC <span style="color:var(--color-red);">*</span></label>
              <div style="display:flex; align-items:center; gap:10px;">
                <?php if ($isVlan): ?>
                  <input type="text" class="form-control" value="<?= esc($iface['physical_device']) ?>" readonly
                         style="font-family:var(--font-mono); background:var(--color-surface-1); opacity:0.85; cursor:not-allowed;">
                  <span class="badge badge-purple" style="white-space:nowrap;">802.1Q VLAN</span>
                <?php else: ?>
                  <select id="physical_device" name="physical_device" class="form-control" onchange="toggleCustomNicInput(this.value)" style="font-family:var(--font-mono);">
                    <?php 
                      $selectedDev = old('physical_device', $iface['physical_device'] ?? '');
                      $allDevs = array_unique(array_filter(array_merge(
                          array_keys($hardwareNics ?? []),
                          [$selectedDev],
                          ['eth0', 'eth1', 'eth2', 'eth3']
                      )));
                    ?>
                    <?php foreach ($allDevs as $device): ?>
                      <?php
                        $isCurrent = ($device === ($iface['physical_device'] ?? ''));
                        $assignedTo = $assignedDevicesMap[$device] ?? null;
                        $labelSuffix = '';
                        if ($isCurrent) {
                            $labelSuffix = ' — [Current Port]';
                        } elseif ($assignedTo && $assignedTo['id'] !== $iface['id']) {
                            $labelSuffix = ' — [In use by ' . esc($assignedTo['name']) . ' (Auto-Swap)]';
                        } else {
                            $labelSuffix = ' — [Available]';
                        }
                        $nicDesc = isset($hardwareNics[$device]) ? \App\Libraries\NetworkHardware::describe($hardwareNics[$device]) : "Port {$device}";
                      ?>
                      <option value="<?= esc($device) ?>" <?= $selectedDev === $device ? 'selected' : '' ?>>
                        <?= esc($nicDesc . $labelSuffix) ?>
                      </option>
                    <?php endforeach; ?>
                    <optgroup label="Virtual &amp; Aggregated Devices">
                      <option value="br0" <?= $selectedDev === 'br0' ? 'selected' : '' ?>>br0 (Software Bridge)</option>
                      <option value="bond0" <?= $selectedDev === 'bond0' ? 'selected' : '' ?>>bond0 (LACP LAG Bond)</option>
                    </optgroup>
                    <option value="__custom__">+ Enter custom device name...</option>
                  </select>
                  <span class="badge badge-cyan" style="white-space:nowrap;">PHYSICAL</span>
                <?php endif; ?>
              </div>
              <input type="text" id="physical_device_custom" name="physical_device_custom" class="form-control"
                     value="<?= esc(old('physical_device_custom')) ?>" placeholder="e.g. enp3s0"
                     style="display:none; margin-top:8px; font-family:var(--font-mono);">
              <div class="form-hint">
                <?= $isVlan ? 'Physical kernel device registered in the Linux netlink routing table.' : 'Select any hardware port (2nd, 3rd, etc.). If you choose a port used by another interface (e.g. LAN), Micron OS will safely auto-swap their ports.' ?>
              </div>
            </div>

            <?php if ($isVlan): ?>
              <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="vlan_tag">VLAN Tag (1 - 4094)</label>
                  <input type="number" id="vlan_tag" name="vlan_tag" class="form-control" min="1" max="4094"
                         value="<?= esc(old('vlan_tag', $iface['vlan_tag'])) ?>" required style="font-family:var(--font-mono);">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="vlan_priority">Priority (PCP)</label>
                  <select id="vlan_priority" name="vlan_priority" class="form-control" style="font-family:var(--font-mono);">
                    <?php for ($p = 0; $p <= 7; $p++): ?>
                      <option value="<?= $p ?>" <?= ((int)($iface['vlan_priority'] ?? 0) === $p) ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
            <?php else: ?>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Hardware Controller</label>
                <input type="text" class="form-control" value="<?= esc(isset($nicInfo['driver']) ? $nicInfo['driver'] . ' (' . ($nicInfo['mac'] ?? '') . ')' : 'Standard Ethernet PHY') ?>" readonly
                       style="background:var(--color-surface-1); opacity:0.85; cursor:not-allowed;">
                <div class="form-hint">Detected hardware NIC driver and permanent MAC address.</div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         CARD 3: ADMINISTRATIVE ACCESS (FORTIGATE SIGNATURE FEATURE)
    ══════════════════════════════════════════════════════════════ -->
    <div class="card card-glass fg-card-highlight" style="margin-bottom:24px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid rgba(6,182,212,0.25);">
        <span class="card-title" style="display:flex; align-items:center; gap:10px; color:var(--color-cyan);">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Administrative Access
          <span class="badge badge-cyan" style="font-size:0.68rem; margin-left:4px;">FORTIGATE FIREWALL FEATURE</span>
        </span>
        <span style="font-size:0.72rem; color:var(--text-muted);">Step 3 of 4</span>
      </div>
      <div class="card-body" style="padding:22px;">

        <p style="font-size:0.83rem; color:var(--text-secondary); margin:0 0 14px; line-height:1.45;">
          Specify which management protocols and listeners may accept inbound administrative connections arriving on this interface. Policies are automatically compiled into the appliance's <code>nftables</code> input filter chain for both IPv4 and IPv6 traffic.
        </p>

        <!-- FortiGate Quick Presets -->
        <div class="fg-presets-bar">
          <span style="font-size:0.74rem; font-weight:600; color:var(--text-muted); margin-right:4px;">
            Quick Presets:
          </span>
          <button type="button" class="fg-preset-btn" onclick="applyAdminPreset(['https', 'http', 'ping', 'ssh'])">
            🛡️ LAN Default (HTTPS, HTTP, PING, SSH)
          </button>
          <button type="button" class="fg-preset-btn" onclick="applyAdminPreset(['https', 'ping'])">
            🔒 Secure Admin (HTTPS, PING)
          </button>
          <button type="button" class="fg-preset-btn" onclick="applyAdminPreset(['ping'])">
            🌐 WAN Safe (PING only)
          </button>
          <button type="button" class="fg-preset-btn" onclick="applyAdminPreset([])">
            🚫 Stealth / Block All
          </button>
        </div>

        <!-- WAN Security Warning Banner -->
        <div id="wanSecurityWarning" class="alert alert-warning" style="display:none; margin-bottom:14px; font-size:0.8rem;">
          <div style="display:flex; align-items:flex-start; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0; margin-top:2px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
              <strong>External Management Exposure Alert:</strong>
              Enabling cleartext HTTP, SSH, or unauthenticated protocols on an external WAN interface exposes management services to the public Internet. Restrict authorized management IP subnets in firewall policies.
            </div>
          </div>
        </div>

        <!-- Administrative Access Protocol Grid -->
        <div class="fg-admin-grid">
          <?php
            $actSshPort = (int) ($systemSettings['ssh_port'] ?? 22);
            $sshPortText = 'TCP ' . ($actSshPort > 0 ? $actSshPort : 22);
            if ($actSshPort > 0 && $actSshPort !== 22) {
                $sshPortText .= ' (custom)';
            }
            $actWebPort = (int) ($systemSettings['webgui_port'] ?? 443);
            $httpsPortText = ($actWebPort === 443) ? 'TCP 443 / 8443' : 'TCP ' . $actWebPort . ' / 443';
            $httpPortText = ((($systemSettings['webgui_protocol'] ?? 'https') === 'http') && $actWebPort !== 80)
                ? 'TCP ' . $actWebPort . ' (HTTP)'
                : 'TCP 80';

            $adminOptions = [
              'https' => [
                'label'   => 'HTTPS',
                'port'    => $httpsPortText,
                'desc'    => 'Secure encrypted Web GUI management via TLS 1.3 / 1.2.',
                'badge'   => 'badge-cyan',
              ],
              'http' => [
                'label'   => 'HTTP',
                'port'    => $httpPortText,
                'desc'    => 'Cleartext Web GUI. Insecure; automatically redirects to HTTPS if enabled.',
                'badge'   => 'badge-amber',
              ],
              'ping' => [
                'label'   => 'PING',
                'port'    => 'ICMP Echo',
                'desc'    => 'Responds to ICMP echo requests for latency diagnosis & uptime monitoring.',
                'badge'   => 'badge-emerald',
              ],
              'ssh' => [
                'label'   => 'SSH',
                'port'    => $sshPortText,
                'desc'    => 'Encrypted terminal shell console for CLI management and automation.',
                'badge'   => 'badge-purple',
              ],
              'snmp' => [
                'label'   => 'SNMP',
                'port'    => 'UDP 161',
                'desc'    => 'Simple Network Management Protocol agent for remote NMS monitoring.',
                'badge'   => 'badge-blue',
              ],
            ];
          ?>

          <?php foreach ($adminOptions as $accessId => $opt): ?>
            <?php $isChecked = in_array($accessId, $selectedAdminAccess, true); ?>
            <label class="fg-admin-card <?= $isChecked ? 'checked' : '' ?>" id="card_admin_<?= esc($accessId) ?>">
              <input type="checkbox" name="admin_access[]" value="<?= esc($accessId) ?>"
                     class="fg-admin-checkbox" id="check_admin_<?= esc($accessId) ?>"
                     <?= $isChecked ? 'checked' : '' ?>
                     onchange="updateAdminAccessCard('<?= esc($accessId) ?>')">
              <div class="fg-admin-body">
                <div class="fg-admin-header">
                  <span class="fg-admin-name"><?= esc($opt['label']) ?></span>
                  <span class="fg-admin-port"><?= esc($opt['port']) ?></span>
                </div>
                <div class="fg-admin-desc"><?= esc($opt['desc']) ?></div>
              </div>
            </label>
          <?php endforeach; ?>
        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         CARD 4: ADDRESSING CONFIGURATION (IPv4 & IPv6)
    ══════════════════════════════════════════════════════════════ -->
    <div class="card card-glass" style="margin-bottom:24px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
          </svg>
          Addressing Configuration (IPv4 &amp; IPv6)
        </span>
        <span style="font-size:0.72rem; color:var(--text-muted);">Step 4 of 4</span>
      </div>
      <div class="card-body" style="padding:22px;">

        <!-- IPv4 Configuration Mode -->
        <div class="form-group" style="margin-bottom:18px;">
          <label class="form-label" for="ip_assignment_type">IPv4 Addressing Mode <span style="color:var(--color-red);">*</span></label>
          <select id="ip_assignment_type" name="ip_assignment_type" class="form-control" onchange="syncAddressingMode(this.value)">
            <option value="dhcp" <?= ($iface['ip_assignment_type'] === 'dhcp') ? 'selected' : '' ?>>
              DHCP (Acquire IPv4 address automatically from upstream DHCP server)
            </option>
            <option value="static" <?= ($iface['ip_assignment_type'] === 'static') ? 'selected' : '' ?>>
              Manual / Static IPv4 (Specify static address, subnet mask, and gateway)
            </option>
            <option value="pppoe" <?= ($iface['ip_assignment_type'] === 'pppoe') ? 'selected' : '' ?>>
              PPPoE Dialup (Client authentication for ISP Access Concentrator)
            </option>
            <option value="none" <?= ($iface['ip_assignment_type'] === 'none') ? 'selected' : '' ?>>
              None / Unnumbered (Layer 2 pass-through / bridge member)
            </option>
          </select>
        </div>

        <!-- Static IPv4 fields container -->
        <div id="staticIpFields" style="display:<?= ($iface['ip_assignment_type'] === 'static') ? 'block' : 'none' ?>; padding:18px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); margin-bottom:18px;">
          <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary); margin-bottom:12px; display:flex; align-items:center; gap:8px;">
            <span class="badge badge-cyan" style="font-size:0.68rem;">STATIC IPv4</span>
            Manual Address &amp; Subnet Parameters
          </div>
          <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px; margin-bottom:14px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="ipv4_address">IPv4 Address <span style="color:var(--color-red);">*</span></label>
              <input type="text" id="ipv4_address" name="ipv4_address" class="form-control"
                     placeholder="192.168.1.1" value="<?= esc(old('ipv4_address', $iface['ipv4_address'])) ?>"
                     style="font-family:var(--font-mono);">
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="ipv4_netmask">Subnet Mask (CIDR)</label>
              <select id="ipv4_netmask" name="ipv4_netmask" class="form-control" style="font-family:var(--font-mono);" onchange="updateMaskTranslation(this.value)">
                <?php for ($i = 32; $i >= 8; $i--): ?>
                  <option value="<?= $i ?>" <?= ((int)($iface['ipv4_netmask'] ?? 24) === $i) ? 'selected' : '' ?>>
                    /<?= $i ?> (<?= long2ip(-1 << (32 - $i)) ?>)
                  </option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ipv4_gateway">Default Gateway IPv4 (Optional)</label>
            <input type="text" id="ipv4_gateway" name="ipv4_gateway" class="form-control"
                   placeholder="e.g. 192.168.1.254 (Leave blank if internal LAN / DMZ subnet)"
                   value="<?= esc(old('ipv4_gateway', $iface['ipv4_gateway'])) ?>"
                   style="font-family:var(--font-mono);">
            <div class="form-hint">Specifying an upstream gateway registers a default route via this interface in the kernel routing table.</div>
          </div>
        </div>

        <!-- PPPoE fields container -->
        <div id="pppoeFields" style="display:<?= ($iface['ip_assignment_type'] === 'pppoe') ? 'block' : 'none' ?>; padding:18px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); margin-bottom:18px;">
          <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary); margin-bottom:4px; display:flex; align-items:center; gap:8px;">
            <span class="badge badge-purple" style="font-size:0.68rem;">PPPoE CLIENT</span>
            ISP Access Concentrator Credentials
          </div>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:14px;">
            Authenticate with upstream ISP broadband concentrator over this interface.
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="pppoe_username">Username <span style="color:var(--color-red);">*</span></label>
              <input type="text" id="pppoe_username" name="pppoe_username" class="form-control"
                     value="<?= esc(old('pppoe_username', $iface['pppoe_username'])) ?>" autocomplete="off"
                     style="font-family:var(--font-mono);">
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="pppoe_password">Password</label>
              <input type="password" id="pppoe_password" name="pppoe_password" class="form-control" autocomplete="new-password" spellcheck="false"
                     placeholder="<?= !empty($iface['pppoe_password']) ? '•••••••• (unchanged)' : '' ?>"
                     style="font-family:var(--font-mono);">
              <div class="form-hint">Leave blank to retain stored encrypted password.</div>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="pppoe_service">Service Name (Optional)</label>
              <input type="text" id="pppoe_service" name="pppoe_service" class="form-control"
                     placeholder="Provider service name"
                     value="<?= esc(old('pppoe_service', $iface['pppoe_service'])) ?>" style="font-family:var(--font-mono);">
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="pppoe_ac_name">Access Concentrator Name (Optional)</label>
              <input type="text" id="pppoe_ac_name" name="pppoe_ac_name" class="form-control"
                     placeholder="Leave blank to accept any AC"
                     value="<?= esc(old('pppoe_ac_name', $iface['pppoe_ac_name'])) ?>" style="font-family:var(--font-mono);">
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label style="display:flex; align-items:center; gap:12px; cursor:pointer; margin:0;">
              <input type="checkbox" name="pppoe_default_route" value="1"
                     <?= ((int)($iface['pppoe_default_route'] ?? 1) === 1) ? 'checked' : '' ?>
                     style="accent-color:var(--color-emerald); width:18px; height:18px; cursor:pointer;">
              <div>
                <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Install default route via this PPPoE link</div>
                <div style="font-size:0.75rem; color:var(--text-muted);">Uncheck on backup links to prevent overwriting primary routing table.</div>
              </div>
            </label>
          </div>
        </div>

        <!-- IPv6 Addressing Section -->
        <div style="padding-top:14px; border-top:1px solid var(--color-border); margin-top:14px;">
          <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary); margin-bottom:12px; display:flex; align-items:center; gap:8px;">
            <span class="badge badge-purple" style="font-size:0.68rem;">IPv6</span>
            IPv6 Addressing Configuration
          </div>

          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label" for="ipv6_assignment_type">IPv6 Mode</label>
            <select id="ipv6_assignment_type" name="ipv6_assignment_type" class="form-control" onchange="toggleIpv6Fields(this.value)">
              <option value="disabled" <?= (($iface['ipv6_assignment_type'] ?? 'disabled') === 'disabled') ? 'selected' : '' ?>>Disabled</option>
              <option value="dhcp6" <?= (($iface['ipv6_assignment_type'] ?? '') === 'dhcp6') ? 'selected' : '' ?>>DHCPv6 Client</option>
              <option value="slaac" <?= (($iface['ipv6_assignment_type'] ?? '') === 'slaac') ? 'selected' : '' ?>>SLAAC (Stateless Address Autoconfiguration)</option>
              <option value="static" <?= (($iface['ipv6_assignment_type'] ?? '') === 'static') ? 'selected' : '' ?>>Static IPv6 (Manual)</option>
            </select>
          </div>

          <div id="staticIpv6Container" style="display:<?= (($iface['ipv6_assignment_type'] ?? '') === 'static') ? 'block' : 'none' ?>; padding:16px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md);">
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="ipv6_address">Static IPv6 Address</label>
                <input type="text" id="ipv6_address" name="ipv6_address" class="form-control"
                       placeholder="2001:db8::1" value="<?= esc(old('ipv6_address', $iface['ipv6_address'] ?? '')) ?>"
                       style="font-family:var(--font-mono);">
              </div>

              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="ipv6_prefix">Prefix Length (/)</label>
                <input type="number" id="ipv6_prefix" name="ipv6_prefix" class="form-control" min="1" max="128"
                       value="<?= esc(old('ipv6_prefix', $iface['ipv6_prefix'] ?? 64)) ?>" style="font-family:var(--font-mono);">
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         CARD 5: ADVANCED TRAFFIC & HARDWARE PARAMETERS
    ══════════════════════════════════════════════════════════════ -->
    <div class="card card-glass" style="margin-bottom:28px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
          </svg>
          Advanced Traffic &amp; Hardware Parameters
        </span>
        <span style="font-size:0.72rem; color:var(--text-muted);">Optional</span>
      </div>
      <div class="card-body" style="padding:22px;">

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:18px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="mtu">Maximum Transmission Unit (MTU)</label>
            <input type="number" id="mtu" name="mtu" class="form-control" style="font-family:var(--font-mono);"
                   value="<?= esc(old('mtu', $iface['mtu'] ?: 1500)) ?>" min="576" max="9000">
            <div class="form-hint">Standard Ethernet is 1500. PPPoE is 1492. Jumbo frames up to 9000.</div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="mss_clamp">TCP MSS Clamping (Bytes)</label>
            <input type="number" id="mss_clamp" name="mss_clamp" class="form-control" style="font-family:var(--font-mono);"
                   placeholder="e.g. 1452 (leave blank to disable)" value="<?= esc(old('mss_clamp', $iface['mss_clamp'] ?? '')) ?>" min="500" max="8960">
            <div class="form-hint">Clamps SYN MSS to prevent fragmentation on PPPoE / IPSec links.</div>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="mac_override">MAC Address Override / Spoofing</label>
            <input type="text" id="mac_override" name="mac_override" class="form-control" style="font-family:var(--font-mono);"
                   placeholder="xx:xx:xx:xx:xx:xx" value="<?= esc(old('mac_override', $iface['mac_override'] ?? '')) ?>" maxlength="17">
            <div class="form-hint">Overrides the physical MAC presented upstream to your ISP.</div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="speed_duplex">Speed &amp; Duplex Negotiation</label>
            <select id="speed_duplex" name="speed_duplex" class="form-control" style="font-family:var(--font-mono);">
              <option value="auto" <?= (($iface['speed_duplex'] ?? 'auto') === 'auto') ? 'selected' : '' ?>>Auto-Negotiate (Recommended)</option>
              <option value="1000baseT-FD" <?= (($iface['speed_duplex'] ?? '') === '1000baseT-FD') ? 'selected' : '' ?>>1000 Mbps Full Duplex (1000baseT-FD)</option>
              <option value="100baseT-FD" <?= (($iface['speed_duplex'] ?? '') === '100baseT-FD') ? 'selected' : '' ?>>100 Mbps Full Duplex (100baseT-FD)</option>
              <option value="100baseT-HD" <?= (($iface['speed_duplex'] ?? '') === '100baseT-HD') ? 'selected' : '' ?>>100 Mbps Half Duplex (100baseT-HD)</option>
              <option value="10baseT-FD" <?= (($iface['speed_duplex'] ?? '') === '10baseT-FD') ? 'selected' : '' ?>>10 Mbps Full Duplex (10baseT-FD)</option>
            </select>
            <div class="form-hint">Physical PHY link auto-negotiation setting.</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         BOTTOM ACTION BAR
    ══════════════════════════════════════════════════════════════ -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:50px;">
      <div>
        <?php if (! $isNew && ! $isCoreInterface): ?>
          <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteFromPage()" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            Delete Interface
          </button>
        <?php endif; ?>
      </div>

      <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <?php if (! $isNew): ?>
          <label style="display:flex; align-items:center; gap:6px; font-size:.78rem; color:var(--text-muted);" title="By default, firewall policies and address objects automatically track interface IP changes. Tick to leave old literal references.">
            <input type="checkbox" name="keep_references" value="1"> Retain literal IP references
          </label>
        <?php endif; ?>
        <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary" style="min-width:150px; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          <?= $isNew ? 'Create Interface' : 'Save &amp; Apply' ?>
        </button>
      </div>
    </div>
  </form>

  <?php if (! $isNew && ! $isCoreInterface): ?>
    <form id="deleteFormDirect" method="POST" action="<?= base_url('interfaces/' . esc($iface['id']) . '/delete') ?>" style="display:none;">
      <?= csrf_field() ?>
    </form>
  <?php endif; ?>

</div>

<script>
// ── FortiGate Role Selection & Preset Automation ──
let currentRole = '<?= esc($initialRole) ?>';

function selectInterfaceRole(role) {
  currentRole = role;
  document.querySelectorAll('.fg-role-card').forEach(card => card.classList.remove('active'));
  const targetCard = document.getElementById('roleCard' + role.charAt(0).toUpperCase() + role.slice(1));
  if (targetCard) targetCard.classList.add('active');

  // Provide FortiGate-style smart defaults if user wants
  if (role === 'wan') {
    applyAdminPreset(['ping']);
  } else if (role === 'lan') {
    applyAdminPreset(['https', 'http', 'ping', 'ssh']);
  } else if (role === 'dmz') {
    applyAdminPreset(['https', 'ping']);
  }

  checkWanSecurityWarning();
}

// ── FortiGate Administrative Access Card Toggle & Presets ──
function updateAdminAccessCard(id) {
  const checkbox = document.getElementById('check_admin_' + id);
  const card = document.getElementById('card_admin_' + id);
  if (checkbox && card) {
    if (checkbox.checked) {
      card.classList.add('checked');
    } else {
      card.classList.remove('checked');
    }
  }
  checkWanSecurityWarning();
}

function applyAdminPreset(protocols) {
  const all = ['https', 'http', 'ping', 'ssh', 'snmp'];
  all.forEach(p => {
    const chk = document.getElementById('check_admin_' + p);
    if (chk) {
      chk.checked = protocols.includes(p);
      updateAdminAccessCard(p);
    }
  });
}

function checkWanSecurityWarning() {
  const isWan = currentRole === 'wan' || document.getElementById('name')?.value.toLowerCase().startsWith('wan');
  const httpChecked = document.getElementById('check_admin_http')?.checked;
  const sshChecked = document.getElementById('check_admin_ssh')?.checked;
  const warning = document.getElementById('wanSecurityWarning');
  if (warning) {
    warning.style.display = (isWan && (httpChecked || sshChecked)) ? 'block' : 'none';
  }
}

// ── Status Switch UI Sync ──
const enabledCheckbox = document.getElementById('enabled');
const statusText = document.getElementById('statusText');
if (enabledCheckbox && statusText) {
  enabledCheckbox.addEventListener('change', function() {
    if (this.checked) {
      statusText.innerText = 'Enabled (Up)';
      statusText.style.color = 'var(--color-emerald)';
    } else {
      statusText.innerText = 'Disabled (Down)';
      statusText.style.color = 'var(--color-red)';
    }
  });
}

// ── Addressing Mode Sync ──
function syncAddressingMode(mode) {
  const isStatic = mode === 'static';
  const isPppoe  = mode === 'pppoe';

  const staticFields = document.getElementById('staticIpFields');
  const pppoeFields  = document.getElementById('pppoeFields');
  if (staticFields) staticFields.style.display = isStatic ? 'block' : 'none';
  if (pppoeFields)  pppoeFields.style.display  = isPppoe ? 'block' : 'none';

  const ipInput = document.getElementById('ipv4_address');
  if (ipInput) {
    if (isStatic) ipInput.setAttribute('required', 'required');
    else ipInput.removeAttribute('required');
  }

  const userInput = document.getElementById('pppoe_username');
  if (userInput) {
    if (isPppoe) userInput.setAttribute('required', 'required');
    else userInput.removeAttribute('required');
  }
}

function toggleIpv6Fields(mode) {
  const isStatic = mode === 'static';
  const container = document.getElementById('staticIpv6Container');
  if (container) {
    container.style.display = isStatic ? 'block' : 'none';
  }
}

function updateMaskTranslation(cidr) {
  // CIDR is already translated in dropdown options
}

// ── New Interface Type Binding (Physical vs VLAN) ──
function toggleInterfaceType(type) {
  const isVlan = type === 'vlan';
  const physSection = document.getElementById('physicalBindingSection');
  const vlanSection = document.getElementById('vlanBindingSection');
  const physCard = document.getElementById('typeRadioPhysicalCard');
  const vlanCard = document.getElementById('typeRadioVlanCard');
  const physSelect = document.getElementById('physical_device');
  const vlanParent = document.getElementById('vlan_parent');
  const vlanTag = document.getElementById('vlan_tag');

  if (physSection) physSection.style.display = isVlan ? 'none' : 'block';
  if (vlanSection) vlanSection.style.display = isVlan ? 'grid' : 'none';
  if (physCard) physCard.classList.toggle('active', !isVlan);
  if (vlanCard) vlanCard.classList.toggle('active', isVlan);

  if (physSelect) physSelect.required = !isVlan;
  if (vlanParent) vlanParent.required = isVlan;
  if (vlanTag) vlanTag.required = isVlan;

  if (isVlan) updateVlanPreview();
}

function toggleCustomNicInput(value) {
  const customInput = document.getElementById('physical_device_custom');
  if (!customInput) return;
  const isCustom = value === '__custom__';
  customInput.style.display = isCustom ? 'block' : 'none';
  customInput.required = isCustom;
}

function updateVlanPreview() {
  const parent = document.getElementById('vlan_parent')?.value || 'eth0';
  const tag    = document.getElementById('vlan_tag')?.value || '100';
  const preview = document.getElementById('vlanKernelPreview');
  if (preview) {
    preview.innerText = parent + '.' + tag;
  }
}

function confirmDeleteFromPage() {
  if (confirm("Are you sure you want to permanently delete this interface? The appliance will check for active firewall policies, NAT rules, and child VLANs.")) {
    document.getElementById('deleteFormDirect').submit();
  }
}

// Initialise page states
document.addEventListener('DOMContentLoaded', function() {
  checkWanSecurityWarning();
  document.getElementById('name')?.addEventListener('input', checkWanSecurityWarning);
  <?php if ($isNew): ?>
    toggleInterfaceType('physical');
  <?php endif; ?>
});
</script>

<?= $this->endSection() ?>
