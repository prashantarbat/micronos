<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span>/</span>
      <span>Security</span>
      <span>/</span>
      <span class="current">Web Filter &amp; Proxy</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="page-title-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
        </svg>
      </div>
      <h1 class="page-title">Enterprise Web Proxy &amp; Content Filter</h1>
    </div>
    <p class="page-subtitle">Deep packet web inspection, category enforcement, ACL access controls, and automated WPAD/PAC discovery</p>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget('squid') ?>
    <a href="<?= base_url('webfilter/download-ca') ?>" class="btn btn-ghost btn-sm" title="Download Appliance Root CA Certificate for HTTPS inspection">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      Download Root CA
    </a>

    <?php if (session()->get('is_superadmin') || in_array('webfilter.reload', session()->get('permissions') ?? [])): ?>
      <form method="POST" action="<?= base_url('webfilter/reload') ?>" style="margin:0; display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-success btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Apply &amp; Reload Proxy
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($isMock ?? false): ?>
  <div class="alert alert-info animate-in" style="margin-bottom:20px; background:rgba(6,182,212,0.08); border:1px solid rgba(6,182,212,0.25); border-radius:var(--radius-md); padding:12px 16px; display:flex; align-items:center; gap:12px;">
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan); flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div style="font-size:0.83rem; color:var(--text-secondary);">
      Running in <strong style="color:var(--color-cyan);">Appliance Emulation Mode</strong>. Proxy worker processes, TPROXY socket intercepts, and cache purges are simulated via the internal IPC client daemon.
    </div>
  </div>
<?php endif; ?>

<!-- ── KPI Summary Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Proxy Engine Status</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.15rem; font-weight:700; color:var(--text-primary); text-transform:uppercase;">
        <?= !empty($settings['enabled']) ? 'Active & Enforcing' : 'Standby / Disabled' ?>
      </span>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px; font-family:var(--font-mono);">
      Mode: <?= esc(str_replace('_', ' ', strtoupper($settings['mode'] ?? 'transparent_sni'))) ?>
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Web Traffic Requests</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-cyan); margin-top:2px; font-family:var(--font-mono);"><?= number_format($status['requests_today'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">
      Requests filtered today &bull; <strong style="color:var(--color-emerald);"><?= esc(($status['cache_hit_rate'] ?? null) ?: '—') ?></strong> Cache Hit
    </div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-red);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Blocked Threats & Quotas</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-red); margin-top:2px; font-family:var(--font-mono);"><?= number_format($status['blocked_today'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Malware, forbidden categories & banned extensions</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-purple);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Auto-Discovery (WPAD / PAC)</div>
    <div style="display:flex; align-items:center; gap:6px; margin-top:4px;">
      <span class="badge <?= !empty($settings['wpad_enabled']) ? 'badge-success' : 'badge-secondary' ?>" style="font-size:0.75rem;">
        <?= !empty($settings['wpad_enabled']) ? 'Auto-Discovery Active' : 'Disabled' ?>
      </span>
      <?php if (!empty($settings['wpad_anti_bypass_firewall'])): ?>
        <span class="badge badge-purple" style="font-size:0.7rem;" title="Direct outbound port 80/443 egress locked down">Egress Locked</span>
      <?php endif; ?>
    </div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:4px; font-family:var(--font-mono);">
      Endpoint: /wpad.dat (Port <?= (int)($settings['wpad_proxy_port'] ?? 3128) ?>)
    </div>
  </div>
</div>

<!-- ── Navigation Tabs ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:20px; padding:6px 12px;">
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;" role="tablist">
    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'general' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-general" onclick="switchWfTab('general')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      General Proxy Settings
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'acls' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-acls" onclick="switchWfTab('acls')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
      Access Control Lists (ACLs)
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'filtering' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-filtering" onclick="switchWfTab('filtering')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
      </svg>
      Content Filtering & Categories
      <span class="badge badge-purple" style="font-size:0.65rem; padding:1px 6px;"><?= count($categories) ?></span>
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'ssl' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-ssl" onclick="switchWfTab('ssl')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
      SSL / TLS Deep Inspection
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'antivirus' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-antivirus" onclick="switchWfTab('antivirus')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
      </svg>
      Antivirus &amp; Malware (ICAP / ClamAV)
      <?php if (!empty($avStats['today'])): ?>
        <span class="badge badge-rose" style="font-size:0.65rem; padding:1px 6px;"><?= (int) $avStats['today'] ?></span>
      <?php endif; ?>
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'wpad' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-wpad" onclick="switchWfTab('wpad')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      Auto-Discovery & PAC Engine (WPAD)
      <span class="badge badge-cyan" style="font-size:0.65rem; padding:1px 6px;">Turnkey</span>
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'cache' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-cache" onclick="switchWfTab('cache')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
      </svg>
      Cache & Acceleration Engine
    </button>

    <button type="button" class="btn btn-sm wf-tab-btn <?= $activeTab === 'logs' ? 'btn-primary active' : 'btn-ghost' ?>" id="tabBtn-logs" onclick="switchWfTab('logs')">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
      </svg>
      Traffic Logs & Analytics
    </button>
  </div>
</div>

<!-- ===================================================================== -->
<!-- TAB 1: GENERAL PROXY SETTINGS                                         -->
<!-- ===================================================================== -->
<div id="tabContent-general" class="wf-tab-pane <?= $activeTab === 'general' ? 'active' : '' ?>">
  <form method="POST" action="<?= base_url('webfilter/save-general') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
          </svg>
          Core Web Proxy Engine & Interception Topology
        </span>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.85rem; color:var(--text-primary); margin:0;">
          <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?> style="width:18px; height:18px; accent-color:var(--color-emerald);">
          Enable Web Proxy Engine
        </label>
      </div>

      <div style="padding:20px;">
        <div style="margin-bottom:20px;">
          <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Proxy Interception Topology</label>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
            <label style="display:block; cursor:pointer;">
              <input type="radio" name="mode" value="explicit" <?= ($settings['mode'] ?? '') === 'explicit' ? 'checked' : '' ?> style="display:none;" onchange="updateModeHighlight(this)">
              <div class="mode-card <?= ($settings['mode'] ?? '') === 'explicit' ? 'selected' : '' ?>" style="border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px; background:var(--color-surface-2); transition:all 0.2s;">
                <div style="font-weight:700; color:var(--text-primary); font-size:0.85rem; margin-bottom:4px;">Explicit Proxy Mode</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">Clients configure proxy port explicitly or auto-detect via WPAD. No firewall packet interception required.</div>
              </div>
            </label>

            <label style="display:block; cursor:pointer;">
              <input type="radio" name="mode" value="transparent_sni" <?= ($settings['mode'] ?? 'transparent_sni') === 'transparent_sni' ? 'checked' : '' ?> style="display:none;" onchange="updateModeHighlight(this)">
              <div class="mode-card <?= ($settings['mode'] ?? 'transparent_sni') === 'transparent_sni' ? 'selected' : '' ?>" style="border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px; background:var(--color-surface-2); transition:all 0.2s;">
                <div style="font-weight:700; color:var(--text-primary); font-size:0.85rem; margin-bottom:4px;">Transparent SNI Interception (Recommended)</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">Zero client configuration. Filters HTTP & inspects TLS SNI headers without requiring internal CA certificate installation on devices.</div>
              </div>
            </label>

            <label style="display:block; cursor:pointer;">
              <input type="radio" name="mode" value="hybrid" <?= ($settings['mode'] ?? '') === 'hybrid' ? 'checked' : '' ?> style="display:none;" onchange="updateModeHighlight(this)">
              <div class="mode-card <?= ($settings['mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>" style="border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px; background:var(--color-surface-2); transition:all 0.2s;">
                <div style="font-weight:700; color:var(--text-primary); font-size:0.85rem; margin-bottom:4px;">Hybrid Dual Mode</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4;">Simultaneously operates explicit port listening, WPAD auto-discovery, and transparent TPROXY packet interception.</div>
              </div>
            </label>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Standard HTTP Proxy Port</label>
            <input type="number" name="http_port" value="<?= (int)($settings['http_port'] ?? 3128) ?>" class="form-control" min="1" max="65535" required style="font-family:var(--font-mono);">
            <small style="font-size:0.7rem; color:var(--text-muted);">Default standard proxy port (3128)</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Transparent / TPROXY Port</label>
            <input type="number" name="https_port" value="<?= (int)($settings['https_port'] ?? 3129) ?>" class="form-control" min="1" max="65535" required style="font-family:var(--font-mono);">
            <small style="font-size:0.7rem; color:var(--text-muted);">Internal port for wire-intercepted packets</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">SSL Interception Port</label>
            <input type="number" name="ssl_port" value="<?= (int)($settings['ssl_port'] ?? 3130) ?>" class="form-control" min="1" max="65535" required style="font-family:var(--font-mono);">
            <small style="font-size:0.7rem; color:var(--text-muted);">Dynamic SSL-Bump termination port</small>
          </div>
        </div>

        <!-- Listening Interfaces -->
        <div style="margin-bottom:20px;">
          <label class="form-label" style="font-size:0.8rem; font-weight:600;">Monitored Listening Interfaces</label>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:6px;">
            <?php 
              $configuredIfaces = is_array($settings['interfaces'] ?? null) ? $settings['interfaces'] : ['lan'];
              foreach ($availableIfaces as $iface):
            ?>
              <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; background:var(--color-surface-2); padding:8px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-subtle); font-size:0.8rem;">
                <input type="checkbox" name="interfaces[]" value="<?= esc($iface['id']) ?>" <?= in_array($iface['id'], $configuredIfaces) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                <span style="font-weight:600; color:var(--text-primary);"><?= esc($iface['label']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Visible Hostname</label>
            <input type="text" name="visible_hostname" value="<?= esc($settings['visible_hostname'] ?? 'micronos-proxy') ?>" class="form-control" placeholder="gateway.internal.net">
            <small style="font-size:0.7rem; color:var(--text-muted);">Name reported in HTTP Via headers and error screens</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Administrator Contact Email</label>
            <input type="email" name="admin_email" value="<?= esc($settings['admin_email'] ?? 'admin@micronos.local') ?>" class="form-control" placeholder="netadmin@company.com">
            <small style="font-size:0.7rem; color:var(--text-muted);">Contact shown on access restriction block pages</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Forwarded-For (X-Forwarded-For) Header</label>
            <select name="forwarded_for" class="form-control">
              <option value="on" <?= ($settings['forwarded_for'] ?? '') === 'on' ? 'selected' : '' ?>>On (Standard client IP append)</option>
              <option value="transparent" <?= ($settings['forwarded_for'] ?? '') === 'transparent' ? 'selected' : '' ?>>Transparent (Preserve inbound header)</option>
              <option value="truncate" <?= ($settings['forwarded_for'] ?? '') === 'truncate' ? 'selected' : '' ?>>Truncate (Replace entirely with client IP)</option>
              <option value="delete" <?= ($settings['forwarded_for'] ?? '') === 'delete' ? 'selected' : '' ?>>Delete (Strip X-Forwarded-For header)</option>
              <option value="off" <?= ($settings['forwarded_for'] ?? '') === 'off' ? 'selected' : '' ?>>Off (Anonymize)</option>
            </select>
          </div>
        </div>

        <!-- Privacy & Headers -->
        <div style="padding:16px; background:var(--color-surface-2); border-radius:var(--radius-md); border:1px solid var(--border-subtle); margin-bottom:20px;">
          <div style="font-weight:700; color:var(--text-primary); font-size:0.82rem; margin-bottom:10px;">Privacy & Client Anonymization Controls</div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-secondary);">
              <input type="checkbox" name="suppress_headers" value="1" <?= !empty($settings['suppress_headers']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <span>Suppress Proxy Headers (Strip <code style="color:var(--color-cyan);">Via</code>, <code style="color:var(--color-cyan);">From</code>, &amp; client headers)</span>
            </label>

            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-secondary);">
              <input type="checkbox" name="ipv6_enabled" value="1" <?= !empty($settings['ipv6_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <span>Enable IPv6 Proxy Listeners &amp; Dual-Stack Resolution</span>
            </label>
          </div>
        </div>

        <!-- Logging Controls -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-secondary);">
            <input type="checkbox" name="log_client_ip" value="1" <?= !empty($settings['log_client_ip']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
            <span>Log Client Source IP in Access Stream</span>
          </label>

          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-secondary);">
            <input type="checkbox" name="log_user_agent" value="1" <?= !empty($settings['log_user_agent']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
            <span>Capture Client User-Agent Strings</span>
          </label>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Access Log Retention (Days)</label>
            <input type="number" name="log_retention_days" value="<?= (int)($settings['log_retention_days'] ?? 30) ?>" class="form-control" min="1" max="365" style="max-width:180px;">
          </div>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save General Configuration
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ===================================================================== -->
<!-- TAB 2: ACCESS CONTROL LISTS (ACLS)                                    -->
<!-- ===================================================================== -->
<div id="tabContent-acls" class="wf-tab-pane <?= $activeTab === 'acls' ? 'active' : '' ?>">
  <form method="POST" action="<?= base_url('webfilter/save-acls') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Client Access Control Lists &amp; Restrictions
        </span>
      </div>

      <div style="padding:20px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:20px;">
          <!-- Allowed Subnets -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Allowed Client Subnets (localnet)</span>
              <span class="badge badge-success" style="font-size:0.65rem;">Permitted</span>
            </label>
            <textarea name="allowed_subnets" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;"><?= esc($settings['allowed_subnets'] ?? "192.168.0.0/16\n10.0.0.0/8\n172.16.0.0/12") ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">One CIDR subnet per line allowed to connect through the proxy</small>
          </div>

          <!-- Unrestricted / Privileged IPs -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Unrestricted Privileged Client IPs</span>
              <span class="badge badge-cyan" style="font-size:0.65rem;">Bypass All Filters</span>
            </label>
            <textarea name="unrestricted_ips" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="192.168.1.50&#10;192.168.1.51"><?= esc($settings['unrestricted_ips'] ?? '') ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">One IP or CIDR per line that completely bypasses category filtering and quotas</small>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:20px;">
          <!-- Banned Client IPs -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Banned Client IPs &amp; Subnets</span>
              <span class="badge badge-danger" style="font-size:0.65rem;">Drop / 403</span>
            </label>
            <textarea name="banned_ips" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="192.168.1.240&#10;10.50.0.0/24"><?= esc($settings['banned_ips'] ?? '') ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">Client IPs immediately rejected with HTTP 403 Access Denied</small>
          </div>

          <!-- Banned User-Agents -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Banned User-Agent Signatures</span>
              <span class="badge badge-danger" style="font-size:0.65rem;">Block Crawlers/Bots</span>
            </label>
            <textarea name="banned_user_agents" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="curl*&#10;wget*&#10;*BitTorrent*"><?= esc($settings['banned_user_agents'] ?? "curl*\nwget*\n*BitTorrent*\n*libwww-perl*\n*python-requests*") ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">Wildcard patterns matching unauthorized scripts or download managers</small>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:20px;">
          <!-- Banned File Extensions -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Banned File Extensions</span>
              <span class="badge badge-danger" style="font-size:0.65rem;">Stop Executables</span>
            </label>
            <textarea name="banned_extensions" rows="4" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder=".exe&#10;.msi&#10;.bat&#10;.iso"><?= esc($settings['banned_extensions'] ?? ".exe\n.msi\n.bat\n.iso\n.torrent\n.scr\n.vbs") ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">File extensions blocked from downloading via HTTP/HTTPS</small>
          </div>

          <!-- Banned MIME Types -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Banned MIME Types</span>
              <span class="badge badge-danger" style="font-size:0.65rem;">Payload Block</span>
            </label>
            <textarea name="banned_mime_types" rows="4" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="application/x-bittorrent&#10;application/x-dosexec"><?= esc($settings['banned_mime_types'] ?? "application/x-bittorrent\napplication/x-dosexec\napplication/x-executable") ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">MIME types blocked by header content-type inspection</small>
          </div>
        </div>

        <!-- Transfer Quotas & Limits -->
        <div style="padding:16px; background:var(--color-surface-2); border-radius:var(--radius-md); border:1px solid var(--border-subtle);">
          <div style="font-weight:700; color:var(--text-primary); font-size:0.82rem; margin-bottom:12px;">Data Transfer Limits &amp; Connection Safeguards</div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Max Upload Request Size (MB)</label>
              <input type="number" name="max_upload_size_mb" value="<?= (int)($settings['max_upload_size_mb'] ?? 50) ?>" class="form-control" min="1" max="10240">
              <small style="font-size:0.7rem; color:var(--text-muted);">Limits maximum HTTP POST body size</small>
            </div>

            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Max Download Object Size (MB)</label>
              <input type="number" name="max_download_size_mb" value="<?= (int)($settings['max_download_size_mb'] ?? 2048) ?>" class="form-control" min="1" max="102400">
              <small style="font-size:0.7rem; color:var(--text-muted);">Prevents individual large media from saturating WAN</small>
            </div>

            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Max Connections per Client IP</label>
              <input type="number" name="max_client_connections" value="<?= (int)($settings['max_client_connections'] ?? 150) ?>" class="form-control" min="10" max="2000">
              <small style="font-size:0.7rem; color:var(--text-muted);">Thwarts connection exhaustion and DoS</small>
            </div>
          </div>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save Access Control Lists
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ===================================================================== -->
<!-- TAB 3: CONTENT FILTERING & CATEGORIES                                 -->
<!-- ===================================================================== -->
<div id="tabContent-filtering" class="wf-tab-pane <?= $activeTab === 'filtering' ? 'active' : '' ?>">
  <!-- Categories Matrix -->
  <div class="card card-glass animate-in" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        URL Category Taxonomy &amp; Policy Matrix
      </span>
      <form method="POST" action="<?= base_url('webfilter/feeds/sync') ?>" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Sync Threat Feeds Now
        </button>
      </form>
    </div>

    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Category Classification</th>
            <th>Enforcement Policy Action</th>
            <th>Known Domain Signatures</th>
            <th>Description &amp; Threat Scope</th>
            <th style="text-align:right;">Quick Toggle</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
            <tr>
              <td>
                <strong style="color:var(--text-primary); font-size:0.85rem;"><?= esc($cat['name']) ?></strong>
              </td>
              <td>
                <?php if ($cat['action'] === 'block'): ?>
                  <span class="badge badge-danger">BLOCK / DROP</span>
                <?php elseif ($cat['action'] === 'monitor'): ?>
                  <span class="badge badge-cyan">MONITOR ONLY</span>
                <?php else: ?>
                  <span class="badge badge-success">ALLOW</span>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--color-cyan);">
                <?= number_format($cat['domain_count'] ?: 15420) ?> domains
              </td>
              <td style="font-size:0.75rem; color:var(--text-secondary); max-width:320px;">
                <?= esc($cat['description']) ?>
              </td>
              <td style="text-align:right;">
                <form method="POST" action="<?= base_url('webfilter/category/' . esc($cat['id']) . '/toggle') ?>" style="margin:0; display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Cycle between Block, Allow, and Monitor">
                    Cycle Action &rarr;
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Domain Whitelist, Blacklist & SafeSearch Form -->
  <form method="POST" action="<?= base_url('webfilter/save-filtering') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">Custom Domain Whitelist, Blacklist &amp; SafeSearch</span>
      </div>

      <div style="padding:20px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:20px;">
          <!-- Custom Whitelist -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Custom Domain Whitelist (Passlist)</span>
              <span class="badge badge-success" style="font-size:0.65rem;">Always Allow</span>
            </label>
            <textarea name="custom_whitelist" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="github.com&#10;*.microsoft.com&#10;intranet.local"><?= esc($settings['custom_whitelist'] ?? '') ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">One domain per line. Wildcards supported (*.example.com). Overrides category blocks.</small>
          </div>

          <!-- Custom Blacklist -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
              <span>Custom Domain Blacklist (Drop Rules)</span>
              <span class="badge badge-danger" style="font-size:0.65rem;">Always Block</span>
            </label>
            <textarea name="custom_blacklist" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="*.tiktok.com&#10;bad-site.org&#10;*.tracker.xyz"><?= esc($settings['custom_blacklist'] ?? '') ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">One domain per line. Drops all access and shows restriction interstitial.</small>
          </div>
        </div>

        <div style="margin-bottom:20px;">
          <label class="form-label" style="font-size:0.8rem; font-weight:600;">Custom URL Path &amp; Regex Blacklist</label>
          <textarea name="custom_regex_blacklist" rows="3" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="^https?://.*(\.onion|\.ru/badware).*"><?= esc($settings['custom_regex_blacklist'] ?? '') ?></textarea>
          <small style="font-size:0.7rem; color:var(--text-muted);">Regular expressions matched against full request URIs (e.g. ^https?://.*badword.*)</small>
        </div>

        <!-- SafeSearch & YouTube Restrictions -->
        <div style="padding:16px; background:var(--color-surface-2); border-radius:var(--radius-md); border:1px solid var(--border-subtle); margin-bottom:20px;">
          <div style="font-weight:700; color:var(--text-primary); font-size:0.82rem; margin-bottom:12px;">Search Engine &amp; Media Protection</div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
            <div>
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-primary); font-weight:600; margin-top:8px;">
                <input type="checkbox" name="safesearch_enabled" value="1" <?= !empty($settings['safesearch_enabled']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                <span>Enforce SafeSearch (Google, Bing, DuckDuckGo)</span>
              </label>
              <small style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:4px;">Automatically injects SafeSearch query directives to prevent explicit search results.</small>
            </div>

            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">YouTube Restricted Mode</label>
              <select name="youtube_restricted_mode" class="form-control">
                <option value="disabled" <?= ($settings['youtube_restricted_mode'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled (Unrestricted)</option>
                <option value="moderate" <?= ($settings['youtube_restricted_mode'] ?? 'moderate') === 'moderate' ? 'selected' : '' ?>>Moderate Restriction</option>
                <option value="strict" <?= ($settings['youtube_restricted_mode'] ?? '') === 'strict' ? 'selected' : '' ?>>Strict Restriction</option>
              </select>
              <small style="font-size:0.7rem; color:var(--text-muted);">Injects YouTube-Restrict headers into video requests</small>
            </div>
          </div>
        </div>

        <!-- Block Page Customizer -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Custom Block Page Banner Title</label>
            <input type="text" name="block_page_title" value="<?= esc($settings['block_page_title'] ?? 'Access Restricted by Corporate Policy') ?>" class="form-control">
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Custom Redirect URL (Optional)</label>
            <input type="url" name="block_page_redirect_url" value="<?= esc($settings['block_page_redirect_url'] ?? '') ?>" class="form-control" placeholder="https://portal.company.com/blocked">
          </div>

          <div style="grid-column: 1 / -1;">
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Custom Block Notification Message</label>
            <textarea name="block_page_message" rows="2" class="form-control"><?= esc($settings['block_page_message'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save Content Filtering Rules
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ===================================================================== -->
<!-- TAB 4: SSL / TLS DEEP INSPECTION                                      -->
<!-- ===================================================================== -->
<div id="tabContent-ssl" class="wf-tab-pane <?= $activeTab === 'ssl' ? 'active' : '' ?>">
  <form method="POST" action="<?= base_url('webfilter/save-ssl') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          SSL / TLS Deep Inspection &amp; Interception
        </span>
        <a href="<?= base_url('webfilter/download-ca') ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
          Download CA Certificate (.crt)
        </a>
        <button type="submit" formaction="<?= base_url('webfilter/provision-ca') ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px;" title="Generate the interception CA in the PKI store and install it at the configured Squid paths">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
          </svg>
          Generate / Provision Interception CA
        </button>
      </div>

      <div style="padding:20px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">SSL Inspection Strategy</label>
            <select name="ssl_inspection_mode" class="form-control">
              <option value="disabled" <?= ($settings['ssl_inspection_mode'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled (Direct TCP tunnel passthrough)</option>
              <option value="transparent_sni" <?= ($settings['ssl_inspection_mode'] ?? 'transparent_sni') === 'transparent_sni' ? 'selected' : '' ?>>SNI Peek &amp; Splice (No Certificate Needed on Clients)</option>
              <option value="deep_inspection" <?= ($settings['ssl_inspection_mode'] ?? '') === 'deep_inspection' ? 'selected' : '' ?>>Deep Packet SSL-Bump (Full Decryption / Needs CA on Clients)</option>
            </select>
            <small style="font-size:0.7rem; color:var(--text-muted);">SNI Peek inspects domain certificates without decrypting user payloads.</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Minimum Supported TLS Protocol</label>
            <select name="ssl_min_tls_version" class="form-control">
              <option value="1.2" <?= ($settings['ssl_min_tls_version'] ?? '1.2') === '1.2' ? 'selected' : '' ?>>TLS 1.2 (Standard compatibility)</option>
              <option value="1.3" <?= ($settings['ssl_min_tls_version'] ?? '') === '1.3' ? 'selected' : '' ?>>TLS 1.3 (Modern high-security only)</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Appliance CA Certificate Path</label>
            <input type="text" name="ca_cert_path" value="<?= esc($settings['ca_cert_path'] ?? '/etc/squid/certs/micron_ca.pem') ?>" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;">
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Appliance CA Private Key Path</label>
            <input type="text" name="ca_key_path" value="<?= esc($settings['ca_key_path'] ?? '/etc/squid/certs/micron_ca.key') ?>" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;">
          </div>
        </div>

        <!-- SSL Bypass Domains -->
        <div style="margin-bottom:20px;">
          <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
            <span>SSL Decryption Bypass Domains (Splice Passthrough)</span>
            <span class="badge badge-purple" style="font-size:0.65rem;">Never Decrypt</span>
          </label>
          <textarea name="ssl_bypass_domains" rows="6" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="*.paypal.com&#10;*.chase.com&#10;*.apple.com"><?= esc($settings['ssl_bypass_domains'] ?? '') ?></textarea>
          <small style="font-size:0.7rem; color:var(--text-muted);">Domains that must NEVER be decrypted to comply with financial, medical, and banking privacy regulations or avoid breaking certificate-pinned apps.</small>
        </div>

        <div style="padding:14px 16px; background:rgba(6,182,212,0.06); border:1px solid rgba(6,182,212,0.2); border-radius:var(--radius-md); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
          <div>
            <strong style="color:var(--text-primary); font-size:0.82rem;">Need to install the Root Certificate on client workstations?</strong>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:2px 0 0 0;">Download the Appliance Root CA and deploy via Active Directory GPO, Microsoft Intune, Jamf Pro, or manual installation.</p>
          </div>
          <a href="<?= base_url('webfilter/download-ca') ?>" class="btn btn-cyan btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download micronos-proxy-ca.crt
          </a>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save SSL Inspection Settings
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ===================================================================== -->
<!-- TAB: ANTIVIRUS & MALWARE INSPECTION (C-ICAP + ClamAV)                 -->
<!-- ===================================================================== -->
<div id="tabContent-antivirus" class="wf-tab-pane <?= $activeTab === 'antivirus' ? 'active' : '' ?>">

  <!-- Status, definitions & daemon controls -->
  <div class="card card-glass animate-in" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-rose);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 4v5c0 4.418-2.91 8.24-7 9-4.09-.76-7-4.582-7-9V7l7-4z"/>
        </svg>
        ICAP Antivirus Bridge &amp; Scanner Status
      </span>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <?= \App\Libraries\ServiceStatus::widget(['clamav', 'c-icap']) ?>
        <form method="POST" action="<?= base_url('webfilter/av/update-signatures') ?>" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-cyan btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Update Signatures Now
          </button>
        </form>
      </div>
    </div>

    <div style="padding:20px; display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:16px;">
      <?php
        $statTiles = [
          ['C-ICAP Bridge',   !empty($avStatus['icap_running']) ? 'Running' : 'Stopped', !empty($avStatus['icap_running']) ? 'var(--color-emerald)' : 'var(--color-rose)'],
          ['Scanner Engine',  (string) ($avStatus['engine'] ?? 'Unknown'), 'var(--text-primary)'],
          ['Signatures',      number_format((int) ($avStatus['signatures'] ?? 0)), 'var(--color-cyan)'],
          ['Definitions Rev', (string) ($avStatus['defs_version'] ?? '—'), 'var(--text-primary)'],
          ['Last Update',     !empty($avStatus['last_update']) ? (string) $avStatus['last_update'] : 'never', 'var(--text-secondary)'],
          ['Payloads Scanned', number_format((int) ($avStatus['requests_scanned'] ?? 0)), 'var(--text-primary)'],
          ['Threats Blocked', number_format((int) ($avStatus['threats_blocked'] ?? 0)), 'var(--color-rose)'],
        ];
      ?>
      <?php foreach ($statTiles as $tile): ?>
        <div style="padding:14px; background:var(--color-surface-2); border:1px solid var(--border-subtle); border-radius:var(--radius-md);">
          <div style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); margin-bottom:6px;"><?= esc($tile[0]) ?></div>
          <div style="font-size:1rem; font-weight:700; color:<?= $tile[2] ?>; font-family:var(--font-mono);"><?= esc((string) $tile[1]) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($avStatus['last_update']) || !empty($settings['clamav_last_update_status'])): ?>
      <div style="padding:0 20px 18px; font-size:0.75rem; color:var(--text-muted);">
        Last definitions sync: <?= esc((string) ($settings['clamav_last_update_status'] ?? '')) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Inspection configuration -->
  <form method="POST" action="<?= base_url('webfilter/save-antivirus') ?>">
    <?= csrf_field() ?>
    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">Streaming Payload Inspection (ICAP RFC 3507)</span>
      </div>

      <div style="padding:20px;">
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:20px; padding:14px 16px; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-md);">
          <input type="checkbox" name="icap_antivirus_enabled" value="1" <?= !empty($settings['icap_antivirus_enabled']) ? 'checked' : '' ?>>
          <span>
            <strong style="font-size:0.85rem; color:var(--text-primary);">Enable C-ICAP + ClamAV payload scanning</strong>
            <div style="font-size:0.73rem; color:var(--text-secondary); margin-top:2px;">Routes proxied HTTP bodies through the C-ICAP bridge to the multi-threaded ClamAV scanner. Disable to bypass all inspection.</div>
          </span>
        </label>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">ICAP Server Host</label>
            <input type="text" name="icap_host" value="<?= esc($settings['icap_host'] ?? '127.0.0.1') ?>" class="form-control" style="font-family:var(--font-mono); font-size:0.82rem;">
          </div>
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">ICAP Port</label>
            <input type="number" name="icap_port" min="1" max="65535" value="<?= (int) ($settings['icap_port'] ?? 1344) ?>" class="form-control">
          </div>
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">ICAP Service Name</label>
            <input type="text" name="icap_service" value="<?= esc($settings['icap_service'] ?? 'squidclamav') ?>" class="form-control" style="font-family:var(--font-mono); font-size:0.82rem;">
          </div>
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Inspection Mode</label>
            <select name="icap_scan_mode" class="form-control">
              <option value="request"  <?= ($settings['icap_scan_mode'] ?? '') === 'request' ? 'selected' : '' ?>>Request (reqmod — uploads)</option>
              <option value="response" <?= ($settings['icap_scan_mode'] ?? 'response') === 'response' ? 'selected' : '' ?>>Response (respmod — downloads)</option>
              <option value="both"     <?= ($settings['icap_scan_mode'] ?? '') === 'both' ? 'selected' : '' ?>>Both directions</option>
            </select>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Preview Size (KB)</label>
            <input type="number" name="icap_preview_kb" min="1" value="<?= (int) ($settings['icap_preview_kb'] ?? 1024) ?>" class="form-control">
          </div>
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Maximum Scanned File (MB)</label>
            <input type="number" name="icap_max_file_mb" min="1" value="<?= (int) ($settings['icap_max_file_mb'] ?? 25) ?>" class="form-control">
          </div>
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">If the Scanner is Unreachable</label>
            <select name="icap_failure_mode" class="form-control">
              <option value="allow" <?= ($settings['icap_failure_mode'] ?? 'allow') === 'allow' ? 'selected' : '' ?>>Fail open — pass traffic unscanned</option>
              <option value="block" <?= ($settings['icap_failure_mode'] ?? '') === 'block' ? 'selected' : '' ?>>Fail closed — block all traffic</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">ClamAV Update Schedule</label>
            <select name="clamav_update_schedule" class="form-control">
              <option value="hourly" <?= ($settings['clamav_update_schedule'] ?? '') === 'hourly' ? 'selected' : '' ?>>Hourly</option>
              <option value="daily"  <?= ($settings['clamav_update_schedule'] ?? 'daily') === 'daily' ? 'selected' : '' ?>>Daily</option>
              <option value="weekly" <?= ($settings['clamav_update_schedule'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly</option>
            </select>
          </div>
        </div>

        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-bottom:20px; font-size:0.8125rem;">
          <input type="checkbox" name="icap_scan_https" value="1" <?= !empty($settings['icap_scan_https']) ? 'checked' : '' ?>>
          <span>Scan decrypted HTTPS bodies (requires SSL-Bump deep inspection above)</span>
        </label>

        <div>
          <label class="form-label" style="font-size:0.8rem; font-weight:600; display:flex; justify-content:space-between;">
            <span>Scan Bypass Domains (never hand payloads to the scanner)</span>
            <span class="badge badge-purple" style="font-size:0.65rem;">Exempt</span>
          </label>
          <textarea name="icap_bypass_domains" rows="5" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="*.windowsupdate.com&#10;*.apple.com"><?= esc($settings['icap_bypass_domains'] ?? '') ?></textarea>
          <small style="font-size:0.7rem; color:var(--text-muted);">Bypass domains skip scanning entirely (skips whitelisted update/CDN hosts that break under inspection).</small>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Save Antivirus Settings
        </button>
      </div>
    </div>
  </form>

  <!-- Detection log -->
  <div class="card card-glass animate-in">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <span class="card-title">
        Malware Detections
        <span class="badge badge-rose" style="font-size:0.65rem; margin-left:6px;"><?= (int) ($avStats['total'] ?? 0) ?> total</span>
        <span class="badge badge-muted" style="font-size:0.65rem;"><?= (int) ($avStats['today'] ?? 0) ?> today</span>
      </span>
      <form method="POST" action="<?= base_url('webfilter/av/clear-detections') ?>" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-2 0V4a1 1 0 00-1-1h-2a1 1 0 00-1 1v3m-3 0h12"/></svg>
          Clear Log
        </button>
      </form>
    </div>

    <?php if (empty($avDetections)): ?>
      <div style="padding:28px; text-align:center; color:var(--text-muted); font-size:0.82rem;">
        No malware has been detected — the C-ICAP bridge is scanning clean traffic.
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="table" style="width:100%; font-size:0.78rem;">
          <thead>
            <tr>
              <th>Detected</th><th>Client</th><th>Domain</th><th>Threat</th><th>Action</th><th>Size</th><th>SHA-256</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($avDetections as $d): ?>
              <?php $kb = (int) ($d['file_size'] ?? 0) / 1024; ?>
              <tr>
                <td style="font-family:var(--font-mono); white-space:nowrap;"><?= esc((string) ($d['detected_at'] ?? '')) ?></td>
                <td style="font-family:var(--font-mono);"><?= esc((string) ($d['client_ip'] ?? '')) ?></td>
                <td><?= esc((string) ($d['domain'] ?? '')) ?></td>
                <td><span class="badge badge-rose"><?= esc((string) ($d['threat_name'] ?? 'unknown')) ?></span></td>
                <td><span class="badge <?= ($d['action'] ?? '') === 'alert' ? 'badge-amber' : 'badge-rose' ?>"><?= esc(strtoupper((string) ($d['action'] ?? 'block'))) ?></span></td>
                <td style="font-family:var(--font-mono);"><?= esc(number_format($kb, 1)) ?> KB</td>
                <td style="font-family:var(--font-mono); color:var(--text-muted);"><?= esc(substr((string) ($d['sha256'] ?? '—'), 0, 16)) ?>…</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===================================================================== -->
<!-- TAB 5: WPAD & PAC AUTO-DISCOVERY ENGINE (Nguvu Simplified)            -->
<!-- ===================================================================== -->
<div id="tabContent-wpad" class="wf-tab-pane <?= $activeTab === 'wpad' ? 'active' : '' ?>">
  <!-- Executive WPAD Overview & Live Endpoints -->
  <div class="card card-glass animate-in" style="margin-bottom:20px; border-left:4px solid var(--color-cyan);">
    <div style="padding:18px 20px;">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
        <div>
          <span class="badge badge-cyan" style="font-size:0.7rem; margin-bottom:4px;">Nguvu PAC Architecture &bull; Fully Automated</span>
          <h2 style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin:2px 0 4px 0;">Turnkey Web Proxy Auto-Discovery (WPAD / PAC)</h2>
          <p style="font-size:0.78rem; color:var(--text-secondary); margin:0; max-width:740px;">
            Eliminates complex manual web server configs, MIME type definitions, and raw JavaScript coding. MicronOS automatically hosts standard WPAD endpoints, serves compliant proxy autoconfig scripts, coordinates DHCP Option 252, and enforces anti-leak egress lockout.
          </p>
        </div>
        <div style="display:flex; gap:8px;">
          <a href="<?= base_url('webfilter/download-pac') ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download proxy.pac
          </a>
        </div>
      </div>

      <!-- Live Endpoints Strip -->
      <div style="background:var(--color-surface-2); border-radius:var(--radius-md); padding:12px 16px; border:1px solid var(--border-subtle); display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
          <div>
            <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600;">Standard WPAD Endpoint</div>
            <code style="font-size:0.78rem; color:var(--color-cyan);"><?= base_url('wpad.dat') ?></code>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="copyToClipboard('<?= base_url('wpad.dat') ?>', this)" style="padding:4px 8px; font-size:0.7rem;">Copy</button>
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
          <div>
            <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600;">macOS / Safari Discovery</div>
            <code style="font-size:0.78rem; color:var(--color-cyan);"><?= base_url('wpad.da') ?></code>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="copyToClipboard('<?= base_url('wpad.da') ?>', this)" style="padding:4px 8px; font-size:0.7rem;">Copy</button>
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
          <div>
            <div style="font-size:0.7rem; color:var(--text-muted); font-weight:600;">Firefox / Direct PAC URL</div>
            <code style="font-size:0.78rem; color:var(--color-cyan);"><?= base_url('proxy.pac') ?></code>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="copyToClipboard('<?= base_url('proxy.pac') ?>', this)" style="padding:4px 8px; font-size:0.7rem;">Copy</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Interactive PAC Rule Simulator & Tester -->
  <div class="card card-glass animate-in" style="margin-bottom:20px;">
    <div class="card-header">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
        </svg>
        Interactive WPAD / PAC Simulator &amp; Validator
      </span>
    </div>

    <div style="padding:20px;">
      <p style="font-size:0.78rem; color:var(--text-secondary); margin-top:0; margin-bottom:12px;">
        Test how client web browsers evaluate your PAC rules when accessing any URL or internal hostname.
      </p>

      <form method="POST" action="<?= base_url('webfilter/wpad/test') ?>" id="pacTestForm" onsubmit="runPacSimulation(event)">
        <?= csrf_field() ?>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <input type="text" name="test_url" id="pacTestInput" class="form-control" placeholder="e.g. http://intranet.local/payroll or https://www.google.com" style="flex:1; min-width:280px; font-family:var(--font-mono); font-size:0.82rem;" value="<?= esc(session()->getFlashdata('sim_url') ?? '') ?>" required>
          <button type="submit" class="btn btn-cyan" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Simulate Evaluation
          </button>
        </div>
      </form>

      <!-- Simulation Result Box -->
      <div id="simResultBox" style="margin-top:16px; display:<?= session()->getFlashdata('sim_rule') ? 'block' : 'none' ?>; background:var(--color-surface-2); border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px 18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
          <div style="font-size:0.8rem; font-weight:700; color:var(--text-primary);" id="simRuleTitle">
            <?= esc(session()->getFlashdata('sim_rule') ?? '') ?>
          </div>
          <span class="badge" id="simDecisionBadge" style="font-size:0.75rem; font-family:var(--font-mono);">
            <?= esc(session()->getFlashdata('sim_decision') ?? '') ?>
          </span>
        </div>
        <div style="font-size:0.78rem; color:var(--text-secondary);" id="simDescription">
          <?= esc(session()->getFlashdata('sim_desc') ?? '') ?>
        </div>
      </div>
    </div>
  </div>

  <!-- WPAD Settings & Rule Builder Form -->
  <form method="POST" action="<?= base_url('webfilter/save-wpad') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">PAC Script Logic &amp; Proxy Server Topology</span>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.85rem; color:var(--text-primary); margin:0;">
          <input type="checkbox" name="wpad_enabled" value="1" <?= !empty($settings['wpad_enabled']) ? 'checked' : '' ?> style="width:18px; height:18px; accent-color:var(--color-cyan);">
          Serve WPAD Endpoints
        </label>
      </div>

      <div style="padding:20px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Primary Proxy Host / IP</label>
            <input type="text" name="wpad_proxy_host" value="<?= esc($settings['wpad_proxy_host'] ?? '192.168.1.1') ?>" class="form-control" style="font-family:var(--font-mono);" placeholder="192.168.1.1">
            <small style="font-size:0.7rem; color:var(--text-muted);">Gateway LAN IP or proxy FQDN returned in PROXY directive</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Proxy Listening Port</label>
            <input type="number" name="wpad_proxy_port" value="<?= (int)($settings['wpad_proxy_port'] ?? 3128) ?>" class="form-control" min="1" max="65535" style="font-family:var(--font-mono);">
            <small style="font-size:0.7rem; color:var(--text-muted);">Port for explicit proxy requests (default: 3128)</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Failover Secondary Proxy (Optional)</label>
            <input type="text" name="wpad_failover_proxy" value="<?= esc($settings['wpad_failover_proxy'] ?? '') ?>" class="form-control" style="font-family:var(--font-mono);" placeholder="192.168.1.2:3128">
            <small style="font-size:0.7rem; color:var(--text-muted);">High-availability secondary proxy failover target</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Fallback Behavior (When Proxy Unreachable)</label>
            <select name="wpad_fallback_policy" class="form-control">
              <option value="DIRECT" <?= ($settings['wpad_fallback_policy'] ?? 'DIRECT') === 'DIRECT' ? 'selected' : '' ?>>Fail Open (Fall back to DIRECT connection)</option>
              <option value="FAIL_SECURE" <?= ($settings['wpad_fallback_policy'] ?? '') === 'FAIL_SECURE' ? 'selected' : '' ?>>Fail Secure (Deny all connection if proxy down)</option>
            </select>
          </div>
        </div>

        <!-- Built-in Direct Bypass Toggles -->
        <div style="padding:16px; background:var(--color-surface-2); border-radius:var(--radius-md); border:1px solid var(--border-subtle); margin-bottom:20px;">
          <div style="font-weight:700; color:var(--text-primary); font-size:0.82rem; margin-bottom:10px;">Automated Direct Connection (Bypass) Rules</div>
          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-primary);">
              <input type="checkbox" name="wpad_bypass_plain_hosts" value="1" <?= !empty($settings['wpad_bypass_plain_hosts']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <span>Bypass Plain Single-Label Hostnames (<code style="color:var(--color-cyan);">isPlainHostName</code>)</span>
            </label>

            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-primary);">
              <input type="checkbox" name="wpad_bypass_rfc1918" value="1" <?= !empty($settings['wpad_bypass_rfc1918']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              <span>Bypass RFC 1918 Private Subnets (<code style="color:var(--color-cyan);">10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16</code>)</span>
            </label>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:20px;">
          <!-- Custom Subnet Bypasses -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Custom Subnet Direct Bypasses (CIDRs)</label>
            <textarea name="wpad_bypass_networks" rows="4" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="10.0.0.0/8&#10;192.168.0.0/16"><?= esc($settings['wpad_bypass_networks'] ?? "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16\n127.0.0.1/32\n169.254.0.0/16") ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">One subnet per line. Resolves destination and bypasses proxy directly.</small>
          </div>

          <!-- Custom Domain Bypasses -->
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Custom Domain &amp; FQDN Direct Bypasses</label>
            <textarea name="wpad_bypass_domains" rows="4" class="form-control" style="font-family:var(--font-mono); font-size:0.8rem;" placeholder="*.local&#10;*.corp&#10;*.internal&#10;*.company.com"><?= esc($settings['wpad_bypass_domains'] ?? "*.local\n*.corp\n*.internal\n*.lan\n*.micronos.test") ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">One domain or wildcard per line to route directly without proxying.</small>
          </div>
        </div>

        <!-- 1-Click Integration Helpers (DHCP Option 252, DNS WPAD, Anti-Bypass Firewall) -->
        <div style="background:var(--color-surface-2); border-radius:var(--radius-md); padding:18px 20px; border:1px solid var(--border-subtle); margin-bottom:20px;">
          <div style="font-weight:700; color:var(--text-primary); font-size:0.85rem; margin-bottom:4px; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            Automated Infrastructure Services &amp; Anti-Bypass Enforcements
          </div>
          <p style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px; margin-bottom:16px;">
            These 1-click toggles automate the steps from the Nguvu guide: DHCP Option 252 distribution, DNS overrides, and strict firewall lockout.
          </p>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
            <!-- DHCP Option 252 -->
            <div style="padding:14px; background:var(--color-surface-1); border-radius:var(--radius-sm); border:1px solid var(--border-subtle);">
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.82rem; color:var(--text-primary); margin-bottom:6px;">
                <input type="checkbox" name="wpad_dhcp_option252" value="1" <?= !empty($settings['wpad_dhcp_option252']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                <span>DHCP Option 252 Auto-Publisher</span>
              </label>
              <p style="font-size:0.72rem; color:var(--text-secondary); margin:0 0 8px 0;">
                Automatically injects Option 252 into MicronOS internal Kea DHCP daemon scopes.
              </p>
              <div style="font-size:0.7rem; color:var(--text-muted); font-family:var(--font-mono); background:var(--color-surface-2); padding:4px 8px; border-radius:var(--radius-xs);">
                Option 252: "<?= base_url('wpad.dat') ?>"
              </div>
            </div>

            <!-- DNS WPAD Override -->
            <div style="padding:14px; background:var(--color-surface-1); border-radius:var(--radius-sm); border:1px solid var(--border-subtle);">
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.82rem; color:var(--text-primary); margin-bottom:6px;">
                <input type="checkbox" name="wpad_dns_override" value="1" <?= !empty($settings['wpad_dns_override']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
                <span>DNS WPAD Auto-Resolver Override</span>
              </label>
              <p style="font-size:0.72rem; color:var(--text-secondary); margin:0 0 8px 0;">
                Automatically publishes <code style="color:var(--color-cyan);">wpad</code> and <code style="color:var(--color-cyan);">wpad.domain</code> A-records in Unbound DNS pointing to the gateway IP.
              </p>
              <div style="font-size:0.7rem; color:var(--text-muted); font-family:var(--font-mono); background:var(--color-surface-2); padding:4px 8px; border-radius:var(--radius-xs);">
                wpad.<?= esc($settings['visible_hostname'] ?? 'micronos') ?> &rarr; <?= esc($settings['wpad_proxy_host'] ?? '192.168.1.1') ?>
              </div>
            </div>

            <!-- Anti-Bypass Firewall Lockout -->
            <div style="padding:14px; background:var(--color-surface-1); border-radius:var(--radius-sm); border:1px solid var(--border-subtle);">
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.82rem; color:var(--text-primary); margin-bottom:6px;">
                <input type="checkbox" name="wpad_anti_bypass_firewall" value="1" <?= !empty($settings['wpad_anti_bypass_firewall']) ? 'checked' : '' ?> style="accent-color:var(--color-purple);">
                <span style="color:var(--color-purple);">Anti-Bypass Egress Lockout (Strict)</span>
              </label>
              <p style="font-size:0.72rem; color:var(--text-secondary); margin:0 0 8px 0;">
                As detailed in Nguvu's guide, drops direct outbound TCP 80/443 on internal LAN interfaces to prevent clients or malware from bypassing the proxy.
              </p>
              <div style="font-size:0.7rem; color:var(--color-red); font-family:var(--font-mono); background:var(--color-surface-2); padding:4px 8px; border-radius:var(--radius-xs);">
                Drop: LAN &rarr; WAN: TCP 80, 443
              </div>
            </div>
          </div>
        </div>

        <!-- Compiled PAC Script Live Preview -->
        <div style="margin-bottom:10px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <label class="form-label" style="font-size:0.8rem; font-weight:600; margin:0;">Compiled PAC Script Code Preview</label>
            <button type="button" class="btn btn-ghost btn-sm" onclick="copyToClipboard(document.getElementById('pacCodeBlock').innerText, this)" style="padding:3px 8px; font-size:0.7rem;">
              Copy Script
            </button>
          </div>
          <pre id="pacCodeBlock" style="background:#0b0f19; color:#e2e8f0; font-family:var(--font-mono); font-size:0.75rem; padding:14px; border-radius:var(--radius-md); border:1px solid var(--border-subtle); overflow-x:auto; max-height:220px;"><?= esc($pacScript) ?></pre>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save WPAD &amp; PAC Configuration
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ===================================================================== -->
<!-- TAB 6: CACHE & ACCELERATION ENGINE                                    -->
<!-- ===================================================================== -->
<div id="tabContent-cache" class="wf-tab-pane <?= $activeTab === 'cache' ? 'active' : '' ?>">
  <form method="POST" action="<?= base_url('webfilter/save-cache') ?>">
    <?= csrf_field() ?>

    <div class="card card-glass animate-in" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title" style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
          </svg>
          Proxy Cache Acceleration &amp; Storage Management
        </span>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.85rem; color:var(--text-primary); margin:0;">
          <input type="checkbox" name="cache_enabled" value="1" <?= !empty($settings['cache_enabled']) ? 'checked' : '' ?> style="width:18px; height:18px; accent-color:var(--color-cyan);">
          Enable Web Cache Acceleration
        </label>
      </div>

      <div style="padding:20px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:20px;">
          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">RAM Memory Cache Size (MB)</label>
            <select name="cache_mem_mb" class="form-control">
              <option value="64" <?= (int)($settings['cache_mem_mb'] ?? 256) === 64 ? 'selected' : '' ?>>64 MB (Low memory appliance)</option>
              <option value="128" <?= (int)($settings['cache_mem_mb'] ?? 256) === 128 ? 'selected' : '' ?>>128 MB</option>
              <option value="256" <?= (int)($settings['cache_mem_mb'] ?? 256) === 256 ? 'selected' : '' ?>>256 MB (Recommended standard)</option>
              <option value="512" <?= (int)($settings['cache_mem_mb'] ?? 256) === 512 ? 'selected' : '' ?>>512 MB (High performance)</option>
              <option value="1024" <?= (int)($settings['cache_mem_mb'] ?? 256) === 1024 ? 'selected' : '' ?>>1024 MB (Enterprise heavy)</option>
              <option value="2048" <?= (int)($settings['cache_mem_mb'] ?? 256) === 2048 ? 'selected' : '' ?>>2048 MB</option>
            </select>
            <small style="font-size:0.7rem; color:var(--text-muted);">In-memory hot object buffer size</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Hard Disk Cache Storage (MB)</label>
            <input type="number" name="cache_size_mb" value="<?= (int)($settings['cache_size_mb'] ?? 4096) ?>" class="form-control" min="256" max="1048576" style="font-family:var(--font-mono);">
            <small style="font-size:0.7rem; color:var(--text-muted);">Spool disk directory limit (e.g. 4096 MB = 4 GB)</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Maximum Cached Object Size (KB)</label>
            <input type="number" name="cache_max_obj_kb" value="<?= (int)($settings['cache_max_obj_kb'] ?? 4096) ?>" class="form-control" min="4" max="1048576" style="font-family:var(--font-mono);">
            <small style="font-size:0.7rem; color:var(--text-muted);">Maximum size of individual web asset to cache</small>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Cache Replacement Policy</label>
            <select name="cache_replacement_policy" class="form-control">
              <option value="heap LFUDA" <?= ($settings['cache_replacement_policy'] ?? '') === 'heap LFUDA' ? 'selected' : '' ?>>heap LFUDA (Least Frequently Used with Dynamic Aging)</option>
              <option value="heap GDSF" <?= ($settings['cache_replacement_policy'] ?? '') === 'heap GDSF' ? 'selected' : '' ?>>heap GDSF (Greedy-Dual Size Frequency)</option>
              <option value="LRU" <?= ($settings['cache_replacement_policy'] ?? '') === 'LRU' ? 'selected' : '' ?>>LRU (Standard Least Recently Used)</option>
            </select>
          </div>
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem; color:var(--text-primary); font-weight:600;">
            <input type="checkbox" name="cache_ignore_queries" value="1" <?= !empty($settings['cache_ignore_queries']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
            <span>Do Not Cache Dynamic Query Strings (<code style="color:var(--color-cyan);">?</code>, <code style="color:var(--color-cyan);">cgi-bin</code>)</span>
          </label>
        </div>

        <!-- Freshness / refresh_pattern -->
        <div style="margin-bottom:20px; padding-top:18px; border-top:1px dashed var(--border-subtle);">
          <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <div>
              <div style="font-weight:700; color:var(--text-primary); font-size:0.85rem;">Freshness &amp; Cache Refresh Patterns</div>
              <small style="font-size:0.7rem; color:var(--text-muted);">Squid applies the first matching rule &mdash; specific rules are written before the catch-all default.</small>
            </div>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:0.8rem; color:var(--text-primary); margin:0;">
              <input type="checkbox" name="cache_refresh_enabled" value="1" <?= !empty($settings['cache_refresh_enabled']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--color-cyan);">
              Emit refresh_pattern Rules
            </label>
          </div>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Minimum Freshness (minutes)</label>
              <input type="number" name="cache_refresh_min_minutes" value="<?= (int)($settings['cache_refresh_min_minutes'] ?? 0) ?>" class="form-control" min="0" max="525600" style="font-family:var(--font-mono);">
              <small style="font-size:0.7rem; color:var(--text-muted);">Objects younger than this are always considered fresh</small>
            </div>

            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Freshness Percentage (%)</label>
              <input type="number" name="cache_refresh_percent" value="<?= (int)($settings['cache_refresh_percent'] ?? 20) ?>" class="form-control" min="0" max="100" style="font-family:var(--font-mono);">
              <small style="font-size:0.7rem; color:var(--text-muted);">Fraction of the object's age treated as fresh</small>
            </div>

            <div>
              <label class="form-label" style="font-size:0.8rem; font-weight:600;">Maximum Freshness (minutes)</label>
              <input type="number" name="cache_refresh_max_minutes" value="<?= (int)($settings['cache_refresh_max_minutes'] ?? 4320) ?>" class="form-control" min="0" max="525600" style="font-family:var(--font-mono);">
              <small style="font-size:0.7rem; color:var(--text-muted);">Objects older than this are always revalidated (4320 = 3 days)</small>
            </div>
          </div>

          <div style="display:flex; flex-wrap:wrap; gap:18px; margin-bottom:16px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; color:var(--text-primary); font-weight:600; margin:0;">
              <input type="checkbox" name="cache_refresh_ignore_reload" value="1" <?= !empty($settings['cache_refresh_ignore_reload']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              ignore-reload
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; color:var(--text-primary); font-weight:600; margin:0;">
              <input type="checkbox" name="cache_refresh_ignore_no_store" value="1" <?= !empty($settings['cache_refresh_ignore_no_store']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              ignore-no-store
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; color:var(--text-primary); font-weight:600; margin:0;">
              <input type="checkbox" name="cache_refresh_ignore_private" value="1" <?= !empty($settings['cache_refresh_ignore_private']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              ignore-private
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.78rem; color:var(--text-primary); font-weight:600; margin:0;">
              <input type="checkbox" name="cache_refresh_reload_into_ims" value="1" <?= !empty($settings['cache_refresh_reload_into_ims']) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
              reload-into-ims
            </label>
          </div>

          <div>
            <label class="form-label" style="font-size:0.8rem; font-weight:600;">Additional refresh_pattern Rules (one per line)</label>
            <textarea name="cache_refresh_custom" class="form-control" rows="4" spellcheck="false" style="font-family:var(--font-mono); font-size:0.75rem;" placeholder="refresh_pattern ^ftp: 1440 20% 10080"><?= esc($settings['cache_refresh_custom'] ?? '') ?></textarea>
            <small style="font-size:0.7rem; color:var(--text-muted);">Only lines beginning with <code style="color:var(--color-cyan);">refresh_pattern</code> are written to squid.conf. Listed first, so they match before the catch-all above.</small>
          </div>

          <div style="margin-top:16px; padding:12px 14px; border:1px solid var(--border-subtle); border-radius:var(--radius-md); background:var(--color-surface-2);">
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; margin:0;">
              <input type="checkbox" name="cache_update_preset_enabled" value="1" <?= !empty($settings['cache_update_preset_enabled']) ? 'checked' : '' ?> style="width:16px; height:16px; margin-top:1px; accent-color:var(--color-cyan);">
              <span>
                <span style="font-weight:700; color:var(--text-primary); font-size:0.82rem;">Cache OS &amp; Software Update Downloads</span>
                <span style="display:block; font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Emit curated <code style="color:var(--color-cyan);">refresh_pattern</code> rules for Windows Update, Apple Software Update and common Linux mirrors &mdash; large update files stay cached instead of being re-downloaded for every client. Independently of the catch-all rules above.</span>
              </span>
            </label>
          </div>
        </div>

        <!-- Do Not Cache destinations -->
        <div style="margin-bottom:20px; padding-top:18px; border-top:1px dashed var(--border-subtle);">
          <label class="form-label" style="font-size:0.8rem; font-weight:600;">Do Not Cache</label>
          <textarea name="cache_do_not_cache" class="form-control" rows="5" spellcheck="false" style="font-family:var(--font-mono); font-size:0.75rem;" placeholder="ads.example.com&#10;192.168.1.50"><?= esc($settings['cache_do_not_cache'] ?? '') ?></textarea>
          <small style="font-size:0.7rem; color:var(--text-muted);">Enter domain(s) and/or IP address(es) that should never be cached &mdash; one entry per line. Domains also cover their subdomains; IPv4, IPv6 and CIDR blocks are accepted.</small>
        </div>

        <!-- Telemetry & Purge Action -->
        <div style="padding:16px; background:var(--color-surface-2); border-radius:var(--radius-md); border:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
          <div>
            <div style="font-weight:700; color:var(--text-primary); font-size:0.82rem;">Cache Storage Health &amp; Telemetry</div>
            <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
              RAM Usage: <strong style="color:var(--color-cyan);"><?= esc(($status['mem_usage'] ?? null) ?: '—') ?></strong> &bull;
              Disk Spool: <strong style="color:var(--color-cyan);"><?= esc(($status['disk_usage'] ?? null) ?: '—') ?></strong> &bull;
              Hit Efficiency: <strong style="color:var(--color-emerald);"><?= esc(($status['cache_hit_rate'] ?? null) ?: '—') ?></strong>
            </div>
          </div>
          <form method="POST" action="<?= base_url('webfilter/cache/clear') ?>" onsubmit="return confirm('Are you sure you want to flush and purge the proxy cache directory?');" style="margin:0;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); border-color:rgba(239,68,68,0.3); display:inline-flex; align-items:center; gap:6px;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              Purge Cache Storage Now
            </button>
          </form>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; padding:12px 20px; background:var(--color-surface-2); border-top:1px solid var(--border-subtle);">
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save Cache Parameters
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ===================================================================== -->
<!-- TAB 7: TRAFFIC LOGS & ANALYTICS                                       -->
<!-- ===================================================================== -->
<div id="tabContent-logs" class="wf-tab-pane <?= $activeTab === 'logs' ? 'active' : '' ?>">
  <style>
    .wflog-pill { display:inline-flex; align-items:center; gap:4px; font-family:var(--font-mono); font-size:0.7rem; font-weight:700; padding:2px 7px; border-radius:4px; text-transform:uppercase; }
    .wflog-allow  { background: rgba(34,197,94,0.15);  color:#86efac; border:1px solid rgba(34,197,94,0.3); }
    .wflog-block  { background: rgba(239,68,68,0.15);  color:#fca5a5; border:1px solid rgba(239,68,68,0.3); }
    .wflog-bypass { background: rgba(245,158,11,0.15); color:#fcd34d; border:1px solid rgba(245,158,11,0.3); }
    .wflog-live { display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--color-emerald); box-shadow:0 0 8px var(--color-emerald); animation: wfPulse 1.5s infinite; }
    @keyframes wfPulse { 0%{transform:scale(0.95); box-shadow:0 0 0 0 rgba(34,197,94,0.7);} 70%{transform:scale(1.1); box-shadow:0 0 0 6px rgba(34,197,94,0);} 100%{transform:scale(0.95); box-shadow:0 0 0 0 rgba(34,197,94,0);} }
  </style>

  <!-- Real Time accessing stream (live Squid access-log tail) -->
  <div class="card card-glass animate-in" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
        Real Time Proxy Access Log
      </span>
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <span style="display:flex; align-items:center; gap:6px; font-size:0.72rem; font-weight:600; color:var(--text-secondary);">
          <span class="wflog-live" id="wfLiveDot"></span>
          <span id="wfLiveStatus">LIVE STREAMING</span>
        </span>
        <input type="text" id="wfLiveSearch" class="form-control" placeholder="Filter URL, domain or client..." onkeyup="wfFilterLive()" style="max-width:240px; font-size:0.8rem;">
        <button type="button" class="btn btn-secondary btn-sm" id="wfLiveToggle" onclick="wfToggleLive()">Pause Stream</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="wfClearLive()">Clear Display</button>
      </div>
    </div>
    <div style="padding:8px 20px; background:var(--color-surface-2); border-bottom:1px solid var(--border-subtle); font-size:0.72rem; color:var(--text-muted);" id="wfLiveMeta">Connecting to the Squid access log&hellip;</div>
    <div style="overflow-x:auto; max-height:420px; overflow-y:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Client</th>
            <th>Action</th>
            <th>Result</th>
            <th>Method</th>
            <th>Domain / URL</th>
            <th style="text-align:right;">Bytes</th>
          </tr>
        </thead>
        <tbody id="wfLiveBody">
          <tr><td colspan="7" style="text-align:center; padding:28px; color:var(--text-muted);">Waiting for live traffic&hellip;</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <script>
  let wfLiveBuffer = [];
  let wfLiveStreaming = true;
  let wfLiveTimer = null;
  const WF_LIVE_MAX = 150;

  async function wfFetchLive() {
    if (!wfLiveStreaming) return;
    const pane = document.getElementById('tabContent-logs');
    if (!pane || !pane.classList.contains('active')) return; // only poll while the tab is visible

    try {
      const res = await MicronScheduler.fetch('<?= base_url('webfilter/logs/data') ?>?limit=40');
      if (!res.ok) return false;
      const data = await res.json();
      const entries = data.entries || [];
      const meta = document.getElementById('wfLiveMeta');
      if (!data.available) {
        meta.textContent = 'Live stream unavailable \u2014 micrond / Squid is not reachable.';
        return;
      }

      meta.textContent = entries.length + ' entries in the latest poll \u00b7 newest first \u00b7 refresh every 2.5s';

      const seen = new Set(wfLiveBuffer.map(e => e.timestamp + '|' + e.client + '|' + e.url));
      const fresh = entries.filter(e => !seen.has(e.timestamp + '|' + e.client + '|' + e.url));
      if (fresh.length) {
        wfLiveBuffer = [...fresh, ...wfLiveBuffer].slice(0, WF_LIVE_MAX);
        wfFilterLive();
      }
    } catch (err) {
      console.error('WebFilter live log fetch failed:', err);
      return false;   // the shared scheduler backs the poll off and shows the banner
    }
  }

  function wfFilterLive() {
    const q = document.getElementById('wfLiveSearch').value.toLowerCase().trim();
    const list = q
      ? wfLiveBuffer.filter(e => `${e.client} ${e.domain} ${e.url} ${e.method} ${e.action}`.toLowerCase().includes(q))
      : wfLiveBuffer;
    wfRenderLive(list);
  }

  function wfRenderLive(list) {
    const tbody = document.getElementById('wfLiveBody');
    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:28px; color:var(--text-muted);">' +
        (wfLiveStreaming ? 'Waiting for live traffic&hellip;' : 'Stream paused.') + '</td></tr>';
      return;
    }

    let html = '';
    list.forEach(e => {
      const action = (e.action || 'ALLOW').toUpperCase();
      const cls = action === 'BLOCK' ? 'wflog-block' : (action === 'BYPASS_SSL' ? 'wflog-bypass' : 'wflog-allow');
      const domain = e.domain || (e.url || '').replace(/^https?:\/\//, '').split('/')[0];
      html += '<tr>' +
        '<td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted); white-space:nowrap;">' + (e.time || '') + '</td>' +
        '<td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">' + (e.client || '') + '</td>' +
        '<td><span class="wflog-pill ' + cls + '">' + action + '</span></td>' +
        '<td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted);">' + (e.result || '') + '</td>' +
        '<td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan);">' + (e.method || '') + '</td>' +
        '<td style="font-size:0.75rem; color:var(--text-primary); max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + (e.url || '') + '">' + domain + '</td>' +
        '<td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-secondary); text-align:right;">' + Number(e.bytes || 0).toLocaleString() + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
  }

  function wfToggleLive() {
    wfLiveStreaming = !wfLiveStreaming;
    const btn = document.getElementById('wfLiveToggle');
    const dot = document.getElementById('wfLiveDot');
    const text = document.getElementById('wfLiveStatus');

    if (wfLiveStreaming) {
      btn.textContent = 'Pause Stream';
      btn.className = 'btn btn-secondary btn-sm';
      dot.style.display = 'inline-block';
      text.textContent = 'LIVE STREAMING';
      wfFetchLive();
    } else {
      btn.textContent = 'Resume Stream';
      btn.className = 'btn btn-primary btn-sm';
      dot.style.display = 'none';
      text.textContent = 'STREAM PAUSED';
    }
  }

  function wfClearLive() {
    wfLiveBuffer = [];
    wfRenderLive([]);
  }

  document.addEventListener('DOMContentLoaded', () => {
    // The shared scheduler runs the first tick at once, pauses while the tab is
    // hidden, and backs the poll off while the appliance is unreachable.
    wfLiveTimer = MicronScheduler.poll('webfilter.live', { interval: 2500, run: wfFetchLive });
  });
  </script>

  <div class="card card-glass animate-in">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        Recorded Web Proxy Access Log &amp; Analytics
      </span>

      <div style="display:flex; gap:8px; align-items:center;">
        <form method="POST" action="<?= base_url('webfilter/logs/clear') ?>" onsubmit="return confirm('Clear all recorded access logs?');" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red);">
            Clear Logs
          </button>
        </form>
      </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding:14px 20px; background:var(--color-surface-2); border-bottom:1px solid var(--border-subtle);">
      <form method="GET" action="<?= base_url('webfilter') ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0;">
        <input type="hidden" name="tab" value="logs">
        <input type="text" name="log_search" value="<?= esc($logSearch ?? '') ?>" class="form-control" placeholder="Search Client IP, domain, URL or User-Agent..." style="max-width:320px; font-size:0.8rem;">

        <select name="log_action" class="form-control" style="max-width:180px; font-size:0.8rem;">
          <option value="all" <?= ($logAction ?? '') === 'all' ? 'selected' : '' ?>>All Actions</option>
          <option value="ALLOW" <?= ($logAction ?? '') === 'ALLOW' ? 'selected' : '' ?>>ALLOW Only</option>
          <option value="BLOCK" <?= ($logAction ?? '') === 'BLOCK' ? 'selected' : '' ?>>BLOCK Only</option>
          <option value="BYPASS_SSL" <?= ($logAction ?? '') === 'BYPASS_SSL' ? 'selected' : '' ?>>BYPASS_SSL Only</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if (!empty($logSearch) || !empty($logAction)): ?>
          <a href="<?= base_url('webfilter?tab=logs') ?>" class="btn btn-ghost btn-sm">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Logs Table -->
    <div style="overflow-x:auto;">
      <table class="iface-table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Client Source</th>
            <th>Method</th>
            <th>Requested Domain / URL</th>
            <th>Status / Cache</th>
            <th>Action</th>
            <th>Category</th>
            <th style="text-align:right;">Quick Whitelist / Blacklist</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($accessLogs)): ?>
            <tr>
              <td colspan="8" style="text-align:center; padding:30px; color:var(--text-muted);">
                No web proxy access events found matching your filter criteria.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($accessLogs as $log): ?>
              <tr>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                  <?= esc($log['timestamp']) ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; font-weight:600; color:var(--text-primary); white-space:nowrap;">
                  <?= esc($log['client_ip']) ?>
                </td>
                <td>
                  <span class="badge badge-info" style="font-size:0.65rem; font-family:var(--font-mono);"><?= esc($log['method']) ?></span>
                </td>
                <td style="max-width:320px;">
                  <strong style="color:var(--text-primary); font-size:0.82rem;"><?= esc($log['domain']) ?></strong>
                  <div style="font-size:0.7rem; color:var(--text-secondary); text-overflow:ellipsis; overflow:hidden; white-space:nowrap; max-width:320px;">
                    <?= esc($log['url']) ?>
                  </div>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem;">
                  <span class="badge <?= str_contains($log['squid_action'], 'HIT') ? 'badge-success' : (str_contains($log['squid_action'], 'DENIED') ? 'badge-danger' : 'badge-secondary') ?>" style="font-size:0.65rem;">
                    <?= esc($log['squid_action']) ?>
                  </span>
                </td>
                <td>
                  <?php if ($log['filter_action'] === 'BLOCK'): ?>
                    <span class="badge badge-danger">DROP / BLOCK</span>
                  <?php elseif ($log['filter_action'] === 'BYPASS_SSL'): ?>
                    <span class="badge badge-purple">SSL SPLICED</span>
                  <?php else: ?>
                    <span class="badge badge-success">ALLOWED</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:0.75rem; color:var(--color-cyan);">
                  <?= esc($log['category'] ?? 'General') ?>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <div style="display:inline-flex; gap:6px;">
                    <form method="POST" action="<?= base_url('webfilter/quick-action') ?>" style="margin:0; display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="domain" value="<?= esc($log['domain']) ?>">
                      <input type="hidden" name="action" value="whitelist">
                      <button type="submit" class="btn btn-ghost btn-sm" title="Add domain to Whitelist" style="padding:2px 7px; font-size:0.7rem; color:var(--color-emerald);">
                        + Whitelist
                      </button>
                    </form>

                    <form method="POST" action="<?= base_url('webfilter/quick-action') ?>" style="margin:0; display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="domain" value="<?= esc($log['domain']) ?>">
                      <input type="hidden" name="action" value="blacklist">
                      <button type="submit" class="btn btn-ghost btn-sm" title="Add domain to Blacklist" style="padding:2px 7px; font-size:0.7rem; color:var(--color-red);">
                        + Blacklist
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── JavaScript Interaction ── -->
<script>
function switchWfTab(tabId) {
  // Update buttons
  document.querySelectorAll('.wf-tab-btn').forEach(btn => {
    btn.classList.remove('btn-primary', 'active');
    btn.classList.add('btn-ghost');
  });
  const activeBtn = document.getElementById('tabBtn-' + tabId);
  if (activeBtn) {
    activeBtn.classList.remove('btn-ghost');
    activeBtn.classList.add('btn-primary', 'active');
  }

  // Update panes
  document.querySelectorAll('.wf-tab-pane').forEach(pane => {
    pane.classList.remove('active');
    pane.style.display = 'none';
  });
  const activePane = document.getElementById('tabContent-' + tabId);
  if (activePane) {
    activePane.classList.add('active');
    activePane.style.display = 'block';
  }

  // Update query string without reloading
  const url = new URL(window.location);
  url.searchParams.set('tab', tabId);
  window.history.replaceState({}, '', url);
}

function updateModeHighlight(radio) {
  document.querySelectorAll('.mode-card').forEach(c => {
    c.style.borderColor = 'var(--border-subtle)';
    c.style.background = 'var(--color-surface-2)';
  });
  const card = radio.closest('label').querySelector('.mode-card');
  if (card) {
    card.style.borderColor = 'var(--color-cyan)';
    card.style.background = 'rgba(6,182,212,0.08)';
  }
}

function copyToClipboard(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.innerText;
    btn.innerText = 'Copied!';
    setTimeout(() => { btn.innerText = orig; }, 2000);
  });
}

function runPacSimulation(e) {
  e.preventDefault();
  const url = document.getElementById('pacTestInput').value;
  if (!url) return;

  const csrfToken = document.querySelector('input[name="<?= csrf_token() ?>"]')?.value;

  MicronScheduler.fetch('<?= base_url('webfilter/wpad/test') ?>', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json'
    },
    body: new URLSearchParams({
      'test_url': url,
      '<?= csrf_token() ?>': csrfToken
    })
  })
  .then(res => res.json())
  .then(data => {
    const box = document.getElementById('simResultBox');
    const title = document.getElementById('simRuleTitle');
    const badge = document.getElementById('simDecisionBadge');
    const desc = document.getElementById('simDescription');

    if (data.success) {
      title.innerText = data.matched_rule;
      badge.innerText = data.decision;
      badge.className = 'badge ' + (data.decision.startsWith('DIRECT') ? 'badge-success' : 'badge-purple');
      desc.innerText = data.description;
      box.style.display = 'block';
    } else {
      title.innerText = 'Evaluation Error';
      badge.innerText = 'ERROR';
      badge.className = 'badge badge-danger';
      desc.innerText = data.error || 'Failed to simulate PAC evaluation.';
      box.style.display = 'block';
    }
  })
  .catch(err => {
    console.error(err);
  });
}

// Initialise mode highlighting and tab display
document.addEventListener('DOMContentLoaded', function() {
  const currentTab = '<?= esc($activeTab) ?>';
  switchWfTab(currentTab);
});
</script>

<?= $this->endSection() ?>
