<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
// Helper — get saved or old-input value
if (!function_exists('sv')) {
    function sv(string $key, array $settings, $fallback = ''): string {
        $v = old($key, $settings[$key] ?? $fallback);
        return esc((string)$v);
    }
}

/** Saved sticky-header colour, but only when it is a usable hex value. */
if (!function_exists('stickyColor')) {
    function stickyColor(string $key, array $settings = []): string {
        $v = (string) (old($key) ?? ($settings[$key] ?? ''));
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($v)) ? strtolower(trim($v)) : '';
    }
}
?>

<!-- ===================================================
     Page Header
=================================================== -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span>/</span>
      <span class="current">System Settings</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="page-title-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
      </div>
      <h1 class="page-title">General System Settings</h1>
    </div>
    <p class="page-subtitle">Comprehensive appliance configuration — identity, organisation, time, access, and notifications</p>
  </div>

  <!-- Power Controls -->
  <div class="page-header-actions">
    <?php if (session()->get('is_superadmin') || in_array('system.reboot', session()->get('permissions') ?? [])): ?>
      <form method="POST" action="<?= base_url('system/reboot') ?>" onsubmit="return confirm('Reboot the appliance now? Network routing will be temporarily interrupted.');" style="margin:0; display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--tone-amber-fg);" id="btn-reboot">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Reboot
        </button>
      </form>
    <?php endif; ?>

    <?php if (session()->get('is_superadmin') || in_array('system.halt', session()->get('permissions') ?? [])): ?>
      <form method="POST" action="<?= base_url('system/halt') ?>" onsubmit="return confirm('Power off the appliance now? Physical access will be required to power it back on.');" style="margin:0; display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger btn-sm" id="btn-shutdown">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/>
          </svg>
          Shutdown
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- ===================================================
     Flash Messages
=================================================== -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('warning')): ?>
  <div class="alert alert-warning animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('warning')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ===================================================
     Micron ID Badge (read-only, prominent)
=================================================== -->
<div class="animate-in animate-in-delay-1" style="margin-bottom:24px;">
  <div style="display:inline-flex; align-items:center; gap:12px; background:linear-gradient(135deg,rgba(0,212,255,0.08),rgba(124,58,237,0.08)); border:1px solid rgba(0,212,255,0.2); border-radius:12px; padding:12px 20px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
    </svg>
    <div>
      <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:2px;">Micron Appliance ID</div>
      <div style="font-family:var(--font-mono); font-size:0.9rem; color:var(--color-cyan); letter-spacing:0.05em;" id="micronIdDisplay"><?= esc(($settings['micron_id'] ?? '') ?: '—') ?></div>
    </div>
    <button type="button" onclick="copyMicronId()" title="Copy ID" style="background:none; border:none; cursor:pointer; color:var(--text-muted); padding:4px; border-radius:4px; transition:color 0.15s;" onmouseenter="this.style.color='var(--color-cyan)'" onmouseleave="this.style.color='var(--text-muted)'" id="btn-copy-id">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
      </svg>
    </button>
    <span style="width:1px; height:28px; background:rgba(255,255,255,0.08);"></span>
    <div style="font-size:0.75rem; color:var(--text-muted);">
      <?php if (!empty($settings['updated_at'])): ?>
        Last saved: <?= esc(date('d M Y H:i', strtotime($settings['updated_at']))) ?>
      <?php else: ?>
        Not yet saved to database
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===================================================
     Tab Navigation
=================================================== -->
<div class="animate-in animate-in-delay-1" style="margin-bottom:0;">
  <div id="settingsTabs" style="display:flex; flex-wrap:wrap; gap:0; border-bottom:1px solid var(--color-border); margin-bottom:0; padding-bottom:0;">
    <?php
    $tabs = [
      ['id'=>'identity',      'icon'=>'M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2', 'label'=>'Identity'],
      ['id'=>'organisation',  'icon'=>'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'label'=>'Organisation'],
      ['id'=>'time',          'icon'=>'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'label'=>'Time & NTP'],
      ['id'=>'network',       'icon'=>'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9', 'label'=>'Networking'],
      ['id'=>'admin_access',  'icon'=>'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'label'=>'Admin Access'],
      ['id'=>'notifications', 'icon'=>'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'label'=>'Notifications'],
      ['id'=>'ldap',          'icon'=>'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z', 'label'=>'LDAP / AD'],
      ['id'=>'radius',        'icon'=>'M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4', 'label'=>'RADIUS Auth'],
      ['id'=>'misc',          'icon'=>'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4', 'label'=>'System & Misc'],
    ];
    foreach ($tabs as $i => $tab):
    ?>
      <button type="button"
              class="sys-tab-btn <?= $i === 0 ? 'active' : '' ?>"
              data-tab="<?= $tab['id'] ?>"
              id="tab-btn-<?= $tab['id'] ?>"
              onclick="switchTab('<?= $tab['id'] ?>')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
          <path stroke-linecap="round" stroke-linejoin="round" d="<?= $tab['icon'] ?>"/>
        </svg>
        <?= $tab['label'] ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================================================
     Settings Form
=================================================== -->
<form method="POST" action="<?= base_url('system/save') ?>" id="settingsForm" class="animate-in animate-in-delay-2">
  <?= csrf_field() ?>
  <input type="hidden" name="micron_id" value="<?= esc($settings['micron_id'] ?? '') ?>">

  <!-- ══════════════════════════════════════════════
       TAB: IDENTITY
  ══════════════════════════════════════════════ -->
  <div class="sys-tab-panel active" id="panel-identity" style="display:block;">
    <div class="settings-section-header">
      <h2 class="settings-section-title">Appliance Identity</h2>
      <p class="settings-section-desc">Hostname, domain, and unique device identifier used across all system services.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></svg>
          Network Identity
        </span>
      </div>
      <div class="card-body">
        <div class="settings-grid-2">
          <div class="form-group">
            <label class="form-label" for="hostname">Appliance Hostname <span class="badge-required">*</span></label>
            <input type="text" id="hostname" name="hostname" class="form-control mono"
                   value="<?= sv('hostname', $settings)?>" spellcheck="false"
                   placeholder="micronos-fw01" required
                   pattern="^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$">
            <small class="form-hint">RFC 1123 hostname — alphanumeric and hyphens only, max 63 chars</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="domain">Search Domain <span class="badge-required">*</span></label>
            <input type="text" id="domain" name="domain" class="form-control mono"
                   value="<?= sv('domain', $settings)?>" spellcheck="false"
                   placeholder="firewall.local" required>
            <small class="form-hint">Default domain for unqualified hostnames</small>
          </div>
        </div>

        <div class="settings-grid-2">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="dns_default_servers">Default DNS Resolvers</label>
            <input type="text" id="dns_default_servers" name="dns_default_servers" class="form-control mono"
                   value="<?= sv('dns_default_servers', $settings, '1.1.1.1, 8.8.8.8')?>" spellcheck="false"
                   placeholder="1.1.1.1, 8.8.8.8">
            <small class="form-hint">Comma-separated list of upstream DNS servers</small>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="micron_id_display">Micron Appliance ID</label>
            <div style="display:flex; gap:8px; align-items:center;">
              <input type="text" id="micron_id_display" class="form-control mono"
                     value="<?= esc(($settings['micron_id'] ?? '') ?: '—')?>" spellcheck="false" readonly
                     style="opacity:0.65; cursor:default; flex:1;">
            </div>
            <small class="form-hint">Auto-generated unique identifier — read only</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB: ORGANISATION
  ══════════════════════════════════════════════ -->
  <div class="sys-tab-panel" id="panel-organisation" style="display:none;">
    <div class="settings-section-header">
      <h2 class="settings-section-title">Organisation Information</h2>
      <p class="settings-section-desc">Client and site details embedded in reports, alerts, and certificates.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
          Client Details
        </span>
      </div>
      <div class="card-body">
        <div class="settings-grid-2">
          <div class="form-group">
            <label class="form-label" for="org_name">Organisation / Client Name</label>
            <input type="text" id="org_name" name="org_name" class="form-control"
                   value="<?= sv('org_name', $settings) ?>" placeholder="Acme Corp Ltd.">
          </div>
          <div class="form-group">
            <label class="form-label" for="org_contact_name">Primary Contact Name</label>
            <input type="text" id="org_contact_name" name="org_contact_name" class="form-control"
                   value="<?= sv('org_contact_name', $settings) ?>" placeholder="John Smith">
          </div>
        </div>
        <div class="settings-grid-2">
          <div class="form-group">
            <label class="form-label" for="org_email">Contact Email</label>
            <input type="email" id="org_email" name="org_email" class="form-control"
                   value="<?= sv('org_email', $settings) ?>" placeholder="admin@example.com">
          </div>
          <div class="form-group">
            <label class="form-label" for="org_phone">Contact Phone</label>
            <input type="tel" id="org_phone" name="org_phone" class="form-control"
                   value="<?= sv('org_phone', $settings) ?>" placeholder="+1 555 000 0000">
          </div>
        </div>
      </div>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          Location & Address
        </span>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="org_address">Street Address</label>
          <textarea id="org_address" name="org_address" class="form-control" rows="2"
                    placeholder="123 Network Street, Suite 4"><?= sv('org_address', $settings) ?></textarea>
        </div>
        <div class="settings-grid-3">
          <div class="form-group">
            <label class="form-label" for="org_city">City</label>
            <input type="text" id="org_city" name="org_city" class="form-control"
                   value="<?= sv('org_city', $settings) ?>" placeholder="San Francisco">
          </div>
          <div class="form-group">
            <label class="form-label" for="org_state">State / Province</label>
            <input type="text" id="org_state" name="org_state" class="form-control"
                   value="<?= sv('org_state', $settings) ?>" placeholder="California">
          </div>
          <div class="form-group">
            <label class="form-label" for="org_country">Country</label>
            <select id="org_country" name="org_country" class="form-control form-select">
              <option value="">— Select country —</option>
              <?php foreach ($countries as $code => $name): ?>
                <option value="<?= esc($code) ?>" <?= (sv('org_country', $settings) === $code) ? 'selected' : '' ?>>
                  <?= esc($name) ?> (<?= esc($code) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="settings-grid-2">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="org_latitude">Latitude</label>
            <div style="position:relative;">
              <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.8rem; pointer-events:none;">LAT</span>
              <input type="number" id="org_latitude" name="org_latitude" class="form-control mono"
                     step="0.0000001" min="-90" max="90"
                     value="<?= sv('org_latitude', $settings)?>" spellcheck="false"
                     placeholder="37.7749" style="padding-left:44px;">
            </div>
            <small class="form-hint">Decimal degrees, e.g. 37.7749 (N) or -33.8688 (S)</small>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="org_longitude">Longitude</label>
            <div style="position:relative;">
              <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.8rem; pointer-events:none;">LON</span>
              <input type="number" id="org_longitude" name="org_longitude" class="form-control mono"
                     step="0.0000001" min="-180" max="180"
                     value="<?= sv('org_longitude', $settings)?>" spellcheck="false"
                     placeholder="-122.4194" style="padding-left:44px;">
            </div>
            <small class="form-hint">Decimal degrees, e.g. -122.4194 (W) or 151.2093 (E)</small>
          </div>
        </div>

        <!-- Mini map preview link -->
        <div style="margin-top:16px;">
          <button type="button" onclick="openMapPreview()" class="btn btn-ghost btn-sm" id="btn-map-preview"
                  style="font-size:0.8rem; gap:6px;">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
            Preview on Map
          </button>
        </div>
      </div>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Notes
        </span>
      </div>
      <div class="card-body">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="org_notes">Internal Notes</label>
          <textarea id="org_notes" name="org_notes" class="form-control" rows="3"
                    placeholder="Site notes, contract references, escalation contacts..."><?= sv('org_notes', $settings) ?></textarea>
          <small class="form-hint">Stored locally, visible only to admins. Not transmitted externally.</small>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB: TIME & NTP
  ══════════════════════════════════════════════ -->
  <div class="sys-tab-panel" id="panel-time" style="display:none;">
    <div class="settings-section-header">
      <h2 class="settings-section-title">Time & NTP Synchronisation</h2>
      <p class="settings-section-desc">Accurate time is critical for firewall logs, VPN certificates, and SIEM correlation.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          System Timezone
        </span>
        <div id="live-clock" style="font-family:var(--font-mono); font-size:0.85rem; color:var(--color-cyan);"></div>
      </div>
      <div class="card-body">
        <div class="settings-grid-2">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="timezone">Timezone <span class="badge-required">*</span></label>
            <select id="timezone" name="timezone" class="form-control form-select" required>
              <?php foreach ($timezones as $group => $tzList): ?>
                <optgroup label="── <?= esc($group) ?>">
                  <?php foreach ($tzList as $tz): ?>
                    <option value="<?= esc($tz) ?>" <?= (sv('timezone', $settings, 'UTC') === $tz) ? 'selected' : '' ?>>
                      <?= esc($tz) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <small class="form-hint">Affects system clock, log timestamps and scheduler</small>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Quick Select</label>
            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;">
              <?php foreach (['UTC','America/New_York','America/Los_Angeles','Europe/London','Europe/Berlin','Asia/Kolkata','Asia/Singapore','Australia/Sydney','Asia/Dubai','Asia/Karachi'] as $qtz): ?>
                <button type="button" class="quick-tz-btn" onclick="document.getElementById('timezone').value='<?= $qtz ?>'">
                  <?= esc(explode('/', $qtz, 2)[1] ?? $qtz) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          NTP Configuration
        </span>
        <label class="toggle-switch" title="Enable NTP">
          <input type="checkbox" id="ntp_enabled" name="ntp_enabled" value="1" <?= !empty($settings['ntp_enabled']) ? 'checked' : '' ?> onchange="toggleNtp(this.checked)">
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body" id="ntp-body">
        <div class="settings-grid-2">
          <div class="form-group">
            <label class="form-label" for="ntp_servers">NTP Server Pool</label>
            <input type="text" id="ntp_servers" name="ntp_servers" class="form-control mono"
                   value="<?= sv('ntp_servers', $settings, 'pool.ntp.org, time.google.com')?>" spellcheck="false"
                   placeholder="pool.ntp.org, time.google.com">
            <small class="form-hint">Comma-separated list of NTP servers or pools</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="ntp_sync_interval">Sync Interval</label>
            <select id="ntp_sync_interval" name="ntp_sync_interval" class="form-control form-select">
              <?php foreach (['300'=>'5 minutes','900'=>'15 minutes','1800'=>'30 minutes','3600'=>'1 hour','7200'=>'2 hours','21600'=>'6 hours','86400'=>'24 hours'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= (sv('ntp_sync_interval', $settings, '3600') === $val) ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Common NTP presets -->
        <div style="margin-top:8px;">
          <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">Common NTP presets:</div>
          <div style="display:flex; flex-wrap:wrap; gap:6px;">
            <?php $ntpPresets = [
              'pool.ntp.org'                                      => 'pool.ntp.org',
              'time.google.com'                                   => 'Google',
              'samay1.nic.in, samay2.nic.in, time.nplindia.org'   => 'CERT-In',
              '0.pool.ntp.org, 1.pool.ntp.org'                   => 'NTP Pool (2 servers)',
              'time.cloudflare.com'                               => 'Cloudflare',
              'ntp.ubuntu.com'                                    => 'Ubuntu',
              'time1.apple.com, time2.apple.com'                  => 'Apple',
            ];
            foreach ($ntpPresets as $val => $label): ?>
              <button type="button" class="quick-tz-btn" onclick="document.getElementById('ntp_servers').value='<?= esc($val) ?>'">
                <?= esc($label) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB: NETWORKING / IPV6
  ══════════════════════════════════════════════ -->
  <?php $ipv6On = (int) sv('ipv6_enabled', $settings, 0) === 1; ?>
  <div class="sys-tab-panel" id="panel-network" style="display:none;">
    <div class="settings-section-header">
      <h2 class="settings-section-title">Networking</h2>
      <p class="settings-section-desc">Protocol stack and address-family options for the whole appliance.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
          IPv6 Protocol
        </span>
        <span class="badge badge-info" style="font-size:0.68rem;">Address Family</span>
      </div>
      <div class="card-body">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">IPv6 Support</label>
          <div style="display:flex; align-items:center; gap:14px; padding:14px 16px; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:8px;">
            <label class="toggle-switch" style="margin:0;">
              <input type="checkbox" name="ipv6_enabled" id="ipv6_enabled" value="1" <?= $ipv6On ? 'checked' : '' ?> onchange="updateIpv6Ui()">
              <span class="toggle-slider"></span>
            </label>
            <div>
              <div id="ipv6StateLabel" style="font-size:0.85rem; font-weight:600; color:var(--text-primary);"><?= $ipv6On ? 'Enabled' : 'Disabled' ?></div>
              <div style="font-size:0.75rem; color:var(--text-muted);">Master switch for the entire IPv6 stack on this appliance.</div>
            </div>
          </div>
          <small class="form-hint">Equivalent to the appliance's IPv6 stack. Changing this applies immediately when you save.</small>
        </div>

        <div id="ipv6Effects" style="margin-top:16px; padding:14px 16px; border-radius:8px; background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.35); <?= $ipv6On ? 'display:none;' : '' ?>">
          <div style="font-size:0.8rem; font-weight:700; color:var(--color-red); margin-bottom:8px;">With IPv6 Disabled, the entire stack is switched off:</div>
          <ul style="margin:0; padding-left:18px; font-size:0.78rem; color:var(--text-secondary); line-height:1.75;">
            <li>Kernel IPv6 is turned off on every interface — <code style="font-family:var(--font-mono);">net.ipv6.conf.all/default.disable_ipv6=1</code></li>
            <li>All IPv6 addresses and IPv6 routes are removed (no IPv6 default gateway)</li>
            <li>Services (DNS, DHCP, VPN daemons, Web GUI) stop listening on <code style="font-family:var(--font-mono);">::</code></li>
            <li>The firewall drops every IPv6 packet — <code style="font-family:var(--font-mono);">meta nfproto ipv6 drop</code></li>
            <li>IPv6-only features (NPTv6, 6rd, IPv6 DHCP/RA) become unavailable</li>
          </ul>
        </div>

        <div style="margin-top:16px; padding:12px 14px; border-radius:8px; background:var(--color-surface-3); border:1px solid var(--color-border); font-size:0.78rem; color:var(--text-secondary);">
          <strong style="color:var(--text-primary);">Current state:</strong>
          <?= $ipv6On
                ? 'IPv6 is <span style="color:var(--color-emerald); font-weight:600;">enabled</span> — dual-stack networking is available.'
                : 'IPv6 is <span style="color:var(--color-red); font-weight:600;">disabled</span> — the appliance operates IPv4-only.' ?>
        </div>
      </div>
    </div>

    <?php $checkinOn = (int) sv('telemetry_enabled', $settings, 1) === 1 && config('Telemetry')->allowed; ?>
    <div class="card card-glass" style="margin-bottom:20px;" id="install-checkin">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
          Install Check-in
        </span>
        <span class="badge <?= $checkinOn ? 'badge-success' : 'badge-info' ?>" style="font-size:0.68rem;"><?= $checkinOn ? 'On' : 'Off' ?></span>
      </div>
      <div class="card-body">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Report this install to micronos.microninfo.com</label>
          <div style="display:flex; align-items:center; gap:14px; padding:14px 16px; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:8px;">
            <label class="toggle-switch" style="margin:0;">
              <input type="checkbox" name="telemetry_enabled" id="telemetry_enabled" value="1" <?= $checkinOn ? 'checked' : '' ?> <?= config('Telemetry')->allowed ? '' : 'disabled' ?>>
              <span class="toggle-slider"></span>
            </label>
            <div>
              <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary);"><?= $checkinOn ? 'Enabled' : 'Disabled' ?></div>
              <div style="font-size:0.75rem; color:var(--text-muted);">Once a few minutes after boot, then once a day. Turn off to send nothing.</div>
            </div>
          </div>
        </div>
        <div style="margin-top:14px; font-size:0.78rem; color:var(--text-secondary); line-height:1.7;">
          <strong style="color:var(--text-primary);">What is sent:</strong> Micron Appliance ID, hostname, Micron OS version, uptime, boot slot, number of active firewall rules, when the configuration was last saved, kernel, CPU model and cores, memory, disk size, number of network ports, timezone and whether it runs in a VM, plus the <strong>organisation details</strong> from the Organisation tab (organisation / client name, primary contact name, email, phone, address, city, state, country and coordinates) when they are filled in. Saving a new hostname or organisation details sends a check-in straight away.
          The website also records the <strong>public IP</strong> the report comes from and looks up its approximate country, city and ISP.
          <strong style="color:var(--text-primary);">Never sent:</strong> firewall rules, traffic, logs, user names, passwords or keys.
          See exactly what would be sent with <code style="font-family:var(--font-mono);">php spark micron:checkin --show</code>.
        </div>
        <div style="margin-top:12px; padding:10px 14px; border-radius:8px; background:var(--color-surface-3); border:1px solid var(--color-border); font-size:0.75rem; color:var(--text-muted);">
          Last sent: <?= esc(($settings['telemetry_last_at'] ?? '') ?: 'never') ?><?php if (! empty($settings['telemetry_last_status'])): ?> &bull; Last result: <?= esc($settings['telemetry_last_status']) ?><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB: ADMIN ACCESS (WEB GUI & SSH)
  ══════════════════════════════════════════════ -->
  <div class="sys-tab-panel" id="panel-admin_access" style="display:none;">
    <div id="panel-webgui">
      <div class="settings-section-header">
        <h2 class="settings-section-title">Administrative Access: Web GUI</h2>
        <p class="settings-section-desc">HTTP/HTTPS access settings, custom TLS certificates, session security, and brute-force protection.</p>
      </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
          Protocol & Port
        </span>
      </div>
      <div class="card-body">
        <div class="settings-grid-3">
          <div class="form-group">
            <label class="form-label" for="webgui_protocol">Protocol</label>
            <select id="webgui_protocol" name="webgui_protocol" class="form-control form-select">
              <option value="https" <?= sv('webgui_protocol',$settings,'https')==='https'?'selected':'' ?>>HTTPS (recommended)</option>
              <option value="http"  <?= sv('webgui_protocol',$settings,'https')==='http' ?'selected':'' ?>>HTTP (insecure)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="webgui_port">Listening Port</label>
            <input type="number" id="webgui_port" name="webgui_port" class="form-control mono"
                   min="1" max="65535" value="<?= sv('webgui_port', $settings, '443')?>" spellcheck="false">
            <small class="form-hint">Default: 443 (HTTPS) or 80 (HTTP)</small>
          </div>
          <div class="form-group">
            <label class="form-label">HTTP → HTTPS Redirect</label>
            <label class="toggle-switch" style="margin-top:8px;">
              <input type="checkbox" id="webgui_redirect_http" name="webgui_redirect_http" value="1"
                     <?= !empty($settings['webgui_redirect_http']) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
            <small class="form-hint">Redirect port 80 requests to HTTPS</small>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Web GUI TLS / SSL Certificate ────────────────────── -->
    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Web GUI TLS / SSL Certificate
        </span>
        <a href="<?= base_url('certificates') ?>" class="btn btn-ghost btn-sm" style="display:flex; align-items:center; gap:6px;">
          <span>Manage Certificates</span>
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </a>
      </div>
      <div class="card-body">
        <div class="settings-grid-2">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="webgui_cert_id">Active SSL/TLS Certificate</label>
            <select id="webgui_cert_id" name="webgui_cert_id" class="form-control form-select" onchange="updateCertDetailsPreview(this)">
              <option value="0" <?= ((int)sv('webgui_cert_id', $settings, '0') === 0) ? 'selected' : '' ?>>
                Default Self-Signed Appliance Certificate (Factory Default)
              </option>
              <?php if (!empty($certificates)): ?>
                <optgroup label="Installed Certificates (PKI &amp; ACME)">
                  <?php foreach ($certificates as $cert): ?>
                    <?php if (!empty($cert['certificate']) && empty($cert['revoked'])): ?>
                      <option value="<?= (int)$cert['id'] ?>" <?= ((int)sv('webgui_cert_id', $settings, '0') === (int)$cert['id']) ? 'selected' : '' ?>
                              data-cn="<?= esc($cert['common_name'] ?? '') ?>"
                              data-expires="<?= esc(!empty($cert['valid_until']) ? date('d M Y', strtotime($cert['valid_until'])) : 'Unknown') ?>"
                              data-san="<?= esc($cert['san_dns'] ?? '') ?>">
                        <?= esc($cert['name']) ?> (CN: <?= esc($cert['common_name']) ?>)<?= !empty($cert['valid_until']) ? ' — Exp: ' . esc(date('d M Y', strtotime($cert['valid_until']))) : '' ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
            <small class="form-hint">Select a custom certificate imported in System &gt; Certificates or issued via Let's Encrypt / ACME.</small>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Certificate Status</label>
            <div id="cert-preview-box" style="padding:10px 14px; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); font-size:0.8rem; line-height:1.5;">
              <?php
                $activeCertId = (int) ($settings['webgui_cert_id'] ?? 0);
                $activeCert = null;
                if ($activeCertId > 0 && !empty($certificates)) {
                    foreach ($certificates as $c) {
                        if ((int)$c['id'] === $activeCertId) {
                            $activeCert = $c;
                            break;
                        }
                    }
                }
              ?>
              <?php if ($activeCert): ?>
                <div style="display:flex; align-items:center; gap:6px; color:var(--color-emerald); font-weight:600; margin-bottom:4px;">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--color-emerald);"></span>
                  Custom Certificate Bound
                </div>
                <div><strong>Common Name:</strong> <?= esc($activeCert['common_name'] ?? '—') ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted);">
                  Valid until: <?= !empty($activeCert['valid_until']) ? esc(date('d M Y', strtotime($activeCert['valid_until']))) : '—' ?>
                  <?= !empty($activeCert['san_dns']) ? ' &bull; SAN: ' . esc($activeCert['san_dns']) : '' ?>
                </div>
              <?php else: ?>
                <div style="display:flex; align-items:center; gap:6px; color:var(--color-cyan); font-weight:600; margin-bottom:4px;">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--color-cyan);"></span>
                  System Default Certificate
                </div>
                <div style="font-size:0.75rem; color:var(--text-secondary);">
                  Using self-signed certificate at <code>/etc/micron/ssl/webgui.crt</code>. To remove browser security warnings, select a CA-signed or ACME certificate.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          Session & Security
        </span>
      </div>
      <div class="card-body">
        <div class="settings-grid-3">
          <div class="form-group">
            <label class="form-label" for="webgui_session_timeout">Session Timeout</label>
            <select id="webgui_session_timeout" name="webgui_session_timeout" class="form-control form-select">
              <?php foreach (['5'=>'5 minutes','15'=>'15 minutes','30'=>'30 minutes','60'=>'1 hour','120'=>'2 hours','480'=>'8 hours','0'=>'Never (not recommended)'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= (sv('webgui_session_timeout',$settings,'30') === $v) ? 'selected' : '' ?>><?= esc($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="webgui_max_login_attempts">Max Login Attempts</label>
            <input type="number" id="webgui_max_login_attempts" name="webgui_max_login_attempts"
                   class="form-control mono" min="1" max="20"
                   value="<?= sv('webgui_max_login_attempts', $settings, '5')?>" spellcheck="false">
            <small class="form-hint">Before account lockout</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="webgui_lockout_duration">Lockout Duration</label>
            <select id="webgui_lockout_duration" name="webgui_lockout_duration" class="form-control form-select">
              <?php foreach (['1'=>'1 minute','5'=>'5 minutes','10'=>'10 minutes','15'=>'15 minutes','30'=>'30 minutes','60'=>'1 hour'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= (sv('webgui_lockout_duration',$settings,'15') === $v) ? 'selected' : '' ?>><?= esc($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
          Login Banner / Disclaimer
        </span>
        <label class="toggle-switch" title="Require acceptance before sign-in">
          <input type="checkbox" id="webgui_login_banner_enabled" name="webgui_login_banner_enabled" value="1"
                 <?= !empty($settings['webgui_login_banner_enabled']) ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body">
        <div class="settings-grid-2">
          <div class="form-group">
            <label class="form-label" for="webgui_login_banner_title">Modal Title</label>
            <input type="text" id="webgui_login_banner_title" name="webgui_login_banner_title"
                   class="form-control" maxlength="190"
                   value="<?= sv('webgui_login_banner_title', $settings) ?>"
                   placeholder="Notice — Authorised Access Only">
            <small class="form-hint">Shown as the heading of the disclaimer modal.</small>
          </div>
          <div class="form-group">
            <label class="form-label">&nbsp;</label>
            <div style="font-size:0.8rem; color:var(--text-secondary); padding:8px 12px; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); line-height:1.5;">
              When enabled the message below is shown as a modal the user must accept before the sign-in form becomes usable.
              Use the switch above to turn it on or off.
            </div>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="webgui_login_banner">Disclaimer Text</label>
          <textarea id="webgui_login_banner" name="webgui_login_banner" class="form-control" rows="6"
                    placeholder="Authorised access only. All activity is monitored and logged. Unauthorised access will be prosecuted."><?= sv('webgui_login_banner', $settings) ?></textarea>
          <small class="form-hint">Line breaks are preserved. Leave blank to disable the modal regardless of the switch.</small>
        </div>
      </div>
    </div>

    <!-- ── Sticky Identification Header ───────────────────────── -->
    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 6v12"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 6v12"/></svg>
          Sticky Identification Header
        </span>
        <label class="toggle-switch" title="Pin a device bar to the top of every page">
          <input type="checkbox" id="webgui_sticky_header_enabled" name="webgui_sticky_header_enabled" value="1"
                 <?= !empty($settings['webgui_sticky_header_enabled']) ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body">
        <p style="font-size:0.8rem; color:var(--text-secondary); margin:0 0 16px; line-height:1.55;">
          Pins a thin bar to the top of every authenticated page so an operator managing several appliances can see at a
          glance which device they are on. Each segment accepts <strong>tokens</strong>, replaced with this appliance's values:
        </p>
        <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted); background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:10px 12px; margin-bottom:16px; line-height:1.7; word-break:break-word;">
          {ip} &nbsp; {hostname} &nbsp; {domain} &nbsp; {micron_id} &nbsp; {org_name} &nbsp; {contact} &nbsp;
          {org_address} &nbsp; {org_city} &nbsp; {org_state} &nbsp; {org_country}
        </div>
        <div class="settings-grid-3">
          <div class="form-group">
            <label class="form-label" for="webgui_sticky_left">Left Segment</label>
            <input type="text" id="webgui_sticky_left" name="webgui_sticky_left" class="form-control mono" maxlength="190"
                   value="<?= sv('webgui_sticky_left', $settings)?>" spellcheck="false" placeholder="e.g. {org_address}, {org_city}">
          </div>
          <div class="form-group">
            <label class="form-label" for="webgui_sticky_center">Centre Segment</label>
            <input type="text" id="webgui_sticky_center" name="webgui_sticky_center" class="form-control mono" maxlength="190"
                   value="<?= sv('webgui_sticky_center', $settings)?>" spellcheck="false" placeholder="e.g. {ip}">
            <small class="form-hint">Typically the LAN IP of this appliance.</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="webgui_sticky_right">Right Segment</label>
            <input type="text" id="webgui_sticky_right" name="webgui_sticky_right" class="form-control mono" maxlength="190"
                   value="<?= sv('webgui_sticky_right', $settings)?>" spellcheck="false" placeholder="e.g. {hostname}">
          </div>
        </div>

        <!-- Colour overrides — blank means "follow the active theme" -->
        <div class="settings-grid-2" style="margin-top:18px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="webgui_sticky_bg">Header Background</label>
            <div class="sticky-color-row">
              <input type="color" id="webgui_sticky_bg_picker" class="sticky-color-picker"
                     value="<?= stickyColor('webgui_sticky_bg', $settings) ?: '#111827' ?>"
                     oninput="syncStickyColor('webgui_sticky_bg', this.value)"
                     aria-label="Pick a header background colour">
              <input type="text" id="webgui_sticky_bg" name="webgui_sticky_bg" class="form-control mono"
                     maxlength="7" value="<?= sv('webgui_sticky_bg', $settings)?>" spellcheck="false" placeholder="theme default"
                     oninput="syncStickyPreview()">
              <button type="button" class="btn btn-ghost btn-sm" onclick="clearStickyColor('webgui_sticky_bg')"
                      title="Use the active theme's background">Theme</button>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="webgui_sticky_fg">Header Text</label>
            <div class="sticky-color-row">
              <input type="color" id="webgui_sticky_fg_picker" class="sticky-color-picker"
                     value="<?= stickyColor('webgui_sticky_fg', $settings) ?: '#f1f5f9' ?>"
                     oninput="syncStickyColor('webgui_sticky_fg', this.value)"
                     aria-label="Pick a header text colour">
              <input type="text" id="webgui_sticky_fg" name="webgui_sticky_fg" class="form-control mono"
                     maxlength="7" value="<?= sv('webgui_sticky_fg', $settings)?>" spellcheck="false" placeholder="theme default"
                     oninput="syncStickyPreview()">
              <button type="button" class="btn btn-ghost btn-sm" onclick="clearStickyColor('webgui_sticky_fg')"
                      title="Use the active theme's label colours">Theme</button>
            </div>
          </div>
        </div>
        <small class="form-hint">Leave blank to inherit the active theme. The three segments keep their cyan / primary / amber
          accents unless a text colour is set here.</small>

        <div class="sticky-header-options">
          <div>
            <div class="sticky-option-title">Flash the header text</div>
            <small class="form-hint" style="margin:0;">
              Blinks the three segments continuously so this appliance stands out when several consoles are open. Honours the
              operating system's <em>reduce motion</em> setting.
            </small>
          </div>
          <label class="toggle-switch" title="Make the sticky header text flash">
            <input type="checkbox" id="webgui_sticky_blink_enabled" name="webgui_sticky_blink_enabled" value="1"
                   <?= !empty($settings['webgui_sticky_blink_enabled']) ? 'checked' : '' ?>
                   onchange="toggleStickyBlink(this.checked)">
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       SUBSECTION: SSH ACCESS
  ══════════════════════════════════════════════ -->
  <div id="panel-ssh" style="margin-top:36px; padding-top:28px; border-top:1px solid var(--color-border);">
    <div class="settings-section-header">
      <h2 class="settings-section-title">Administrative Access: SSH &amp; Console</h2>
      <p class="settings-section-desc">Secure shell daemon configuration. The serial console (when the hardware has one) is fixed at 115200 baud, 8N1.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          SSH Daemon (OpenSSH)
        </span>
        <label class="toggle-switch" title="Enable SSH">
          <input type="checkbox" id="ssh_enabled" name="ssh_enabled" value="1"
                 <?= !empty($settings['ssh_enabled']) ? 'checked' : '' ?> onchange="toggleSsh(this.checked)">
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body" id="ssh-body">
        <div class="settings-grid-3">
          <div class="form-group">
            <label class="form-label" for="ssh_port">SSH Port</label>
            <input type="number" id="ssh_port" name="ssh_port" class="form-control mono"
                   min="1" max="65535" value="<?= sv('ssh_port', $settings, '22')?>" spellcheck="false">
            <small class="form-hint">Default 22; change to reduce brute-force exposure</small>
          </div>
          <div class="form-group">
            <label class="form-label" for="ssh_max_auth_tries">Max Auth Tries</label>
            <input type="number" id="ssh_max_auth_tries" name="ssh_max_auth_tries" class="form-control mono"
                   min="1" max="10" value="<?= sv('ssh_max_auth_tries', $settings, '3')?>" spellcheck="false">
            <small class="form-hint">Failed attempts before disconnect</small>
          </div>
          <div class="form-group">
            <label class="form-label">Root Login</label>
            <label class="toggle-switch" style="margin-top:8px;">
              <input type="checkbox" id="ssh_root_login" name="ssh_root_login" value="1"
                     <?= !empty($settings['ssh_root_login']) ? 'checked' : '' ?>>
              <span class="toggle-slider toggle-slider-danger"></span>
            </label>
            <small class="form-hint" style="color:var(--color-amber);">⚠ Not recommended — use sudo instead</small>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Authentication Methods</label>
          <div style="display:flex; gap:16px; margin-top:8px; flex-wrap:wrap;">
            <?php
            $currentMethods = explode(',', $settings['ssh_auth_methods'] ?? 'key,password');
            $currentMethods = array_map('trim', $currentMethods);
            foreach (['key'=>'SSH Public Key','password'=>'Password','keyboard-interactive'=>'Keyboard Interactive'] as $val => $lbl):
            ?>
              <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:8px 14px; border:1px solid var(--color-border); border-radius:8px; background:var(--color-surface-3); transition:all 0.15s;"
                     onmouseenter="this.style.borderColor='var(--color-cyan-glow)'"
                     onmouseleave="this.style.borderColor='var(--color-border)'">
                <input type="checkbox" name="ssh_auth_methods[]" value="<?= $val ?>"
                       <?= in_array($val, $currentMethods) ? 'checked' : '' ?>
                       style="accent-color:var(--color-cyan);">
                <span style="font-size:0.85rem;"><?= esc($lbl) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB: NOTIFICATIONS
  ══════════════════════════════════════════════ -->
  <div class="sys-tab-panel" id="panel-notifications" style="display:none;">
    <div class="settings-section-header">
      <h2 class="settings-section-title">Notifications & Alerting</h2>
      <p class="settings-section-desc">Email delivery for system alerts, firewall events, and health monitoring.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
          Email / SMTP
        </span>
        <label class="toggle-switch" title="Enable email notifications">
          <input type="checkbox" id="notif_email_enabled" name="notif_email_enabled" value="1"
                 <?= !empty($settings['notif_email_enabled']) ? 'checked' : '' ?> onchange="toggleNotif(this.checked)">
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body" id="notif-body" <?= empty($settings['notif_email_enabled']) ? 'style="opacity:0.4;pointer-events:none;"' : '' ?>>
        <div class="settings-grid-2" style="margin-bottom:20px;">
          <div class="form-group">
            <label class="form-label" for="notif_smtp_host">SMTP Server</label>
            <input type="text" id="notif_smtp_host" name="notif_smtp_host" class="form-control mono"
                   value="<?= sv('notif_smtp_host', $settings)?>" spellcheck="false" placeholder="smtp.gmail.com">
          </div>
          <div class="form-group">
            <label class="form-label" for="notif_smtp_port">SMTP Port</label>
            <select id="notif_smtp_port" name="notif_smtp_port" class="form-control form-select mono">
              <?php foreach (['25'=>'25 (SMTP)','465'=>'465 (SMTPS)','587'=>'587 (STARTTLS)','2525'=>'2525 (Alt)'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= (sv('notif_smtp_port',$settings,'587') === $v) ? 'selected' : '' ?>><?= esc($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="settings-grid-3" style="margin-bottom:20px;">
          <div class="form-group">
            <label class="form-label" for="notif_smtp_tls">Encryption</label>
            <select id="notif_smtp_tls" name="notif_smtp_tls" class="form-control form-select">
              <option value="starttls" <?= sv('notif_smtp_tls',$settings,'starttls')==='starttls'?'selected':'' ?>>STARTTLS (recommended)</option>
              <option value="tls"      <?= sv('notif_smtp_tls',$settings,'starttls')==='tls'     ?'selected':'' ?>>SSL/TLS</option>
              <option value="none"     <?= sv('notif_smtp_tls',$settings,'starttls')==='none'    ?'selected':'' ?>>None (insecure)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="notif_smtp_user">SMTP Username</label>
            <input type="text" id="notif_smtp_user" name="notif_smtp_user" class="form-control mono"
                   value="<?= sv('notif_smtp_user', $settings)?>" spellcheck="false" placeholder="alerts@example.com"
                   autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label" for="notif_smtp_pass">SMTP Password</label>
            <div style="position:relative;">
              <input type="password" id="notif_smtp_pass" name="notif_smtp_pass" class="form-control mono" autocomplete="new-password" spellcheck="false"
                     value="<?= sv('notif_smtp_pass', $settings) ?>" autocomplete="new-password"
                     style="padding-right:44px;">
              <button type="button" onclick="togglePasswordVisibility('notif_smtp_pass', this)"
                      style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--text-muted); padding:4px;">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>
        </div>

        <div class="settings-grid-2">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="notif_smtp_from">From Address</label>
            <input type="email" id="notif_smtp_from" name="notif_smtp_from" class="form-control mono"
                   value="<?= sv('notif_smtp_from', $settings)?>" spellcheck="false" placeholder="micronos@example.com">
            <small class="form-hint">Sender address appearing in notification emails</small>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="notif_smtp_to">Recipient(s)</label>
            <input type="text" id="notif_smtp_to" name="notif_smtp_to" class="form-control mono"
                   value="<?= sv('notif_smtp_to', $settings)?>" spellcheck="false" placeholder="admin@example.com, noc@example.com">
            <small class="form-hint">Comma-separated list of alert recipients</small>
          </div>
        </div>

        <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--color-border);">
          <button type="button" onclick="sendTestEmail()" class="btn btn-ghost btn-sm" id="btn-test-email" style="gap:6px;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            Send Test Email
          </button>
          <span id="test-email-result" style="font-size:0.8rem; margin-left:12px;"></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB: SYSTEM & MISC
  ══════════════════════════════════════════════ -->
  <div class="sys-tab-panel" id="panel-misc" style="display:none;">
    <div class="settings-section-header">
      <h2 class="settings-section-title">System & Miscellaneous</h2>
      <p class="settings-section-desc">Interface theme and navigation layout. Micron OS is English-only.</p>
    </div>

    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
          UI Preferences
        </span>
      </div>
      <div class="card-body">
        <div class="settings-grid-2">
          <div class="form-group">
            <label class="form-label" for="theme">Interface Theme</label>
            <select id="theme" name="theme" class="form-control form-select">
              <?php
              // Theme catalogue comes from Config\Appearance (single source of truth)
              $appearance   = config('Appearance');
              $currentTheme = $appearance->resolveTheme(sv('theme', $settings, $appearance->defaultTheme));
              $themeGroups  = ['dark' => 'Dark', 'light' => 'Light'];
              foreach ($themeGroups as $mode => $groupLabel): ?>
                <optgroup label="<?= esc($groupLabel) ?>">
                  <?php foreach ($appearance->themes as $value => $meta): if ($meta['mode'] !== $mode) { continue; } ?>
                    <option value="<?= esc($value) ?>" <?= $currentTheme === $value ? 'selected' : '' ?>><?= esc($meta['label']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="layout">Layout Preset</label>
            <select id="layout" name="layout" class="form-control form-select">
              <?php
              $currentLayout = config('Appearance')->resolveLayout(sv('layout', $settings, config('Appearance')->defaultLayout));
              foreach (config('Appearance')->layouts as $value => $label): ?>
                <option value="<?= esc($value) ?>" <?= $currentLayout === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Database & Storage Self-Healing Resilience -->
    <div class="card card-glass" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
          </svg>
          Database & Storage Durability
        </span>
        <span class="badge <?= ($dbResilienceInfo['healthy'] ?? false) ? 'badge-success' : 'badge-danger' ?>" id="badge-db-health">
          <?= ($dbResilienceInfo['healthy'] ?? false) ? 'SQLite WAL Verified' : 'Integrity Attention Needed' ?>
        </span>
      </div>
      <div class="card-body">
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:16px;">
          MicronOS enforces Write-Ahead Logging (WAL) with synchronous write barriers, atomic shadow snapshotting (<code>VACUUM INTO</code>), and early boot-time self-healing against power cuts.
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:16px;">
          <div style="background:rgba(255,255,255,0.03); border:1px solid var(--color-border); border-radius:8px; padding:12px;">
            <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Database File</div>
            <div style="font-size:0.9rem; font-weight:600; color:var(--text-primary); margin-top:4px;" id="val-db-size">
              <?= round(($dbResilienceInfo['db_size'] ?? 0) / 1048576, 2) ?> MB
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
              B-Tree Integrity: <strong style="color:var(--tone-green-fg);"><?= esc($dbResilienceInfo['integrity'] ?? 'OK') ?></strong>
            </div>
          </div>

          <div style="background:rgba(255,255,255,0.03); border:1px solid var(--color-border); border-radius:8px; padding:12px;">
            <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Write-Ahead Log (WAL)</div>
            <div style="font-size:0.9rem; font-weight:600; color:var(--text-primary); margin-top:4px;" id="val-wal-size">
              <?= round(($dbResilienceInfo['wal_size'] ?? 0) / 1024, 1) ?> KB
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Auto-checkpoint at 16MB</div>
          </div>

          <div style="background:rgba(255,255,255,0.03); border:1px solid var(--color-border); border-radius:8px; padding:12px;">
            <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Crash-Recovery Snapshot</div>
            <div style="font-size:0.9rem; font-weight:600; color:var(--text-primary); margin-top:4px;" id="val-snap-status">
              <?= ($dbResilienceInfo['snapshot_exists'] ?? false) ? 'Ready (' . round(($dbResilienceInfo['snapshot_size'] ?? 0) / 1048576, 2) . ' MB)' : 'No snapshot yet' ?>
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;" id="val-snap-mtime">
              <?= ($dbResilienceInfo['snapshot_exists'] ?? false && !empty($dbResilienceInfo['snapshot_mtime'])) ? date('Y-m-d H:i', $dbResilienceInfo['snapshot_mtime']) : 'Auto-created on boot' ?>
            </div>
          </div>
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:10px;">
          <button type="button" class="btn btn-ghost btn-sm" id="btn-db-check" onclick="runDbIntegrityCheck()">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Verify Integrity
          </button>
          <button type="button" class="btn btn-ghost btn-sm" id="btn-db-checkpoint" onclick="runDbCheckpoint()">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Flush & Truncate WAL
          </button>
          <button type="button" class="btn btn-ghost btn-sm" id="btn-db-snapshot" onclick="runDbSnapshot()">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
            </svg>
            Create Shadow Snapshot
          </button>
        </div>
        <div id="db-action-feedback" style="display:none; margin-top:12px; font-size:0.8rem; padding:8px 12px; border-radius:6px;"></div>
      </div>
    </div>

  </div>

  <!-- ===================================================
       Save Bar
  =================================================== -->
  <div class="settings-save-bar" id="general-save-bar">
    <div style="display:flex; align-items:center; gap:12px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--color-amber)" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span style="font-size:0.85rem; color:var(--text-secondary);">Changes on general tabs are saved together.</span>
    </div>
    <div style="display:flex; gap:10px;">
      <a href="<?= base_url('system') ?>" class="btn btn-ghost">Discard Changes</a>
      <button type="submit" name="submit" class="btn btn-primary" id="btn-save-settings" style="min-width:160px;">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
        </svg>
        Save All Settings
      </button>
    </div>
  </div>

</form>

<!-- ══════════════════════════════════════════════
     TAB: LDAP / ACTIVE DIRECTORY
══════════════════════════════════════════════ -->
<form method="POST" action="<?= base_url('system/ldap/save') ?>" id="panel-ldap" class="sys-tab-panel animate-in" style="display:none;">
  <?= csrf_field() ?>

  <div class="settings-section-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h2 class="settings-section-title">LDAP & Active Directory Authentication</h2>
      <p class="settings-section-desc">Centralized enterprise directory services for operator sign-in, user auto-provisioning, and AD security group to RBAC role mappings.</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="openLdapTestModal()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Test LDAP Auth
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="triggerLdapSync()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Sync Users Now
      </button>
    </div>
  </div>

  <!-- Server & Connection Architecture -->
  <div class="card card-glass" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
        </svg>
        Directory Server & Transport Security
      </span>
      <label class="toggle-switch" title="Enable/Disable LDAP Authentication">
        <input type="checkbox" name="enabled" value="1" <?= (!empty($ldapServer['enabled'])) ? 'checked' : '' ?>>
        <span class="toggle-slider"></span>
        <span style="font-size:0.8rem; margin-left:8px; color:var(--text-secondary); font-weight:500;">Enable LDAP Service</span>
      </label>
    </div>
    <div class="card-body">
      <div class="settings-grid-3">
        <div class="form-group">
          <label class="form-label" for="ldap_name">Connection Profile Name <span class="badge-required">*</span></label>
          <input type="text" id="ldap_name" name="name" class="form-control"
                 value="<?= esc($ldapServer['name'] ?? 'Corporate Active Directory') ?>" required placeholder="Corporate Active Directory">
          <span class="form-hint">Descriptive label for this directory server profile.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_schema_type">Directory Schema / Type</label>
          <select id="ldap_schema_type" name="schema_type" class="form-control form-select" onchange="adaptLdapDefaults(this.value)">
            <option value="ad" <?= (($ldapServer['schema_type'] ?? 'ad') === 'ad') ? 'selected' : '' ?>>Microsoft Active Directory (AD)</option>
            <option value="openldap" <?= (($ldapServer['schema_type'] ?? '') === 'openldap') ? 'selected' : '' ?>>OpenLDAP (RFC 2307 / slapd)</option>
            <option value="rfc2307" <?= (($ldapServer['schema_type'] ?? '') === 'rfc2307') ? 'selected' : '' ?>>FreeIPA / 389 Directory Server</option>
          </select>
          <span class="form-hint">Presets user/group filters and attribute names.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_hostname">Server Hostname or IP <span class="badge-required">*</span></label>
          <input type="text" id="ldap_hostname" name="hostname" class="form-control mono"
                 value="<?= esc($ldapServer['hostname'] ?? 'dc01.corp.example.local')?>" spellcheck="false" required placeholder="dc01.corp.example.local">
          <span class="form-hint">FQDN or IPv4/IPv6 address of directory domain controller.</span>
        </div>
      </div>

      <div class="settings-grid-3">
        <div class="form-group">
          <label class="form-label" for="ldap_port">Port <span class="badge-required">*</span></label>
          <input type="number" id="ldap_port" name="port" class="form-control mono"
                 value="<?= (int)($ldapServer['port'] ?? 389)?>" spellcheck="false" min="1" max="65535" required>
          <span class="form-hint">Standard port: 389 (Plain / STARTTLS) or 636 (LDAPS).</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_encryption">Transport Encryption</label>
          <select id="ldap_encryption" name="encryption" class="form-control form-select" onchange="autoAdjustLdapPort(this.value)">
            <option value="none" <?= (empty($ldapServer['use_ssl']) && empty($ldapServer['use_tls'])) ? 'selected' : '' ?>>None (Plain Text - Port 389)</option>
            <option value="starttls" <?= (!empty($ldapServer['use_tls'])) ? 'selected' : '' ?>>STARTTLS (RFC 4511 - Port 389)</option>
            <option value="ldaps" <?= (!empty($ldapServer['use_ssl'])) ? 'selected' : '' ?>>SSL / TLS (LDAPS - Port 636)</option>
          </select>
          <span class="form-hint">Encrypts user credentials in transit across network.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_timeout">Connection Timeout</label>
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="number" id="ldap_timeout" name="timeout" class="form-control mono"
                   value="<?= (int)($ldapServer['timeout'] ?? 5)?>" spellcheck="false" min="1" max="30">
            <span style="font-size:0.8rem; color:var(--text-muted);">seconds</span>
          </div>
          <span class="form-hint">Timeout before failing over to next auth provider.</span>
        </div>
      </div>

      <div class="settings-grid-2" style="margin-bottom:0;">
        <div class="form-group">
          <label class="form-label" for="ldap_ca_cert_id">Trusted CA Certificate</label>
          <select id="ldap_ca_cert_id" name="ca_cert_id" class="form-control form-select">
            <option value="">System Default Trust Store (/etc/ssl/certs)</option>
            <?php foreach ($certificates as $cert): ?>
              <option value="<?= esc($cert['id']) ?>" <?= (($ldapServer['ca_cert_id'] ?? '') === $cert['id']) ? 'selected' : '' ?>>
                <?= esc($cert['name']) ?> (<?= esc($cert['common_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <span class="form-hint">CA authority for validating LDAPS / TLS server certificates.</span>
        </div>

        <div class="form-group" style="display:flex; flex-direction:column; justify-content:center;">
          <label class="toggle-switch">
            <input type="checkbox" name="fallback_local" value="1" <?= (!empty($ldapServer['fallback_local'])) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <span style="font-size:0.85rem; margin-left:10px; color:var(--text-primary); font-weight:500;">Fallback to Local Database</span>
          </label>
          <span class="form-hint" style="margin-left:52px;">Permits local administrator login if LDAP directory is offline or unreachable.</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Bind Credentials & Search Filters -->
  <div class="card card-glass" style="margin-bottom:20px;">
    <div class="card-header">
      <span class="card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        Bind Account & Search Filters
      </span>
    </div>
    <div class="card-body">
      <div class="settings-grid-2">
        <div class="form-group">
          <label class="form-label" for="ldap_bind_dn">Bind DN / Service Account</label>
          <input type="text" id="ldap_bind_dn" name="bind_dn" class="form-control mono"
                 value="<?= esc($ldapServer['bind_dn'] ?? 'CN=svc-micronos,OU=Service Accounts,DC=corp,DC=example,DC=local')?>" spellcheck="false"
                 placeholder="CN=svc-micronos,OU=Services,DC=corp,DC=example,DC=local">
          <span class="form-hint">Distinguished Name or UPN (e.g. svc@corp.local). Leave empty for anonymous bind.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_bind_password">Bind Password</label>
          <div style="position:relative;">
            <input type="password" id="ldap_bind_password" name="bind_password" class="form-control mono" autocomplete="new-password" spellcheck="false"
                   value="<?= esc($ldapServer['bind_password'] ?? '') ?>" placeholder="••••••••••••">
            <button type="button" class="btn-eye" onclick="togglePasswordVisibility('ldap_bind_password', this)"
                    style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
            </button>
          </div>
          <span class="form-hint">Password for bind account. Stored securely encrypted in appliance vault.</span>
        </div>
      </div>

      <div class="settings-grid-2">
        <div class="form-group">
          <label class="form-label" for="ldap_base_dn">Base DN <span class="badge-required">*</span></label>
          <input type="text" id="ldap_base_dn" name="base_dn" class="form-control mono"
                 value="<?= esc($ldapServer['base_dn'] ?? 'DC=corp,DC=example,DC=local')?>" spellcheck="false" required
                 placeholder="DC=corp,DC=example,DC=local">
          <span class="form-hint">Top-level directory branch containing users and security groups.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_user_search_base">User Search Base (OU)</label>
          <input type="text" id="ldap_user_search_base" name="user_search_base" class="form-control mono"
                 value="<?= esc($ldapServer['user_search_base'] ?? 'OU=Users,DC=corp,DC=example,DC=local')?>" spellcheck="false"
                 placeholder="OU=Users,DC=corp,DC=example,DC=local">
          <span class="form-hint">Subtree for querying user accounts. Defaults to Base DN if empty.</span>
        </div>
      </div>

      <div class="settings-grid-3">
        <div class="form-group">
          <label class="form-label" for="ldap_user_search_filter">User Search Filter</label>
          <input type="text" id="ldap_user_search_filter" name="user_search_filter" class="form-control mono"
                 value="<?= esc($ldapServer['user_search_filter'] ?? '(&(objectClass=user)(sAMAccountName={username}))')?>" spellcheck="false"
                 placeholder="(&(objectClass=user)(sAMAccountName={username}))">
          <span class="form-hint">Filter syntax. Use <code style="color:var(--color-cyan);">{username}</code> placeholder.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_username_attribute">Username Attribute</label>
          <input type="text" id="ldap_username_attribute" name="username_attribute" class="form-control mono"
                 value="<?= esc($ldapServer['username_attribute'] ?? 'sAMAccountName')?>" spellcheck="false" placeholder="sAMAccountName">
          <span class="form-hint">Attribute containing login ID (AD: sAMAccountName, OpenLDAP: uid).</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_display_name_attribute">Full Name Attribute</label>
          <input type="text" id="ldap_display_name_attribute" name="display_name_attribute" class="form-control mono"
                 value="<?= esc($ldapServer['display_name_attribute'] ?? 'displayName')?>" spellcheck="false" placeholder="displayName">
          <span class="form-hint">Attribute used for operator display name (displayName or cn).</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Role Mapping & Directory Groups -->
  <div class="card card-glass" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        Directory Groups & RBAC Role Mappings
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddGroupMappingModal('ldap')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Add Group Mapping
      </button>
    </div>
    <div class="card-body">
      <div class="settings-grid-3">
        <div class="form-group">
          <label class="form-label" for="ldap_group_attribute">Group Member Attribute</label>
          <input type="text" id="ldap_group_attribute" name="group_attribute" class="form-control mono"
                 value="<?= esc($ldapServer['group_attribute'] ?? 'memberOf')?>" spellcheck="false" placeholder="memberOf">
          <span class="form-hint">Attribute containing group memberships (AD: memberOf, OpenLDAP: member).</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_group_search_base">Group Search Base (OU)</label>
          <input type="text" id="ldap_group_search_base" name="group_search_base" class="form-control mono"
                 value="<?= esc($ldapServer['group_search_base'] ?? 'OU=Security Groups,DC=corp,DC=example,DC=local')?>" spellcheck="false"
                 placeholder="OU=Security Groups,DC=corp,DC=example,DC=local">
          <span class="form-hint">Base subtree for resolving nested groups.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="ldap_default_role_id">Default Fallback Role</label>
          <select id="ldap_default_role_id" name="default_role_id" class="form-control form-select">
            <option value="">— Select Fallback Role —</option>
            <?php foreach ($allRoles as $role): ?>
              <option value="<?= esc($role['id']) ?>" <?= (($ldapServer['default_role_id'] ?? '') === $role['id']) ? 'selected' : '' ?>>
                <?= esc($role['display_name']) ?> (<?= esc($role['name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <span class="form-hint">Assigned when user's directory groups match none of the mapping rules.</span>
        </div>
      </div>

      <!-- Group Mapping Table -->
      <div style="margin-top:16px;">
        <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary); margin-bottom:8px;">Active LDAP / Active Directory Group Mappings</div>
        <table class="iface-table">
          <thead>
            <tr>
              <th>Active Directory / LDAP Group (DN or CN)</th>
              <th>Mapped MicronOS RBAC Role</th>
              <th style="width:100px;">Priority</th>
              <th style="text-align:right; width:90px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($ldapMappings)): ?>
              <tr>
                <td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted); font-size:0.85rem;">
                  No LDAP group mappings defined. Users will receive the default fallback role upon login.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($ldapMappings as $m): ?>
                <tr>
                  <?php $mGrp = $m['external_group'] ?? $m['external_group_name'] ?? ''; ?>
                  <td>
                    <div style="font-weight:600; font-size:0.88rem; color:var(--text-primary); font-family:var(--font-mono);">
                      <?= esc($mGrp) ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge <?= ($m['role_name'] === 'superadmin') ? 'badge-purple' : 'badge-info' ?>" style="font-size:0.75rem;">
                      <?= esc($m['role_display_name'] ?? $m['role_name']) ?>
                    </span>
                  </td>
                  <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
                    <?= (int)$m['priority'] ?>
                  </td>
                  <td style="text-align:right;">
                    <a href="<?= base_url('system/group-mappings/' . esc($m['id']) . '/delete') ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Remove group mapping for \'<?= esc(addslashes($mGrp)) ?>\'?');"
                       title="Delete mapping">
                      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- LDAP Save Bar -->
  <div class="settings-save-bar">
    <div style="display:flex; align-items:center; gap:12px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span style="font-size:0.85rem; color:var(--text-secondary);">LDAP credentials and search filters will apply immediately to operator logins.</span>
    </div>
    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn btn-primary" id="btn-save-ldap" style="min-width:180px;">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        Save LDAP Settings
      </button>
    </div>
  </div>
</form>

<!-- ══════════════════════════════════════════════
     TAB: RADIUS AUTHENTICATION
══════════════════════════════════════════════ -->
<form method="POST" action="<?= base_url('system/radius/save') ?>" id="panel-radius" class="sys-tab-panel animate-in" style="display:none;">
  <?= csrf_field() ?>

  <div class="settings-section-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h2 class="settings-section-title">RADIUS Authentication & Accounting</h2>
      <p class="settings-section-desc">Remote Authentication Dial-In User Service (RFC 2865 / RFC 2866) with primary & secondary failover, PAP/CHAP protocols, and dynamic attribute-to-role assignment.</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="openRadiusTestModal()" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Test RADIUS Auth
      </button>
    </div>
  </div>

  <!-- Primary & Backup Server Infrastructure -->
  <div class="settings-grid-2">
    <!-- Primary Server -->
    <div class="card card-glass">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
          Primary RADIUS Server
        </span>
        <label class="toggle-switch">
          <input type="checkbox" name="enabled" value="1" <?= (!empty($radiusPrimary['enabled'])) ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="rad_name">Profile Name</label>
          <input type="text" id="rad_name" name="name" class="form-control"
                 value="<?= esc($radiusPrimary['name'] ?? 'Primary RADIUS Server') ?>" placeholder="Primary RADIUS Server">
        </div>

        <div class="form-group">
          <label class="form-label" for="rad_hostname">Server IP or Hostname <span class="badge-required">*</span></label>
          <input type="text" id="rad_hostname" name="hostname" class="form-control mono"
                 value="<?= esc($radiusPrimary['hostname'] ?? '')?>" spellcheck="false" placeholder="192.168.1.50 or radius.corp.local">
          <span class="form-hint">Target FreeRADIUS, Cisco ISE, NPS, or Aruba ClearPass host.</span>
        </div>

        <div class="settings-grid-2" style="margin-bottom:0;">
          <div class="form-group">
            <label class="form-label" for="rad_auth_port">Auth Port</label>
            <input type="number" id="rad_auth_port" name="auth_port" class="form-control mono"
                   value="<?= (int)($radiusPrimary['auth_port'] ?? 1812)?>" spellcheck="false" min="1" max="65535">
            <span class="form-hint">RFC 2865 standard: 1812 (legacy: 1645)</span>
          </div>
          <div class="form-group">
            <label class="form-label" for="rad_acct_port">Accounting Port</label>
            <input type="number" id="rad_acct_port" name="acct_port" class="form-control mono"
                   value="<?= (int)($radiusPrimary['acct_port'] ?? 1813)?>" spellcheck="false" min="1" max="65535">
            <span class="form-hint">RFC 2866 standard: 1813 (legacy: 1646)</span>
          </div>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label" for="rad_secret">Shared Secret <span class="badge-required">*</span></label>
          <div style="position:relative;">
            <input type="password" id="rad_secret" name="secret" class="form-control mono" autocomplete="new-password" spellcheck="false"
                   value="<?= esc($radiusPrimary['secret'] ?? '') ?>" placeholder="••••••••••••">
            <button type="button" class="btn-eye" onclick="togglePasswordVisibility('rad_secret', this)"
                    style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
          <span class="form-hint">Shared secret key configured in RADIUS clients configuration.</span>
        </div>
      </div>
    </div>

    <!-- Secondary / Backup Failover Server -->
    <div class="card card-glass">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
          </svg>
          Secondary Failover Server (HA)
        </span>
        <label class="toggle-switch">
          <input type="checkbox" name="backup_enabled" value="1" <?= (!empty($radiusBackup['enabled'])) ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="rad_backup_name">Backup Profile Name</label>
          <input type="text" id="rad_backup_name" name="backup_name" class="form-control"
                 value="<?= esc($radiusBackup['name'] ?? 'Secondary RADIUS Server') ?>" placeholder="Secondary RADIUS Server">
        </div>

        <div class="form-group">
          <label class="form-label" for="rad_backup_hostname">Backup Server IP or Hostname</label>
          <input type="text" id="rad_backup_hostname" name="backup_hostname" class="form-control mono"
                 value="<?= esc($radiusBackup['hostname'] ?? '')?>" spellcheck="false" placeholder="192.168.1.51 (optional)">
          <span class="form-hint">Automatic failover if primary RADIUS server fails to respond.</span>
        </div>

        <div class="settings-grid-2" style="margin-bottom:0;">
          <div class="form-group">
            <label class="form-label" for="rad_backup_auth_port">Auth Port</label>
            <input type="number" id="rad_backup_auth_port" name="backup_auth_port" class="form-control mono"
                   value="<?= (int)($radiusBackup['auth_port'] ?? 1812)?>" spellcheck="false" min="1" max="65535">
          </div>
          <div class="form-group">
            <label class="form-label" for="rad_backup_acct_port">Accounting Port</label>
            <input type="number" id="rad_backup_acct_port" name="backup_acct_port" class="form-control mono"
                   value="<?= (int)($radiusBackup['acct_port'] ?? 1813)?>" spellcheck="false" min="1" max="65535">
          </div>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label" for="rad_backup_secret">Backup Shared Secret</label>
          <div style="position:relative;">
            <input type="password" id="rad_backup_secret" name="backup_secret" class="form-control mono" autocomplete="new-password" spellcheck="false"
                   value="<?= esc($radiusBackup['secret'] ?? '') ?>" placeholder="••••••••••••">
            <button type="button" class="btn-eye" onclick="togglePasswordVisibility('rad_backup_secret', this)"
                    style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
          <span class="form-hint">Leave blank to maintain existing configured secret.</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Protocol, Identification & Attributes -->
  <div class="card card-glass" style="margin-bottom:20px;">
    <div class="card-header">
      <span class="card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        RADIUS Protocol & Client Parameters
      </span>
    </div>
    <div class="card-body">
      <div class="settings-grid-3">
        <div class="form-group">
          <label class="form-label" for="rad_auth_type">Authentication Protocol</label>
          <select id="rad_auth_type" name="auth_type" class="form-control form-select">
            <option value="pap" <?= (($radiusPrimary['auth_type'] ?? 'pap') === 'pap') ? 'selected' : '' ?>>PAP (Password Authentication Protocol)</option>
            <option value="chap" <?= (($radiusPrimary['auth_type'] ?? '') === 'chap') ? 'selected' : '' ?>>CHAP (Challenge Handshake Auth)</option>
            <option value="mschapv2" <?= (($radiusPrimary['auth_type'] ?? '') === 'mschapv2') ? 'selected' : '' ?>>MS-CHAPv2 (Microsoft Challenge Handshake)</option>
          </select>
          <span class="form-hint">Must match configured RADIUS server authentication policy.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="rad_nas_identifier">NAS Identifier</label>
          <input type="text" id="rad_nas_identifier" name="nas_identifier" class="form-control mono"
                 value="<?= esc($radiusPrimary['nas_identifier'] ?? 'micronos-appliance')?>" spellcheck="false" placeholder="micronos-appliance">
          <span class="form-hint">RFC 2865 Attribute 32: Network Access Server identifier string.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="rad_nas_ip">NAS IP Address</label>
          <input type="text" id="rad_nas_ip" name="nas_ip" class="form-control mono"
                 value="<?= esc($radiusPrimary['nas_ip'] ?? '127.0.0.1')?>" spellcheck="false" placeholder="127.0.0.1">
          <span class="form-hint">RFC 2865 Attribute 4: Appliance management or client IP.</span>
        </div>
      </div>

      <div class="settings-grid-3">
        <div class="form-group">
          <label class="form-label" for="rad_timeout">Request Timeout</label>
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="number" id="rad_timeout" name="timeout" class="form-control mono"
                   value="<?= (int)($radiusPrimary['timeout'] ?? 5)?>" spellcheck="false" min="1" max="30">
            <span style="font-size:0.8rem; color:var(--text-muted);">seconds</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="rad_retries">Max Retries</label>
          <input type="number" id="rad_retries" name="retries" class="form-control mono"
                 value="<?= (int)($radiusPrimary['retries'] ?? 3)?>" spellcheck="false" min="1" max="10">
          <span class="form-hint">Transmission attempts before marking server dead.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="rad_default_role_id">Default Fallback Role</label>
          <select id="rad_default_role_id" name="default_role_id" class="form-control form-select">
            <option value="">— Select Fallback Role —</option>
            <?php foreach ($allRoles as $role): ?>
              <option value="<?= esc($role['id']) ?>" <?= (($radiusPrimary['default_role_id'] ?? '') === $role['id']) ? 'selected' : '' ?>>
                <?= esc($role['display_name']) ?> (<?= esc($role['name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <span class="form-hint">Assigned when Access-Accept has no matching role attribute.</span>
        </div>
      </div>

      <div class="settings-grid-2" style="margin-bottom:0; padding-top:12px; border-top:1px solid var(--color-border);">
        <div class="form-group">
          <label class="form-label" for="rad_group_attribute">Role Assignment Attribute</label>
          <select id="rad_group_attribute" name="group_attribute" class="form-control form-select">
            <option value="Filter-Id" <?= (($radiusPrimary['group_attribute'] ?? 'Filter-Id') === 'Filter-Id') ? 'selected' : '' ?>>Filter-Id (RFC 2865 Attribute 11 - Recommended)</option>
            <option value="Class" <?= (($radiusPrimary['group_attribute'] ?? '') === 'Class') ? 'selected' : '' ?>>Class (RFC 2865 Attribute 25)</option>
          </select>
          <span class="form-hint">Attribute inspected inside Access-Accept packet for role matching.</span>
        </div>

        <div class="form-group" style="display:flex; flex-direction:column; justify-content:center;">
          <label class="toggle-switch">
            <input type="checkbox" name="acct_enabled" value="1" <?= (!empty($radiusPrimary['acct_enabled'])) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <span style="font-size:0.85rem; margin-left:10px; color:var(--text-primary); font-weight:500;">Enable RADIUS Accounting</span>
          </label>
          <span class="form-hint" style="margin-left:52px;">Send RFC 2866 Start / Stop accounting packets for admin audit logs.</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Attribute to Role Mappings Table -->
  <div class="card card-glass" style="margin-bottom:20px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        RADIUS Attribute-to-Role Mappings (Filter-Id / Class)
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openAddGroupMappingModal('radius')">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Add RADIUS Mapping
      </button>
    </div>
    <div class="card-body">
      <table class="iface-table">
        <thead>
          <tr>
            <th>RADIUS Attribute Value (Filter-Id / Class String)</th>
            <th>Mapped MicronOS RBAC Role</th>
            <th style="width:100px;">Priority</th>
            <th style="text-align:right; width:90px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($radiusMappings)): ?>
            <tr>
              <td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted); font-size:0.85rem;">
                No RADIUS attribute mappings defined. Users will receive the fallback role upon successful authentication.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($radiusMappings as $rm): ?>
              <?php $rmGrp = $rm['external_group'] ?? $rm['external_group_name'] ?? ''; ?>
              <tr>
                <td>
                  <div style="font-weight:600; font-size:0.88rem; color:var(--text-primary); font-family:var(--font-mono);">
                    <?= esc($rmGrp) ?>
                  </div>
                </td>
                <td>
                  <span class="badge <?= ($rm['role_name'] === 'superadmin') ? 'badge-purple' : 'badge-info' ?>" style="font-size:0.75rem;">
                    <?= esc($rm['role_display_name'] ?? $rm['role_name']) ?>
                  </span>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
                  <?= (int)$rm['priority'] ?>
                </td>
                <td style="text-align:right;">
                  <a href="<?= base_url('system/group-mappings/' . esc($rm['id']) . '/delete') ?>"
                     class="btn btn-danger btn-sm"
                     onclick="return confirm('Remove RADIUS mapping for \'<?= esc(addslashes($rmGrp)) ?>\'?');"
                     title="Delete mapping">
                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- RADIUS Save Bar -->
  <div class="settings-save-bar">
    <div style="display:flex; align-items:center; gap:12px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--color-cyan)" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span style="font-size:0.85rem; color:var(--text-secondary);">RADIUS server settings and shared secrets take effect immediately on next sign-in.</span>
    </div>
    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn btn-primary" id="btn-save-radius" style="min-width:180px;">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        Save RADIUS Settings
      </button>
    </div>
  </div>
</form>

<!-- ===================================================
     MODAL: Add Group Mapping (LDAP or RADIUS)
=================================================== -->
<div id="addGroupMappingModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:540px; padding:24px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;" id="mappingModalTitle">Add External Group Mapping</h3>
      <button type="button" onclick="closeAddGroupMappingModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('system/group-mappings/store') ?>" id="groupMappingForm">
      <?= csrf_field() ?>
      <input type="hidden" name="source_type" id="modal_source_type" value="ldap">

      <div class="form-group" style="margin-bottom:16px;">
        <label class="form-label" for="modal_external_group_name" id="modalGroupLabel">Directory Group Name / DN <span class="badge-required">*</span></label>
        <input type="text" id="modal_external_group_name" name="external_group_name" class="form-control mono" required
               placeholder="e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local" spellcheck="false">
        <span class="form-hint" id="modalGroupHint">Exact CN, full distinguished name (DN), or Filter-Id string returned by auth server.</span>
      </div>

      <div class="form-group" style="margin-bottom:16px;">
        <label class="form-label" for="modal_role_id">Target MicronOS RBAC Role <span class="badge-required">*</span></label>
        <select id="modal_role_id" name="role_id" class="form-control form-select" required>
          <option value="">— Select Target Role —</option>
          <?php foreach ($allRoles as $role): ?>
            <option value="<?= esc($role['id']) ?>">
              <?= esc($role['display_name']) ?> (<?= esc($role['name']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <span class="form-hint">Role and privileges granted to members of this external group.</span>
      </div>

      <div class="form-group" style="margin-bottom:24px;">
        <label class="form-label" for="modal_priority">Resolution Priority (1 = Highest)</label>
        <input type="number" id="modal_priority" name="priority" class="form-control mono" value="10" min="1" max="1000" spellcheck="false">
        <span class="form-hint">When a user belongs to multiple groups, the mapping with lowest priority number wins.</span>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeAddGroupMappingModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Mapping Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================================================
     MODAL: Test LDAP Authentication
=================================================== -->
<div id="testLdapModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:580px; padding:24px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;">Test Active Directory / LDAP Authentication</h3>
      <button type="button" onclick="closeLdapTestModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <p style="font-size:0.83rem; color:var(--text-secondary); margin-bottom:16px;">
      Enter directory credentials to simulate authentication, search resolution, group extraction, and RBAC role assignment in real time.
    </p>

    <div class="form-group" style="margin-bottom:14px;">
      <label class="form-label" for="test_ldap_user">Test Username (sAMAccountName or UID)</label>
      <input type="text" id="test_ldap_user" class="form-control mono" placeholder="e.g. administrator or jdoe" spellcheck="false">
    </div>

    <div class="form-group" style="margin-bottom:16px;">
      <label class="form-label" for="test_ldap_pass">Test Password</label>
      <input type="password" id="test_ldap_pass" class="form-control mono" autocomplete="off" spellcheck="false" placeholder="••••••••••••">
    </div>

    <div id="ldapTestOutput" style="display:none; margin-bottom:16px; padding:12px; border-radius:6px; font-family:var(--font-mono); font-size:0.8rem; max-height:220px; overflow-y:auto;">
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px;">
      <button type="button" class="btn btn-ghost" onclick="closeLdapTestModal()">Close</button>
      <button type="button" class="btn btn-primary" id="btn-run-ldap-test" onclick="executeLdapTest()">
        Execute Test
      </button>
    </div>
  </div>
</div>

<!-- ===================================================
     MODAL: Test RADIUS Authentication
=================================================== -->
<div id="testRadiusModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:580px; padding:24px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin:0;">Test RADIUS Authentication (Access-Request)</h3>
      <button type="button" onclick="closeRadiusTestModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <p style="font-size:0.83rem; color:var(--text-secondary); margin-bottom:16px;">
      Transmits an RFC 2865 Access-Request packet to the primary RADIUS server to verify the shared secret, protocol handshake, and received attributes.
    </p>

    <div class="form-group" style="margin-bottom:14px;">
      <label class="form-label" for="test_rad_user">RADIUS Username</label>
      <input type="text" id="test_rad_user" class="form-control mono" placeholder="e.g. radiusadmin or testuser" spellcheck="false">
    </div>

    <div class="form-group" style="margin-bottom:16px;">
      <label class="form-label" for="test_rad_pass">RADIUS Password</label>
      <input type="password" id="test_rad_pass" class="form-control mono" autocomplete="off" spellcheck="false" placeholder="••••••••••••">
    </div>

    <div id="radiusTestOutput" style="display:none; margin-bottom:16px; padding:12px; border-radius:6px; font-family:var(--font-mono); font-size:0.8rem; max-height:220px; overflow-y:auto;">
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px;">
      <button type="button" class="btn btn-ghost" onclick="closeRadiusTestModal()">Close</button>
      <button type="button" class="btn btn-primary" id="btn-run-rad-test" onclick="executeRadiusTest()">
        Send Access-Request
      </button>
    </div>
  </div>
</div>

<!-- ===================================================
     Styles
=================================================== -->
<style>
/* ── Tabs ───────────────────────────────────────── */
.sys-tab-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 10px 18px; background: none; border: none;
  border-bottom: 2px solid transparent;
  color: var(--text-muted); font-size: 0.83rem; font-weight: 500;
  cursor: pointer; transition: all 0.2s; white-space: nowrap;
  font-family: var(--font-sans);
}
.sys-tab-btn:hover { color: var(--text-primary); border-bottom-color: rgba(255,255,255,0.2); }
.sys-tab-btn.active { color: var(--color-cyan); border-bottom-color: var(--color-cyan); }

/* ── Section headers ────────────────────────────── */
.settings-section-header { padding: 24px 0 16px; }
.settings-section-title  { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
.settings-section-desc   { font-size: 0.82rem; color: var(--text-muted); }

/* ── Grid layouts ───────────────────────────────── */
.settings-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.settings-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px; }
@media (max-width: 768px) {
  .settings-grid-2, .settings-grid-3 { grid-template-columns: 1fr; }
}

/* ── Form hints ─────────────────────────────────── */
.form-hint { font-size: 0.73rem; color: var(--text-muted); margin-top: 5px; display: block; }

/* ── Badge required ─────────────────────────────── */
.badge-required { color: var(--color-red); font-size: 0.75rem; }

/* ── Mono inputs ────────────────────────────────── */
.form-control.mono { font-family: var(--font-mono); }

/* ── Toggle switch ──────────────────────────────── */
.toggle-switch { display: inline-flex; align-items: center; cursor: pointer; }
.toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-slider {
  position: relative; width: 42px; height: 22px;
  background: var(--color-surface-3); border-radius: 11px;
  transition: 0.25s; border: 1px solid var(--color-border);
}
.toggle-slider::before {
  content: ''; position: absolute; left: 3px; top: 2px;
  width: 16px; height: 16px; background: var(--text-muted);
  border-radius: 50%; transition: 0.25s;
}
.toggle-switch input:checked + .toggle-slider { background: var(--color-cyan-dim); border-color: var(--color-cyan-glow); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); background: var(--color-cyan); }
.toggle-switch input:checked + .toggle-slider-danger { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); }
.toggle-switch input:checked + .toggle-slider-danger::before { background: var(--color-red); }

/* ── Form select ────────────────────────────────── */
.form-control.form-select {
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: 36px;
}

/* ── Quick timezone / NTP buttons ───────────────── */
.quick-tz-btn {
  padding: 4px 10px; background: var(--color-surface-3);
  border: 1px solid var(--color-border); border-radius: 6px;
  color: var(--text-secondary); font-size: 0.75rem; cursor: pointer;
  transition: all 0.15s; font-family: var(--font-sans);
}
.quick-tz-btn:hover { border-color: var(--color-cyan-glow); color: var(--color-cyan); background: var(--color-cyan-dim); }

/* ── Sticky header colour pickers ───────────────── */
.sticky-color-row { display: flex; align-items: center; gap: 10px; }
.sticky-color-row .form-control { flex: 1 1 auto; min-width: 0; }
.sticky-color-picker {
  flex: 0 0 auto; width: 44px; height: 38px; padding: 3px;
  background: var(--color-surface-3); border: 1px solid var(--color-border);
  border-radius: var(--radius-sm); cursor: pointer;
}
.sticky-color-picker::-webkit-color-swatch-wrapper { padding: 0; }
.sticky-color-picker::-webkit-color-swatch { border: none; border-radius: 4px; }
.sticky-color-picker::-moz-color-swatch { border: none; border-radius: 4px; }

/* ── Sticky header options row ──────────────────── */
.sticky-header-options {
  display: flex; justify-content: space-between; align-items: center;
  gap: 20px; margin-top: 18px; padding: 14px 16px;
  background: var(--color-surface-3); border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
}
.sticky-option-title { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }

/* ── Save bar ───────────────────────────────────── */
.settings-save-bar {
  position: sticky; bottom: 0; z-index: 50;
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 24px; padding: 16px 24px;
  background: linear-gradient(135deg, rgba(13,18,32,0.97), rgba(17,24,39,0.97));
  backdrop-filter: blur(12px); border: 1px solid var(--color-border);
  border-radius: 12px; box-shadow: var(--shadow-lg);
}

/* ── btn-sm ─────────────────────────────────────── */
.btn-sm { padding: 6px 14px; font-size: 0.8rem; }
</style>

<!-- ===================================================
     Scripts
=================================================== -->
<script>
// ── Tab switching ────────────────────────────────────
function switchTab(id) {
  if (id === 'webgui' || id === 'ssh') {
    id = 'admin_access';
  }
  document.querySelectorAll('.sys-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.sys-tab-panel').forEach(p => {
    p.classList.remove('active');
    p.style.display = 'none';
  });
  const genSaveBar = document.getElementById('general-save-bar');
  if (genSaveBar) {
    genSaveBar.style.display = (id === 'ldap' || id === 'radius') ? 'none' : 'flex';
  }
  const btn = document.getElementById('tab-btn-' + id);
  const panel = document.getElementById('panel-' + id);
  if (btn) btn.classList.add('active');
  if (panel) {
    panel.classList.add('active');
    panel.style.display = 'block';
  }
  localStorage.setItem('sys_active_tab', id);
  if (window.history && window.history.replaceState) {
    window.history.replaceState(null, null, '#' + id);
  }
}

// Restore last active tab (from URL hash or localStorage)
(function() {
  let hash = window.location.hash.replace('#', '');
  if (hash === 'webgui' || hash === 'ssh') {
    hash = 'admin_access';
  }
  if (hash && document.getElementById('panel-' + hash)) {
    switchTab(hash);
    return;
  }
  let saved = localStorage.getItem('sys_active_tab');
  if (saved === 'webgui' || saved === 'ssh') {
    saved = 'admin_access';
  }
  if (saved && document.getElementById('panel-' + saved)) {
    switchTab(saved);
    return;
  }
  switchTab('identity');
})();

function updateCertDetailsPreview(sel) {
  const box = document.getElementById('cert-preview-box');
  if (!box) return;
  const opt = sel.options[sel.selectedIndex];
  const val = parseInt(sel.value, 10);
  if (val === 0) {
    box.innerHTML = `
      <div style="display:flex; align-items:center; gap:6px; color:var(--color-cyan); font-weight:600; margin-bottom:4px;">
        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--color-cyan);"></span>
        System Default Certificate
      </div>
      <div style="font-size:0.75rem; color:var(--text-secondary);">
        Using self-signed certificate at <code>/etc/micron/ssl/webgui.crt</code>. To remove browser security warnings, select a CA-signed or ACME certificate.
      </div>`;
  } else {
    const cn = opt.getAttribute('data-cn') || '—';
    const exp = opt.getAttribute('data-expires') || '—';
    const san = opt.getAttribute('data-san') || '';
    box.innerHTML = `
      <div style="display:flex; align-items:center; gap:6px; color:var(--color-emerald); font-weight:600; margin-bottom:4px;">
        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--color-emerald);"></span>
        Custom Certificate Selected
      </div>
      <div><strong>Common Name:</strong> ${escapeHtml(cn)}</div>
      <div style="font-size:0.75rem; color:var(--text-muted);">
        Valid until: ${escapeHtml(exp)} ${san ? '&bull; SAN: ' + escapeHtml(san) : ''}
      </div>`;
  }
}

function escapeHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── LDAP & RADIUS Helpers ────────────────────────────
function adaptLdapDefaults(schema) {
  const filter = document.getElementById('ldap_user_search_filter');
  const userAttr = document.getElementById('ldap_username_attribute');
  const grpAttr = document.getElementById('ldap_group_attribute');
  if (schema === 'ad') {
    if (filter) filter.value = '(&(objectClass=user)(sAMAccountName={username}))';
    if (userAttr) userAttr.value = 'sAMAccountName';
    if (grpAttr) grpAttr.value = 'memberOf';
  } else if (schema === 'openldap') {
    if (filter) filter.value = '(&(objectClass=inetOrgPerson)(uid={username}))';
    if (userAttr) userAttr.value = 'uid';
    if (grpAttr) grpAttr.value = 'member';
  } else if (schema === 'rfc2307') {
    if (filter) filter.value = '(&(objectClass=posixAccount)(uid={username}))';
    if (userAttr) userAttr.value = 'uid';
    if (grpAttr) grpAttr.value = 'memberUid';
  }
}

function autoAdjustLdapPort(enc) {
  const port = document.getElementById('ldap_port');
  if (!port) return;
  if (enc === 'ldaps' && (port.value === '389' || port.value === '')) {
    port.value = '636';
  } else if (enc !== 'ldaps' && port.value === '636') {
    port.value = '389';
  }
}

// ── Group Mapping Modal ──────────────────────────────
function openAddGroupMappingModal(sourceType) {
  const modal = document.getElementById('addGroupMappingModal');
  const title = document.getElementById('mappingModalTitle');
  const typeInput = document.getElementById('modal_source_type');
  const label = document.getElementById('modalGroupLabel');
  const hint = document.getElementById('modalGroupHint');
  const groupInp = document.getElementById('modal_external_group_name');

  typeInput.value = sourceType;
  if (sourceType === 'ldap') {
    title.textContent = 'Add Active Directory / LDAP Group Mapping';
    label.innerHTML = 'Directory Group Name / DN <span class="badge-required">*</span>';
    hint.textContent = 'Active Directory CN or full distinguished name (DN), e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local';
    groupInp.placeholder = 'e.g. Domain Admins';
  } else {
    title.textContent = 'Add RADIUS Attribute Mapping';
    label.innerHTML = 'RADIUS Attribute Value (Filter-Id / Class) <span class="badge-required">*</span>';
    hint.textContent = 'Exact string value received in Filter-Id or Class attribute, e.g. network_superadmins or noc-operators';
    groupInp.placeholder = 'e.g. network_superadmins';
  }

  modal.style.display = 'flex';
}

function closeAddGroupMappingModal() {
  document.getElementById('addGroupMappingModal').style.display = 'none';
}

// ── Test LDAP Modal & AJAX ───────────────────────────
function openLdapTestModal() {
  document.getElementById('testLdapModal').style.display = 'flex';
}
function closeLdapTestModal() {
  document.getElementById('testLdapModal').style.display = 'none';
}

function executeLdapTest() {
  const username = document.getElementById('test_ldap_user').value.trim();
  const password = document.getElementById('test_ldap_pass').value;
  const out = document.getElementById('ldapTestOutput');
  const btn = document.getElementById('btn-run-ldap-test');

  if (!username) {
    alert('Please enter a username to test.');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Connecting…';
  out.style.display = 'block';
  out.style.background = 'rgba(0,0,0,0.5)';
  out.style.border = '1px solid var(--color-border)';
  out.style.color = 'var(--text-secondary)';
  out.innerHTML = '<span style="color:var(--color-cyan);">[INFO]</span> Initiating directory connection test…';

  const formData = new FormData();
  formData.append('username', username);
  formData.append('password', password);
  formData.append('hostname', document.getElementById('ldap_hostname')?.value || '');
  formData.append('port', document.getElementById('ldap_port')?.value || '389');
  formData.append('schema_type', document.getElementById('ldap_schema_type')?.value || 'ad');
  formData.append('encryption', document.getElementById('ldap_encryption')?.value || 'none');
  formData.append('base_dn', document.getElementById('ldap_base_dn')?.value || '');
  formData.append('bind_dn', document.getElementById('ldap_bind_dn')?.value || '');
  formData.append('bind_password', document.getElementById('ldap_bind_password')?.value || '');

  MicronScheduler.fetch('<?= base_url('system/ldap/test') ?>', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = 'Execute Test';
    if (data.success) {
      out.style.border = '1px solid rgba(16, 185, 129, 0.4)';
      let html = '<span style="color:var(--color-emerald); font-weight:700;">✔ AUTHENTICATION SUCCESSFUL</span><br>';
      html += '<span style="color:var(--text-muted);">User DN:</span> ' + (data.user?.dn || 'N/A') + '<br>';
      html += '<span style="color:var(--text-muted);">Display Name:</span> ' + (data.user?.full_name || 'N/A') + '<br>';
      html += '<span style="color:var(--text-muted);">Resolved Role:</span> <span class="badge badge-purple">' + (data.user?.role || 'operator') + '</span><br>';
      if (data.user?.groups && data.user.groups.length > 0) {
        html += '<span style="color:var(--text-muted);">Groups:</span> ' + data.user.groups.join(', ') + '<br>';
      }
      out.innerHTML = html;
    } else {
      out.style.border = '1px solid rgba(239, 68, 68, 0.4)';
      out.innerHTML = '<span style="color:var(--color-red); font-weight:700;">✖ AUTHENTICATION FAILED</span><br><span style="color:var(--text-muted);">Error:</span> ' + (data.error || 'Connection failed.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.textContent = 'Execute Test';
    out.style.border = '1px solid rgba(239, 68, 68, 0.4)';
    out.innerHTML = '<span style="color:var(--color-red);">✖ Request failed: ' + err.message + '</span>';
  });
}

function triggerLdapSync() {
  if (!confirm('Synchronize directory users and security groups from LDAP/AD now?')) return;
  const f = document.createElement('form');
  f.method = 'POST';
  f.action = '<?= base_url('system/ldap/sync') ?>';
  const csrf = document.createElement('input');
  csrf.type = 'hidden';
  csrf.name = '<?= csrf_token() ?>';
  csrf.value = '<?= csrf_hash() ?>';
  f.appendChild(csrf);
  document.body.appendChild(f);
  f.submit();
}

// ── Test RADIUS Modal & AJAX ─────────────────────────
function openRadiusTestModal() {
  document.getElementById('testRadiusModal').style.display = 'flex';
}
function closeRadiusTestModal() {
  document.getElementById('testRadiusModal').style.display = 'none';
}

function executeRadiusTest() {
  const username = document.getElementById('test_rad_user').value.trim();
  const password = document.getElementById('test_rad_pass').value;
  const out = document.getElementById('radiusTestOutput');
  const btn = document.getElementById('btn-run-rad-test');

  if (!username) {
    alert('Please enter a RADIUS username to test.');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Sending…';
  out.style.display = 'block';
  out.style.background = 'rgba(0,0,0,0.5)';
  out.style.border = '1px solid var(--color-border)';
  out.style.color = 'var(--text-secondary)';
  out.innerHTML = '<span style="color:var(--color-cyan);">[INFO]</span> Sending RADIUS Access-Request packet…';

  const formData = new FormData();
  formData.append('username', username);
  formData.append('password', password);
  formData.append('hostname', document.getElementById('rad_hostname')?.value || '');
  formData.append('auth_port', document.getElementById('rad_auth_port')?.value || '1812');
  formData.append('secret', document.getElementById('rad_secret')?.value || '');
  formData.append('auth_type', document.getElementById('rad_auth_type')?.value || 'pap');
  formData.append('nas_identifier', document.getElementById('rad_nas_identifier')?.value || '');
  formData.append('nas_ip', document.getElementById('rad_nas_ip')?.value || '');

  MicronScheduler.fetch('<?= base_url('system/radius/test') ?>', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = 'Send Access-Request';
    if (data.success) {
      out.style.border = '1px solid rgba(16, 185, 129, 0.4)';
      let html = '<span style="color:var(--color-emerald); font-weight:700;">✔ RADIUS ACCESS-ACCEPT RECEIVED</span><br>';
      html += '<span style="color:var(--text-muted);">Response Code:</span> 2 (Access-Accept)<br>';
      html += '<span style="color:var(--text-muted);">Resolved Role:</span> <span class="badge badge-purple">' + (data.user?.role || 'operator') + '</span><br>';
      if (data.attributes) {
        html += '<span style="color:var(--text-muted);">Attributes:</span> ' + JSON.stringify(data.attributes) + '<br>';
      }
      out.innerHTML = html;
    } else {
      out.style.border = '1px solid rgba(239, 68, 68, 0.4)';
      out.innerHTML = '<span style="color:var(--color-red); font-weight:700;">✖ RADIUS REJECTED / TIMEOUT</span><br><span style="color:var(--text-muted);">Message:</span> ' + (data.error || 'Access-Reject');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.textContent = 'Send Access-Request';
    out.style.border = '1px solid rgba(239, 68, 68, 0.4)';
    out.innerHTML = '<span style="color:var(--color-red);">✖ Request error: ' + err.message + '</span>';
  });
}

// ── Toggle sections ──────────────────────────────────
function toggleNtp(on) {
  const body = document.getElementById('ntp-body');
  body.style.opacity = on ? '1' : '0.4';
  body.style.pointerEvents = on ? '' : 'none';
}

function toggleSsh(on) {
  const body = document.getElementById('ssh-body');
  body.style.opacity = on ? '1' : '0.4';
  body.style.pointerEvents = on ? '' : 'none';
}

function toggleNotif(on) {
  const body = document.getElementById('notif-body');
  body.style.opacity = on ? '1' : '0.4';
  body.style.pointerEvents = on ? '' : 'none';
}

// ── Sticky header flash — preview the effect as the switch is flipped ──
function toggleStickyBlink(on) {
  const bar = document.querySelector('.device-sticky-bar');
  if (!bar) return;
  bar.classList.toggle('dsb-blink', !!on);
}

// ── Sticky header colours — live preview on the real bar ──
const STICKY_HEX = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

function syncStickyColor(field, value) {
  const text = document.getElementById(field);
  if (text) text.value = value;
  syncStickyPreview();
}

function clearStickyColor(field) {
  const text = document.getElementById(field);
  const picker = document.getElementById(field + '_picker');
  if (text) text.value = '';
  // Keep the picker showing something sensible for the next click.
  if (picker) picker.value = field === 'webgui_sticky_bg' ? '#111827' : '#f1f5f9';
  syncStickyPreview();
}

function syncStickyPreview() {
  const bar = document.querySelector('.device-sticky-bar');
  if (!bar) return;
  const read = (field) => {
    const el = document.getElementById(field);
    const v = el ? el.value.trim() : '';
    return STICKY_HEX.test(v) ? v.toLowerCase() : '';
  };
  const bg = read('webgui_sticky_bg');
  const fg = read('webgui_sticky_fg');
  const pickerBg = document.getElementById('webgui_sticky_bg_picker');
  const pickerFg = document.getElementById('webgui_sticky_fg_picker');
  if (pickerBg && bg) pickerBg.value = bg;
  if (pickerFg && fg) pickerFg.value = fg;

  if (bg) bar.style.background = bg; else bar.style.removeProperty('background');
  if (fg) bar.style.setProperty('--dsb-fg', fg); else bar.style.removeProperty('--dsb-fg');
}

// Reflect saved values once the panel is on screen.
syncStickyPreview();

// ── IPv6 master switch ───────────────────────────────
function updateIpv6Ui() {
  const input = document.getElementById('ipv6_enabled');
  if (!input) return;
  const on = input.checked;
  const label = document.getElementById('ipv6StateLabel');
  const effects = document.getElementById('ipv6Effects');
  if (label) label.textContent = on ? 'Enabled' : 'Disabled';
  if (effects) effects.style.display = on ? 'none' : 'block';
}

// Init toggle states
toggleNtp(document.getElementById('ntp_enabled').checked);
toggleSsh(document.getElementById('ssh_enabled').checked);

// ── Copy Micron ID ───────────────────────────────────
function copyMicronId() {
  const id = document.getElementById('micronIdDisplay').textContent.trim();
  if (id === '—') return;
  navigator.clipboard.writeText(id).then(() => {
    const btn = document.getElementById('btn-copy-id');
    const orig = btn.innerHTML;
    btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="var(--color-emerald)" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    setTimeout(() => btn.innerHTML = orig, 2000);
  });
}

// ── Live clock ───────────────────────────────────────
function updateClock() {
  const el = document.getElementById('live-clock');
  if (!el) return;
  el.textContent = new Date().toUTCString().replace(' GMT', ' UTC');
}
updateClock();
setInterval(updateClock, 1000);

// ── Password visibility ──────────────────────────────
function togglePasswordVisibility(inputId, btn) {
  const inp = document.getElementById(inputId);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.style.color = inp.type === 'text' ? 'var(--color-cyan)' : 'var(--text-muted)';
}

// ── Map preview ──────────────────────────────────────
function openMapPreview() {
  const lat = document.getElementById('org_latitude').value;
  const lon = document.getElementById('org_longitude').value;
  if (!lat || !lon) {
    alert('Please enter latitude and longitude coordinates first.');
    return;
  }
  const url = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=14/${lat}/${lon}`;
  window.open(url, '_blank', 'noopener');
}

// ── Test email (stub) ────────────────────────────────
function sendTestEmail() {
  const resultEl = document.getElementById('test-email-result');
  const btn = document.getElementById('btn-test-email');
  btn.disabled = true;
  resultEl.style.color = 'var(--text-muted)';
  resultEl.textContent = 'Sending…';
  setTimeout(() => {
    btn.disabled = false;
    resultEl.style.color = 'var(--color-amber)';
    resultEl.textContent = 'Save settings first, then use Diagnostics → Email Test.';
  }, 1200);
}

// ── Database & Storage Resilience Controls ──────────
function runDbIntegrityCheck() {
  const btn = document.getElementById('btn-db-check');
  const fb = document.getElementById('db-action-feedback');
  btn.disabled = true;
  btn.innerText = 'Verifying...';
  MicronScheduler.fetch('<?= base_url('system/db/check') ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '<?= csrf_hash() ?>' }
  }).then(r => r.json()).then(data => {
    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Verify Integrity';
    fb.style.display = 'block';
    fb.style.background = data.healthy ? 'rgba(34,197,94,0.1)' : 'rgba(239,68,68,0.1)';
    fb.style.color = data.healthy ? 'var(--tone-green-fg)' : 'var(--tone-red-fg)';
    fb.innerText = 'Integrity Check: ' + data.result;
  }).catch(e => {
    btn.disabled = false;
    btn.innerText = 'Verify Integrity';
    fb.style.display = 'block';
    fb.innerText = 'Error checking integrity: ' + e;
  });
}

function runDbCheckpoint() {
  const btn = document.getElementById('btn-db-checkpoint');
  const fb = document.getElementById('db-action-feedback');
  btn.disabled = true;
  btn.innerText = 'Checkpointing...';
  MicronScheduler.fetch('<?= base_url('system/db/checkpoint') ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '<?= csrf_hash() ?>' }
  }).then(r => r.json()).then(data => {
    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Flush & Truncate WAL';
    fb.style.display = 'block';
    fb.style.background = data.success ? 'rgba(34,197,94,0.1)' : 'rgba(239,68,68,0.1)';
    fb.style.color = data.success ? 'var(--tone-green-fg)' : 'var(--tone-red-fg)';
    fb.innerText = data.message || 'WAL truncated.';
  }).catch(e => {
    btn.disabled = false;
    btn.innerText = 'Flush & Truncate WAL';
  });
}

function runDbSnapshot() {
  const btn = document.getElementById('btn-db-snapshot');
  const fb = document.getElementById('db-action-feedback');
  btn.disabled = true;
  btn.innerText = 'Snapshotting...';
  MicronScheduler.fetch('<?= base_url('system/db/snapshot') ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '<?= csrf_hash() ?>' }
  }).then(r => r.json()).then(data => {
    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg> Create Shadow Snapshot';
    fb.style.display = 'block';
    fb.style.background = data.success ? 'rgba(34,197,94,0.1)' : 'rgba(239,68,68,0.1)';
    fb.style.color = data.success ? 'var(--tone-green-fg)' : 'var(--tone-red-fg)';
    fb.innerText = data.success ? ('Snapshot verified and saved: ' + data.path + ' (' + Math.round(data.size / 1024) + ' KB)') : ('Failed: ' + (data.error || 'Error'));
  }).catch(e => {
    btn.disabled = false;
    btn.innerText = 'Create Shadow Snapshot';
  });
}

// ── Save button feedback ─────────────────────────────
document.getElementById('settingsForm').addEventListener('submit', function() {
  const btn = document.getElementById('btn-save-settings');
  btn.disabled = true;
  btn.innerHTML = '<svg width="15" height="15" class="spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving…';
});
</script>

<?= $this->endSection() ?>
