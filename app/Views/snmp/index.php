<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<style>
/* ── Custom Button Themes (Theme-adaptive) ── */
.btn-warning {
  background: var(--tone-amber-bg) !important;
  color: var(--tone-amber-fg) !important;
  border: 1px solid var(--tone-amber-border) !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
}
.btn-warning:hover {
  background: var(--tone-amber-border) !important;
  color: var(--text-primary) !important;
}
.btn-cyan {
  background: var(--tone-cyan-bg) !important;
  color: var(--tone-cyan-fg) !important;
  border: 1px solid var(--tone-cyan-border) !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
}
.btn-cyan:hover {
  background: var(--tone-cyan-border) !important;
  color: var(--text-primary) !important;
}

/* ── Telemetry Stat Cards Grid ── */
.snmp-stats-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 1.25rem;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}
@media (max-width: 1100px) {
  .snmp-stats-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .snmp-stats-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

.stat-widget {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 0.9rem 1.1rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  position: relative;
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  min-width: 0;
  box-sizing: border-box;
  transition: transform 0.2s ease, border-color 0.2s ease;
}
.stat-widget:hover {
  transform: translateY(-2px);
  border-color: var(--color-border-bright);
}
.stat-widget::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--widget-accent, var(--color-cyan)), transparent);
}
.stat-widget-title {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-widget-value {
  font-size: 1.2rem;
  font-weight: 800;
  font-family: var(--font-mono);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 3px;
}
.stat-widget-footer {
  margin-top: 8px;
  font-size: 0.73rem;
  color: var(--text-secondary);
  display: flex;
  justify-content: space-between;
  align-items: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  gap: 6px;
}

/* ── Modern Tabs Navigation ── */
.snmp-tabs-bar {
  display: flex;
  gap: 6px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 8px;
  flex-wrap: wrap;
  width: 100%;
  box-sizing: border-box;
}
.snmp-tab-btn {
  padding: 7px 13px;
  border-radius: var(--radius-md);
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all var(--transition-fast);
  white-space: nowrap;
}
.snmp-tab-btn:hover {
  color: var(--text-primary);
  background: var(--color-surface-3);
  border-color: var(--color-border-bright);
}
.snmp-tab-btn.active {
  background: var(--color-cyan-dim);
  border-color: var(--color-cyan);
  color: var(--color-cyan);
  box-shadow: 0 2px 8px rgba(0, 212, 255, 0.15);
}
.snmp-panel {
  display: none;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}
.snmp-panel.active { display: block; }

/* ── Preset Chips ── */
.preset-chip {
  padding: 3px 8px;
  border-radius: 5px;
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  color: var(--text-secondary);
  font-size: 0.72rem;
  font-family: var(--font-mono);
  cursor: pointer;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  user-select: none;
}
.preset-chip:hover {
  background: var(--color-cyan-dim);
  border-color: var(--color-cyan);
  color: var(--color-cyan);
}

.log-row {
  display: flex;
  gap: 8px;
  padding: 3px 0;
  border-bottom: 1px solid var(--color-border);
  align-items: center;
}
.log-row:hover { background: rgba(255,255,255,0.03); }
.log-time { color: var(--text-muted); font-size: 0.7rem; min-width: 125px; }
.log-sub { color: var(--text-secondary); font-weight: 600; min-width: 80px; font-size: 0.72rem; }
.log-msg { color: var(--text-primary); word-break: break-all; flex: 1; font-size: 0.74rem; }

/* ── MIB Reference Cards ── */
.mib-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 14px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
  box-sizing: border-box;
  transition: all 0.2s ease;
}
.mib-card:hover {
  background: var(--color-surface-3);
  border-color: var(--color-border-bright);
  transform: translateY(-2px);
}

/* ── NMS Template Code Snippet Box ── */
.code-snippet-box {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 10px 12px;
  font-family: var(--font-mono);
  font-size: 0.74rem;
  color: var(--tone-indigo-fg);
  overflow-x: auto;
  position: relative;
  white-space: pre-wrap;
  word-break: break-all;
}
</style>

<!-- Main Container Clamped to Viewport Width (Zero Out-of-Page Overflow) -->
<div style="width:100%; max-width:100%; box-sizing:border-box; overflow-x:clip;">

  <!-- ───────────────────────────────────────────────────────────── -->
  <!-- Header & Context Actions -->
  <!-- ───────────────────────────────────────────────────────────── -->
  <div class="page-header animate-in" style="margin-bottom: 1.25rem;">
    <div class="page-header-content">
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0; font-size:1.35rem; white-space:nowrap;">SNMP Agent &amp; Network Monitoring</h1>
        <span class="mno-obj-chip mno-obj-chip-lan">SNMP AGENT</span>
        <?php if (!empty($settings['enabled'])): ?>
          <span class="mno-obj-chip mno-obj-chip-success">NET-SNMP 5.9.3 ACTIVE</span>
        <?php else: ?>
          <span class="mno-obj-chip mno-obj-chip-wan">DAEMON DISABLED</span>
        <?php endif; ?>
        <span class="mno-obj-chip mno-obj-chip-dmz">UDP <?= esc($settings['listen_port'] ?? 161) ?></span>
      </div>
      <p class="page-subtitle" style="margin:0; font-size:0.8rem; color:var(--text-secondary); max-width:850px;">
        Net-SNMP daemon, community ACL filtering, secure SNMPv3 USM, asynchronous Traps, and enterprise MIB telemetry.
      </p>
    </div>
    <div class="page-header-actions">
      <?= \App\Libraries\ServiceStatus::widget('snmpd') ?>
      <a href="<?= base_url('services') ?>" class="btn btn-secondary btn-sm" style="padding:6px 11px; font-size:0.75rem; gap:5px;" title="Services Inventory">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
        </svg>
        Services
      </a>
      <a href="<?= base_url('siem') ?>" class="btn btn-secondary btn-sm" style="padding:6px 11px; font-size:0.75rem; gap:5px;" title="SIEM Forwarding">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        SIEM
      </a>
      <button type="button" class="btn btn-cyan btn-sm" style="padding:6px 11px; font-size:0.75rem; gap:5px;" onclick="switchSnmpTab('logs', document.getElementById('tab-logs-btn'))">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
        </svg>
        Live OID Walk
      </button>
      <button type="button" class="btn btn-warning btn-sm" style="padding:6px 11px; font-size:0.75rem; gap:5px;" onclick="sendTestTrap()">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        Send Test Trap
      </button>
    </div>
  </div>

  <?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success animate-in" style="margin-bottom:1.25rem;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
      <div><?= session()->getFlashdata('success') ?></div>
    </div>
  <?php endif; ?>

  <?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-error animate-in" style="margin-bottom:1.25rem;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      <div><?= session()->getFlashdata('error') ?></div>
    </div>
  <?php endif; ?>

  <!-- ───────────────────────────────────────────────────────────── -->
  <!-- Telemetry Stat Cards Grid (Strict Single-Row Fit) -->
  <!-- ───────────────────────────────────────────────────────────── -->
  <div class="snmp-stats-grid animate-in animate-in-delay-1">
    <!-- Card 1: Daemon Status -->
    <div class="stat-widget" style="--widget-accent: <?= !empty($settings['enabled']) ? 'var(--color-emerald)' : 'var(--color-amber)' ?>;">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span class="stat-widget-title">NET-SNMP DAEMON</span>
          <span class="status-indicator <?= !empty($settings['enabled']) ? 'online' : 'offline' ?>"></span>
        </div>
        <div class="stat-widget-value" style="color:<?= !empty($settings['enabled']) ? 'var(--color-emerald)' : 'var(--color-amber)' ?>;">
          <?= !empty($settings['enabled']) ? 'ONLINE' : 'STOPPED' ?>
        </div>
      </div>
      <div class="stat-widget-footer">
        <span>Port: UDP <?= esc($settings['listen_port'] ?? 161) ?></span>
        <span style="font-family:var(--font-mono); color:var(--color-cyan);"><?= esc($settings['listen_interfaces'] ?? 'all') ?></span>
      </div>
    </div>

    <!-- Card 2: Community & ACL -->
    <div class="stat-widget" style="--widget-accent: var(--color-cyan);">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span class="stat-widget-title">COMMUNITY &amp; ACL</span>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
        </div>
        <div class="stat-widget-value" style="color:var(--color-cyan);">
          <?= esc($settings['read_community'] ?: 'public') ?>
        </div>
      </div>
      <div class="stat-widget-footer">
        <span>Subnet Filter:</span>
        <span style="font-family:var(--font-mono); color:<?= !empty($settings['allowed_subnets']) ? 'var(--color-emerald)' : 'var(--color-amber)' ?>;">
          <?= !empty($settings['allowed_subnets']) ? 'Restricted' : 'Permissive (Any)' ?>
        </span>
      </div>
    </div>

    <!-- Card 3: SNMPv3 USM Users -->
    <div class="stat-widget" style="--widget-accent: var(--color-indigo);">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span class="stat-widget-title">SNMPV3 USM USERS</span>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-indigo);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
        </div>
        <div class="stat-widget-value" style="color:#a5b4fc;">
          <?= count($v3Users) ?> <span style="font-size:0.75rem; font-weight:500; color:var(--text-muted);">Configured</span>
        </div>
      </div>
      <div class="stat-widget-footer">
        <span>Cipher:</span>
        <span style="font-family:var(--font-mono); color:var(--color-emerald);">SHA-256 / AES-256</span>
      </div>
    </div>

    <!-- Card 4: Traps & Alerts -->
    <div class="stat-widget" style="--widget-accent: var(--color-amber);">
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span class="stat-widget-title">TRAP SINK &amp; ALERTS</span>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
        </div>
        <div class="stat-widget-value" style="color:var(--color-amber);">
          <?= !empty($settings['traps_enabled']) ? 'ACTIVE' : 'DISABLED' ?>
        </div>
      </div>
      <div class="stat-widget-footer">
        <span>Receiver:</span>
        <span style="font-family:var(--font-mono); color:var(--text-primary); max-width:130px; overflow:hidden; text-overflow:ellipsis;">
          <?= esc($settings['trap_receiver_ip'] ?? '192.168.1.50') ?>:<?= esc($settings['trap_receiver_port'] ?? 162) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- ───────────────────────────────────────────────────────────── -->
  <!-- Navigation Tabs Bar (Clean, Compact & Zero Out-of-Page Overflow) -->
  <!-- ───────────────────────────────────────────────────────────── -->
  <div class="tab-bar snmp-tabs-bar animate-in animate-in-delay-2" id="snmpTabs">
    <button type="button" class="tab-item snmp-tab-btn active" id="tab-general-btn" onclick="switchSnmpTab('general', this)">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
      </svg>
      Agent Core &amp; v1/v2c
    </button>
    <button type="button" class="tab-item snmp-tab-btn" id="tab-v3-btn" onclick="switchSnmpTab('v3', this)">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
      SNMPv3 USM Users
      <span class="badge badge-purple" style="font-size:0.65rem; padding:1px 5px;"><?= count($v3Users) ?></span>
    </button>
    <button type="button" class="tab-item snmp-tab-btn" id="tab-traps-btn" onclick="switchSnmpTab('traps', this)">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
      </svg>
      Traps &amp; Informs
      <?php if (!empty($settings['traps_enabled'])): ?>
        <span class="badge badge-success" style="font-size:0.65rem; padding:1px 5px;">Active</span>
      <?php endif; ?>
    </button>
    <button type="button" class="tab-item snmp-tab-btn" id="tab-mibs-btn" onclick="switchSnmpTab('mibs', this)">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
      </svg>
      Enterprise MIB Tree
      <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 5px;">6</span>
    </button>
    <button type="button" class="tab-item snmp-tab-btn" id="tab-nms-btn" onclick="switchSnmpTab('nms', this)">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
      </svg>
      NMS Integration
    </button>
    <button type="button" class="tab-item snmp-tab-btn" id="tab-logs-btn" onclick="switchSnmpTab('logs', this)">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
      </svg>
      Live Walk &amp; Logs
      <span class="status-indicator online" style="margin-left:2px;"></span>
    </button>
  </div>

  <!-- ============================================================
       TAB 1: General & SNMPv1 / v2c
  ============================================================ -->
  <div class="snmp-panel active" id="panel-general">
    <div class="card card-glass animate-in animate-in-delay-2" style="max-width:920px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
          </svg>
          <span class="card-title">Net-SNMP Agent Configuration</span>
        </div>
        <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.75rem;">
          <?= !empty($settings['enabled']) ? 'DAEMON ACTIVE' : 'DAEMON DISABLED' ?>
        </span>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= base_url('snmp/settings') ?>">
          <?= csrf_field() ?>

          <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:14px 16px; border-radius:var(--radius-md); margin-bottom:18px;">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary); margin-bottom:4px;">
              <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
              Enable SNMP Daemon (snmpd)
            </label>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0;">
              Activates the Net-SNMP background service to answer polling queries from NMS platforms (Zabbix, PRTG, LibreNMS, Observium, Prometheus).
            </p>
          </div>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:16px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                  <circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>
                </svg>
                Listening Port
              </label>
              <input type="number" name="listen_port" class="form-control" value="<?= esc($settings['listen_port'] ?? 161) ?>" required style="font-family:var(--font-mono);">
              <div class="form-hint">Standard SNMP listening port is UDP 161.</div>
            </div>

            <div class="form-group" style="margin:0;">
              <label class="form-label">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                  <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
                </svg>
                Listen Interfaces
              </label>
              <select name="listen_interfaces" class="form-control">
                <option value="all" <?= ($settings['listen_interfaces'] ?? '') === 'all' ? 'selected' : '' ?>>All Interfaces (0.0.0.0)</option>
                <?php foreach ($interfaces as $if): ?>
                  <option value="<?= esc($if['name']) ?>" <?= ($settings['listen_interfaces'] ?? '') === $if['name'] ? 'selected' : '' ?>>
                    <?= esc($if['name']) ?> (<?= esc($if['physical_device'] ?? $if['name']) ?> - <?= esc($if['ipv4_address'] ?? 'Unassigned') ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint">Bind daemon strictly to management interface or all networks.</div>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:20px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                  <path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                System Location (sysLocation)
              </label>
              <input type="text" name="system_location" class="form-control" value="<?= esc($settings['system_location'] ?? 'Primary DC, Rack 04') ?>" placeholder="e.g. Primary DC, Rack 04">
            </div>

            <div class="form-group" style="margin:0;">
              <label class="form-label">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                  <path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                System Contact (sysContact)
              </label>
              <input type="text" name="system_contact" class="form-control" value="<?= esc($settings['system_contact'] ?? 'noc@micronos.network') ?>" placeholder="e.g. noc@micronos.network">
            </div>
          </div>

          <!-- SNMPv1/v2c Section -->
          <div style="border-top:1px solid var(--color-border); padding-top:18px; margin-top:18px;">
            <h4 style="margin:0 0 12px; font-size:0.92rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
              SNMPv1 &amp; SNMPv2c Community Configuration
            </h4>

            <div style="margin-bottom:14px;">
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:var(--text-primary);">
                <input type="checkbox" name="v1_v2c_enabled" value="1" <?= !empty($settings['v1_v2c_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                Enable SNMPv1 / SNMPv2c Community Polling Support
              </label>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:16px;">
              <div class="form-group" style="margin:0;">
                <label class="form-label">Read Community String (RO)</label>
                <input type="text" name="read_community" class="form-control" value="<?= esc($settings['read_community'] ?? 'public') ?>" required style="font-family:var(--font-mono);">
                <div class="form-hint">NMS read-only community secret (e.g. public or custom).</div>
              </div>

              <div class="form-group" style="margin:0;">
                <label class="form-label">Write Community String (RW - Optional)</label>
                <input type="text" name="write_community" class="form-control" value="<?= esc($settings['write_community'] ?? '') ?>" placeholder="Leave blank to disable SET operations" style="font-family:var(--font-mono);">
                <div class="form-hint" style="color:var(--color-amber);">⚠ Caution: Write access permits remote parameter modification.</div>
              </div>
            </div>

            <div class="form-group" style="margin-bottom:18px;">
              <label class="form-label">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Authorized NMS Subnets / Hosts (CIDR Filter)
              </label>
              <textarea name="allowed_subnets" class="form-control" rows="2" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="e.g. 192.168.1.50/32, 10.10.0.0/16"><?= esc($settings['allowed_subnets'] ?? '') ?></textarea>
              <div class="form-hint">Restrict SNMP polling strictly to these IP addresses or CIDRs. Leave empty to allow all internal clients.</div>
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end;">
            <button type="submit" class="btn btn-primary" style="gap:6px;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
              </svg>
              Save SNMP Configuration
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ============================================================
       TAB 2: SNMPv3 Security (USM Users)
  ============================================================ -->
  <div class="snmp-panel" id="panel-v3">
    <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
          <span class="card-title" style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            SNMPv3 User-Based Security Model (USM)
          </span>
          <p style="margin:4px 0 0; font-size:0.75rem; color:var(--text-muted);">Provides cryptographic authentication (SHA-256 / SHA-512) and privacy encryption (AES-256) without cleartext community strings.</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddV3UserModal()" style="gap:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          Add SNMPv3 User
        </button>
      </div>
      
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr>
              <th>Status</th>
              <th>Username</th>
              <th>Security Level</th>
              <th>Auth Protocol</th>
              <th>Privacy Encryption</th>
              <th>Access</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($v3Users)): ?>
              <tr>
                <td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">
                  <div style="display:inline-flex; flex-direction:column; align-items:center; gap:8px;">
                    <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--text-muted);">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <span>No SNMPv3 users configured. Click "+ Add SNMPv3 User" to create an encrypted polling account.</span>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($v3Users as $u): ?>
                <tr>
                  <td>
                    <form action="<?= base_url('snmp/v3-users/' . esc($u['id']) . '/toggle') ?>" method="POST" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 8px; font-size:0.68rem;" title="Toggle Account Status">
                        <?php if (!empty($u['enabled'])): ?>
                          <span class="badge badge-success">ACTIVE</span>
                        <?php else: ?>
                          <span class="badge badge-muted">DISABLED</span>
                        <?php endif; ?>
                      </button>
                    </form>
                  </td>
                  <td style="font-family:var(--font-mono); font-weight:700; color:var(--text-primary);">
                    <?= esc($u['username']) ?>
                  </td>
                  <td>
                    <span class="badge badge-purple" style="font-size:0.7rem; font-family:var(--font-mono);">
                      <?= esc($u['security_level']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="mno-obj-chip mno-obj-chip-lan" style="font-size:0.7rem;">
                      <?= esc($u['auth_protocol'] ?: 'None') ?>
                    </span>
                  </td>
                  <td>
                    <span class="mno-obj-chip mno-obj-chip-success" style="font-size:0.7rem;">
                      <?= esc($u['priv_protocol'] ?: 'None') ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge badge-blue" style="font-size:0.68rem;">
                      <?= strtoupper(esc($u['access_mode'])) ?>
                    </span>
                  </td>
                  <td style="text-align:right;">
                    <button type="button" class="btn btn-secondary btn-sm" style="padding:2px 8px; font-size:0.7rem; margin-right:4px;" onclick="showV3CliSnippet('<?= esc($u['username']) ?>', '<?= esc($u['security_level']) ?>', '<?= esc($u['auth_protocol']) ?>', '<?= esc($u['priv_protocol']) ?>')">
                      CLI Command
                    </button>
                    <form action="<?= base_url('snmp/v3-users/' . esc($u['id']) . '/delete') ?>" method="POST" onsubmit="return confirm('Delete this SNMPv3 user account?');" style="display:inline;">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-rose); padding:4px 8px;" title="Delete User">
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2.032 2.032 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
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
  </div>

  <!-- ============================================================
       TAB 3: SNMP Traps & Informs
  ============================================================ -->
  <div class="snmp-panel" id="panel-traps">
    <div class="card card-glass animate-in animate-in-delay-2" style="max-width:920px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <span class="card-title">SNMP Traps &amp; Informs Configuration</span>
        </div>
        <button type="button" class="btn btn-warning btn-sm" onclick="sendTestTrap()">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
          </svg>
          Send Test Trap
        </button>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= base_url('snmp/traps') ?>">
          <?= csrf_field() ?>

          <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:14px 16px; border-radius:var(--radius-md); margin-bottom:18px;">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary); margin-bottom:4px;">
              <input type="checkbox" name="traps_enabled" value="1" <?= !empty($settings['traps_enabled']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
              Enable SNMP Trap Generation
            </label>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0;">
              Transmits asynchronous alert traps to your central monitoring receiver (NMS) upon hardware, link, HA, and threshold events.
            </p>
          </div>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:16px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Trap Receiver Host / IP</label>
              <input type="text" name="trap_receiver_ip" class="form-control" value="<?= esc($settings['trap_receiver_ip'] ?? '192.168.1.50') ?>" required style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Trap Port</label>
              <input type="number" name="trap_receiver_port" class="form-control" value="<?= esc($settings['trap_receiver_port'] ?? 162) ?>" required style="font-family:var(--font-mono);">
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Trap Version</label>
              <select name="trap_version" class="form-control">
                <option value="v2c" <?= ($settings['trap_version'] ?? '') === 'v2c' ? 'selected' : '' ?>>SNMPv2c</option>
                <option value="v3" <?= ($settings['trap_version'] ?? '') === 'v3' ? 'selected' : '' ?>>SNMPv3</option>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:18px;">
            <label class="form-label">Trap Community String</label>
            <input type="text" name="trap_community" class="form-control" value="<?= esc($settings['trap_community'] ?? 'public') ?>" style="font-family:var(--font-mono); max-width:320px;">
          </div>

          <div style="border-top:1px solid var(--color-border); padding-top:16px; margin-bottom:18px;">
            <label class="form-label" style="margin-bottom:12px; font-weight:700;">Trigger Traps on Security &amp; Hardware Events:</label>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
              <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:6px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; font-weight:600; color:var(--text-primary);">
                  <input type="checkbox" name="trap_link_events" value="1" <?= !empty($settings['trap_link_events']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                  Interface Link State Changes (linkDown / linkUp)
                </label>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-left:24px; margin-top:2px;">Standard IF-MIB trap sent when physical carrier status shifts.</div>
              </div>

              <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:6px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; font-weight:600; color:var(--text-primary);">
                  <input type="checkbox" name="trap_ha_events" value="1" <?= !empty($settings['trap_ha_events']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                  VRRP High Availability Failovers
                </label>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-left:24px; margin-top:2px;">Dispatched when cluster node transitions between MASTER and BACKUP.</div>
              </div>

              <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:6px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; font-weight:600; color:var(--text-primary);">
                  <input type="checkbox" name="trap_threshold_events" value="1" <?= !empty($settings['trap_threshold_events']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                  Resource Threshold Alarms (CPU &gt;90%, States &gt;85%)
                </label>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-left:24px; margin-top:2px;">Triggers when hardware saturation or conntrack table exhaustion is detected.</div>
              </div>

              <div style="padding:10px 12px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:6px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; font-weight:600; color:var(--text-primary);">
                  <input type="checkbox" name="trap_auth_events" value="1" <?= !empty($settings['trap_auth_events']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                  Administrative Authentication Failures
                </label>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-left:24px; margin-top:2px;">authenticationFailure trap when invalid SNMP credentials or brute force is detected.</div>
              </div>
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end;">
            <button type="submit" class="btn btn-primary" style="gap:6px;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
              </svg>
              Save Trap Settings
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ============================================================
       TAB 4: Enterprise MIB Tree & OID Reference
  ============================================================ -->
  <div class="snmp-panel" id="panel-mibs">
    <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div>
          <span class="card-title" style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Supported Enterprise MIB Modules &amp; OID Reference
          </span>
          <p style="margin:4px 0 0; font-size:0.75rem; color:var(--text-muted);">Standard IETF and Enterprise MIB trees exported by the Net-SNMP daemon on MicronOS appliances.</p>
        </div>
        <span class="mno-obj-chip mno-obj-chip-lan">ENTERPRISE PEN: 59999</span>
      </div>
      <div class="card-body">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">
          
          <!-- MIB 1: IF-MIB -->
          <div class="mib-card">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:var(--color-cyan); font-size:0.88rem;">IF-MIB (RFC 2863)</strong>
                <span class="mno-obj-chip mno-obj-chip-lan" style="font-size:0.65rem;">Interfaces</span>
              </div>
              <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:10px;">
                Physical interfaces, operStatus (up/down), MTU, and 64-bit HC counters (`ifHCInOctets`).
              </p>
              <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); background:rgba(0,0,0,0.3); padding:6px 8px; border-radius:4px; margin-bottom:12px;">
                OID: <strong>.1.3.6.1.2.1.2.2</strong> (ifTable)<br>
                OID: <strong>.1.3.6.1.2.1.31.1.1</strong> (ifXTable)
              </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; font-size:0.72rem;" onclick="testOidDirect('interfaces')">
              Walk IF-MIB &rarr;
            </button>
          </div>

          <!-- MIB 2: HOST-RESOURCES-MIB -->
          <div class="mib-card">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:var(--color-indigo); font-size:0.88rem;">HOST-RESOURCES-MIB</strong>
                <span class="mno-obj-chip mno-obj-chip-dmz" style="font-size:0.65rem;">RFC 2790</span>
              </div>
              <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:10px;">
                CPU core loads (`hrProcessorLoad`), physical RAM, swap space, and mounted disk partitions.
              </p>
              <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); background:rgba(0,0,0,0.3); padding:6px 8px; border-radius:4px; margin-bottom:12px;">
                OID: <strong>.1.3.6.1.2.1.25.3.3</strong> (CPU)<br>
                OID: <strong>.1.3.6.1.2.1.25.2.3</strong> (Storage)
              </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; font-size:0.72rem;" onclick="testOidDirect('cpu')">
              Walk Host Resources &rarr;
            </button>
          </div>

          <!-- MIB 3: UCD-SNMP-MIB -->
          <div class="mib-card">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:var(--color-emerald); font-size:0.88rem;">UCD-SNMP-MIB</strong>
                <span class="mno-obj-chip mno-obj-chip-success" style="font-size:0.65rem;">Linux Core</span>
              </div>
              <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:10px;">
                Linux system load averages (1/5/15m averages), raw CPU ticks, and cached memory breakdown.
              </p>
              <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); background:rgba(0,0,0,0.3); padding:6px 8px; border-radius:4px; margin-bottom:12px;">
                OID: <strong>.1.3.6.1.4.1.2021.10</strong> (laLoad)<br>
                OID: <strong>.1.3.6.1.4.1.2021.4</strong> (Memory)
              </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; font-size:0.72rem;" onclick="testOidDirect('cpu')">
              Walk System Load &rarr;
            </button>
          </div>

          <!-- MIB 4: MICRONOS-ENTERPRISE-MIB -->
          <div class="mib-card">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:var(--color-amber); font-size:0.88rem;">MICRONOS-ENTERPRISE</strong>
                <span class="mno-obj-chip mno-obj-chip-wan" style="font-size:0.65rem;">PEN 59999</span>
              </div>
              <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:10px;">
                Firewall conntrack state count, drop counters, VRRP HA cluster state, and VPN tunnels.
              </p>
              <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); background:rgba(0,0,0,0.3); padding:6px 8px; border-radius:4px; margin-bottom:12px;">
                OID: <strong>.1.3.6.1.4.1.59999.1</strong> (Firewall)<br>
                OID: <strong>.1.3.6.1.4.1.59999.2</strong> (HA &amp; VPN)
              </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; font-size:0.72rem;" onclick="testOidDirect('firewall')">
              Walk Enterprise Firewall &rarr;
            </button>
          </div>

          <!-- MIB 5: IP-FORWARD-MIB -->
          <div class="mib-card">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:#38bdf8; font-size:0.88rem;">IP-MIB &amp; IP-FORWARD</strong>
                <span class="mno-obj-chip mno-obj-chip-lan" style="font-size:0.65rem;">Routing</span>
              </div>
              <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:10px;">
                FIB kernel routing table (`ipCidrRouteTable`), next-hop gateways, and ARP translation.
              </p>
              <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); background:rgba(0,0,0,0.3); padding:6px 8px; border-radius:4px; margin-bottom:12px;">
                OID: <strong>.1.3.6.1.2.1.4.24</strong> (FIB Route)<br>
                OID: <strong>.1.3.6.1.2.1.4.22</strong> (ARP Cache)
              </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; font-size:0.72rem;" onclick="testOidDirect('routing')">
              Walk Routing FIB &rarr;
            </button>
          </div>

          <!-- MIB 6: SNMPv2-MIB -->
          <div class="mib-card">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:var(--text-primary); font-size:0.88rem;">SNMPv2-MIB (system)</strong>
                <span class="mno-obj-chip" style="font-size:0.65rem;">RFC 3418</span>
              </div>
              <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:10px;">
                Core system group with `sysDescr`, `sysObjectID`, `sysUpTime`, `sysContact`, and `sysName`.
              </p>
              <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); background:rgba(0,0,0,0.3); padding:6px 8px; border-radius:4px; margin-bottom:12px;">
                OID: <strong>.1.3.6.1.2.1.1.1</strong> (sysDescr)<br>
                OID: <strong>.1.3.6.1.2.1.1.3</strong> (sysUpTime)
              </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; font-size:0.72rem;" onclick="testOidDirect('system')">
              Walk System Group &rarr;
            </button>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================
       TAB 5: NMS Integration Templates & Quick Connect
  ============================================================ -->
  <div class="snmp-panel" id="panel-nms">
    <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          NMS Platform Setup Recipes &amp; CLI Commands
        </span>
        <p style="margin:4px 0 0; font-size:0.75rem; color:var(--text-muted);">Quick copy configuration snippets to onboard this MicronOS firewall into popular monitoring servers.</p>
      </div>
      <div class="card-body">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
          
          <!-- Recipe 1: Zabbix -->
          <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="color:#f87171; font-size:0.88rem;">1. Zabbix Server Integration</strong>
              <span class="badge badge-purple" style="font-size:0.65rem;">Zabbix 6.x / 7.x</span>
            </div>
            <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:8px;">
              Add host in Zabbix pointing to firewall IP on port 161:
            </p>
            <div class="code-snippet-box">Template: "Linux by SNMP" OR "Generic SNMP"
SNMP Version: SNMPv2c
{$SNMP_COMMUNITY} => "<?= esc($settings['read_community'] ?: 'public') ?>"
Port: <?= esc($settings['listen_port'] ?? 161) ?></div>
          </div>

          <!-- Recipe 2: LibreNMS / Observium -->
          <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="color:var(--color-cyan); font-size:0.88rem;">2. LibreNMS Auto-Discovery</strong>
              <span class="badge badge-cyan" style="font-size:0.65rem;">LibreNMS</span>
            </div>
            <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:8px;">
              Run from your LibreNMS CLI console to discover NICs:
            </p>
            <div class="code-snippet-box">lnms device:add -v2c -c <?= esc($settings['read_community'] ?: 'public') ?> -p <?= esc($settings['listen_port'] ?? 161) ?> 192.168.1.1</div>
          </div>

          <!-- Recipe 3: Prometheus SNMP Exporter -->
          <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="color:var(--color-amber); font-size:0.88rem;">3. Prometheus SNMP Exporter</strong>
              <span class="badge badge-warning" style="font-size:0.65rem;">snmp_exporter</span>
            </div>
            <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:8px;">
              Add scrape target in `prometheus.yml`:
            </p>
            <div class="code-snippet-box">- job_name: 'micronos_snmp'
  static_configs:
    - targets: ['192.168.1.1:<?= esc($settings['listen_port'] ?? 161) ?>']
  metrics_path: /snmp
  params:
    module: [if_mib]</div>
          </div>

          <!-- Recipe 4: Linux CLI snmpwalk -->
          <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:8px; padding:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <strong style="color:var(--color-emerald); font-size:0.88rem;">4. Linux / macOS Terminal</strong>
              <span class="badge badge-success" style="font-size:0.65rem;">snmpwalk</span>
            </div>
            <p style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:8px;">
              Test connection from authorized NOC terminal:
            </p>
            <div class="code-snippet-box">snmpwalk -v2c -c <?= esc($settings['read_community'] ?: 'public') ?> 192.168.1.1:<?= esc($settings['listen_port'] ?? 161) ?> system</div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================
       TAB 6: Live Logs & MIB Walk Diagnostics
  ============================================================ -->
  <div class="snmp-panel" id="panel-logs">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px; align-items:start;">
      
      <!-- MIB Walk Query Tester -->
      <div class="card card-glass animate-in animate-in-delay-2">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <span class="card-title" style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
              <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
            </svg>
            Live SNMP Walk &amp; OID Inspector
          </span>
          <span class="mno-obj-chip mno-obj-chip-lan">snmpget / walk</span>
        </div>
        <div class="card-body">
          <p style="font-size:0.75rem; color:var(--text-muted); margin-top:0; margin-bottom:12px;">
            Execute live SNMP queries directly against the local daemon to verify MIB exports and credentials.
          </p>

          <!-- Presets -->
          <div style="margin-bottom:12px;">
            <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; margin-bottom:6px;">Standard MIB Presets</div>
            <div style="display:flex; gap:5px; flex-wrap:wrap;">
              <button type="button" class="preset-chip" onclick="setOidPreset('system')">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
                System
              </button>
              <button type="button" class="preset-chip" onclick="setOidPreset('interfaces')">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/></svg>
                Interfaces (IF-MIB)
              </button>
              <button type="button" class="preset-chip" onclick="setOidPreset('cpu')">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
                CPU Load
              </button>
              <button type="button" class="preset-chip" onclick="setOidPreset('storage')">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                Storage
              </button>
              <button type="button" class="preset-chip" onclick="setOidPreset('firewall')">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Firewall States
              </button>
              <button type="button" class="preset-chip" onclick="setOidPreset('routing')">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                FIB Routes
              </button>
            </div>
          </div>

          <form id="snmpQueryForm" onsubmit="runSnmpQuery(event)">
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:10px; margin-bottom:12px;">
              <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.75rem;">Target OID / Subtree</label>
                <input type="text" id="queryOid" class="form-control" value="system" required style="font-family:var(--font-mono); font-size:0.8rem;">
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.75rem;">Community</label>
                <input type="text" id="queryCommunity" class="form-control" value="<?= esc($settings['read_community'] ?? 'public') ?>" style="font-family:var(--font-mono); font-size:0.8rem;">
              </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <div style="font-size:0.73rem; color:var(--text-muted);">Version: <strong>SNMPv2c</strong> (UDP 127.0.0.1:<?= esc($settings['listen_port'] ?? 161) ?>)</div>
              <button type="submit" id="btnRunQuery" class="btn btn-cyan btn-sm" style="padding:4px 10px; font-size:0.75rem;">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                Execute Query
              </button>
            </div>
          </form>

          <div style="font-size:0.74rem; color:var(--text-secondary); font-weight:600; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
            <span>Query Response:</span>
            <div style="display:flex; align-items:center; gap:6px;">
              <span id="queryStats" style="font-family:var(--font-mono); font-size:0.68rem; color:var(--color-cyan);"></span>
              <button type="button" class="btn btn-secondary btn-sm" style="padding:1px 6px; font-size:0.65rem;" onclick="copyQueryOutput()">Copy</button>
            </div>
          </div>
          <pre id="queryOutput" class="log-terminal" style="max-height:280px; margin:0;">Click "Execute Query" or choose a MIB preset above to inspect live SNMP response.</pre>
        </div>
      </div>

      <!-- Live Daemon Logs Stream -->
      <div class="card card-glass animate-in animate-in-delay-2">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <span class="card-title" style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
            </svg>
            Real-Time SNMP Daemon Logs
          </span>
          <div style="display:flex; gap:5px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleLogAutoRefresh()" id="btnAutoRefresh" style="font-size:0.68rem; padding:2px 7px;">
              <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              Auto: ON
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="fetchSnmpLogs()" style="font-size:0.68rem; padding:2px 7px;">
              Refresh
            </button>
          </div>
        </div>
        <div class="card-body">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <input type="text" id="logFilter" class="form-control" placeholder="Filter logs (e.g. GETBULK, trap, authorized)..." style="font-size:0.75rem; padding:4px 10px;" oninput="applyLogFilter()">
            <span id="logCountBadge" class="badge badge-muted" style="margin-left:10px; font-size:0.68rem; font-family:var(--font-mono); white-space:nowrap;">0 items</span>
          </div>
          <div id="snmpLogsContainer" class="log-terminal" style="max-height:380px;">
            <div style="color:var(--text-muted); text-align:center; padding:20px;">Loading live snmpd telemetry...</div>
          </div>
        </div>
      </div>

    </div>
  </div>

</div><!-- /clamp container -->

<!-- ───────────────────────────────────────────────────────────── -->
<!-- Modal: Add SNMPv3 User -->
<!-- ───────────────────────────────────────────────────────────── -->
<div id="addV3UserModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:540px; padding:24px; box-shadow:0 8px 32px rgba(0,0,0,0.5); box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        Add SNMPv3 USM User
      </h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeAddV3UserModal()">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('snmp/v3-users/store') ?>">
      <?= csrf_field() ?>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required placeholder="e.g. zabbix_collector" style="font-family:var(--font-mono);">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Security Level</label>
          <select name="security_level" id="v3SecLevel" class="form-control" onchange="toggleV3AuthFields()">
            <option value="authPriv">authPriv (Auth + Privacy Encryption)</option>
            <option value="authNoPriv">authNoPriv (Auth only)</option>
            <option value="noAuthNoPriv">noAuthNoPriv (No Auth, No Privacy)</option>
          </select>
        </div>
      </div>

      <div id="v3AuthRow" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Authentication Protocol</label>
          <select name="auth_protocol" class="form-control">
            <option value="SHA-256">SHA-256 (Recommended)</option>
            <option value="SHA-512">SHA-512</option>
            <option value="SHA-1">SHA-1</option>
            <option value="MD5">MD5</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Auth Passphrase (min 8 chars)</label>
          <input type="password" name="auth_passphrase" id="v3AuthPass" class="form-control" autocomplete="new-password" spellcheck="false" placeholder="••••••••" style="font-family:var(--font-mono);">
        </div>
      </div>

      <div id="v3PrivRow" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Privacy Protocol (Cipher)</label>
          <select name="priv_protocol" class="form-control">
            <option value="AES-256">AES-256 (High Security)</option>
            <option value="AES-192">AES-192</option>
            <option value="AES-128">AES-128</option>
            <option value="DES">DES</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Privacy Passphrase (min 8 chars)</label>
          <input type="password" name="priv_passphrase" id="v3PrivPass" class="form-control" autocomplete="new-password" spellcheck="false" placeholder="••••••••" style="font-family:var(--font-mono);">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Access Mode</label>
        <select name="access_mode" class="form-control">
          <option value="ro">Read-Only (RO) - Standard Monitoring</option>
          <option value="rw">Read-Write (RW)</option>
        </select>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddV3UserModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="gap:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          Create SNMPv3 User
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- Modal: CLI Command Helper -->
<!-- ───────────────────────────────────────────────────────────── -->
<div id="v3CliModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:580px; padding:24px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0; font-size:1.05rem; font-weight:700;">SNMPv3 CLI Query Command</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeV3CliModal()">&times;</button>
    </div>
    <p style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:12px;">
      Copy and run this command on your monitoring server or terminal:
    </p>
    <div class="code-snippet-box" id="v3CliBox" style="margin-bottom:16px;"></div>
    <div style="display:flex; justify-content:flex-end; gap:8px;">
      <button type="button" class="btn btn-secondary" onclick="closeV3CliModal()">Close</button>
      <button type="button" class="btn btn-cyan" onclick="copyV3CliCommand()">Copy Command</button>
    </div>
  </div>
</div>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- JavaScript Logic -->
<!-- ───────────────────────────────────────────────────────────── -->
<script>
let autoRefreshTimer = null;
let currentLogs = [];

function switchSnmpTab(tabId, btn) {
  document.querySelectorAll('.snmp-tab-btn').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.snmp-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-' + tabId);
  if (panel) panel.classList.add('active');

  if (tabId === 'logs') {
    fetchSnmpLogs();
    startAutoRefresh();
  } else {
    stopAutoRefresh();
  }
}

function openAddV3UserModal() {
  document.getElementById('addV3UserModal').style.display = 'flex';
}
function closeAddV3UserModal() {
  document.getElementById('addV3UserModal').style.display = 'none';
}

function toggleV3AuthFields() {
  const level = document.getElementById('v3SecLevel').value;
  const authRow = document.getElementById('v3AuthRow');
  const privRow = document.getElementById('v3PrivRow');

  if (level === 'noAuthNoPriv') {
    authRow.style.display = 'none';
    privRow.style.display = 'none';
  } else if (level === 'authNoPriv') {
    authRow.style.display = 'grid';
    privRow.style.display = 'none';
  } else {
    authRow.style.display = 'grid';
    privRow.style.display = 'grid';
  }
}

function showV3CliSnippet(user, secLevel, authProto, privProto) {
  let cmd = `snmpwalk -v3 -l ${secLevel} -u ${user}`;
  if (secLevel === 'authNoPriv' || secLevel === 'authPriv') {
    cmd += ` -a ${authProto || 'SHA-256'} -A 'YOUR_AUTH_PASS'`;
  }
  if (secLevel === 'authPriv') {
    cmd += ` -x ${privProto || 'AES-256'} -X 'YOUR_PRIV_PASS'`;
  }
  cmd += ` 192.168.1.1:<?= esc($settings['listen_port'] ?? 161) ?> system`;

  document.getElementById('v3CliBox').textContent = cmd;
  document.getElementById('v3CliModal').style.display = 'flex';
}

function closeV3CliModal() {
  document.getElementById('v3CliModal').style.display = 'none';
}

function copyV3CliCommand() {
  const text = document.getElementById('v3CliBox').textContent;
  navigator.clipboard.writeText(text).then(() => {
    alert('SNMPv3 CLI command copied to clipboard!');
  });
}

async function sendTestTrap() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('snmp/traps/test') ?>', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
      }
    });
    const data = await res.json();
    alert(data.message || 'SNMP test trap transmitted.');
    if (document.getElementById('panel-logs').classList.contains('active')) {
      fetchSnmpLogs();
    }
  } catch (err) {
    alert('Failed to send test trap. Check appliance network connectivity.');
  }
}

function testOidDirect(preset) {
  const btn = document.getElementById('tab-logs-btn');
  switchSnmpTab('logs', btn);
  setOidPreset(preset);
  window.scrollTo({ top: document.getElementById('snmpTabs').offsetTop - 60, behavior: 'smooth' });
}

function setOidPreset(preset) {
  document.getElementById('queryOid').value = preset;
  runSnmpQuery();
}

async function runSnmpQuery(e) {
  if (e) e.preventDefault();
  const oid = document.getElementById('queryOid').value;
  const comm = document.getElementById('queryCommunity').value;
  const outEl = document.getElementById('queryOutput');
  const statsEl = document.getElementById('queryStats');
  const btn = document.getElementById('btnRunQuery');

  btn.disabled = true;
  btn.innerHTML = 'Querying...';
  outEl.innerText = '⏳ Transmitting SNMP GET/WALK query for ' + oid + '...';

  try {
    const formData = new FormData();
    formData.append('oid', oid);
    formData.append('community', comm);
    formData.append('version', 'v2c');

    const res = await MicronScheduler.fetch('<?= base_url('snmp/test-query') ?>', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
      },
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      outEl.innerText = data.output;
      statsEl.innerText = (data.lineCount || 0) + ' MIB items (' + (data.timestamp || '') + ')';
    } else {
      outEl.innerText = 'Error: ' + (data.message || 'SNMP query failed.');
      statsEl.innerText = 'Failed';
    }
  } catch (err) {
    outEl.innerText = 'Failed to execute SNMP query. Check server daemon status.';
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Execute Query';
  }
}

function copyQueryOutput() {
  const text = document.getElementById('queryOutput').innerText;
  navigator.clipboard.writeText(text).then(() => {
    alert('Query response copied to clipboard!');
  });
}

async function fetchSnmpLogs() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('snmp/logs') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) return false;
    const data = await res.json();
    currentLogs = data.logs || [];
    renderSnmpLogs(currentLogs);
  } catch (err) {
    console.error('Failed to fetch SNMP logs:', err);
    return false;   // the shared scheduler backs the poll off and shows the banner
  }
}

// The chip each log level is painted with. Looked up through `hasOwnProperty`
// rather than directly, so a level named like an object key falls through to the
// plain chip instead of picking an inherited value out of the prototype.
const LEVEL_CHIPS = {
  'AUTH-OK': 'mno-obj-chip mno-obj-chip-success',
  'QUERY': 'mno-obj-chip mno-obj-chip-lan',
  'TRAP-OUT': 'mno-obj-chip mno-obj-chip-wan',
  'WARN': 'badge badge-error',
  'AUTH-FAIL': 'badge badge-error',
};

/**
 * Draws the SNMP log list.
 *
 * Every field here arrives over the wire — a subsystem, a level, a message — and
 * each one reaches the list as a `text` column of the builder's spec, on a span
 * that carries the class the row is styled by. The level used to be concatenated
 * straight into its chip, so an unrecognised level was markup by construction;
 * now the chip is classed from a fixed table and the level itself, like every
 * other field here, can only ever be text.
 */
const SNMP_LOG_COLUMNS = [
  { text: 'timestamp', class: 'log-time' },
  { text: 'subsystem', class: 'log-sub' },
  { node: (l) => levelChip(l.level), style: 'min-width:65px;' },
  { text: 'message', class: 'log-msg' },
];

function renderSnmpLogs(logs) {
  const list = Array.isArray(logs) ? logs : [];

  document.getElementById('logCountBadge').innerText = list.length + ' entries';

  // The whole list is swapped at once, so a re-render cannot leave rows behind.
  MicronTable.render(document.getElementById('snmpLogsContainer'), list, {
    rowTag: 'div',
    cellTag: 'span',
    rowClass: 'log-row',
    columns: SNMP_LOG_COLUMNS,
    empty: {
      style: 'color:var(--text-muted); text-align:center; padding:20px;',
      text: 'No SNMP log entries recorded.',
    },
  });
}

/** The chip a level is shown with; the level itself is only ever text. */
function levelChip(level) {
  // A trap is shipped as TRAP-OUT and shown as TRAP, as the page always has.
  const label = level === 'TRAP-OUT' ? 'TRAP' : (level || 'INFO');
  const cls = Object.prototype.hasOwnProperty.call(LEVEL_CHIPS, level)
    ? LEVEL_CHIPS[level]
    : 'mno-obj-chip';

  return MicronTable.badge(label, cls, 'font-size:0.65rem; padding:1px 5px;');
}

function applyLogFilter() {
  const query = (document.getElementById('logFilter').value || '').toLowerCase();
  if (!query) {
    renderSnmpLogs(currentLogs);
    return;
  }
  const filtered = currentLogs.filter(l => 
    (l.message && l.message.toLowerCase().includes(query)) ||
    (l.subsystem && l.subsystem.toLowerCase().includes(query)) ||
    (l.level && l.level.toLowerCase().includes(query))
  );
  renderSnmpLogs(filtered);
}

function toggleLogAutoRefresh() {
  const btn = document.getElementById('btnAutoRefresh');
  if (autoRefreshTimer) {
    stopAutoRefresh();
    btn.innerHTML = 'Auto: OFF';
    btn.classList.remove('btn-cyan');
    btn.classList.add('btn-secondary');
  } else {
    startAutoRefresh();
    btn.innerHTML = '<svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Auto: ON';
    btn.classList.add('btn-cyan');
    btn.classList.remove('btn-secondary');
  }
}

function startAutoRefresh() {
  if (autoRefreshTimer) autoRefreshTimer.stop();
  autoRefreshTimer = MicronScheduler.poll('snmp.logs', { interval: 4000, run: fetchSnmpLogs });
}

function stopAutoRefresh() {
  if (autoRefreshTimer) {
    autoRefreshTimer.stop();
    autoRefreshTimer = null;
  }
}

</script>

<?= $this->endSection() ?>
