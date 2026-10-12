<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.siem-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 12px;
  flex-wrap: wrap;
}
.siem-tab {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: rgba(255,255,255,0.02);
  border: 1px solid var(--color-border);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all var(--transition-fast);
}
.siem-tab:hover {
  color: var(--text-primary);
  background: rgba(255,255,255,0.06);
  border-color: var(--color-border-bright);
}
.siem-tab.active {
  background: var(--color-cyan-dim);
  color: var(--color-cyan);
  border-color: var(--color-cyan);
  font-weight: 700;
}
.siem-panel { display: none; }
.siem-panel.active { display: block; }

.format-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 14px 16px;
  background: rgba(255,255,255,0.02);
  cursor: pointer;
  transition: all var(--transition-fast);
  display: block;
}
.format-card:hover {
  background: rgba(255,255,255,0.05);
  border-color: var(--color-border-bright);
}
.format-card.selected {
  border-color: var(--color-cyan);
  background: var(--color-cyan-dim);
}
.format-radio { margin-right: 8px; accent-color: var(--color-cyan); }

.stat-widget {
  background: rgba(255,255,255,0.02);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.stat-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.log-terminal {
  background: #090d16;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: var(--radius-md);
  font-family: var(--font-mono, monospace);
  font-size: 0.78rem;
  padding: 14px;
  max-height: 480px;
  overflow-y: auto;
  color: #d1d5db;
  line-height: 1.55;
}
.stream-row {
  display: flex;
  gap: 12px;
  padding: 8px 10px;
  border-bottom: 1px solid rgba(255,255,255,0.04);
  align-items: center;
  transition: background 0.15s ease;
}
.stream-row:hover { background: rgba(255,255,255,0.03); }
</style>

<!-- Header -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>Diagnostics</span>
      <span class="sep">/</span>
      <span class="current">SIEM &amp; Syslog</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-cyan">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
      </div>
      SIEM Integration &amp; Remote Syslog Forwarding
    </h1>
    <p class="page-subtitle">
      Stream live firewall, IDS/IPS, VPN, and audit telemetry to Splunk, Microsoft Sentinel, IBM QRadar, Wazuh, and Elastic ELK.
    </p>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget('rsyslog') ?>
    <a href="<?= base_url('diag/logs') ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Live Firewall Logs
    </a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="switchSiemTab('stream', document.getElementById('tab-stream-btn'))">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
      </svg>
      Live Stream
    </button>
    <button type="button" class="btn btn-primary btn-sm" onclick="sendSiemTestEvent()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      Send Test SIEM Event
    </button>
  </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:20px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <div><?= session()->getFlashdata('success') ?></div>
  </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="tab-bar siem-tabs animate-in animate-in-delay-1">
  <button type="button" class="tab-item siem-tab active" id="tab-config-btn" onclick="switchSiemTab('config', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    SIEM Collector &amp; Transport
  </button>
  <button type="button" class="tab-item siem-tab" id="tab-stream-btn" onclick="switchSiemTab('stream', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
    </svg>
    Live SIEM Forwarding Stream &amp; Logs
    <span class="badge badge-success" style="font-size:0.65rem; padding:1px 6px;">Live Stream</span>
  </button>
</div>

<!-- ============================================================
     TAB 1: Configuration
============================================================ -->
<div class="siem-panel active" id="panel-config">
  <div class="card card-glass animate-in animate-in-delay-2" style="max-width:920px; margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <div class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        SIEM Collector &amp; Transport Settings
      </div>
      <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.75rem;">
        <?= !empty($settings['enabled']) ? 'FORWARDING ACTIVE' : 'FORWARDING PAUSED' ?>
      </span>
    </div>

    <div class="card-body">
      <form method="POST" action="<?= base_url('siem/settings') ?>">
        <?= csrf_field() ?>

        <!-- Enable Checkbox -->
        <div style="background:rgba(255,255,255,0.02); border:1px solid var(--color-border); padding:16px; border-radius:var(--radius-md); margin-bottom:20px;">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--text-primary); margin-bottom:4px;">
            <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
            Enable Remote SIEM Log Forwarding
          </label>
          <p style="font-size:0.75rem; color:var(--text-muted); margin:0;">
            Continuously streams Netfilter, Suricata, VPN, and system audit logs to your external Security Operations Center (SOC) collector.
          </p>
        </div>

        <!-- Server Host, Port, Protocol -->
        <div style="display:grid; grid-template-columns:3fr 1fr 1fr; gap:14px; margin-bottom:20px;">
          <div class="form-group" style="margin:0;">
            <label class="form-label">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
              </svg>
              SIEM Collector Host / FQDN
            </label>
            <input type="text" name="server_host" class="form-control" value="<?= esc($settings['server_host'] ?? '') ?>" required placeholder="e.g. splunk-hf.company.internal" style="font-family:var(--font-mono);">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
                <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
              </svg>
              Port
            </label>
            <input type="number" name="server_port" class="form-control" value="<?= esc($settings['server_port'] ?? 514) ?>" required style="font-family:var(--font-mono);">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Transport Protocol</label>
            <select name="protocol" id="transportProto" class="form-control" onchange="toggleTlsSection()">
              <option value="UDP" <?= ($settings['protocol'] ?? '') === 'UDP' ? 'selected' : '' ?>>UDP (Standard)</option>
              <option value="TCP" <?= ($settings['protocol'] ?? '') === 'TCP' ? 'selected' : '' ?>>TCP (Reliable)</option>
              <option value="TLS" <?= ($settings['protocol'] ?? '') === 'TLS' ? 'selected' : '' ?>>TLS (Encrypted mTLS)</option>
            </select>
          </div>
        </div>

        <!-- Format Selector -->
        <div class="form-group" style="margin-bottom:20px;">
          <label class="form-label" style="margin-bottom:8px;">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:4px; vertical-align:text-bottom;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
            </svg>
            SIEM Log Encoding &amp; Payload Schema
          </label>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:12px;">
            
            <label class="format-card <?= ($settings['log_format'] ?? 'CEF') === 'CEF' ? 'selected' : '' ?>">
              <input type="radio" name="log_format" value="CEF" class="format-radio" <?= ($settings['log_format'] ?? 'CEF') === 'CEF' ? 'checked' : '' ?> onchange="highlightFormat(this)">
              <strong style="font-size:0.85rem; color:var(--text-primary); display:block;">CEF (Common Event Format)</strong>
              <span style="font-size:0.72rem; color:var(--text-muted); display:block; margin-top:2px;">
                Optimized for Micro Focus ArcSight, Microsoft Sentinel, Rapid7 InsightIDR.
              </span>
            </label>

            <label class="format-card <?= ($settings['log_format'] ?? '') === 'JSON_ECS' ? 'selected' : '' ?>">
              <input type="radio" name="log_format" value="JSON_ECS" class="format-radio" <?= ($settings['log_format'] ?? '') === 'JSON_ECS' ? 'checked' : '' ?> onchange="highlightFormat(this)">
              <strong style="font-size:0.85rem; color:var(--color-cyan); display:block;">JSON / ECS (Elastic Schema)</strong>
              <span style="font-size:0.72rem; color:var(--text-muted); display:block; margin-top:2px;">
                Optimized for Splunk, Elastic SIEM / ELK, Wazuh, Graylog, Datadog.
              </span>
            </label>

            <label class="format-card <?= ($settings['log_format'] ?? '') === 'LEEF' ? 'selected' : '' ?>">
              <input type="radio" name="log_format" value="LEEF" class="format-radio" <?= ($settings['log_format'] ?? '') === 'LEEF' ? 'checked' : '' ?> onchange="highlightFormat(this)">
              <strong style="font-size:0.85rem; color:var(--text-primary); display:block;">LEEF (Log Extended Event)</strong>
              <span style="font-size:0.72rem; color:var(--text-muted); display:block; margin-top:2px;">
                Native format for IBM QRadar SIEM DSM auto-parsing.
              </span>
            </label>

            <label class="format-card <?= ($settings['log_format'] ?? '') === 'RFC5424' ? 'selected' : '' ?>">
              <input type="radio" name="log_format" value="RFC5424" class="format-radio" <?= ($settings['log_format'] ?? '') === 'RFC5424' ? 'checked' : '' ?> onchange="highlightFormat(this)">
              <strong style="font-size:0.85rem; color:var(--text-primary); display:block;">RFC 5424 Structured Syslog</strong>
              <span style="font-size:0.72rem; color:var(--text-muted); display:block; margin-top:2px;">
                IETF standard structured headers with microsecond timestamps and structured elements.
              </span>
            </label>

          </div>
        </div>

        <!-- TLS / mTLS Security Details (conditionally shown) -->
        <div id="tlsSection" style="display:<?= ($settings['protocol'] ?? '') === 'TLS' ? 'block' : 'none' ?>; background:rgba(6,182,212,0.03); border:1px solid rgba(6,182,212,0.2); border-radius:var(--radius-md); padding:16px; margin-bottom:20px;">
          <h4 style="margin:0 0 10px; font-size:0.9rem; color:var(--color-cyan); display:flex; align-items:center; gap:8px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            TLS / Mutual TLS (mTLS) Encryption Parameters (RFC 5425)
          </h4>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Client X.509 Certificate (for mTLS Auth)</label>
              <select name="client_cert_id" class="form-control">
                <option value="">None (Server Auth Only)</option>
                <?php foreach ($certificates as $cert): ?>
                  <option value="<?= esc($cert['id']) ?>" <?= ($settings['client_cert_id'] ?? '') == $cert['id'] ? 'selected' : '' ?>>
                    <?= esc($cert['name'] ?? $cert['descr'] ?? ('Cert #' . $cert['id'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin:0; display:flex; align-items:center;">
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:var(--text-secondary); margin-top:20px;">
                <input type="checkbox" name="tls_ca_verify" value="1" <?= !empty($settings['tls_ca_verify']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                Enforce Strict CA Validation on Remote Collector
              </label>
            </div>
          </div>
        </div>

        <!-- Subsystem Filtering -->
        <div style="border-top:1px solid var(--color-border); padding-top:18px; margin-bottom:20px;">
          <label class="form-label" style="margin-bottom:12px; font-weight:700;">Subsystems Forwarded to SIEM:</label>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:12px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-secondary);">
              <input type="checkbox" name="forward_firewall" value="1" <?= !empty($settings['forward_firewall']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
              Netfilter Firewall (Pass / Block / Reject)
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-secondary);">
              <input type="checkbox" name="forward_ids" value="1" <?= !empty($settings['forward_ids']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-rose);"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              Suricata IDS/IPS Threat Alerts
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-secondary);">
              <input type="checkbox" name="forward_vpn" value="1" <?= !empty($settings['forward_vpn']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              VPN Tunnels (WireGuard, OpenVPN, IPsec)
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-secondary);">
              <input type="checkbox" name="forward_proxy" value="1" <?= !empty($settings['forward_proxy']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
              Web Filter &amp; DNS Sinkhole Queries
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-secondary);">
              <input type="checkbox" name="forward_system" value="1" <?= !empty($settings['forward_system']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              System Auth, SSH &amp; WebUI Audit Log
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-secondary);">
              <input type="checkbox" name="forward_dhcp" value="1" <?= !empty($settings['forward_dhcp']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-blue);"><rect x="2" y="2" width="20" height="8" rx="2"/></svg>
              Kea DHCP Lease Allocations &amp; Renewals
            </label>
          </div>
        </div>

        <!-- Buffer & Flow Control -->
        <div style="border-top:1px solid var(--color-border); padding-top:18px; margin-bottom:20px;">
          <h4 style="margin:0 0 12px; font-size:0.9rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
            </svg>
            Reliability &amp; Disk Spool Buffering
          </h4>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Local Disk Spool Size (MB)</label>
              <input type="number" name="disk_spool_mb" class="form-control" value="<?= esc($settings['disk_spool_mb'] ?? 500) ?>" min="50" max="10000" style="font-family:var(--font-mono);">
              <div class="form-hint">Protects against packet loss during collector reboot or WAN link flaps.</div>
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Max Forwarding Rate Limit (EPS)</label>
              <input type="number" name="rate_limit_per_sec" class="form-control" value="<?= esc($settings['rate_limit_per_sec'] ?? 5000) ?>" min="100" max="50000" style="font-family:var(--font-mono);">
              <div class="form-hint">Events per second cap to prevent SIEM ingest license overages.</div>
            </div>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button type="submit" class="btn btn-primary">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            Save SIEM Settings
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     TAB 2: Live Stream & Telemetry Logs
============================================================ -->
<div class="siem-panel" id="panel-stream">
  
  <!-- Metric Gauges -->
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:16px; margin-bottom:20px;">
    
    <div class="stat-widget">
      <div class="stat-icon" style="background:rgba(6,182,212,0.15); color:var(--color-cyan);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
      </div>
      <div>
        <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Current Rate</div>
        <div id="statEps" style="font-size:1.3rem; font-weight:700; color:var(--color-cyan); font-family:var(--font-mono);">48 EPS</div>
      </div>
    </div>

    <div class="stat-widget">
      <div class="stat-icon" style="background:rgba(16,185,129,0.15); color:var(--color-emerald);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <div>
        <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Events Forwarded Today</div>
        <div id="statToday" style="font-size:1.3rem; font-weight:700; color:var(--text-primary); font-family:var(--font-mono);">184,290</div>
      </div>
    </div>

    <div class="stat-widget">
      <div class="stat-icon" style="background:rgba(168,85,247,0.15); color:var(--color-purple);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
        </svg>
      </div>
      <div>
        <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Spool Buffer Status</div>
        <div id="statSpool" style="font-size:1.3rem; font-weight:700; color:var(--color-purple); font-family:var(--font-mono);">24 MB / <?= esc($settings['disk_spool_mb'] ?? 500) ?> MB</div>
      </div>
    </div>

    <div class="stat-widget">
      <div class="stat-icon" style="background:rgba(34,197,94,0.15); color:#86efac;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Collector State</div>
        <div id="statConn" style="font-size:1.3rem; font-weight:700; color:#86efac; font-family:var(--font-mono);"><?= !empty($settings['enabled']) ? 'ESTABLISHED' : 'IDLE' ?></div>
      </div>
    </div>

  </div>

  <!-- Live Stream Table & Inspector -->
  <div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
        <span class="card-title">Real-Time Forwarded Event Stream</span>
        <span id="streamTargetBadge" class="badge badge-cyan" style="font-size:0.68rem; font-family:var(--font-mono);">
          <?= esc($settings['protocol'] ?? 'UDP') ?>://<?= esc($settings['server_host'] ?? '127.0.0.1') ?>:<?= esc($settings['server_port'] ?? 514) ?>
        </span>
      </div>
      <div style="display:flex; gap:8px;">
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleStreamAuto()" id="btnStreamAuto">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Auto-Stream: ON
        </button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="fetchSiemLogs()">
          Refresh
        </button>
      </div>
    </div>

    <div class="card-body" style="padding:0;">
      <div style="overflow-x:auto;">
        <table class="iface-table" style="margin:0;">
          <thead>
            <tr>
              <th style="width:140px;">Timestamp</th>
              <th>Subsystem</th>
              <th>Action</th>
              <th>Source &rarr; Destination</th>
              <th>Rule / Event Description</th>
              <th>Delivery Status</th>
              <th style="text-align:right;">Payload</th>
            </tr>
          </thead>
          <tbody id="siemStreamBody">
            <tr>
              <td colspan="7" style="text-align:center; padding:24px; color:var(--text-muted);">
                Connecting to SIEM log streamer...
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Modal: Raw Event Inspector -->
<div id="eventModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:680px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0; font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
        </svg>
        Raw SIEM Encoded Payload
      </h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeEventModal()">&times;</button>
    </div>
    <div style="margin-bottom:12px; font-size:0.75rem; color:var(--text-muted);" id="modalEventMeta"></div>
    <pre id="modalEventContent" class="log-terminal" style="max-height:320px; white-space:pre-wrap; word-break:break-all;"></pre>
    <div style="display:flex; justify-content:flex-end; margin-top:14px;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeEventModal()">Close</button>
    </div>
  </div>
</div>

<script>
let streamTimer = null;
let currentEvents = [];

function switchSiemTab(tabId, btn) {
  document.querySelectorAll('.siem-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.siem-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-' + tabId);
  if (panel) panel.classList.add('active');

  if (tabId === 'stream') {
    fetchSiemLogs();
    fetchSiemStats();
    startStreamAuto();
  } else {
    stopStreamAuto();
  }
}

function highlightFormat(radio) {
  document.querySelectorAll('.format-card').forEach(c => c.classList.remove('selected'));
  if (radio.checked) {
    radio.closest('.format-card').classList.add('selected');
  }
}

function toggleTlsSection() {
  const val = document.getElementById('transportProto').value;
  document.getElementById('tlsSection').style.display = (val === 'TLS') ? 'block' : 'none';
}

async function sendSiemTestEvent() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('siem/test-event') ?>', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
      }
    });
    const data = await res.json();
    alert(data.message || 'SIEM test event transmitted successfully.');
    if (document.getElementById('panel-stream').classList.contains('active')) {
      fetchSiemLogs();
      fetchSiemStats();
    }
  } catch (err) {
    alert('Failed to transmit test event.');
  }
}

async function fetchSiemLogs() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('siem/logs') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) return false;
    const data = await res.json();
    currentEvents = data.events || [];
    renderSiemEvents(currentEvents);
    if (data.target) {
      const badge = document.getElementById('streamTargetBadge');
      if (badge) badge.innerText = data.target + ' (' + data.format + ')';
    }
    return true;
  } catch (err) {
    console.error('Failed to fetch SIEM stream logs:', err);
    return false;   // reported to the shared scheduler, which backs the stream off
  }
}

async function fetchSiemStats() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('siem/stats') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) return false;
    const s = await res.json();
    if (document.getElementById('statEps')) document.getElementById('statEps').innerText = s.epsCurrent + ' EPS';
    if (document.getElementById('statToday')) document.getElementById('statToday').innerText = Number(s.eventsToday).toLocaleString();
    if (document.getElementById('statSpool')) document.getElementById('statSpool').innerText = s.spoolUsedMb + ' MB / ' + s.spoolTotalMb + ' MB';
    if (document.getElementById('statConn')) document.getElementById('statConn').innerText = s.connectionState;
    return true;
  } catch (err) { return false; }
}

// The SVG namespace, and the cell styles the event row builder uses.
const SVG_NS = 'http://www.w3.org/2000/svg';
const STYLE_TIME  = 'font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted);';
const STYLE_SUB   = 'font-weight:600; font-size:0.8rem; color:var(--text-primary);';
const STYLE_ROUTE = 'font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan);';
const STYLE_RULE  = 'font-size:0.78rem; color:var(--text-secondary);';
const STYLE_RIGHT = 'text-align:right;';
const STYLE_EMPTY = 'text-align:center; padding:24px; color:var(--text-muted);';

/**
 * The event table, declared once: one entry per column, in the order drawn.
 *
 * Every field here arrives over the wire — a subsystem, an action, a rule name,
 * a status — and each one reaches the table as a `text` column (or a function of
 * the row returning text) or as a `node` this page built. `MicronTable.render`
 * has no other kind of column, so an event whose action is named `<img src=x>`
 * renders those characters: the string builder these rows used to be could not
 * promise that, because it interpolated the action straight into its badge.
 */
const SIEM_COLUMNS = [
  { text: 'timestamp', style: STYLE_TIME },
  { text: 'subsystem', style: STYLE_SUB },
  { node: (ev) => actionBadge(ev.action) },
  { text: (ev) => (ev.src || '') + ' → ' + (ev.dst || ''), style: STYLE_ROUTE },
  { text: 'rule', style: STYLE_RULE },
  { node: (ev) => deliveryBadge(ev.status || 'DELIVERED') },
  { node: (ev) => inspectButton(ev), style: STYLE_RIGHT },
];

/**
 * Draws the event table: one row per event, or the one row that says the stream
 * is empty. The whole body is swapped at once, so a re-render cannot leave rows
 * behind.
 */
function renderSiemEvents(events) {
  MicronTable.render(document.getElementById('siemStreamBody'), events, {
    columns: SIEM_COLUMNS,
    empty: {
      colspan: 7,
      text: 'No SIEM events recorded yet. Click "Send Test SIEM Event" to verify shipping.',
      style: STYLE_EMPTY,
    },
  });
}

/** The action as the badge that colours it; its label is text. */
function actionBadge(action) {
  let cls = 'badge badge-cyan';
  if (action === 'PASS' || action === 'LOGIN') cls = 'badge badge-success';
  else if (action === 'ALERT' || action === 'DROP') cls = 'badge badge-error';
  else if (action === 'AUTH') cls = 'badge badge-purple';

  return MicronTable.badge(action || 'EVENT', cls, 'font-size:0.65rem;');
}

/** The delivery badge: the check icon, then the status as text beside it. */
function deliveryBadge(status) {
  return MicronTable.badge(status, 'badge badge-success', 'font-size:0.65rem; padding:1px 6px;', checkIcon());
}

/** The Inspect button for one row, closed over that row's own event. */
function inspectButton(ev) {
  return MicronTable.button('Inspect', {
    className: 'btn btn-ghost btn-sm',
    style: 'padding:2px 8px; font-size:0.7rem;',
    onClick: () => inspectPayload(ev),
  });
}

/** The delivery badge's check mark, node-built like the rest of the row. */
function checkIcon() {
  const svg = document.createElementNS(SVG_NS, 'svg');
  for (const [name, value] of Object.entries({
    width: '10', height: '10', fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor',
    'stroke-width': '2', style: 'display:inline; margin-right:2px;',
  })) {
    svg.setAttribute(name, value);
  }

  const path = document.createElementNS(SVG_NS, 'path');
  path.setAttribute('d', 'M5 13l4 4L19 7');
  svg.appendChild(path);

  return svg;
}

function inspectPayload(ev) {
  if (!ev) return;
  document.getElementById('modalEventMeta').innerText = ev.subsystem + ' | ' + ev.action + ' | ' + ev.timestamp;
  document.getElementById('modalEventContent').innerText = ev.raw || 'No raw payload available.';
  document.getElementById('eventModal').style.display = 'flex';
}

function closeEventModal() {
  document.getElementById('eventModal').style.display = 'none';
}

function toggleStreamAuto() {
  const btn = document.getElementById('btnStreamAuto');
  if (streamTimer) {
    stopStreamAuto();
    btn.innerHTML = 'Auto-Stream: OFF';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-ghost');
  } else {
    startStreamAuto();
    btn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Auto-Stream: ON';
    btn.classList.add('btn-primary');
    btn.classList.remove('btn-ghost');
  }
}

function startStreamAuto() {
  // One tick fetches the stream and the stats together; if either failed the
  // whole attempt counts as failed, so the shared scheduler backs the pair off
  // as one unit rather than retrying each on its own clock.
  if (streamTimer) streamTimer.stop();
  streamTimer = MicronScheduler.poll('siem.stream', {
    interval: 3500,
    run: () => Promise.all([fetchSiemLogs(), fetchSiemStats()])
      .then((results) => (results.indexOf(false) === -1 ? true : false))
  });
}

function stopStreamAuto() {
  if (streamTimer) {
    streamTimer.stop();
    streamTimer = null;
  }
}

</script>

<?= $this->endSection() ?>
