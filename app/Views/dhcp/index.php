<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<style>
/* ── Interface Selector Bar ── */
.iface-selector-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  padding: 14px 18px;
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  margin-bottom: 20px;
  align-items: center;
}
.iface-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  border-radius: var(--radius-sm);
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  text-decoration: none;
  transition: all var(--transition-fast);
}
.iface-pill:hover {
  color: var(--text-primary);
  border-color: var(--color-border-bright);
}
.iface-pill.active {
  background: rgba(0, 212, 255, 0.12);
  color: var(--color-cyan);
  border-color: rgba(0, 212, 255, 0.4);
}

/* ── Summary Stats Grid ── */
.dhcp-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>DHCP &amp; IPAM</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-emerald-bg); color:var(--tone-emerald-fg); border:1px solid var(--tone-emerald-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
        </svg>
      </div>
      <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <h1 class="page-title">DHCP Server &amp; Dynamic Leases</h1>
          <span class="badge badge-info" style="font-size:0.75rem;"><?= esc(strtoupper($currentIface['name'] ?? $currentIfaceId)) ?></span>
          <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.75rem;">
            <?= !empty($settings['enabled']) ? 'KEA DHCP ACTIVE' : 'DISABLED' ?>
          </span>
          <span class="badge badge-cyan" style="font-size:0.75rem;"><?= count($leases) ?> Leases</span>
        </div>
        <p class="page-subtitle">Per-interface Kea DHCPv4 service, dynamic address pools, PXE/TFTP boot options, and static hardware reservations</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget('kea-dhcp4') ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="openAddReservationModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
      </svg>
      Add Static Mapping
    </button>

    <?php if (session()->get('is_superadmin') || in_array('dhcp.restart', session()->get('permissions') ?? [])): ?>
      <form method="POST" action="<?= base_url('dhcp/restart') ?>" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-success btn-sm" title="Reload Kea DHCPv4 service daemon">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Restart DHCP Service
        </button>
      </form>
    <?php endif; ?>

    <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
      Firewall Rules
    </a>
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

<!-- ── Interface Selector Bar ── -->
<div class="iface-selector-bar animate-in animate-in-delay-1">
  <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.05em; margin-right:6px;">
    Select Interface:
  </span>
  <?php foreach ($interfaces as $iface): ?>
    <?php 
      $ifId = $iface['id'] ?? $iface['name'];
      $isActive = (strtolower($ifId) === strtolower($currentIfaceId));
      $isWan = !empty($iface['is_wan']);
      $chipStyle = $isWan ? 'mno-obj-chip-wan' : (strtolower($ifId) === 'dmz' ? 'mno-obj-chip-dmz' : 'mno-obj-chip-lan');
    ?>
    <a href="<?= base_url('dhcp?iface=' . urlencode($ifId)) ?>" class="iface-pill <?= $isActive ? 'active' : '' ?>">
      <span class="mno-obj-chip <?= $chipStyle ?>" style="margin:0; padding:1px 6px;"<?= \App\Libraries\ObjectTip::iface($iface) ?>>
        <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
        </svg>
        <span><?= esc(strtoupper($iface['name'] ?? $ifId)) ?></span>
      </span>
      <span style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
        <?= esc($iface['ipv4_address'] ?: 'DHCP Client') ?>
      </span>
    </a>
  <?php endforeach; ?>
</div>

<!-- ── Summary Metric Cards ── -->
<div class="dhcp-stats-grid animate-in animate-in-delay-1">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-cyan);"><?= esc($subnetStr) ?></div>
    <div class="stat-label">Subnet / CIDR Mask</div>
    <div class="stat-sublabel">Mask: <?= esc($subnetMaskStr) ?> &bull; Device: <?= esc($currentIface['physical_device'] ?? 'eth1') ?></div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-emerald);"><?= esc($settings['range_from'] ?? '—') ?> &ndash; <?= esc($settings['range_to'] ?? '—') ?></div>
    <div class="stat-label">Dynamic Address Pool</div>
    <div class="stat-sublabel">Available: <?= esc($availFromStr) ?> &ndash; <?= esc($availToStr) ?></div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-amber);"><?= count($leases) ?> Active</div>
    <div class="stat-label">Dynamic Client Leases</div>
    <div class="stat-sublabel">Default renewal lifetime: <?= esc($settings['default_lease_time'] ?? 7200) ?>s</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);"><?= count($reservations) ?> Reserved</div>
    <div class="stat-label">Static Hardware Bindings</div>
    <div class="stat-sublabel">Fixed IP mappings tied to client MAC</div>
  </div>
</div>

<!-- ── Tabs Navigation ── -->
<div class="tab-bar dhcp-tabs animate-in animate-in-delay-1">
  <button type="button" class="tab-item dhcp-tab active" id="tab-config-btn" onclick="switchDhcpTab('config', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
    </svg>
    Server Configuration
  </button>

  <button type="button" class="tab-item dhcp-tab" id="tab-leases-btn" onclick="switchDhcpTab('leases', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
    </svg>
    Active Dynamic Leases
    <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 6px;"><?= count($leases) ?></span>
  </button>

  <button type="button" class="tab-item dhcp-tab" id="tab-reservations-btn" onclick="switchDhcpTab('reservations', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
    </svg>
    Static Hardware Reservations
    <span class="badge badge-purple" style="font-size:0.65rem; padding:1px 6px;"><?= count($reservations) ?></span>
  </button>
</div>

<!-- ============================================================
     TAB 1: Server Configuration Form
============================================================ -->
<div class="dhcp-panel active" id="panel-config">
  <form method="POST" action="<?= base_url('dhcp/settings') ?>" class="animate-in animate-in-delay-1">
    <?= csrf_field() ?>
    <input type="hidden" name="interface_id" value="<?= esc($currentIfaceId) ?>">

    <div class="card card-glass" style="padding:24px; margin-bottom:24px;">
      
      <!-- General Enable & Unknown Clients Toggle -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; background:var(--color-surface-2); padding:16px; border-radius:var(--radius-sm); border:1px solid var(--color-border);">
        <div>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.875rem; color:var(--text-primary); margin:0 0 6px;">
            <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); width:16px; height:16px;">
            <span style="font-weight:700;">Enable DHCP Server on <?= esc(strtoupper($currentIface['name'] ?? $currentIfaceId)) ?></span>
          </label>
          <div style="font-size:0.75rem; color:var(--text-muted); padding-left:24px;">
            Activates Kea DHCPv4 daemon listener on interface <code><?= esc($currentIface['physical_device'] ?? 'eth1') ?></code>.
          </div>
        </div>

        <div>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.875rem; color:var(--text-primary); margin:0 0 6px;">
            <input type="checkbox" name="deny_unknown" value="1" <?= !empty($settings['deny_unknown']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); width:16px; height:16px;">
            <span style="font-weight:700;">Deny Unknown Clients (MAC Filter)</span>
          </label>
          <div style="font-size:0.75rem; color:var(--text-muted); padding-left:24px;">
            Only devices with pre-configured static hardware reservations will receive leases.
          </div>
        </div>
      </div>

      <!-- Address Pool Range -->
      <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:8px;">
        Address Pool Range
      </h3>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Range From <span style="color:var(--color-red);">*</span>
          </label>
          <input type="text" name="range_from" value="<?= esc($settings['range_from'] ?? '') ?>" placeholder="e.g. 192.168.1.10" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">First IP address available for dynamic assignment.</div>
        </div>

        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Range To <span style="color:var(--color-red);">*</span>
          </label>
          <input type="text" name="range_to" value="<?= esc($settings['range_to'] ?? '') ?>" placeholder="e.g. 192.168.1.245" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Last IP address available for dynamic assignment.</div>
        </div>
      </div>

      <!-- Gateway & Domain Configuration -->
      <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:8px;">
        Gateway &amp; Domain Parameters
      </h3>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Default Gateway (Option 3)
          </label>
          <input type="text" name="gateway" value="<?= esc($settings['gateway'] ?? '') ?>" placeholder="Leave blank to use interface IP (<?= esc($currentIface['ipv4_address'] ?? '192.168.1.1') ?>)"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
        </div>
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Default Domain Name (Option 15)
          </label>
          <input type="text" name="domain_name" value="<?= esc($settings['domain_name'] ?? 'micronos.internal') ?>" placeholder="e.g. micronos.internal"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
          Domain Search List (Option 119)
        </label>
        <input type="text" name="domain_search" value="<?= esc($settings['domain_search'] ?? '') ?>" placeholder="e.g. corp.internal, home.arpa (comma separated)"
               style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
      </div>

      <!-- DNS Servers -->
      <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:8px;">
        DNS Servers (Option 6)
      </h3>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            DNS Server 1
          </label>
          <input type="text" name="dns_1" value="<?= esc($settings['dns_1'] ?? '') ?>" placeholder="e.g. <?= esc($currentIface['ipv4_address'] ?? '192.168.1.1') ?>"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem; font-family:var(--font-mono);">
        </div>
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            DNS Server 2
          </label>
          <input type="text" name="dns_2" value="<?= esc($settings['dns_2'] ?? '') ?>" placeholder="e.g. 1.1.1.1"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem; font-family:var(--font-mono);">
        </div>
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            DNS Server 3
          </label>
          <input type="text" name="dns_3" value="<?= esc($settings['dns_3'] ?? '') ?>" placeholder="e.g. 8.8.8.8"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem; font-family:var(--font-mono);">
        </div>
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            DNS Server 4
          </label>
          <input type="text" name="dns_4" value="<?= esc($settings['dns_4'] ?? '') ?>" placeholder="Optional"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem; font-family:var(--font-mono);">
        </div>
      </div>

      <!-- Lease Times -->
      <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:8px;">
        Lease Time Parameters
      </h3>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Default Lease Time (Seconds) <span style="color:var(--color-red);">*</span>
          </label>
          <input type="number" name="default_lease_time" value="<?= esc($settings['default_lease_time'] ?? 7200) ?>" min="60" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Standard lease renewal time (Default: 7200s / 2 hours).</div>
        </div>

        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Maximum Lease Time (Seconds) <span style="color:var(--color-red);">*</span>
          </label>
          <input type="number" name="max_lease_time" value="<?= esc($settings['max_lease_time'] ?? 86400) ?>" min="60" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Absolute expiration lifetime (Default: 86400s / 24 hours).</div>
        </div>
      </div>

      <!-- Advanced Options Accordion/Card -->
      <details style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); margin-bottom:24px;">
        <summary style="padding:14px 18px; font-weight:700; font-size:0.875rem; color:var(--text-primary); cursor:pointer; display:flex; align-items:center; gap:8px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
          </svg>
          Advanced Options (NTP, PXE/TFTP Boot, WINS, Custom Options) &darr;
        </summary>
        
        <div style="padding:20px; border-top:1px solid var(--color-border);">
          <!-- NTP Servers -->
          <h4 style="font-size:0.85rem; font-weight:700; color:var(--text-primary); margin:0 0 12px;">Network Time Protocol (Option 42)</h4>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">NTP Server 1</label>
              <input type="text" name="ntp_1" value="<?= esc($settings['ntp_1'] ?? '') ?>" placeholder="e.g. pool.ntp.org"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">NTP Server 2</label>
              <input type="text" name="ntp_2" value="<?= esc($settings['ntp_2'] ?? '') ?>" placeholder="e.g. time.google.com"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
          </div>

          <!-- PXE Boot -->
          <h4 style="font-size:0.85rem; font-weight:700; color:var(--text-primary); margin:0 0 12px;">Network Booting (PXE / TFTP Option 66 &amp; 67)</h4>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:20px;">
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">TFTP Server (Option 66)</label>
              <input type="text" name="tftp_server" value="<?= esc($settings['tftp_server'] ?? '') ?>" placeholder="IP or hostname"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">Next-Server IP</label>
              <input type="text" name="next_server" value="<?= esc($settings['next_server'] ?? '') ?>" placeholder="PXE Server IP"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">Bootfile Name (Option 67)</label>
              <input type="text" name="bootfile_name" value="<?= esc($settings['bootfile_name'] ?? '') ?>" placeholder="e.g. pxelinux.0 / ipxe.efi"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
          </div>

          <!-- WINS Servers -->
          <h4 style="font-size:0.85rem; font-weight:700; color:var(--text-primary); margin:0 0 12px;">WINS Servers (Option 44)</h4>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">WINS Server 1</label>
              <input type="text" name="wins_1" value="<?= esc($settings['wins_1'] ?? '') ?>" placeholder="e.g. 192.168.1.200"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
            <div>
              <label style="display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">WINS Server 2</label>
              <input type="text" name="wins_2" value="<?= esc($settings['wins_2'] ?? '') ?>" placeholder="Optional"
                     style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem; font-family:var(--font-mono);">
            </div>
          </div>

          <!-- Custom Options -->
          <h4 style="font-size:0.85rem; font-weight:700; color:var(--text-primary); margin:0 0 8px;">Custom DHCP Options</h4>
          <p style="font-size:0.75rem; color:var(--text-muted); margin-top:0; margin-bottom:12px;">Option 43 (controller inform IP), Option 150 (VoIP TFTP server), Option 252 (WPAD proxy auto-discovery).</p>

          <div id="customOptionsContainer">
            <?php 
              $customOpts = json_decode($settings['custom_options'] ?? '[]', true) ?: [];
              if (empty($customOpts)) {
                $customOpts = [
                  ['number' => 43, 'type' => 'IP', 'value' => '192.168.1.10', 'description' => 'Controller Inform IP'],
                  ['number' => 150, 'type' => 'IP', 'value' => '192.168.1.20', 'description' => 'VoIP TFTP Server IP']
                ];
              }
            ?>
            <?php foreach ($customOpts as $opt): ?>
              <div class="custom-opt-row" style="display:grid; grid-template-columns:90px 110px 1fr 1fr 40px; gap:8px; margin-bottom:8px; align-items:center;">
                <input type="number" name="custom_opt_number[]" value="<?= esc($opt['number'] ?? '') ?>" min="1" max="254" placeholder="Opt #"
                       style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem; font-family:var(--font-mono);">
                <select name="custom_opt_type[]" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem;">
                  <option value="IP" <?= (($opt['type'] ?? '') === 'IP') ? 'selected' : '' ?>>IP Address</option>
                  <option value="Text" <?= (($opt['type'] ?? '') === 'Text') ? 'selected' : '' ?>>Text</option>
                  <option value="Boolean" <?= (($opt['type'] ?? '') === 'Boolean') ? 'selected' : '' ?>>Boolean</option>
                </select>
                <input type="text" name="custom_opt_value[]" value="<?= esc($opt['value'] ?? '') ?>" placeholder="Value"
                       style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem; font-family:var(--font-mono);">
                <input type="text" name="custom_opt_desc[]" value="<?= esc($opt['description'] ?? '') ?>" placeholder="Description"
                       style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.custom-opt-row').remove();" style="color:var(--color-red); padding:4px 8px;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </div>
            <?php endforeach; ?>
          </div>

          <button type="button" class="btn btn-ghost btn-sm" onclick="addCustomOptionRow()" style="margin-top:6px;">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Add Custom Option
          </button>
        </div>
      </details>

      <div style="display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save DHCP Options for <?= esc(strtoupper($currentIface['name'] ?? $currentIfaceId)) ?>
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ============================================================
     TAB 2: Active Dynamic Leases Table
============================================================ -->
<div class="dhcp-panel" id="panel-leases">
  <!-- Toolbar & Search -->
  <div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="position:relative;">
      <input type="text" id="leasesSearchInput" placeholder="Search leases (IP, MAC, hostname)..."
             oninput="filterLeasesTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:280px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <div style="font-size:0.75rem; color:var(--text-muted);">
      Total: <strong style="color:var(--text-primary);" id="filteredLeasesCount"><?= count($leases) ?></strong> active leases
    </div>
  </div>

  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
    <table class="mno-table" id="leasesTable">
      <thead>
        <tr>
          <th style="min-width:140px;">Assigned IP</th>
          <th style="min-width:150px;">MAC Hardware Address</th>
          <th style="min-width:160px;">Client Hostname</th>
          <th>Client ID / Vendor</th>
          <th style="width:130px;">Lease Started</th>
          <th style="width:130px;">Expires</th>
          <th style="width:80px; text-align:center;">Status</th>
          <th style="width:110px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($leases)): ?>
          <tr>
            <td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">
              No active leases currently granted on this subnet.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($leases as $lease): ?>
            <tr data-search="<?= esc(strtolower($lease['ip'] . ' ' . $lease['mac'] . ' ' . $lease['hostname'] . ' ' . ($lease['client_id'] ?? ''))) ?>">
              <td style="font-family:var(--font-mono); font-size:0.825rem; font-weight:700; color:var(--color-amber);">
                <?= esc($lease['ip']) ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($lease['mac']) ?>
              </td>
              <td style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;">
                <?= esc($lease['hostname']) ?>
              </td>
              <td style="font-size:0.75rem; color:var(--text-muted);">
                <?= esc($lease['client_id'] ?? '—') ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted);">
                <?= esc($lease['start']) ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan);">
                <?= esc($lease['expires']) ?>
              </td>
              <td style="text-align:center;">
                <span class="badge badge-success" style="font-size:0.65rem;">ACTIVE</span>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="prefillStaticModal('<?= esc($lease['ip']) ?>', '<?= esc($lease['mac']) ?>', '<?= esc($lease['hostname']) ?>')" title="Convert to Static Reservation" style="padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                  </svg>
                  <span>Make Static</span>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================
     TAB 3: Static DHCP Hardware Reservations Table
============================================================ -->
<div class="dhcp-panel" id="panel-reservations">
  <!-- Toolbar & Search -->
  <div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="position:relative;">
      <input type="text" id="resSearchInput" placeholder="Search reservations (IP, MAC, hostname)..."
             oninput="filterReservationsTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:280px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <div style="display:flex; align-items:center; gap:12px;">
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddReservationModal()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add Static Mapping
      </button>
      <div style="font-size:0.75rem; color:var(--text-muted);">
        Total: <strong style="color:var(--text-primary);" id="filteredResCount"><?= count($reservations) ?></strong> mappings
      </div>
    </div>
  </div>

  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
    <table class="mno-table" id="resTable">
      <thead>
        <tr>
          <th style="min-width:140px;">Reserved IP</th>
          <th style="min-width:160px;">MAC Hardware Address</th>
          <th style="min-width:180px;">Hostname / FQDN</th>
          <th>Description</th>
          <th style="width:90px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reservations)): ?>
          <tr>
            <td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">
              No static hardware reservations configured for this interface.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($reservations as $res): ?>
            <tr data-search="<?= esc(strtolower($res['ip_address'] . ' ' . $res['mac_address'] . ' ' . $res['hostname'] . ' ' . ($res['description'] ?? ''))) ?>">
              <td style="font-family:var(--font-mono); font-size:0.825rem; font-weight:700; color:var(--color-cyan);">
                <?= esc($res['ip_address']) ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($res['mac_address']) ?>
              </td>
              <td style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;">
                <?= esc($res['hostname']) ?>
              </td>
              <td style="color:var(--text-muted); font-size:0.75rem;">
                <?= esc($res['description'] ?: '—') ?>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-ghost btn-sm" onclick='openEditReservationModal(<?= htmlspecialchars(json_encode($res), ENT_QUOTES, "UTF-8") ?>)' title="Edit Reservation" style="padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                  </svg>
                </button>

                <form method="POST" action="<?= base_url('dhcp/reservations/' . esc($res['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete static mapping for <?= esc(addslashes($res['hostname'])) ?>?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Delete Mapping" style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
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
     MODAL: Add / Edit Static Hardware Reservation
============================================================ -->
<div class="mno-modal-backdrop" id="reservationModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="resModalTitle">Add Static DHCP Reservation</span>
      </div>
      <button type="button" onclick="closeReservationModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" id="reservationForm" action="<?= base_url('dhcp/reservations/store') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="interface_id" value="<?= esc($currentIfaceId) ?>">

      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              MAC Hardware Address <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="mac_address" id="modal_mac" required placeholder="aa:bb:cc:dd:ee:ff"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Reserved IP Address <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="ip_address" id="modal_ip" required placeholder="e.g. 192.168.1.50"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Client Hostname / FQDN <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="hostname" id="modal_hostname" required placeholder="e.g. nas-backup.lan"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          </div>

          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Description / Notes
            </label>
            <input type="text" name="description" id="modal_desc" placeholder="e.g. Department TrueNAS Storage Node"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;">
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeReservationModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="resSubmitBtn">Save Reservation</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchDhcpTab(tabId, btn) {
  document.querySelectorAll('.dhcp-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.dhcp-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-' + tabId);
  if (panel) panel.classList.add('active');
}

function openAddReservationModal() {
  document.getElementById('reservationForm').action = '<?= base_url('dhcp/reservations/store') ?>';
  document.getElementById('resModalTitle').innerText = 'Add Static DHCP Reservation';
  document.getElementById('modal_mac').value = '';
  document.getElementById('modal_ip').value = '';
  document.getElementById('modal_hostname').value = '';
  document.getElementById('modal_desc').value = '';
  document.getElementById('resSubmitBtn').innerText = 'Add Reservation';
  document.getElementById('reservationModal').classList.add('active');
}

function prefillStaticModal(ip, mac, hostname) {
  openAddReservationModal();
  document.getElementById('modal_ip').value = ip;
  document.getElementById('modal_mac').value = mac;
  document.getElementById('modal_hostname').value = hostname;
  document.getElementById('modal_desc').value = 'Converted from active lease';
}

function openEditReservationModal(res) {
  document.getElementById('reservationForm').action = '<?= base_url('dhcp/reservations') ?>/' + encodeURIComponent(res.id) + '/update';
  document.getElementById('resModalTitle').innerText = 'Edit Static DHCP Reservation';
  document.getElementById('modal_mac').value = res.mac_address || '';
  document.getElementById('modal_ip').value = res.ip_address || '';
  document.getElementById('modal_hostname').value = res.hostname || '';
  document.getElementById('modal_desc').value = res.description || '';
  document.getElementById('resSubmitBtn').innerText = 'Update Reservation';
  document.getElementById('reservationModal').classList.add('active');
}

function closeReservationModal() {
  document.getElementById('reservationModal').classList.remove('active');
}

function filterLeasesTable() {
  const query = (document.getElementById('leasesSearchInput').value || '').toLowerCase().trim();
  let count = 0;
  document.querySelectorAll('#leasesTable tbody tr').forEach(row => {
    const sData = row.dataset.search || '';
    const match = !query || sData.includes(query);
    row.style.display = match ? '' : 'none';
    if (match) count++;
  });
  const countEl = document.getElementById('filteredLeasesCount');
  if (countEl) countEl.innerText = count;
}

function filterReservationsTable() {
  const query = (document.getElementById('resSearchInput').value || '').toLowerCase().trim();
  let count = 0;
  document.querySelectorAll('#resTable tbody tr').forEach(row => {
    const sData = row.dataset.search || '';
    const match = !query || sData.includes(query);
    row.style.display = match ? '' : 'none';
    if (match) count++;
  });
  const countEl = document.getElementById('filteredResCount');
  if (countEl) countEl.innerText = count;
}

function addCustomOptionRow() {
  const container = document.getElementById('customOptionsContainer');
  const div = document.createElement('div');
  div.className = 'custom-opt-row';
  div.style.cssText = 'display:grid; grid-template-columns:90px 110px 1fr 1fr 40px; gap:8px; margin-bottom:8px; align-items:center;';
  div.innerHTML = `
    <input type="number" name="custom_opt_number[]" min="1" max="254" placeholder="Opt #" required
           style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem; font-family:var(--font-mono);">
    <select name="custom_opt_type[]" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem;">
      <option value="IP">IP Address</option>
      <option value="Text">Text</option>
      <option value="Boolean">Boolean</option>
    </select>
    <input type="text" name="custom_opt_value[]" placeholder="Value" required
           style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem; font-family:var(--font-mono);">
    <input type="text" name="custom_opt_desc[]" placeholder="Description"
           style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 8px; border-radius:var(--radius-sm); font-size:0.75rem;">
    <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.custom-opt-row').remove();" style="color:var(--color-red); padding:4px 8px;">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
      </svg>
    </button>
  `;
  container.appendChild(div);
}

// Close modals on Escape key or backdrop click
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeReservationModal();
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
