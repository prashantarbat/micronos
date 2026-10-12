<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.services-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.srv-filter-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 12px;
}
.srv-filter-tab {
  padding: 6px 14px;
  border-radius: var(--radius-md);
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: rgba(255,255,255,0.02);
  border: 1px solid var(--color-border);
  text-decoration: none;
  transition: all var(--transition-fast);
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.srv-filter-tab:hover {
  color: var(--text-primary);
  background: rgba(255,255,255,0.06);
  border-color: var(--color-border-bright);
}
.srv-filter-tab.active {
  background: var(--color-cyan-dim);
  color: var(--color-cyan);
  border-color: var(--color-cyan);
  font-weight: 700;
}
.action-btn-group {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.action-btn {
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--color-border);
  color: var(--text-secondary);
  cursor: pointer;
  transition: all var(--transition-fast);
  text-decoration: none;
}
.action-btn:hover {
  background: var(--color-cyan-dim);
  color: var(--color-cyan);
  border-color: var(--color-cyan);
}
.action-btn.btn-stop:hover {
  background: var(--color-red-dim);
  color: var(--color-red);
  border-color: var(--color-red);
}
.action-btn.btn-start:hover {
  background: var(--color-emerald-dim);
  color: var(--color-emerald);
  border-color: var(--color-emerald);
}
</style>

<!-- Header & Flash Messages -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>System</span>
      <span class="sep">/</span>
      <span class="current">Services &amp; Daemons</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-cyan">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
      </div>
      System Services &amp; Daemon Status
    </h1>
    <p class="page-subtitle">
      Manage appliance background daemons (WireGuard, OpenVPN, FRR, nftables, Kea, Unbound), configure boot startup and Service Watchdog.
    </p>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('dashboard') ?>" class="btn btn-ghost btn-sm">
      &larr; Back to Dashboard
    </a>
    <form action="<?= base_url('services/restart-all') ?>" method="POST" onsubmit="return confirm('Restart all running core services? Transient packet drops may occur.');" style="margin:0;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Restart All Services
      </button>
    </form>
  </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:20px;">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <div><?= session()->getFlashdata('success') ?></div>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-error animate-in" style="margin-bottom:20px;">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    <div><?= session()->getFlashdata('error') ?></div>
  </div>
<?php endif; ?>

<!-- Summary Stat Cards -->
<div class="services-stats-grid animate-in animate-in-delay-1">
  
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
      </svg>
    </div>
    <div class="stat-value"><?= $totalCount ?></div>
    <div class="stat-label">Total Services</div>
    <div class="stat-sublabel">Core daemon definitions</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-emerald);"><?= $runningCount ?></div>
    <div class="stat-label">Running Daemons</div>
    <div class="stat-sublabel">All operational</div>
  </div>

  <div class="stat-card <?= $stoppedCount > 0 ? 'amber' : 'emerald' ?>">
    <div class="stat-icon <?= $stoppedCount > 0 ? 'amber' : 'emerald' ?>">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:<?= $stoppedCount > 0 ? 'var(--color-amber)' : 'var(--color-emerald)' ?>;"><?= $stoppedCount ?></div>
    <div class="stat-label">Stopped / Inactive</div>
    <div class="stat-sublabel">Suricata IDS standby</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);"><?= $watchdogCount ?></div>
    <div class="stat-label">Watchdog Monitored</div>
    <div class="stat-sublabel">Auto-recovers if terminated</div>
  </div>

</div>

<!-- Filter Tabs -->
<div class="srv-filter-tabs animate-in animate-in-delay-1">
  <a href="<?= base_url('services') ?>?subsystem=all" class="srv-filter-tab <?= $subsystem === 'all' ? 'active' : '' ?>">
    All Subsystems (<?= $totalCount ?>)
  </a>
  <a href="<?= base_url('services') ?>?subsystem=firewall" class="srv-filter-tab <?= $subsystem === 'firewall' ? 'active' : '' ?>">
    Firewall (nftables)
  </a>
  <a href="<?= base_url('services') ?>?subsystem=vpn" class="srv-filter-tab <?= $subsystem === 'vpn' ? 'active' : '' ?>">
    VPN (WireGuard &amp; OpenVPN)
  </a>
  <a href="<?= base_url('services') ?>?subsystem=routing" class="srv-filter-tab <?= $subsystem === 'routing' ? 'active' : '' ?>">
    Routing (FRR OSPF/BGP)
  </a>
  <a href="<?= base_url('services') ?>?subsystem=network" class="srv-filter-tab <?= $subsystem === 'network' ? 'active' : '' ?>">
    Core Network (DHCP &amp; DNS)
  </a>
  <a href="<?= base_url('services') ?>?subsystem=security" class="srv-filter-tab <?= $subsystem === 'security' ? 'active' : '' ?>">
    Security (Squid &amp; IDS)
  </a>
  <a href="<?= base_url('services') ?>?subsystem=ha" class="srv-filter-tab <?= $subsystem === 'ha' ? 'active' : '' ?>">
    High Availability (VRRP)
  </a>
</div>

<!-- Services Table -->
<div class="card card-glass animate-in animate-in-delay-2">
  <div class="card-header">
    <div class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Daemon Process Inventory &amp; Lifecycle Controls
    </div>
    <span class="badge badge-cyan" style="font-size:0.75rem;"><?= count($services) ?> Services Listed</span>
  </div>

  <div style="overflow-x:auto;">
    <table class="iface-table">
      <thead>
        <tr>
          <th>Status</th>
          <th>Service Name</th>
          <th>Subsystem</th>
          <th>Description</th>
          <th>PID / Memory</th>
          <th>Boot State</th>
          <th>Watchdog</th>
          <th style="text-align:right;">Daemon Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($services as $srv): ?>
          <tr>
            <!-- Status Badge -->
            <td>
              <?php if ($srv['status'] === 'running'): ?>
                <span class="badge badge-success" style="font-size:0.68rem; padding:3px 8px;">
                  <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:currentColor; box-shadow:0 0 6px currentColor; margin-right:3px;"></span>
                  RUNNING
                </span>
              <?php else: ?>
                <span class="badge badge-danger" style="font-size:0.68rem; padding:3px 8px;">
                  STOPPED
                </span>
              <?php endif; ?>
            </td>

            <!-- Service & Systemd Name -->
            <td>
              <?php
                $srvIcon = match($srv['id']) {
                  'nftables'    => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
                  'wireguard'   => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
                  'openvpn'     => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
                  'frr'         => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-blue);"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
                  'kea-dhcp'    => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
                  'unbound'     => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><line x1="2" y1="12" x2="22" y2="12"/></svg>',
                  'snmpd'       => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
                  'rsyslog'     => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>',
                  'suricata'    => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-rose);"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
                  'squid'       => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
                  'keepalived'  => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
                  'sshd'        => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-blue);"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>',
                  'gateway-monitor'     => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>',
                  default       => '<svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
                };
              ?>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:6px; background:rgba(255,255,255,0.04); border:1px solid var(--color-border);">
                  <?= $srvIcon ?>
                </span>
                <div>
                  <div style="font-weight:700; font-size:0.875rem; color:var(--text-primary);"><?= esc($srv['id']) ?></div>
                  <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted);"><?= esc($srv['name']) ?>.service</div>
                </div>
              </div>
            </td>

            <!-- Subsystem category -->
            <td>
              <span class="badge badge-muted" style="text-transform:uppercase; font-size:0.68rem;">
                <?= esc($srv['subsystem']) ?>
              </span>
            </td>

            <!-- Description -->
            <td style="color:var(--text-secondary); font-size:0.82rem; max-width:280px;">
              <?= esc($srv['description']) ?>
            </td>

            <!-- PID & RAM -->
            <td>
              <?php if ($srv['status'] === 'running'): ?>
                <div style="font-family:var(--font-mono); font-size:0.78rem; color:var(--color-cyan);">PID: <?= esc($srv['pid'] ?? '—') ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted);"><?= number_format($srv['memory_mb'], 1) ?> MB RAM</div>
              <?php else: ?>
                <span style="color:var(--text-muted); font-size:0.75rem;">—</span>
              <?php endif; ?>
            </td>

            <!-- Boot Enabled Toggle -->
            <td>
              <form action="<?= base_url('services/' . esc($srv['id']) . '/toggle-boot') ?>" method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 8px; font-size:0.72rem;" title="Click to toggle startup at boot">
                  <?php if (!empty($srv['enabled_at_boot'])): ?>
                    <span style="color:var(--color-emerald); font-weight:700;">&#10003; Enabled</span>
                  <?php else: ?>
                    <span style="color:var(--text-muted);">&#10005; Disabled</span>
                  <?php endif; ?>
                </button>
              </form>
            </td>

            <!-- Watchdog Monitored Toggle -->
            <td>
              <form action="<?= base_url('services/' . esc($srv['id']) . '/toggle-watchdog') ?>" method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 8px; font-size:0.72rem;" title="Click to toggle watchdog monitoring">
                  <?php if (!empty($srv['watchdog_monitored'])): ?>
                    <span style="color:var(--color-purple); font-weight:700;">Active</span>
                  <?php else: ?>
                    <span style="color:var(--text-muted);">Off</span>
                  <?php endif; ?>
                </button>
              </form>
            </td>

            <!-- Actions -->
            <td style="text-align:right;">
              <div class="action-btn-group">
                
                <!-- Start Button (shown if stopped) -->
                <?php if ($srv['status'] !== 'running'): ?>
                  <form action="<?= base_url('services/' . esc($srv['id']) . '/start') ?>" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="action-btn btn-start" title="Start Service">
                      <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    </button>
                  </form>
                <?php else: ?>
                  <!-- Stop Button (shown if running) -->
                  <form action="<?= base_url('services/' . esc($srv['id']) . '/stop') ?>" method="POST" style="display:inline;" onsubmit="return confirm('Stop daemon <?= esc($srv['id']) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="action-btn btn-stop" title="Stop Service">
                      <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16"/></svg>
                    </button>
                  </form>
                <?php endif; ?>

                <!-- Restart Button -->
                <form action="<?= base_url('services/' . esc($srv['id']) . '/restart') ?>" method="POST" style="display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="action-btn" title="Restart Service">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                  </button>
                </form>

                <!-- View Logs Button -->
                <button type="button" class="action-btn" onclick="openServiceLogModal('<?= esc($srv['id']) ?>', '<?= esc($srv['name']) ?>')" title="Inspect Service Logs">
                  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                </button>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?= $this->include('partials/service_logs_modal') ?>

<?= $this->endSection() ?>
