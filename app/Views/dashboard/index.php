<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
/* ============================================================
   MicronOS Enterprise Dashboard Aesthetics
   ============================================================ */
.dash-header-card {
  background: linear-gradient(135deg, rgba(13,18,32,0.95) 0%, rgba(20,29,51,0.85) 100%);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 22px 26px;
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 24px rgba(0,0,0,0.3);
}
.dash-header-card::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 450px; height: 100%;
  background: radial-gradient(circle at top right, rgba(0, 212, 255, 0.08), transparent 70%);
  pointer-events: none;
}
.dash-brand-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px;
  border-radius: 99px;
  background: rgba(16, 185, 129, 0.12);
  border: 1px solid rgba(16, 185, 129, 0.3);
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--color-emerald);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.dash-brand-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 8px var(--color-emerald);
  animation: pulse-dot 2s ease-in-out infinite;
}

/* System Metric Gauges Grid */
.gauges-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.gauge-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 18px 20px;
  position: relative;
  overflow: hidden;
  transition: transform var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
}
.gauge-card:hover {
  transform: translateY(-2px);
  border-color: var(--color-border-bright);
  box-shadow: 0 8px 24px rgba(0,0,0,0.3);
}
.gauge-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
}
.gauge-card.cyan::before    { background: linear-gradient(90deg, transparent, var(--color-cyan), transparent); }
.gauge-card.emerald::before { background: linear-gradient(90deg, transparent, var(--color-emerald), transparent); }
.gauge-card.purple::before  { background: linear-gradient(90deg, transparent, var(--color-purple), transparent); }
.gauge-card.amber::before   { background: linear-gradient(90deg, transparent, var(--color-amber), transparent); }
.gauge-card.red::before     { background: linear-gradient(90deg, transparent, var(--color-red), transparent); }

.gauge-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
.gauge-title {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-secondary);
}
.gauge-icon {
  width: 28px; height: 28px;
  border-radius: var(--radius-sm);
  display: flex; align-items: center; justify-content: center;
}
.gauge-icon.cyan    { background: var(--color-cyan-dim);    color: var(--color-cyan); }
.gauge-icon.emerald { background: var(--color-emerald-dim); color: var(--color-emerald); }
.gauge-icon.purple  { background: var(--color-purple-dim);  color: var(--color-purple); }
.gauge-icon.amber   { background: var(--color-amber-dim);   color: var(--color-amber); }
.gauge-icon.red     { background: var(--color-red-dim);     color: var(--color-red); }

.gauge-value {
  font-size: 1.8rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1;
  color: var(--text-primary);
  display: flex;
  align-items: baseline;
  gap: 4px;
}
.gauge-subtext {
  font-size: 0.72rem;
  color: var(--text-muted);
  margin-top: 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gauge-progress-track {
  height: 5px;
  background: var(--color-surface-3);
  border-radius: 99px;
  margin-top: 12px;
  overflow: hidden;
}
.gauge-progress-fill {
  height: 100%;
  border-radius: 99px;
  transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
.gauge-progress-fill.cyan    { background: linear-gradient(90deg, #0096c7, var(--color-cyan)); }
.gauge-progress-fill.emerald { background: linear-gradient(90deg, #059669, var(--color-emerald)); }
.gauge-progress-fill.purple  { background: linear-gradient(90deg, #6d28d9, var(--color-purple)); }
.gauge-progress-fill.amber   { background: linear-gradient(90deg, #d97706, var(--color-amber)); }
.gauge-progress-fill.red     { background: linear-gradient(90deg, #dc2626, var(--color-red)); }

/* Dashboard 2-Column Responsive Layout */
.dash-masonry {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 20px;
}
.col-12 { grid-column: span 12; }
.col-8  { grid-column: span 8; }
.col-7  { grid-column: span 7; }
.col-6  { grid-column: span 6; }
.col-5  { grid-column: span 5; }
.col-4  { grid-column: span 4; }

@media (max-width: 1200px) {
  .col-8, .col-7, .col-5, .col-4 { grid-column: span 12; }
}
@media (max-width: 900px) {
  .col-6 { grid-column: span 12; }
}

/* Real-Time Traffic Graph Widget */
.traffic-widget {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 22px;
  position: relative;
}
.traffic-canvas-wrap {
  width: 100%;
  height: 200px;
  position: relative;
  margin-top: 16px;
  border-radius: var(--radius-md);
  background: rgba(10, 14, 26, 0.6);
  border: 1px solid rgba(255,255,255,0.05);
  overflow: hidden;
}
.traffic-chart-svg {
  width: 100%;
  height: 100%;
  display: block;
}
.traffic-stats-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--color-border);
}
.traffic-stat-box {
  background: rgba(255,255,255,0.02);
  border: 1px solid var(--color-border);
  padding: 10px 14px;
  border-radius: var(--radius-md);
}

/* Service Row & Controls */
.service-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  transition: background var(--transition-fast);
}
.service-row:last-child { border-bottom: none; }
.service-row:hover { background: rgba(255,255,255,0.02); }

.service-btn {
  width: 28px; height: 28px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: var(--radius-sm);
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--color-border);
  color: var(--text-secondary);
  cursor: pointer;
  transition: all var(--transition-fast);
  padding: 0;
}
.service-btn:hover {
  background: var(--color-cyan-dim);
  color: var(--color-cyan);
  border-color: var(--color-cyan);
}

/* Hardware Spec Table */
.spec-table {
  width: 100%;
  font-size: 0.82rem;
  border-collapse: collapse;
}
.spec-table td {
  padding: 9px 0;
  border-bottom: 1px solid var(--color-border);
}
.spec-table tr:last-child td { border-bottom: none; }
.spec-key {
  color: var(--text-muted);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-size: 0.72rem;
  width: 38%;
}
.spec-val {
  color: var(--text-primary);
  font-family: var(--font-mono);
  font-size: 0.8rem;
}

/* Live Filter Log Stream Table */
.log-stream-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}
.log-stream-table th {
  text-align: left;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
  padding: 8px 10px;
  border-bottom: 1px solid var(--color-border);
}
.log-stream-table td {
  padding: 9px 10px;
  border-bottom: 1px solid rgba(255,255,255,0.04);
  vertical-align: middle;
}
.log-stream-table tr:last-child td { border-bottom: none; }
.log-stream-table tr:hover td { background: rgba(255,255,255,0.02); }

/* Toast Notification for Service Actions */
#dashToast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  padding: 12px 20px;
  background: var(--color-surface-1);
  border: 1px solid var(--color-cyan);
  border-radius: var(--radius-md);
  color: var(--text-primary);
  font-size: 0.82rem;
  box-shadow: 0 8px 30px rgba(0,212,255,0.25);
  display: none;
  z-index: 9999;
  animation: slide-up 0.3s ease-out;
}
@keyframes slide-up {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
</style>

<!-- ============================================================
     1. Top Appliance Header & Quick Action Bar
============================================================ -->
<div class="dash-header-card animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:20px;">
    
    <div>
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px; flex-wrap:wrap;">
        <span class="dash-brand-pill">
          <span class="dash-brand-dot"></span>
          Operational &bull; All Subsystems Nominal
        </span>
        <span class="badge badge-cyan" style="font-size:0.72rem; padding:4px 9px;">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          <?= esc($cryptoEngine) ?>
        </span>
        <span class="badge <?= ($haRole === 'master') ? 'badge-emerald' : 'badge-amber' ?>" style="font-size:0.72rem; padding:4px 9px;">
          VRRP HA: <?= strtoupper($haRole) ?> (Priority 110)
        </span>
      </div>

      <h1 class="page-title" style="margin:0; font-size:1.65rem; font-weight:800; letter-spacing:-0.03em;">
        <?= esc($systemInfo['hostname'] ?? 'micronos-fw01') ?>
        <span style="font-size:0.95rem; font-weight:500; color:var(--text-muted); font-family:var(--font-mono);">
          .internal.network
        </span>
      </h1>
      
      <p style="margin:6px 0 0; font-size:0.84rem; color:var(--text-secondary);">
        <?= esc(\App\Libraries\AppVersion::getProjectName()) ?> Security Appliance <strong>v<?= esc(\App\Libraries\AppVersion::getVersion()) ?></strong> (<?= esc(\App\Libraries\AppVersion::getChannel()) ?>, amd64) &nbsp;&bull;&nbsp;
        Uptime: <strong style="color:var(--color-emerald); font-family:var(--font-mono);"><?= esc($uptimeStr) ?></strong> &nbsp;&bull;&nbsp;
        Load Avg: <span style="font-family:var(--font-mono); color:var(--text-primary);"><?= number_format($cpuLoad1, 2) ?>, <?= number_format($cpuLoad5, 2) ?>, <?= number_format($cpuLoad15, 2) ?></span>
      </p>
    </div>

    <!-- Quick Action Cluster -->
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; z-index:2;">
      <a href="<?= base_url('diag') ?>" class="btn btn-ghost btn-sm" title="Diagnostics Utilities">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        Diagnostics
      </a>

      <a href="<?= base_url('diag/states') ?>" class="btn btn-ghost btn-sm" title="Inspect Active Connection States">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
        </svg>
        States (<?= number_format($statesActive) ?>)
      </a>

      <a href="<?= base_url('backup') ?>" class="btn btn-ghost btn-sm" title="Appliance Configuration Backup">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
        </svg>
        Backup
      </a>

      <form action="<?= base_url('firewall/rules/apply') ?>" method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary btn-sm" title="Flush and Recompile Netfilter / nftables Ruleset">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Reload Filter
        </button>
      </form>
    </div>

  </div>
</div>

<!-- ============================================================
     2. Hardware & Resource Usage Gauges (System Resource Meter)
============================================================ -->
<div class="gauges-grid animate-in animate-in-delay-1">

  <!-- CPU Load -->
  <div class="gauge-card <?= $cpuPct > 80 ? 'red' : ($cpuPct > 60 ? 'amber' : 'cyan') ?>">
    <div class="gauge-header">
      <span class="gauge-title">CPU Usage</span>
      <div class="gauge-icon <?= $cpuPct > 80 ? 'red' : ($cpuPct > 60 ? 'amber' : 'cyan') ?>">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>
          <path stroke-linecap="round" d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>
        </svg>
      </div>
    </div>
    <div class="gauge-value" id="val_cpu_pct"><?= $cpuPct ?>%</div>
    <div class="gauge-subtext"><?= $cpuCores ?> Cores &bull; Temp: <?= $temperature ?>°C</div>
    <div class="gauge-progress-track">
      <div class="gauge-progress-fill <?= $cpuPct > 80 ? 'red' : ($cpuPct > 60 ? 'amber' : 'cyan') ?>" id="bar_cpu" style="width: <?= $cpuPct ?>%"></div>
    </div>
  </div>

  <!-- Memory / RAM -->
  <div class="gauge-card <?= $memPct > 85 ? 'red' : ($memPct > 70 ? 'amber' : 'emerald') ?>">
    <div class="gauge-header">
      <span class="gauge-title">Memory (RAM)</span>
      <div class="gauge-icon <?= $memPct > 85 ? 'red' : ($memPct > 70 ? 'amber' : 'emerald') ?>">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
        </svg>
      </div>
    </div>
    <div class="gauge-value"><?= $memPct ?>%</div>
    <div class="gauge-subtext"><?= number_format($memUsed) ?> MB / <?= number_format($memTotal) ?> MB (Cache: <?= number_format($memCache) ?>M)</div>
    <div class="gauge-progress-track">
      <div class="gauge-progress-fill <?= $memPct > 85 ? 'red' : ($memPct > 70 ? 'amber' : 'emerald') ?>" style="width: <?= $memPct ?>%"></div>
    </div>
  </div>

  <!-- State Table (Conntrack States) -->
  <div class="gauge-card <?= $statesPct > 80 ? 'red' : ($statesPct > 50 ? 'amber' : 'purple') ?>">
    <div class="gauge-header">
      <span class="gauge-title">State Table</span>
      <div class="gauge-icon <?= $statesPct > 80 ? 'red' : ($statesPct > 50 ? 'amber' : 'purple') ?>">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
      </div>
    </div>
    <div class="gauge-value" id="val_states_cnt"><?= number_format($statesActive) ?></div>
    <div class="gauge-subtext"><?= $statesPct ?>% of <?= number_format($statesMax) ?> max states</div>
    <div class="gauge-progress-track">
      <div class="gauge-progress-fill <?= $statesPct > 80 ? 'red' : ($statesPct > 50 ? 'amber' : 'purple') ?>" id="bar_states" style="width: <?= max(2, $statesPct) ?>%"></div>
    </div>
  </div>

  <!-- MBUF Usage (Socket buffer clusters) -->
  <div class="gauge-card emerald">
    <div class="gauge-header">
      <span class="gauge-title">MBUF Clusters</span>
      <div class="gauge-icon emerald">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
      </div>
    </div>
    <div class="gauge-value"><?= number_format($mbufActive) ?></div>
    <div class="gauge-subtext"><?= $mbufPct ?>% of <?= number_format($mbufMax) ?> socket buffers</div>
    <div class="gauge-progress-track">
      <div class="gauge-progress-fill emerald" style="width: <?= max(2, $mbufPct) ?>%"></div>
    </div>
  </div>

  <!-- Disk / NVMe -->
  <div class="gauge-card <?= $diskPct > 85 ? 'red' : ($diskPct > 70 ? 'amber' : 'cyan') ?>">
    <div class="gauge-header">
      <span class="gauge-title">Disk (ZFS/Ext4)</span>
      <div class="gauge-icon <?= $diskPct > 85 ? 'red' : ($diskPct > 70 ? 'amber' : 'cyan') ?>">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
        </svg>
      </div>
    </div>
    <div class="gauge-value"><?= $diskPct ?>%</div>
    <div class="gauge-subtext"><?= $diskUsed ?> GB / <?= $diskTotal ?> GB NVMe root</div>
    <div class="gauge-progress-track">
      <div class="gauge-progress-fill <?= $diskPct > 85 ? 'red' : ($diskPct > 70 ? 'amber' : 'cyan') ?>" style="width: <?= $diskPct ?>%"></div>
    </div>
  </div>

</div>

<!-- ============================================================
     3. Main Dashboard Grid: Charts, Services, Gateways & Logs
============================================================ -->
<div class="dash-masonry">

  <!-- LEFT COLUMN (Span 7): Real-Time Traffic Graph + Network Interfaces -->
  <div class="col-7" style="display:flex; flex-direction:column; gap:20px;">

    <!-- WIDGET 1: Real-Time Traffic & Bandwidth Graph (Live Telemetry) -->
    <div class="traffic-widget animate-in animate-in-delay-2">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
          <div style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
            </svg>
            <strong style="font-size:0.95rem; font-weight:700; color:var(--text-primary);">Real-Time Traffic Graph</strong>
            <span class="badge badge-success" style="font-size:0.65rem; padding:2px 7px;">LIVE 2s</span>
          </div>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
            Dynamic in/out bandwidth throughput &amp; packet inspection rates
          </div>
        </div>

        <!-- Interface Selector Pills -->
        <div style="display:flex; gap:6px; align-items:center;">
          <select id="trafficIfaceSelect" class="form-control" style="padding:4px 8px; font-size:0.75rem; height:28px; width:auto;" onchange="changeTrafficIface(this.value)">
            <?php foreach ($interfaces as $if): ?>
              <option value="<?= esc($if['id']) ?>"><?= esc($if['name']) ?> (<?= esc($if['device']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 8px; height:28px;" onclick="toggleTrafficStream()" id="btnTrafficPause" title="Pause / Resume Live Feed">
            ⏸
          </button>
        </div>
      </div>

      <!-- Live SVG Area Chart -->
      <div class="traffic-canvas-wrap">
        <svg class="traffic-chart-svg" id="trafficSvgChart" viewBox="0 0 600 200" preserveAspectRatio="none">
          <defs>
            <linearGradient id="gradIn" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#10b981" stop-opacity="0.45"/>
              <stop offset="100%" stop-color="#10b981" stop-opacity="0.0"/>
            </linearGradient>
            <linearGradient id="gradOut" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#00d4ff" stop-opacity="0.4"/>
              <stop offset="100%" stop-color="#00d4ff" stop-opacity="0.0"/>
            </linearGradient>
          </defs>
          <!-- Grid Lines -->
          <line x1="0" y1="50" x2="600" y2="50" stroke="rgba(255,255,255,0.05)" stroke-width="1" stroke-dasharray="4,4"/>
          <line x1="0" y1="100" x2="600" y2="100" stroke="rgba(255,255,255,0.05)" stroke-width="1" stroke-dasharray="4,4"/>
          <line x1="0" y1="150" x2="600" y2="150" stroke="rgba(255,255,255,0.05)" stroke-width="1" stroke-dasharray="4,4"/>
          
          <!-- Area Fills -->
          <path id="svgAreaIn" d="" fill="url(#gradIn)"/>
          <path id="svgAreaOut" d="" fill="url(#gradOut)"/>

          <!-- Line Strokes -->
          <path id="svgLineIn" d="" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round"/>
          <path id="svgLineOut" d="" fill="none" stroke="#00d4ff" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </div>

      <!-- Real-time speed metrics strip -->
      <div class="traffic-stats-strip">
        <div class="traffic-stat-box">
          <div style="font-size:0.7rem; font-weight:700; color:var(--color-emerald); text-transform:uppercase; display:flex; align-items:center; gap:5px;">
            <span style="display:inline-block; width:8px; height:8px; background:var(--color-emerald); border-radius:50%;"></span>
            Inbound (Rx)
          </div>
          <div style="font-size:1.25rem; font-weight:800; font-family:var(--font-mono); color:var(--text-primary); margin-top:2px;" id="stat_in_mbps">
            38.4 <span style="font-size:0.75rem; font-weight:500; color:var(--text-muted);">Mbps</span>
          </div>
          <div style="font-size:0.7rem; color:var(--text-muted);" id="stat_in_pps">5,420 pps</div>
        </div>

        <div class="traffic-stat-box">
          <div style="font-size:0.7rem; font-weight:700; color:var(--color-cyan); text-transform:uppercase; display:flex; align-items:center; gap:5px;">
            <span style="display:inline-block; width:8px; height:8px; background:var(--color-cyan); border-radius:50%;"></span>
            Outbound (Tx)
          </div>
          <div style="font-size:1.25rem; font-weight:800; font-family:var(--font-mono); color:var(--text-primary); margin-top:2px;" id="stat_out_mbps">
            14.2 <span style="font-size:0.75rem; font-weight:500; color:var(--text-muted);">Mbps</span>
          </div>
          <div style="font-size:0.7rem; color:var(--text-muted);" id="stat_out_pps">3,110 pps</div>
        </div>

        <div class="traffic-stat-box">
          <div style="font-size:0.7rem; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Peak Recorded</div>
          <div style="font-size:1.05rem; font-weight:700; font-family:var(--font-mono); color:var(--text-primary); margin-top:3px;" id="stat_peak">
            68.5 <span style="font-size:0.7rem; color:var(--text-muted);">Mbps</span>
          </div>
          <div style="font-size:0.7rem; color:var(--text-muted);">WAN Interface</div>
        </div>
      </div>
    </div>

    <!-- WIDGET 2: Network Interfaces Live Matrix (Interface Widget) -->
    <div class="card card-glass animate-in animate-in-delay-2">
      <div class="card-header">
        <div class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
          Interfaces Status &amp; Link Details
        </div>
        <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost btn-sm">Configure &rarr;</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr>
              <th>Interface</th>
              <th>Status / Link</th>
              <th>IP &amp; Subnet</th>
              <th>MAC / MTU</th>
              <th>Throughput</th>
              <th>Cumulative Traffic</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($interfaces as $if): ?>
              <tr>
                <td>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $if['status'] === 'up' ? 'var(--color-emerald)' : 'var(--color-red)' ?>; box-shadow:0 0 6px <?= $if['status'] === 'up' ? 'var(--color-emerald)' : 'var(--color-red)' ?>;"></span>
                    <div>
                      <div class="iface-name" style="font-weight:700; font-size:0.85rem; color:var(--text-primary);"><?= esc($if['name']) ?></div>
                      <div class="iface-device" style="font-size:0.72rem; color:var(--color-cyan); font-family:var(--font-mono);"><?= esc($if['device']) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if ($if['status'] === 'up'): ?>
                    <span class="badge badge-success">UP</span>
                  <?php else: ?>
                    <span class="badge badge-danger">DOWN</span>
                  <?php endif; ?>
                  <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px; font-family:var(--font-mono);"><?= esc($if['media']) ?></div>
                </td>
                <td>
                  <span class="iface-ip" style="font-weight:700; color:var(--text-primary);"><?= esc($if['ip']) ?></span>
                  <?php if (!empty($if['mask'])): ?>
                    <span class="text-muted text-xs">/<?= esc($if['mask']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($if['ipv6']) && $if['ipv6'] !== '—'): ?>
                    <div style="font-size:0.68rem; color:var(--text-muted); font-family:var(--font-mono);"><?= esc($if['ipv6']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-secondary);"><?= esc($if['mac']) ?></div>
                  <div style="font-size:0.68rem; color:var(--text-muted);">MTU: <?= esc($if['mtu']) ?></div>
                </td>
                <td>
                  <div style="font-size:0.76rem; font-family:var(--font-mono); color:var(--color-emerald); font-weight:600;">
                    &darr; <?= number_format($if['rx_mbps'], 1) ?> Mbps
                  </div>
                  <div style="font-size:0.76rem; font-family:var(--font-mono); color:var(--color-cyan); font-weight:600;">
                    &uarr; <?= number_format($if['tx_mbps'], 1) ?> Mbps
                  </div>
                </td>
                <td>
                  <div style="font-size:0.75rem; color:var(--text-secondary); font-family:var(--font-mono);">
                    In: <strong style="color:var(--text-primary);"><?= esc($if['in_bytes']) ?></strong>
                  </div>
                  <div style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono);">
                    Out: <?= esc($if['out_bytes']) ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- WIDGET 3: Recent Firewall Filter Log (Live Filter Log) -->
    <div class="card card-glass animate-in animate-in-delay-3">
      <div class="card-header">
        <div class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
          </svg>
          Live Firewall Filter Log Stream
        </div>
        <a href="<?= base_url('diag/logs') ?>" class="btn btn-ghost btn-sm">Full Log &rarr;</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="log-stream-table">
          <thead>
            <tr>
              <th>Action</th>
              <th>Time</th>
              <th>Iface</th>
              <th>Proto</th>
              <th>Source &rarr; Destination</th>
              <th>Rule Description</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentLogs as $log): ?>
              <tr>
                <td>
                  <?php if ($log['action'] === 'pass'): ?>
                    <span class="badge badge-success" style="font-size:0.65rem; padding:2px 6px;">PASS</span>
                  <?php else: ?>
                    <span class="badge badge-danger" style="font-size:0.65rem; padding:2px 6px;">BLOCK</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono); color:var(--text-muted);"><?= esc($log['time']) ?></td>
                <td><span class="badge badge-muted" style="font-size:0.65rem; text-transform:uppercase;"><?= esc($log['iface']) ?></span></td>
                <td><span style="font-family:var(--font-mono); font-weight:700; font-size:0.75rem; color:var(--color-cyan);"><?= esc($log['proto']) ?></span></td>
                <td style="font-family:var(--font-mono);">
                  <span style="color:var(--text-primary);"><?= esc($log['src']) ?></span>
                  <span style="color:var(--text-muted); margin:0 3px;">&rarr;</span>
                  <span style="color:var(--color-emerald);"><?= esc($log['dst']) ?></span>
                </td>
                <td style="color:var(--text-muted); max-width:180px;" class="truncate" title="<?= esc($log['desc']) ?>">
                  <?= esc($log['desc']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /col-7 -->

  <!-- RIGHT COLUMN (Span 5): Services Status + Gateways + Hardware Info + Security -->
  <div class="col-5" style="display:flex; flex-direction:column; gap:20px;">

    <!-- WIDGET 4: Core Services Status Widget (Cluster-aware) -->
    <div class="card card-glass animate-in animate-in-delay-2">
      <div class="card-header">
        <div class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          Services Status (<?= count($services) ?> Daemons)
        </div>
        <span class="badge badge-success" style="font-size:0.68rem;">All Active</span>
      </div>

      <div style="padding:4px 0;">
        <?php foreach ($services as $srv): ?>
          <div class="service-row" id="row_srv_<?= esc($srv['name']) ?>">
            <div style="display:flex; align-items:center; gap:10px;">
              <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--color-emerald); box-shadow:0 0 6px var(--color-emerald);"></span>
              <div>
                <div style="font-weight:700; font-size:0.82rem; color:var(--text-primary);"><?= esc($srv['title']) ?></div>
                <div style="font-size:0.7rem; color:var(--text-muted);"><?= esc($srv['desc']) ?></div>
              </div>
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
              <span class="badge badge-success" style="font-size:0.62rem; padding:2px 6px;">RUNNING</span>
              <button type="button" class="service-btn" onclick="triggerServiceAction('<?= esc($srv['name']) ?>', 'restart')" title="Restart Daemon">
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- WIDGET 5: Multi-WAN gateways quality -->
    <div class="card card-glass animate-in animate-in-delay-3">
      <div class="card-header">
        <div class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
          </svg>
          Multi-WAN Gateway Quality
        </div>
        <a href="<?= base_url('routing') ?>?tab=multiwan" class="btn btn-ghost btn-sm">Routing &rarr;</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="iface-table">
          <thead>
            <tr>
              <th>Gateway</th>
              <th>Monitor IP</th>
              <th>RTT (Latency)</th>
              <th>Loss</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($gateways as $gw): ?>
              <tr>
                <td>
                  <div style="font-weight:700; font-size:0.82rem; color:var(--text-primary);"><?= esc($gw['name']) ?></div>
                  <div style="font-size:0.68rem; color:var(--text-muted); font-family:var(--font-mono);"><?= esc($gw['iface']) ?> &bull; <?= esc($gw['ip']) ?></div>
                  <div style="font-size:0.65rem; color:var(--color-cyan);"><?= esc($gw['tier']) ?></div>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan);">
                  <?= esc($gw['monitor']) ?>
                  <div style="font-size:0.65rem; color:var(--text-muted);"><?= esc($gw['subtext']) ?></div>
                </td>
                <td>
                  <span style="font-family:var(--font-mono); font-size:0.85rem; font-weight:800; color:var(--color-emerald);"><?= esc($gw['rtt']) ?> ms</span>
                  <div style="font-size:0.65rem; color:var(--text-muted); font-family:var(--font-mono);">Jitter: <?= esc($gw['rttsd']) ?>ms</div>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; font-weight:700; color:var(--color-emerald);">
                  <?= esc($gw['loss']) ?>%
                </td>
                <td>
                  <span class="badge badge-emerald" style="font-size:0.68rem;">ONLINE</span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- WIDGET 6: System Hardware & Firmware Information (System Info) -->
    <div class="card card-glass animate-in animate-in-delay-3">
      <div class="card-header">
        <div class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          System Information &amp; Hardware
        </div>
        <a href="<?= base_url('system') ?>" class="btn btn-ghost btn-sm">Settings &rarr;</a>
      </div>
      <div class="card-body" style="padding:16px 20px;">
        <table class="spec-table">
          <tr>
            <td class="spec-key">System Model</td>
            <td class="spec-val">MicronOS Hardware Appliance XG-8000</td>
          </tr>
          <tr>
            <td class="spec-key">Processor</td>
            <td class="spec-val" style="font-size:0.75rem; color:var(--text-secondary);"><?= esc($cpuModel) ?></td>
          </tr>
          <tr>
            <td class="spec-key">Crypto Acceleration</td>
            <td class="spec-val" style="color:var(--color-cyan); font-size:0.75rem;"><?= esc($cryptoEngine) ?></td>
          </tr>
          <tr>
            <td class="spec-key">Kernel Release</td>
            <td class="spec-val"><?= esc($systemInfo['kernel'] ?? 'Linux 6.8.0-micronos-hardened') ?></td>
          </tr>
          <tr>
            <td class="spec-key">BIOS / Firmware</td>
            <td class="spec-val">AMI UEFI 2.8.4 (SecureBoot Enabled)</td>
          </tr>
          <tr>
            <td class="spec-key">Last Rollback</td>
            <td class="spec-val" style="font-size:0.75rem;">
              <?php if ($lastRollback === null): ?>
                <span style="color:var(--text-secondary);">No configuration rollback has been recorded</span>
              <?php else: ?>
                <span style="color:var(--color-amber); font-weight:700;">
                  <?= esc($lastRollback['at'] !== '' ? $lastRollback['at'] : 'time not recorded') ?>
                </span>
                <?php if ($lastRollback['ago'] !== ''): ?>
                  <span style="color:var(--text-muted);">(<?= esc($lastRollback['ago']) ?>)</span>
                <?php endif; ?>
                <span style="color:var(--text-secondary);">by <?= esc($lastRollback['admin_user']) ?></span>
                <?php if (! $lastRollback['exact']): ?>
                  <div style="color:var(--text-muted); font-size:0.68rem; margin-top:2px;">
                    The rollback itself was not timestamped; this is when the discarded configuration was applied.
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="spec-key">Thermal State</td>
            <td class="spec-val" style="color:var(--color-emerald); font-weight:700;">
              <?= $temperature ?>°C / 99.5°F (Normal)
            </td>
          </tr>
        </table>
      </div>
    </div>

    <!-- WIDGET 7: Threat Shield & Security Telemetry (DNSBL & VPN) -->
    <div class="card card-glass animate-in animate-in-delay-3">
      <div class="card-header">
        <div class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Security Telemetry &amp; Remote Access
        </div>
        <a href="<?= base_url('dns') ?>" class="btn btn-ghost btn-sm">DNSBL &rarr;</a>
      </div>
      <div class="card-body" style="padding:16px 20px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
          
          <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:12px; border-radius:var(--radius-md);">
            <div style="font-size:0.68rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;">DNSBL Threat Shield</div>
            <div style="font-size:1.15rem; font-weight:800; color:var(--color-purple); font-family:var(--font-mono);">
              <?= number_format($dnsblSignatures) ?>
            </div>
            <div style="font-size:0.7rem; color:var(--text-muted);"><?= $dnsblFeedsCount ?> active feed lists</div>
          </div>

          <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:12px; border-radius:var(--radius-md);">
            <div style="font-size:0.68rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;">WireGuard VPN Peers</div>
            <div style="font-size:1.15rem; font-weight:800; color:var(--color-cyan); font-family:var(--font-mono);">
              <?= $wgPeersCount ?> Active
            </div>
            <div style="font-size:0.7rem; color:var(--text-muted);">ChaCha20-Poly1305</div>
          </div>

        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:var(--text-secondary); padding-top:10px; border-top:1px solid var(--color-border);">
          <span>DHCPv4 Pool: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= $dhcpActiveLeases ?> / <?= $dhcpPoolSize ?> leases</strong></span>
          <a href="<?= base_url('dhcp') ?>" class="text-xs" style="color:var(--color-cyan); font-weight:600;">Manage DHCP &rarr;</a>
        </div>
      </div>
    </div>

  </div><!-- /col-5 -->

</div><!-- /dash-masonry -->

<!-- Floating Toast Notification -->
<div id="dashToast"></div>

<!-- ============================================================
     Real-Time Chart & Polling Engine Scripts
============================================================ -->
<script>
let trafficInterval = null;
let trafficPaused = false;
let currentIface = 'WAN';

// History buffers for SVG path rendering (20 data points each)
const BUFFER_SIZE = 20;
let inHistory = [18, 22, 28, 35, 41, 38, 34, 45, 52, 48, 42, 39, 44, 40, 36, 42, 46, 38, 42, 38.4];
let outHistory = [8, 11, 14, 12, 16, 18, 15, 20, 24, 22, 19, 17, 21, 18, 14, 19, 21, 16, 18, 14.2];

function renderSvgChart() {
  const maxVal = Math.max(70, ...inHistory, ...outHistory);
  const width = 600;
  const height = 200;
  const step = width / (BUFFER_SIZE - 1);

  // Inbound Path
  let inPoints = inHistory.map((val, idx) => {
    const x = idx * step;
    const y = height - (val / maxVal) * (height - 20) - 10;
    return `${x},${y}`;
  });
  const inLineD = 'M ' + inPoints.join(' L ');
  const inAreaD = `${inLineD} L ${width},${height} L 0,${height} Z`;

  // Outbound Path
  let outPoints = outHistory.map((val, idx) => {
    const x = idx * step;
    const y = height - (val / maxVal) * (height - 20) - 10;
    return `${x},${y}`;
  });
  const outLineD = 'M ' + outPoints.join(' L ');
  const outAreaD = `${outLineD} L ${width},${height} L 0,${height} Z`;

  const svgLineIn = document.getElementById('svgLineIn');
  const svgAreaIn = document.getElementById('svgAreaIn');
  const svgLineOut = document.getElementById('svgLineOut');
  const svgAreaOut = document.getElementById('svgAreaOut');

  if (svgLineIn) svgLineIn.setAttribute('d', inLineD);
  if (svgAreaIn) svgAreaIn.setAttribute('d', inAreaD);
  if (svgLineOut) svgLineOut.setAttribute('d', outLineD);
  if (svgAreaOut) svgAreaOut.setAttribute('d', outAreaD);
}

async function fetchTrafficData() {
  if (trafficPaused) return;

  try {
    const res = await MicronScheduler.fetch(`<?= base_url('dashboard/traffic') ?>?iface=${encodeURIComponent(currentIface)}`);
    if (!res.ok) return false;
    const data = await res.json();

    // Update history arrays
    inHistory.push(data.in_mbps);
    if (inHistory.length > BUFFER_SIZE) inHistory.shift();

    outHistory.push(data.out_mbps);
    if (outHistory.length > BUFFER_SIZE) outHistory.shift();

    // Update DOM counters
    const inEl = document.getElementById('stat_in_mbps');
    const inPpsEl = document.getElementById('stat_in_pps');
    const outEl = document.getElementById('stat_out_mbps');
    const outPpsEl = document.getElementById('stat_out_pps');
    const peakEl = document.getElementById('stat_peak');
    const cpuEl = document.getElementById('val_cpu_pct');
    const cpuBar = document.getElementById('bar_cpu');
    const statesEl = document.getElementById('val_states_cnt');

    if (inEl) inEl.innerHTML = `${data.in_mbps.toFixed(1)} <span style="font-size:0.75rem; font-weight:500; color:var(--text-muted);">Mbps</span>`;
    if (inPpsEl) inPpsEl.innerText = `${data.in_pps.toLocaleString()} pps`;
    if (outEl) outEl.innerHTML = `${data.out_mbps.toFixed(1)} <span style="font-size:0.75rem; font-weight:500; color:var(--text-muted);">Mbps</span>`;
    if (outPpsEl) outPpsEl.innerText = `${data.out_pps.toLocaleString()} pps`;

    const peak = Math.max(...inHistory, ...outHistory);
    if (peakEl) peakEl.innerHTML = `${peak.toFixed(1)} <span style="font-size:0.7rem; color:var(--text-muted);">Mbps</span>`;

    if (cpuEl && data.cpu_pct !== undefined) cpuEl.innerText = `${data.cpu_pct}%`;
    if (cpuBar && data.cpu_pct !== undefined) cpuBar.style.width = `${data.cpu_pct}%`;
    if (statesEl && data.states !== undefined) statesEl.innerText = data.states.toLocaleString();

    renderSvgChart();
  } catch (err) {
    // A missed sample is not a broken page: the shared scheduler backs the poll
    // off and the one connection banner says the appliance cannot be reached.
    // Resolving `false` is how this attempt tells the poll it failed.
    console.error('Traffic fetch error:', err);
    return false;
  }
}

function changeTrafficIface(iface) {
  currentIface = iface;
  inHistory = new Array(BUFFER_SIZE).fill(0);
  outHistory = new Array(BUFFER_SIZE).fill(0);
  fetchTrafficData();
}

function toggleTrafficStream() {
  trafficPaused = !trafficPaused;
  const btn = document.getElementById('btnTrafficPause');
  if (btn) {
    btn.innerText = trafficPaused ? '▶' : '⏸';
    btn.title = trafficPaused ? 'Resume Live Feed' : 'Pause Live Feed';
  }
}

async function triggerServiceAction(service, action) {
  const row = document.getElementById(`row_srv_${service}`);
  if (row) row.style.opacity = '0.5';

  const formData = new FormData();
  formData.append('service', service);
  formData.append('action', action);
  formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  try {
    const res = await MicronScheduler.fetch(`<?= base_url('dashboard/service-toggle') ?>`, {
      method: 'POST',
      body: formData,
    });
    const data = await res.json();
    showToast(data.message || `Service ${service} ${action} triggered`);
  } catch (err) {
    showToast(`Failed to ${action} ${service}`, true);
  } finally {
    if (row) row.style.opacity = '1';
  }
}

function showToast(msg, isError = false) {
  const toast = document.getElementById('dashToast');
  if (!toast) return;
  toast.innerText = msg;
  toast.style.borderColor = isError ? 'var(--color-red)' : 'var(--color-cyan)';
  toast.style.display = 'block';
  setTimeout(() => {
    toast.style.display = 'none';
  }, 3500);
}

document.addEventListener('DOMContentLoaded', () => {
  renderSvgChart();
  // The shared scheduler owns the cadence: it stops firing while the tab is
  // hidden, backs off while the appliance is unreachable, and resumes the
  // moment any request on the page gets through again.
  trafficInterval = MicronScheduler.poll('dashboard.traffic', { interval: 2000, run: fetchTrafficData });
});
</script>

<?= $this->endSection() ?>
