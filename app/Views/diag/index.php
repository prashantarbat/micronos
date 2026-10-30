<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<style>

/* ── Custom Button & Card Accents ── */
.btn-cyan {
  background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%) !important;
  color: #030712 !important;
  border: 1px solid #06b6d4 !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  box-shadow: 0 2px 10px rgba(6, 182, 212, 0.3) !important;
}
.btn-cyan:hover {
  background: linear-gradient(135deg, #22d3ee 0%, #06b6d4 100%) !important;
  color: #000 !important;
}

/* ── Quick preset pills ── */
/* ── Ping form (pfSense's field set) ── */
.diag-ping-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(0, 0.8fr) minmax(0, 1.2fr);
  gap: 12px 16px;
  align-items: start;
}
@media (max-width: 900px) { .diag-ping-grid { grid-template-columns: 1fr; } }
.diag-field-hint { font-size: 0.68rem; color: var(--text-muted); margin-top: 3px; }
/* ── Traceroute checkboxes (pfSense's two switches) ── */
.diag-check-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px 16px;
  align-items: start;
  margin-top: 12px;
}
@media (max-width: 900px) { .diag-check-grid { grid-template-columns: 1fr; } }
.diag-check-label {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-primary);
  cursor: pointer;
}
.preset-pill {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 4px;
  font-size: 0.72rem;
  font-family: var(--font-mono);
  color: var(--text-secondary);
  cursor: pointer;
  transition: all var(--transition-fast);
  user-select: none;
}
.preset-pill:hover {
  background: rgba(6, 182, 212, 0.15);
  border-color: rgba(6, 182, 212, 0.4);
  color: #67e8f9;
}

/* ── Telemetry Stat Cards Grid ── */
.diag-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.stat-widget {
  background: rgba(15, 23, 42, 0.65);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: var(--radius-lg);
  padding: 1.1rem 1.25rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,0.25);
  transition: transform 0.2s ease, border-color 0.2s ease;
}
.stat-widget:hover {
  transform: translateY(-2px);
  border-color: rgba(255, 255, 255, 0.18);
}
.stat-widget::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--widget-accent, var(--color-cyan)), transparent);
}

/* ── Diagnostic Tab Switcher ── */
.diag-tab-bar {
  display: flex;
  gap: 4px;
  background: rgba(0, 0, 0, 0.25);
  padding: 6px;
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  overflow-x: auto;
  scrollbar-width: thin;
}
.diag-tab-btn {
  padding: 9px 14px;
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-secondary);
  display: inline-flex;
  align-items: center;
  gap: 7px;
  border-radius: var(--radius-md);
  transition: all var(--transition-fast);
  white-space: nowrap;
}
.diag-tab-btn:hover {
  color: var(--text-primary);
  background: rgba(255, 255, 255, 0.05);
}
.diag-tab-btn.active {
  background: rgba(6, 182, 212, 0.14);
  border-color: rgba(6, 182, 212, 0.35);
  color: #38bdf8;
  box-shadow: 0 2px 8px rgba(6, 182, 212, 0.15);
}

/* ── Terminal Console Area ── */
.terminal-wrapper {
  margin-top: 1rem;
  background: #090d16;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: inset 0 2px 10px rgba(0,0,0,0.6);
}
.terminal-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 14px;
  background: rgba(255, 255, 255, 0.03);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  font-size: 0.75rem;
}
.terminal-dots {
  display: flex;
  gap: 6px;
  align-items: center;
}
.terminal-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}
.terminal-output {
  padding: 14px 16px;
  font-family: var(--font-mono);
  font-size: 0.82rem;
  line-height: 1.65;
  color: #e2e8f0;
  min-height: 160px;
  max-height: 380px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-all;
  background: #060911;
  transition: all 0.2s ease;
}
.terminal-output.running {
  border-left: 3px solid #06b6d4;
  color: #67e8f9;
}
.terminal-output.success {
  border-left: 3px solid #10b981;
}
.terminal-output.error {
  border-left: 3px solid #f43f5e;
  color: #fda4af;
}

/* ── Deep Telemetry Subsections ── */
.subtabs-bar {
  display: flex;
  gap: 8px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  padding-bottom: 12px;
  margin-bottom: 16px;
}
.subtab-btn {
  padding: 7px 14px;
  border-radius: 6px;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.08);
  color: var(--text-secondary);
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.subtab-btn:hover {
  color: var(--text-primary);
  background: rgba(255, 255, 255, 0.08);
}
.subtab-btn.active {
  background: rgba(14, 165, 233, 0.16);
  border-color: rgba(14, 165, 233, 0.4);
  color: #38bdf8;
}
</style>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- ── Page Header ── -->
<div class="page-header animate-in" style="margin-bottom: 1.5rem;">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Diagnostics</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Network Telemetry</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-cyan-bg); color:var(--tone-cyan-fg); border:1px solid var(--tone-cyan-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
      </div>
      <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <h1 class="page-title" style="margin:0;">Network Diagnostics &amp; Telemetry Suite</h1>
          <span class="badge badge-info" style="font-size:0.75rem;">DIAGNOSTIC CORE</span>
          <span class="badge badge-success" style="font-size:0.75rem;">FIB KERNEL ACTIVE</span>
          <?php if ($isMock): ?>
            <span class="badge badge-warning" style="font-size:0.75rem;">DEV PROTOTYPE</span>
          <?php endif; ?>
        </div>
        <p class="page-subtitle" style="margin:0;">
          Interactive network inspection suite &mdash; ICMP ping, traceroute, recursive DNS dig, kernel FIB routing decisions, port probes, multi-resolver latency benchmark, and packet capture.
        </p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('diag/states') ?>" class="btn btn-secondary btn-sm" style="gap:6px;">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      Firewall States (<?= number_format($conntrackCount ?? 0) ?>)
    </a>
    <a href="<?= base_url('diag/logs') ?>" class="btn btn-secondary btn-sm" style="gap:6px;">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
      </svg>
      Live Packet Logs
    </a>
    <a href="<?= base_url('routing') ?>" class="btn btn-secondary btn-sm" style="gap:6px;">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
      </svg>
      Routing Policies
    </a>
  </div>
</div>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- Telemetry Stat Cards Grid -->
<!-- ───────────────────────────────────────────────────────────── -->
<div class="diag-stats-grid animate-in animate-in-delay-1">
  <!-- System Uptime & Load -->
  <div class="stat-widget" style="--widget-accent: var(--color-emerald);">
    <div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">Uptime &amp; Load</span>
        <span class="status-indicator online"></span>
      </div>
      <div style="font-size:1.25rem; font-weight:800; color:var(--color-emerald); font-family:var(--font-mono);">
        <?php
          $s = (int)($stats['uptime_seconds'] ?? 0);
          printf('%dd %02dh %02dm', intdiv($s, 86400), intdiv($s % 86400, 3600), intdiv($s % 3600, 60));
        ?>
      </div>
    </div>
    <div style="margin-top:8px; font-size:0.75rem; color:var(--text-secondary); display:flex; justify-content:space-between;">
      <span>Load 1/5/15m:</span>
      <span style="font-family:var(--font-mono); color:var(--color-cyan); font-weight:600;">
        <?= esc($stats['load_1min'] ?? '0.12') ?> / <?= esc($stats['load_5min'] ?? '0.15') ?> / <?= esc($stats['load_15min'] ?? '0.18') ?>
      </span>
    </div>
  </div>

  <!-- Memory Footprint -->
  <div class="stat-widget" style="--widget-accent: var(--color-indigo);">
    <div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">Memory Allocation</span>
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-indigo);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
        </svg>
      </div>
      <div style="font-size:1.25rem; font-weight:800; color:#a5b4fc; font-family:var(--font-mono);">
        <?= esc($stats['memory_used_mb'] ?? 412) ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-muted);">/ <?= esc($stats['memory_total_mb'] ?? 2048) ?> MB</span>
      </div>
    </div>
    <div style="margin-top:8px;">
      <?php
        $used = (int)($stats['memory_used_mb'] ?? 412);
        $total = max(1, (int)($stats['memory_total_mb'] ?? 2048));
        $pct = round(($used / $total) * 100);
      ?>
      <div style="height:4px; background:rgba(255,255,255,0.08); border-radius:2px; overflow:hidden;">
        <div style="width:<?= $pct ?>%; height:100%; background:linear-gradient(90deg, #6366f1, #818cf8);"></div>
      </div>
      <div style="display:flex; justify-content:space-between; margin-top:4px; font-size:0.7rem; color:var(--text-muted);">
        <span>Utilized: <?= $pct ?>%</span>
        <span>Free: <?= max(0, $total - $used) ?> MB</span>
      </div>
    </div>
  </div>

  <!-- Conntrack Table States -->
  <div class="stat-widget" style="--widget-accent: var(--color-cyan);">
    <div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">Conntrack States</span>
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
      </div>
      <div style="font-size:1.25rem; font-weight:800; color:var(--color-cyan); font-family:var(--font-mono);">
        <?= number_format($conntrackCount ?? 1482) ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-muted);">active</span>
      </div>
    </div>
    <div style="margin-top:8px; font-size:0.75rem; color:var(--text-secondary); display:flex; justify-content:space-between;">
      <span>Table capacity:</span>
      <span style="font-family:var(--font-mono); color:var(--text-primary); font-weight:600;">262,144 max</span>
    </div>
  </div>

  <!-- Active Kernel Routes & Neighbors -->
  <div class="stat-widget" style="--widget-accent: var(--color-amber);">
    <div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <span style="font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted);">FIB Routes &amp; Neighbors</span>
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
        </svg>
      </div>
      <div style="font-size:1.25rem; font-weight:800; color:var(--color-amber); font-family:var(--font-mono);">
        <?= count($routingTable) ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-muted);">Routes</span> &middot; <?= count($arpEntries) + count($ndpEntries) ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-muted);">Neigh</span>
      </div>
    </div>
    <div style="margin-top:8px; font-size:0.75rem; color:var(--text-secondary); display:flex; justify-content:space-between;">
      <span>ARP (v4): <?= count($arpEntries) ?></span>
      <span>NDP (v6): <?= count($ndpEntries) ?></span>
    </div>
  </div>
</div>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- Network Diagnostic Tools Suite Card -->
<!-- ───────────────────────────────────────────────────────────── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:1.5rem; padding:0; overflow:hidden;">
  <!-- Tool Tabs Navigation -->
  <div class="diag-tab-bar" id="diagTabs">
    <button type="button" class="diag-tab-btn active" data-tab="tab-ping">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      ICMP Ping
    </button>
    <button type="button" class="diag-tab-btn" data-tab="tab-trace">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
      </svg>
      Traceroute
    </button>
    <button type="button" class="diag-tab-btn" data-tab="tab-dns">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
      </svg>
      DNS Dig Query
    </button>
    <button type="button" class="diag-tab-btn" data-tab="tab-route">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
      </svg>
      Route Decision (FIB)
    </button>
    <button type="button" class="diag-tab-btn" data-tab="tab-port">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
      </svg>
      Port / Socket Probe
    </button>
    <button type="button" class="diag-tab-btn" data-tab="tab-bench">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      DNS Benchmark
    </button>
    <button type="button" class="diag-tab-btn" data-tab="tab-pcap">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
      </svg>
      Packet Capture
    </button>
  </div>

  <div style="padding:1.25rem;">
    <!-- 1. Ping Tab -->
    <div id="tab-ping" class="diag-pane">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:var(--text-secondary);">Test reachability and round-trip latency to a host via ICMP echo requests.</span>
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick Presets:</span>
          <span class="preset-pill" onclick="setPing('1.1.1.1')">Cloudflare (1.1.1.1)</span>
          <span class="preset-pill" onclick="setPing('8.8.8.8')">Google (8.8.8.8)</span>
          <span class="preset-pill" onclick="setPing('192.168.1.1')">Gateway (192.168.1.1)</span>
        </div>
      </div>
      <?php $probeSources = $probeSources ?? []; ?>
      <div class="diag-ping-grid">
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="pingHost">Hostname</label>
          <input type="text" id="pingHost" class="form-control" placeholder="Hostname to ping" value="1.1.1.1" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="pingProto">IP Protocol</label>
          <select id="pingProto" class="form-control" style="font-size:0.85rem;">
            <option value="ipv4" selected>IPv4</option>
            <option value="ipv6">IPv6</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="pingSource">Source address</label>
          <select id="pingSource" class="form-control" style="font-size:0.85rem;">
            <option value="">Automatically selected (default)</option>
            <?php foreach ($probeSources as $source): ?>
              <option value="<?= esc($source['value']) ?>"><?= esc($source['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="diag-field-hint">Select source address for the ping.</div>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="pingCount">Maximum number of pings</label>
          <select id="pingCount" class="form-control" style="font-size:0.85rem;">
            <?php foreach (range(1, 10) as $n): ?>
              <option value="<?= $n ?>"<?= $n === 3 ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          <div class="diag-field-hint">Select the maximum number of pings.</div>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="pingWait">Seconds between pings</label>
          <select id="pingWait" class="form-control" style="font-size:0.85rem;">
            <?php foreach (range(1, 10) as $n): ?>
              <option value="<?= $n ?>"<?= $n === 1 ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          <div class="diag-field-hint">Select the number of seconds to wait between pings.</div>
        </div>
      </div>
      <div style="display:flex; gap:8px; margin-top:14px;">
        <button type="button" id="btnRunPing" class="btn btn-cyan">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
          </svg>
          Ping
        </button>
      </div>
    </div>

    <!-- 2. Traceroute Tab -->
    <div id="tab-trace" class="diag-pane" style="display:none;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:var(--text-secondary);">Map the network path, transit autonomous systems, and hop-by-hop latency to target.</span>
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick Presets:</span>
          <span class="preset-pill" onclick="setTrace('8.8.8.8')">Google DNS</span>
          <span class="preset-pill" onclick="setTrace('1.1.1.1')">Cloudflare</span>
          <span class="preset-pill" onclick="setTrace('github.com')">GitHub</span>
        </div>
      </div>
      <div class="diag-ping-grid">
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="traceHost">Hostname</label>
          <input type="text" id="traceHost" class="form-control" placeholder="e.g. 8.8.8.8, cloudflare.com" value="8.8.8.8" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="traceProto">IP Protocol</label>
          <select id="traceProto" class="form-control" style="font-size:0.85rem;">
            <option value="ipv4" selected>IPv4</option>
            <option value="ipv6">IPv6</option>
          </select>
          <div class="diag-field-hint">Select the protocol to use.</div>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="traceSource">Source Address</label>
          <select id="traceSource" class="form-control" style="font-size:0.85rem;">
            <option value="any">Any</option>
            <?php foreach ($probeSources as $source): ?>
              <option value="<?= esc($source['value']) ?>"><?= esc($source['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="diag-field-hint">Select source address for the trace.</div>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;" for="traceTtl">Maximum number of hops</label>
          <select id="traceTtl" class="form-control" style="font-size:0.85rem;">
            <?php foreach (range(1, 64) as $n): ?>
              <option value="<?= $n ?>"<?= $n === 18 ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          <div class="diag-field-hint">Select the maximum number of network hops to trace.</div>
        </div>
      </div>
      <div class="diag-check-grid">
        <div>
          <label class="diag-check-label" for="traceResolve">
            <input type="checkbox" id="traceResolve">
            <span>Reverse Address Lookup</span>
          </label>
          <div class="diag-field-hint">When checked, traceroute will attempt to perform a PTR lookup to locate hostnames for hops along the path. This will slow down the process as it has to wait for DNS replies.</div>
        </div>
        <div>
          <label class="diag-check-label" for="traceIcmp">
            <input type="checkbox" id="traceIcmp">
            <span>Use ICMP</span>
          </label>
          <div class="diag-field-hint">By default, traceroute uses UDP but that may be blocked by some routers. Check this box to use ICMP instead, which may succeed.</div>
        </div>
      </div>
      <div style="display:flex; gap:8px; margin-top:14px;">
        <button type="button" id="btnRunTrace" class="btn btn-cyan">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
          </svg>
          Traceroute
        </button>
      </div>
    </div>

    <!-- 3. DNS Dig Tab -->
    <div id="tab-dns" class="diag-pane" style="display:none;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:var(--text-secondary);">Query DNS resource records (RR) using appliance Unbound cache or external resolvers.</span>
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick Presets:</span>
          <span class="preset-pill" onclick="setDns('cloudflare.com', 'A')">cloudflare.com (A)</span>
          <span class="preset-pill" onclick="setDns('google.com', 'MX')">google.com (MX)</span>
          <span class="preset-pill" onclick="setDns('kernel.org', 'AAAA')">kernel.org (AAAA)</span>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr auto auto auto; gap:12px; align-items:flex-end;">
        <div>
          <label class="form-label" style="font-size:0.8rem;">Domain Name / FQDN</label>
          <input type="text" id="dnsName" class="form-control" placeholder="e.g. example.com" value="cloudflare.com" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">Record Type</label>
          <select id="dnsType" class="form-control" style="width:100px; font-size:0.85rem;">
            <?php foreach (['A','AAAA','MX','NS','TXT','CNAME','PTR','SOA','ANY'] as $t): ?>
              <option value="<?= $t ?>"><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">Resolver Server</label>
          <select id="dnsServer" class="form-control" style="width:160px; font-size:0.85rem;">
            <option value="1.1.1.1">1.1.1.1 (Cloudflare)</option>
            <option value="8.8.8.8">8.8.8.8 (Google)</option>
            <option value="9.9.9.9">9.9.9.9 (Quad9 Security)</option>
            <option value="system">Appliance Local DNS</option>
          </select>
        </div>
        <button type="button" id="btnRunDns" class="btn btn-cyan" style="height:38px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
          </svg>
          Resolve RR
        </button>
      </div>
    </div>

    <!-- 4. Route Decision (FIB) Tab -->
    <div id="tab-route" class="diag-pane" style="display:none;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:var(--text-secondary);">Query Linux Forwarding Information Base (FIB) to inspect outgoing interface, source IP, and next-hop gateway.</span>
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick Presets:</span>
          <span class="preset-pill" onclick="setRoute('8.8.8.8')">Internet (8.8.8.8)</span>
          <span class="preset-pill" onclick="setRoute('10.8.0.5')">VPN (10.8.0.5)</span>
          <span class="preset-pill" onclick="setRoute('192.168.1.100')">LAN Host</span>
          <span class="preset-pill" onclick="setRoute('192.168.20.50')">DMZ Host</span>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr auto; gap:12px; align-items:flex-end;">
        <div>
          <label class="form-label" style="font-size:0.8rem;">Target Destination IP or Hostname</label>
          <input type="text" id="routeTarget" class="form-control" placeholder="e.g. 8.8.8.8, 10.100.0.1, or 192.168.1.50" value="8.8.8.8" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <button type="button" id="btnRunRoute" class="btn btn-cyan" style="height:38px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
          </svg>
          Query FIB Route
        </button>
      </div>
    </div>

    <!-- 5. Port / Socket Probe Tab -->
    <div id="tab-port" class="diag-pane" style="display:none;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:var(--text-secondary);">Test TCP socket handshake or UDP service connectivity to verify firewall rules and server listening state.</span>
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick Ports:</span>
          <span class="preset-pill" onclick="setPort(443, 'tcp')">443 (HTTPS)</span>
          <span class="preset-pill" onclick="setPort(80, 'tcp')">80 (HTTP)</span>
          <span class="preset-pill" onclick="setPort(22, 'tcp')">22 (SSH)</span>
          <span class="preset-pill" onclick="setPort(53, 'udp')">53 (DNS)</span>
          <span class="preset-pill" onclick="setPort(8080, 'tcp')">8080 (Alt-HTTP)</span>
          <span class="preset-pill" onclick="setPort(51820, 'udp')">51820 (WireGuard)</span>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 100px 100px 100px auto; gap:12px; align-items:flex-end;">
        <div>
          <label class="form-label" style="font-size:0.8rem;">Host / IP</label>
          <input type="text" id="portHost" class="form-control" placeholder="e.g. 1.1.1.1 or micronos.test" value="1.1.1.1" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">Port</label>
          <input type="number" id="portNum" class="form-control" min="1" max="65535" value="443" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">Protocol</label>
          <select id="portProto" class="form-control" style="font-size:0.85rem;">
            <option value="tcp" selected>TCP</option>
            <option value="udp">UDP</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">Timeout</label>
          <select id="portTimeout" class="form-control" style="font-size:0.85rem;">
            <option value="1.5">1.5s</option>
            <option value="2.5" selected>2.5s</option>
            <option value="5.0">5.0s</option>
          </select>
        </div>
        <button type="button" id="btnRunPort" class="btn btn-cyan" style="height:38px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          Probe Socket
        </button>
      </div>
    </div>

    <!-- 6. DNS Benchmark Tab -->
    <div id="tab-bench" class="diag-pane" style="display:none;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.8rem; color:var(--text-secondary);">Benchmark latency across public upstream resolvers (Cloudflare, Google, Quad9, OpenDNS, Appliance).</span>
        <div style="display:flex; align-items:center; gap:6px;">
          <span style="font-size:0.72rem; color:var(--text-muted);">Quick Domains:</span>
          <span class="preset-pill" onclick="setBench('cloudflare.com')">cloudflare.com</span>
          <span class="preset-pill" onclick="setBench('microsoft.com')">microsoft.com</span>
          <span class="preset-pill" onclick="setBench('wikipedia.org')">wikipedia.org</span>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr auto; gap:12px; align-items:flex-end;">
        <div>
          <label class="form-label" style="font-size:0.8rem;">Target Domain for Resolution Benchmark</label>
          <input type="text" id="benchDomain" class="form-control" placeholder="e.g. cloudflare.com" value="cloudflare.com" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <button type="button" id="btnRunBench" class="btn btn-cyan" style="height:38px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Run Benchmark
        </button>
      </div>
    </div>

    <!-- 7. Packet Capture Tab -->
    <div id="tab-pcap" class="diag-pane" style="display:none;">
      <div style="background:rgba(251,191,36,0.08); border:1px solid rgba(251,191,36,0.3); border-radius:var(--radius-md); padding:0.6rem 1rem; margin-bottom:12px; font-size:0.78rem; color:var(--color-amber); display:flex; align-items:center; gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Appliance TCPDump engine. Capture frames live with a Berkeley Packet Filter (BPF) — output streams into the terminal below, and the filter is validated on the appliance before the capture starts.
      </div>
      <div style="display:grid; grid-template-columns:180px 1fr 110px auto; gap:12px; align-items:flex-end;">
        <div>
          <label class="form-label" style="font-size:0.8rem;">Interface</label>
          <select id="pcapIface" class="form-control" style="font-size:0.85rem;">
            <option value="eth0">eth0 (WAN Gateway)</option>
            <option value="eth1">eth1 (LAN Direct)</option>
            <option value="tun0">tun0 (OpenVPN)</option>
            <option value="wg0">wg0 (WireGuard)</option>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">BPF Filter Expression</label>
          <input type="text" id="pcapFilter" class="form-control" placeholder="e.g. icmp, tcp port 443, or port 53" value="icmp" style="font-family:var(--font-mono); font-size:0.85rem;">
        </div>
        <div>
          <label class="form-label" style="font-size:0.8rem;">Frames</label>
          <select id="pcapCount" class="form-control" style="font-size:0.85rem;">
            <option value="10" selected>10 frames</option>
            <option value="25">25 frames</option>
            <option value="50">50 frames</option>
          </select>
        </div>
        <button type="button" id="btnRunPcap" class="btn btn-cyan" style="height:38px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
          </svg>
          Live Capture
        </button>
      </div>
    </div>

    <!-- Unified Diagnostic Terminal Console -->
    <div class="terminal-wrapper">
      <div class="terminal-topbar">
        <div style="display:flex; align-items:center; gap:10px;">
          <div class="terminal-dots">
            <span class="terminal-dot" style="background:#ef4444;"></span>
            <span class="terminal-dot" style="background:#f59e0b;"></span>
            <span class="terminal-dot" style="background:#10b981;"></span>
          </div>
          <span style="font-weight:700; color:var(--text-secondary); letter-spacing:0.04em;">DIAGNOSTIC TERMINAL</span>
          <span id="termStatusBadge" class="mno-obj-chip mno-obj-chip-lan" style="font-size:0.7rem; padding:1px 6px;">READY</span>
          <span id="termLatencyBadge" style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); display:none;"></span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
          <button type="button" id="btnStopTerminal" class="btn btn-secondary" style="padding:3px 8px; font-size:0.72rem; gap:4px; display:none;">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <rect x="6" y="6" width="12" height="12" rx="1"/>
            </svg>
            Stop
          </button>
          <button type="button" id="btnCopyTerminal" class="btn btn-secondary" style="padding:3px 8px; font-size:0.72rem; gap:4px;">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
            </svg>
            <span id="copyBtnText">Copy</span>
          </button>
          <button type="button" id="btnClearTerminal" class="btn btn-secondary" style="padding:3px 8px; font-size:0.72rem; gap:4px;">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            Clear
          </button>
        </div>
      </div>
      <div id="diagTerminal" class="terminal-output">Ready — select a network utility above and click execute to begin live probe…</div>
    </div>
  </div>
</div>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- Deep Inspection Tables Section (FIB Routing, Neighbors, Audit) -->
<!-- ───────────────────────────────────────────────────────────── -->
<div class="card card-glass animate-in animate-in-delay-3" style="padding:1.25rem; margin-bottom:1.5rem;">
  <div class="subtabs-bar">
    <button type="button" class="subtab-btn active" data-sub="sub-fib">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
      </svg>
      Kernel Routing Table (FIB)
      <span class="badge badge-muted" style="margin-left:4px; font-size:0.7rem;"><?= count($routingTable) ?></span>
    </button>
    <button type="button" class="subtab-btn" data-sub="sub-arp">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
      </svg>
      ARP &amp; IPv6 NDP Cache
      <span class="badge badge-muted" style="margin-left:4px; font-size:0.7rem;"><?= count($arpEntries) + count($ndpEntries) ?></span>
    </button>
    <button type="button" class="subtab-btn" data-sub="sub-audit">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Admin Audit Trail
      <span class="badge badge-muted" style="margin-left:4px; font-size:0.7rem;"><?= count($auditLogs) ?></span>
    </button>
  </div>

  <!-- Sub-Panel 1: Kernel Routing Table (FIB) -->
  <div id="sub-fib" class="subtab-pane">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
      <span style="font-size:0.8rem; color:var(--text-secondary);">Active kernel routing entries parsed directly from the system FIB table.</span>
      <a href="<?= base_url('routing') ?>" class="btn btn-secondary" style="font-size:0.75rem; padding:4px 10px;">Configure Static Routes &rarr;</a>
      <a href="<?= base_url('routing/full-table') ?>" class="btn btn-secondary" style="font-size:0.75rem; padding:4px 10px;">View Full Routing Table &rarr;</a>
    </div>
    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Destination Network</th>
            <th>Gateway / Next-Hop</th>
            <th>Interface</th>
            <th>Metric</th>
            <th>Protocol</th>
            <th>Flags</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routingTable as $r): ?>
            <tr>
              <td style="font-family:var(--font-mono); font-weight:700; color:var(--color-cyan);">
                <?= esc($r['destination']) ?>
                <?php if ($r['destination'] === '0.0.0.0/0'): ?>
                  <span class="mno-obj-chip mno-obj-chip-wan" style="font-size:0.68rem; margin-left:6px;">DEFAULT GATEWAY</span>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.82rem; color:<?= $r['gateway'] === 'direct' ? 'var(--text-muted)' : 'var(--text-primary)' ?>;">
                <?= esc($r['gateway']) ?>
              </td>
              <td>
                <?php
                  $ifName = strtolower(explode(' ', $r['interface'])[0]);
                  $chipClass = match(true) {
                    str_contains($ifName, 'wan') || str_contains($ifName, 'eth0') => 'mno-obj-chip-wan',
                    str_contains($ifName, 'lan') || str_contains($ifName, 'eth1') => 'mno-obj-chip-lan',
                    str_contains($ifName, 'dmz') || str_contains($ifName, 'eth2') => 'mno-obj-chip-dmz',
                    str_contains($ifName, 'tun') || str_contains($ifName, 'wg')   => 'mno-obj-chip-vpn',
                    default => 'mno-obj-chip',
                  };
                ?>
                <span class="mno-obj-chip <?= $chipClass ?>">
                  <?= esc($r['interface']) ?>
                </span>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-muted);"><?= esc($r['metric']) ?></td>
              <td><span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.72rem;"><?= esc($r['protocol']) ?></span></td>
              <td><span class="badge badge-purple" style="font-family:var(--font-mono); font-size:0.72rem;"><?= esc($r['flags']) ?></span></td>
              <td style="text-align:right;">
                <button type="button" class="btn btn-secondary" style="padding:2px 8px; font-size:0.72rem;" onclick="testRouteDecision('<?= esc($r['destination'] === '0.0.0.0/0' ? '8.8.8.8' : explode('/', $r['destination'])[0]) ?>')">
                  Test FIB &rarr;
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Sub-Panel 2: ARP & IPv6 NDP Cache -->
  <div id="sub-arp" class="subtab-pane" style="display:none;">
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
      <!-- IPv4 ARP -->
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <h4 style="margin:0; font-size:0.85rem; font-weight:700; color:var(--color-cyan);">IPv4 ARP Table</h4>
          <span class="badge badge-muted" style="font-size:0.7rem;"><?= count($arpEntries) ?> entries</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="iface-table">
            <thead>
              <tr>
                <th>IPv4 Address</th>
                <th>MAC Address</th>
                <th>Interface</th>
                <th>Type</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($arpEntries as $arp): ?>
                <tr>
                  <td style="font-family:var(--font-mono); color:var(--color-cyan); font-size:0.82rem;"><?= esc($arp['ip']) ?></td>
                  <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);"><?= esc($arp['mac']) ?></td>
                  <td><span class="mno-obj-chip mno-obj-chip-lan" style="font-size:0.7rem;"><?= esc($arp['interface']) ?></span></td>
                  <td>
                    <span class="badge <?= match($arp['type']) { 'gateway' => 'badge-success', 'vpn' => 'badge-purple', default => 'badge-info' } ?>" style="font-size:0.7rem;">
                      <?= esc(ucfirst($arp['type'])) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- IPv6 NDP -->
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <h4 style="margin:0; font-size:0.85rem; font-weight:700; color:var(--color-indigo);">IPv6 Neighbor Discovery (NDP)</h4>
          <span class="badge badge-muted" style="font-size:0.7rem;"><?= count($ndpEntries) ?> entries</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="iface-table">
            <thead>
              <tr>
                <th>IPv6 Neighbor</th>
                <th>Link-Layer MAC</th>
                <th>Iface</th>
                <th>NDP State</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ndpEntries)): ?>
                <tr><td colspan="4" style="text-align:center; padding:1.5rem; color:var(--text-muted); font-size:0.8rem;">No active IPv6 neighbors detected.</td></tr>
              <?php else: ?>
                <?php foreach ($ndpEntries as $ndp): ?>
                  <tr>
                    <td style="font-family:var(--font-mono); color:#a5b4fc; font-size:0.78rem; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= esc($ndp['ip']) ?></td>
                    <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);"><?= esc($ndp['mac']) ?></td>
                    <td><span class="mno-obj-chip" style="font-size:0.7rem;"><?= esc($ndp['interface']) ?></span></td>
                    <td>
                      <span class="badge <?= match($ndp['state']) { 'REACHABLE' => 'badge-success', 'STALE' => 'badge-warning', default => 'badge-muted' } ?>" style="font-size:0.7rem;">
                        <?= esc($ndp['state']) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Sub-Panel 3: Administrative Audit Trail -->
  <div id="sub-audit" class="subtab-pane" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
      <span style="font-size:0.8rem; color:var(--text-secondary);">Security audit trail recording recent administrative operations and configuration revisions.</span>
      <span class="badge badge-muted"><?= count($auditLogs) ?> Total Records</span>
    </div>
    <div style="overflow-x:auto; max-height:360px; overflow-y:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Administrator</th>
            <th>Action Code</th>
            <th>Target Context</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($auditLogs)): ?>
            <tr><td colspan="4" style="text-align:center; padding:2rem; color:var(--text-muted);">No audit events recorded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($auditLogs as $log): ?>
              <tr>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                  <?= esc($log['created_at']) ?>
                </td>
                <td>
                  <strong style="font-size:0.8rem; color:var(--text-primary); font-family:var(--font-mono);">
                    @<?= esc($log['username'] ?: 'admin') ?>
                  </strong>
                </td>
                <td>
                  <span class="badge badge-purple" style="font-family:var(--font-mono); font-size:0.72rem;">
                    <?= esc($log['action']) ?>
                  </span>
                </td>
                <td style="font-size:0.8rem; color:var(--text-secondary); max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                  <?= esc($log['target'] ?? '—') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ───────────────────────────────────────────────────────────── -->
<!-- Interactive Logic & AJAX Diagnostics Controller Integration -->
<!-- ───────────────────────────────────────────────────────────── -->
<script>
const CSRF_NAME = '<?= csrf_token() ?>';
let CSRF_HASH   = '<?= csrf_hash() ?>';
const BASE_URL  = '<?= base_url() ?>';


// ── Tab Navigation for Diagnostic Tools
document.querySelectorAll('.diag-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.diag-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.diag-pane').forEach(p => p.style.display = 'none');
    btn.classList.add('active');
    const targetPane = document.getElementById(btn.dataset.tab);
    if (targetPane) targetPane.style.display = 'block';
  });
});

// ── Subtabs for Deep Telemetry (FIB / ARP / Audit)
document.querySelectorAll('.subtab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.subtab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.subtab-pane').forEach(p => p.style.display = 'none');
    btn.classList.add('active');
    const targetPane = document.getElementById(btn.dataset.sub);
    if (targetPane) targetPane.style.display = 'block';
  });
});

// ── Quick Preset Setters
function setPing(target) {
  document.getElementById('pingHost').value = target;
  document.getElementById('btnRunPing').click();
}

function setTrace(target) {
  document.getElementById('traceHost').value = target;
  document.getElementById('btnRunTrace').click();
}

function setDns(domain, type) {
  document.getElementById('dnsName').value = domain;
  if (type) document.getElementById('dnsType').value = type;
  document.getElementById('btnRunDns').click();
}

function setRoute(target) {
  document.getElementById('routeTarget').value = target;
  document.getElementById('btnRunRoute').click();
}

function setPort(port, proto) {
  document.getElementById('portNum').value = port;
  if (proto) document.getElementById('portProto').value = proto;
  document.getElementById('btnRunPort').click();
}

function setBench(domain) {
  document.getElementById('benchDomain').value = domain;
  document.getElementById('btnRunBench').click();
}

function testRouteDecision(dest) {
  // Switch to Route Decision tab and run
  const routeTabBtn = document.querySelector('[data-tab="tab-route"]');
  if (routeTabBtn) routeTabBtn.click();
  document.getElementById('routeTarget').value = dest;
  document.getElementById('btnRunRoute').click();
  window.scrollTo({ top: document.querySelector('.terminal-wrapper').offsetTop - 100, behavior: 'smooth' });
}

// ── Shared Terminal Runner
async function executeDiagTool(endpoint, payload, toolLabel) {
  const term = document.getElementById('diagTerminal');
  const statusBadge = document.getElementById('termStatusBadge');
  const latencyBadge = document.getElementById('termLatencyBadge');

  statusBadge.textContent = 'PROBING...';
  statusBadge.className = 'mno-obj-chip mno-obj-chip-wan';
  latencyBadge.style.display = 'none';

  term.className = 'terminal-output running';
  term.textContent = `⏳ [${new Date().toLocaleTimeString()}] Executing ${toolLabel} probe on appliance...\n`;

  const startTime = performance.now();
  const formData = new FormData();
  formData.append(CSRF_NAME, CSRF_HASH);
  for (const [k, v] of Object.entries(payload)) {
    formData.append(k, v);
  }

  try {
    const res = await MicronScheduler.fetch(BASE_URL + endpoint, {
      method: 'POST',
      body: formData,
    });
    
    const data = await res.json();
    const elapsed = Math.round(performance.now() - startTime);

    // Token regeneration is on and the CSRF cookie is HttpOnly, so the hash the
    // page rendered is spent by this request. The response carries the next one;
    // without adopting it every probe after the first would be refused.
    if (data && data.csrf) CSRF_HASH = data.csrf;

    // A refused request (an invalid field, or the daemon turning it down) is a
    // failure to show, not simulated output to print as if it had run.
    const refused = !res.ok || !!data.error;

    term.className = 'terminal-output ' + (refused ? 'error' : 'success');
    term.textContent = data.output || data.error || JSON.stringify(data, null, 2);

    if (!refused && data.mock) {
      term.textContent += '\n\n💡 [MicronOS Development Prototyping Mode — simulated response]';
    }

    statusBadge.textContent = refused ? 'REJECTED' : 'COMPLETED';
    statusBadge.className = 'mno-obj-chip ' + (refused ? 'mno-obj-chip-vpn' : 'mno-obj-chip-success');

    if (refused) {
      latencyBadge.style.display = 'none';
      return;
    }

    latencyBadge.style.display = 'inline';
    latencyBadge.textContent = `RTT: ${elapsed} ms`;
  } catch (err) {
    term.className = 'terminal-output error';
    term.textContent = `✗ Execution failed: ${err.message}\nEnsure the micrond daemon or webserver endpoint is active.`;
    statusBadge.textContent = 'FAILED';
    statusBadge.className = 'mno-obj-chip mno-obj-chip-vpn';
  }
}

// ── Bind Tool Buttons
// Ping and traceroute stream through a session runner (`startProbe`) so each
// reply or hop reaches the terminal as it is produced, rather than after the
// whole command finishes. The other tools are one-shot and use `executeDiagTool`.
document.getElementById('btnRunPing').addEventListener('click', () => {
  startProbe('diag/ping/start', {
    host: document.getElementById('pingHost').value.trim(),
    ipproto: document.getElementById('pingProto').value,
    sourceip: document.getElementById('pingSource').value,
    count: document.getElementById('pingCount').value,
    wait: document.getElementById('pingWait').value,
  }, 'ICMP Ping');
});

document.getElementById('btnRunTrace').addEventListener('click', () => {
  startProbe('diag/traceroute/start', {
    host: document.getElementById('traceHost').value.trim(),
    ipproto: document.getElementById('traceProto').value,
    sourceip: document.getElementById('traceSource').value,
    ttl: document.getElementById('traceTtl').value,
    resolve: document.getElementById('traceResolve').checked ? 'yes' : '',
    useicmp: document.getElementById('traceIcmp').checked ? 'yes' : '',
  }, 'Traceroute');
});

document.getElementById('btnRunDns').addEventListener('click', () => {
  executeDiagTool('diag/dns-lookup', {
    name: document.getElementById('dnsName').value.trim(),
    type: document.getElementById('dnsType').value,
    server: document.getElementById('dnsServer').value,
  }, 'DNS Dig');
});

document.getElementById('btnRunRoute').addEventListener('click', () => {
  executeDiagTool('diag/route-lookup', {
    target: document.getElementById('routeTarget').value.trim(),
  }, 'Kernel Route Decision (FIB)');
});

document.getElementById('btnRunPort').addEventListener('click', () => {
  executeDiagTool('diag/port-probe', {
    host: document.getElementById('portHost').value.trim(),
    port: document.getElementById('portNum').value,
    protocol: document.getElementById('portProto').value,
    timeout: document.getElementById('portTimeout').value,
  }, 'Port / Socket Probe');
});

document.getElementById('btnRunBench').addEventListener('click', () => {
  executeDiagTool('diag/dns-benchmark', {
    domain: document.getElementById('benchDomain').value.trim(),
  }, 'DNS Multi-Resolver Benchmark');
});

document.getElementById('btnRunPcap').addEventListener('click', startLiveCapture);

// ── Live Packet Capture Runner (streamed into the terminal)
// A capture is a session: `start` opens it, `stream` hands back only what tcpdump
// has produced since the previous poll, and `stop` closes it. Output is appended
// as it arrives rather than replaced, so frames appear on the terminal live.
let captureSession = null;
let capturePollHandle = null;

// A refusal can be an HTML error page (the permission filter), so a response is
// parsed as JSON only when it actually is one.
async function readJsonResponse(res) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (err) {
    return {
      error: res.ok
        ? 'The appliance returned an unreadable response.'
        : `The request was refused (HTTP ${res.status}).`,
    };
  }
}

function captureStatus(text, cls) {
  const badge = document.getElementById('termStatusBadge');
  badge.textContent = text;
  badge.className = 'mno-obj-chip ' + cls;
}

function termAppend(text) {
  const term = document.getElementById('diagTerminal');
  if (!text) return;
  if (term.textContent && !term.textContent.endsWith('\n')) term.textContent += '\n';
  term.textContent += text;
  term.scrollTop = term.scrollHeight;
}

function setCaptureRunning(running) {
  document.getElementById('btnStopTerminal').style.display = running ? 'inline-flex' : 'none';
}

function endCapture(label, cls) {
  captureSession = null;
  if (capturePollHandle) { capturePollHandle.stop(); capturePollHandle = null; }
  captureStatus(label, cls);
  setCaptureRunning(false);
}

async function startLiveCapture() {
  const term = document.getElementById('diagTerminal');

  await stopLiveCapture(true);
  await stopProbe(true);   // one stream at a time shares the one terminal

  captureStatus('CAPTURING', 'mno-obj-chip-wan');
  setCaptureRunning(true);
  term.className = 'terminal-output running';
  term.textContent = `⏳ [${new Date().toLocaleTimeString()}] Starting live capture…\n`;

  const formData = new FormData();
  formData.append(CSRF_NAME, CSRF_HASH);
  formData.append('interface', document.getElementById('pcapIface').value);
  formData.append('filter', document.getElementById('pcapFilter').value.trim());
  formData.append('packets', document.getElementById('pcapCount').value);

  let data;
  try {
    const res = await MicronScheduler.fetch(BASE_URL + 'diag/packet-capture/start', { method: 'POST', body: formData });
    data = await readJsonResponse(res);
  } catch (err) {
    term.className = 'terminal-output error';
    term.textContent = `✗ Could not start the capture: ${err.message}`;
    captureStatus('FAILED', 'mno-obj-chip-vpn');
    setCaptureRunning(false);
    return;
  }

  if (data && data.csrf) CSRF_HASH = data.csrf;

  if (!data || data.error) {
    term.className = 'terminal-output error';
    term.textContent = (data && (data.error || data.output)) || 'The capture request was refused.';
    captureStatus('REJECTED', 'mno-obj-chip-vpn');
    setCaptureRunning(false);
    return;
  }

  // The command line the daemon actually ran, then whatever it has produced so far.
  term.textContent = data.cmd ? `$ ${data.cmd}\n` : '';
  termAppend(data.output || '');

  if (!data.session) {
    // The dev mock has no session to poll — the whole capture came back at once.
    if (data.mock) termAppend('\n💡 [MicronOS Development Prototyping Mode — simulated response]');
    term.className = 'terminal-output success';
    captureStatus('COMPLETED', 'mno-obj-chip-success');
    setCaptureRunning(false);
    return;
  }

  captureSession = data.session;
  capturePollHandle = MicronScheduler.poll('diag.pcap', { interval: 600, run: pollLiveCapture });
}

async function pollLiveCapture() {
  if (!captureSession) return true;   // idle is not an outage
  try {
    const res = await MicronScheduler.fetch(
      BASE_URL + 'diag/packet-capture/stream?session=' + encodeURIComponent(captureSession)
    );
    const data = await readJsonResponse(res);
    if (data && data.csrf) CSRF_HASH = data.csrf;

    if (!res.ok || (data && data.error)) {
      endCapture('STOPPED', 'mno-obj-chip');
      return true;
    }

    termAppend(data.output || '');
    if (!data.running) {
      document.getElementById('diagTerminal').className = 'terminal-output success';
      endCapture('COMPLETED', 'mno-obj-chip-success');
    }
    return true;
  } catch (err) {
    // `false` tells the shared scheduler this attempt failed, so it backs off
    // and raises the one connection banner rather than hammering a dead link.
    return false;
  }
}

async function stopLiveCapture(quiet = false) {
  if (!captureSession) { setCaptureRunning(false); return; }

  const session = captureSession;
  endCapture('STOPPED', 'mno-obj-chip');

  const formData = new FormData();
  formData.append(CSRF_NAME, CSRF_HASH);
  formData.append('session', session);
  try {
    const res = await MicronScheduler.fetch(BASE_URL + 'diag/packet-capture/stop', { method: 'POST', body: formData });
    const data = await readJsonResponse(res);
    if (data && data.csrf) CSRF_HASH = data.csrf;
    if (!quiet && data && data.output) termAppend(data.output);
  } catch (err) {
    // The session is already closed locally; the daemon will reap it either way.
  }
}

// ── Streamed Ping / Traceroute Runner (streamed into the terminal)
// A ping prints a line per reply and a traceroute a line per hop; both are
// sessions like a live capture: `start` opens one, `stream` hands back only the
// bytes produced since the previous poll, and `stop` closes it early. Output is
// appended as it arrives, so each reply or hop appears on the terminal live.
let probeSession = null;
let probePollHandle = null;

function endProbe(label, cls) {
  probeSession = null;
  if (probePollHandle) { probePollHandle.stop(); probePollHandle = null; }
  captureStatus(label, cls);
  setCaptureRunning(false);
}

async function startProbe(endpoint, payload, label) {
  const term = document.getElementById('diagTerminal');

  // Only one stream runs at a time: a new probe replaces whatever was running.
  await stopLiveCapture(true);
  await stopProbe(true);

  captureStatus('RUNNING', 'mno-obj-chip-wan');
  setCaptureRunning(true);
  term.className = 'terminal-output running';
  term.textContent = `⏳ [${new Date().toLocaleTimeString()}] Executing ${label} probe on appliance...\n`;

  const formData = new FormData();
  formData.append(CSRF_NAME, CSRF_HASH);
  for (const [k, v] of Object.entries(payload)) formData.append(k, v);

  let data;
  try {
    const res = await MicronScheduler.fetch(BASE_URL + endpoint, { method: 'POST', body: formData });
    data = await readJsonResponse(res);
  } catch (err) {
    term.className = 'terminal-output error';
    term.textContent = `✗ Could not run the ${label} probe: ${err.message}`;
    captureStatus('FAILED', 'mno-obj-chip-vpn');
    setCaptureRunning(false);
    return;
  }

  if (data && data.csrf) CSRF_HASH = data.csrf;

  if (!data || data.error) {
    term.className = 'terminal-output error';
    term.textContent = (data && (data.error || data.output)) || 'The probe request was refused.';
    captureStatus('REJECTED', 'mno-obj-chip-vpn');
    setCaptureRunning(false);
    return;
  }

  // The command line the daemon actually ran, then whatever it has produced so far.
  term.textContent = data.cmd ? `$ ${data.cmd}\n` : '';
  termAppend(data.output || '');

  if (!data.session) {
    // The dev mock has no session to poll — the whole run came back at once.
    if (data.mock) termAppend('\n💡 [MicronOS Development Prototyping Mode — simulated response]');
    term.className = 'terminal-output success';
    captureStatus('COMPLETED', 'mno-obj-chip-success');
    setCaptureRunning(false);
    return;
  }

  probeSession = data.session;
  probePollHandle = MicronScheduler.poll('diag.probe', { interval: 400, run: pollProbe });
}

async function pollProbe() {
  if (!probeSession) return true;   // idle is not an outage
  try {
    const res = await MicronScheduler.fetch(
      BASE_URL + 'diag/stream?session=' + encodeURIComponent(probeSession)
    );
    const data = await readJsonResponse(res);
    if (data && data.csrf) CSRF_HASH = data.csrf;

    if (!res.ok || (data && data.error)) {
      endProbe('STOPPED', 'mno-obj-chip');
      return true;
    }

    termAppend(data.output || '');
    if (!data.running) {
      document.getElementById('diagTerminal').className = 'terminal-output success';
      endProbe('COMPLETED', 'mno-obj-chip-success');
    }
    return true;
  } catch (err) {
    // `false` tells the shared scheduler this attempt failed, so it backs off
    // and raises the one connection banner rather than hammering a dead link.
    return false;
  }
}

async function stopProbe(quiet = false) {
  if (!probeSession) { setCaptureRunning(false); return; }

  const session = probeSession;
  endProbe('STOPPED', 'mno-obj-chip');

  const formData = new FormData();
  formData.append(CSRF_NAME, CSRF_HASH);
  formData.append('session', session);
  try {
    const res = await MicronScheduler.fetch(BASE_URL + 'diag/stream/stop', { method: 'POST', body: formData });
    const data = await readJsonResponse(res);
    if (data && data.csrf) CSRF_HASH = data.csrf;
    if (!quiet && data && data.output) termAppend(data.output);
  } catch (err) {
    // The session is already closed locally; the daemon reaps it either way.
  }
}

// The one stop button ends whichever stream is running.
document.getElementById('btnStopTerminal').addEventListener('click', () => {
  if (probeSession) { stopProbe(); } else { stopLiveCapture(); }
});

// ── Terminal Action Controls (Copy & Clear)
document.getElementById('btnCopyTerminal').addEventListener('click', () => {
  const content = document.getElementById('diagTerminal').textContent;
  navigator.clipboard.writeText(content).then(() => {
    const btnText = document.getElementById('copyBtnText');
    const old = btnText.textContent;
    btnText.textContent = 'Copied!';
    setTimeout(() => { btnText.textContent = old; }, 2000);
  });
});

document.getElementById('btnClearTerminal').addEventListener('click', () => {
  stopLiveCapture(true);   // a running capture is stopped before the console is wiped
  stopProbe(true);         // and so is a running probe
  const term = document.getElementById('diagTerminal');
  term.className = 'terminal-output';
  term.textContent = 'Console cleared. Select a utility to run a new probe.';
  document.getElementById('termStatusBadge').textContent = 'IDLE';
  document.getElementById('termStatusBadge').className = 'mno-obj-chip';
  document.getElementById('termLatencyBadge').style.display = 'none';
});

// ── Keyboard Enter Handlers
['pingHost', 'traceHost', 'dnsName', 'routeTarget', 'portHost', 'portNum', 'benchDomain', 'pcapFilter'].forEach(id => {
  const el = document.getElementById(id);
  if (el) {
    el.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const map = {
          pingHost: 'btnRunPing',
          traceHost: 'btnRunTrace',
          dnsName: 'btnRunDns',
          routeTarget: 'btnRunRoute',
          portHost: 'btnRunPort',
          portNum: 'btnRunPort',
          benchDomain: 'btnRunBench',
          pcapFilter: 'btnRunPcap',
        };
        document.getElementById(map[id])?.click();
      }
    });
  }
});
</script>

<?= $this->endSection() ?>
